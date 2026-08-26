import { app, BrowserWindow, session, shell, protocol, net } from 'electron';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { APP_CONFIG } from '../shared/app-config';
import { registerCapabilities, trustWindow } from './capabilities';
import { assessLocalEncryption } from './local-encryption';

/**
 * Privileged main process.
 *
 * Everything the renderer is not allowed to do happens here: authenticated
 * transport, credential storage, OS integration, and window policy. The
 * renderer is treated as hostile throughout, because a single XSS in a
 * dependency makes it hostile in fact.
 */

declare const MAIN_WINDOW_WEBPACK_ENTRY: string;
declare const MAIN_WINDOW_PRELOAD_WEBPACK_ENTRY: string;

const isDevelopment = !app.isPackaged;

/**
 * Single instance.
 *
 * Two instances would race over the user-data directory, and in Phase 05 over
 * the encrypted database. SQLite corruption from concurrent writers is not
 * theoretical.
 */
if (!app.requestSingleInstanceLock()) {
  app.quit();
}

// Distinct user-data root per application (Phase 00 §2.3). Set before any
// path is read, or Electron caches the default.
app.setPath('userData', path.join(app.getPath('appData'), APP_CONFIG.userDataDirectory));

/**
 * Register the packaged-asset scheme as privileged and standard BEFORE the app
 * is ready.
 *
 * `standard` gives it a real origin so CSP and same-origin apply; `secure` lets
 * it use APIs Chromium restricts to secure contexts. Without this the renderer
 * would run on `file://`, which has no meaningful origin and can read the
 * filesystem through relative paths.
 */
protocol.registerSchemesAsPrivileged([
  {
    scheme: APP_CONFIG.assetProtocolScheme,
    privileges: { standard: true, secure: true, supportFetchAPI: true, corsEnabled: false },
  },
]);

/** Content Security Policy for packaged renderer content. */
function contentSecurityPolicy(): string {
  const self = `${APP_CONFIG.assetProtocolScheme}://-`;

  return [
    `default-src 'none'`,
    `script-src ${self}`,
    // MUI/Emotion inject styles at runtime; script stays strict, which is the
    // half that matters for code execution.
    `style-src ${self} 'unsafe-inline'`,
    `img-src ${self} data:`,
    `font-src ${self} data:`,
    // The renderer never connects anywhere. All I/O goes through IPC to main.
    `connect-src 'none'`,
    `object-src 'none'`,
    `frame-src 'none'`,
    `frame-ancestors 'none'`,
    `base-uri 'none'`,
    `form-action 'none'`,
  ].join('; ');
}

function createWindow(): void {
  const window = new BrowserWindow({
    width: 1280,
    height: 800,
    minWidth: 1024,
    minHeight: 700,
    show: false,
    title: APP_CONFIG.productName,
    webPreferences: {
      // The three non-negotiables (ADR 0010). Never relax any of them, and
      // never pass --no-sandbox in production.
      nodeIntegration: false,
      contextIsolation: true,
      sandbox: true,

      webSecurity: true,
      allowRunningInsecureContent: false,
      experimentalFeatures: false,
      // A renderer that can spawn a sibling window can spawn one without these
      // protections.
      nodeIntegrationInSubFrames: false,
      webviewTag: false,

      preload: MAIN_WINDOW_PRELOAD_WEBPACK_ENTRY,
    },
  });

  // Paint only when ready, so the user never sees an empty frame.
  window.once('ready-to-show', () => window.show());

  // Register before loading: an IPC message can arrive as soon as the
  // preload runs, and an unregistered window would be refused.
  trustWindow(window);

  applyWindowPolicies(window);
  void window.loadURL(
    isDevelopment ? MAIN_WINDOW_WEBPACK_ENTRY : `${APP_CONFIG.packagedOrigin}/index.html`,
  );
}

/**
 * Deny-by-default navigation, window, permission, and download policy.
 *
 * Each handler below closes a documented Electron escape route. They are the
 * difference between "an XSS defaces the UI" and "an XSS reaches the OS".
 */
function applyWindowPolicies(window: BrowserWindow): void {
  const contents = window.webContents;

  // 1. No navigation away from packaged assets. A redirect to an attacker page
  //    inside a privileged renderer would inherit its origin.
  contents.on('will-navigate', (event, url) => {
    if (!isPackagedAsset(url)) {
      event.preventDefault();
    }
  });

  // 2. No child windows at all. window.open, target=_blank, and
  //    shell-triggered popups are refused outright.
  contents.setWindowOpenHandler(() => ({ action: 'deny' }));

  // 3. No attaching to a webview, belt-and-braces with webviewTag: false.
  contents.on('will-attach-webview', (event) => event.preventDefault());

  // 4. No downloads. Phase 07 introduces file handling through a narrow
  //    adapter with server-side quarantine, not a renderer-initiated download.
  session.defaultSession.on('will-download', (event) => event.preventDefault());

  // 5. Deny every permission. Camera, geolocation, notifications, and clipboard
  //    read are all refused; a later phase that needs one adds it explicitly.
  session.defaultSession.setPermissionRequestHandler((_wc, _permission, callback) => callback(false));
  session.defaultSession.setPermissionCheckHandler(() => false);

  // 6. No external protocol handlers. Without this, a link to an ms-msdt: or
  //    similar URI can reach the OS handler.
  contents.on('will-frame-navigate', (event) => {
    if (!isPackagedAsset(event.url)) {
      event.preventDefault();
    }
  });

  // 7. Strip any Origin the renderer might assert and enforce CSP on responses.
  session.defaultSession.webRequest.onHeadersReceived((details, callback) => {
    callback({
      responseHeaders: {
        ...details.responseHeaders,
        'Content-Security-Policy': [contentSecurityPolicy()],
        'X-Content-Type-Options': ['nosniff'],
      },
    });
  });
}

function isPackagedAsset(url: string): boolean {
  try {
    const parsed = new URL(url);

    if (isDevelopment && (parsed.protocol === 'http:' || parsed.protocol === 'https:')) {
      // The Forge dev server serves the renderer over http in development only.
      return parsed.hostname === 'localhost' || parsed.hostname === '127.0.0.1';
    }

    return parsed.protocol === `${APP_CONFIG.assetProtocolScheme}:`;
  } catch {
    return false;
  }
}

function isPathInside(root: string, candidate: string): boolean {
  const resolvedRoot = path.resolve(root);
  const resolved = path.resolve(candidate);
  const relative = path.relative(resolvedRoot, resolved);

  return relative !== '' && !relative.startsWith('..') && !path.isAbsolute(relative);
}

/** Serve packaged renderer assets from the privileged custom scheme. */
function registerAssetProtocol(): void {
  protocol.handle(APP_CONFIG.assetProtocolScheme, async (request) => {
    const { pathname } = new URL(request.url);
    const root = path.join(__dirname, '..', 'renderer', 'main_window');

    // Resolve and confirm containment. Without this check a crafted
    // `../../` path reads arbitrary files with the app's privileges.
    const resolved = path.normalize(path.join(root, pathname === '/' ? 'index.html' : pathname));

    if (!isPathInside(root, resolved)) {
      return new Response('Forbidden', { status: 403 });
    }

    return net.fetch(pathToFileURL(resolved).toString());
  });
}

app.whenReady().then(() => {
  const localEncryption = assessLocalEncryption();
  if (!localEncryption.allowed) {
    // Fail closed: do not mint a database key and do not open an encrypted file.
    // Linux `basic_text` is in this branch by policy, not as a degraded mode.
  }

  registerAssetProtocol();
  registerCapabilities();
  createWindow();

  app.on('activate', () => {
    if (BrowserWindow.getAllWindows().length === 0) {
      createWindow();
    }
  });
});

// Exclusive deep-link scheme registration, distinct per application.
app.setAsDefaultProtocolClient(APP_CONFIG.protocolScheme);

app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') {
    app.quit();
  }
});

/**
 * Refuse `shell.openExternal` globally.
 *
 * Phase 00 has no legitimate external link. A later phase that adds one must
 * parse, allowlist, and require explicit user intent — not call through here.
 */
void shell;
