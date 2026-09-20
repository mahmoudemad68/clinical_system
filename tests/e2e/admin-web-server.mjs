#!/usr/bin/env node
/**
 * Same-origin static host for the Admin production build plus an /api proxy
 * to Laravel. Vite preview's http-proxy-3 path is not used here because
 * cookie sessions must keep Cookie and Set-Cookie arrays intact on POST.
 *
 * Set-Cookie must be applied with `setHeader` *before* `writeHead`. Calling
 * `setHeader` after `writeHead` throws ERR_HTTP_HEADERS_SENT on Node 22 and
 * kills the host, which then surfaces as Playwright net::ERR_CONNECTION_REFUSED.
 */
import { createReadStream, existsSync, statSync } from 'node:fs';
import http from 'node:http';
import { extname, join, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const distRoot = resolve(fileURLToPath(new URL('../../apps/admin-web/dist', import.meta.url))) + sep;
const listenPort = Number.parseInt(process.env.CLINIC_ADMIN_WEB_PORT ?? '4173', 10);
const api = new URL(process.env.CLINIC_WEB_BASE_URL ?? 'http://127.0.0.1:8080');
const hopByHop = new Set([
  'connection',
  'keep-alive',
  'proxy-authenticate',
  'proxy-authorization',
  'te',
  'trailers',
  'transfer-encoding',
  'upgrade',
]);

const mime = {
  '.css': 'text/css; charset=utf-8',
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.map': 'application/json; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.txt': 'text/plain; charset=utf-8',
  '.woff2': 'font/woff2',
};

function safeStaticPath(urlPath) {
  const pathname = decodeURIComponent(urlPath.split('?')[0] ?? '/');
  const relative = pathname === '/' ? 'index.html' : pathname.replace(/^\/+/, '');
  const resolved = resolve(join(distRoot, relative));
  if (resolved !== distRoot.slice(0, -1) && !resolved.startsWith(distRoot)) {
    return null;
  }
  if (existsSync(resolved) && statSync(resolved).isFile()) {
    return resolved;
  }

  return join(distRoot, 'index.html');
}

function apiHostHeader() {
  const port = api.port === '' ? (api.protocol === 'https:' ? '443' : '80') : api.port;

  return `${api.hostname}:${port}`;
}

/**
 * PHP's built-in server matches the Cookie header name case-sensitively
 * (`Cookie`, not `cookie`). Node lowercases IncomingMessage.headers, so the
 * proxy must restore RFC Cookie casing from rawHeaders and set Host to the
 * Laravel listener — not the Admin preview origin.
 */
function incomingHeaders(req) {
  const headers = {};
  const cookies = [];
  const raw = req.rawHeaders ?? [];

  for (let i = 0; i < raw.length; i += 2) {
    const name = raw[i];
    const value = raw[i + 1];
    const lower = name.toLowerCase();
    if (hopByHop.has(lower) || lower === 'host' || value === undefined) {
      continue;
    }
    if (lower === 'cookie') {
      cookies.push(value);
      continue;
    }
    const existing = Object.keys(headers).find((key) => key.toLowerCase() === lower);
    if (existing === undefined) {
      headers[name] = value;
      continue;
    }
    const current = headers[existing];
    headers[existing] = Array.isArray(current) ? [...current, value] : [current, value];
  }

  if (cookies.length > 0) {
    headers.Cookie = cookies.join('; ');
  }
  headers.Host = apiHostHeader();

  return headers;
}

function setCookieCount(proxyRes) {
  const cookies = proxyRes.headers['set-cookie'];
  if (Array.isArray(cookies)) {
    return cookies.length;
  }

  return typeof cookies === 'string' && cookies !== '' ? 1 : 0;
}

function cookieNames(header) {
  if (typeof header !== 'string' || header === '') {
    return '-';
  }

  return header
    .split(';')
    .map((part) => part.split('=')[0]?.trim() ?? '')
    .filter((name) => name !== '')
    .sort()
    .join(',');
}

function writeProxyHead(proxyRes, res) {
  for (const [name, value] of Object.entries(proxyRes.headers)) {
    const lower = name.toLowerCase();
    if (hopByHop.has(lower) || value === undefined) {
      continue;
    }
    res.setHeader(name, value);
  }
  res.writeHead(proxyRes.statusCode ?? 502);
}

function proxyApi(req, res) {
  const hasCookie = typeof req.headers.cookie === 'string' && req.headers.cookie !== '';
  const proxyReq = http.request(
    {
      hostname: api.hostname,
      port: api.port === '' ? (api.protocol === 'https:' ? 443 : 80) : Number.parseInt(api.port, 10),
      path: req.url,
      method: req.method,
      headers: incomingHeaders(req),
    },
    (proxyRes) => {
      const status = proxyRes.statusCode ?? 502;
      const authorization = typeof req.headers.authorization === 'string' && req.headers.authorization !== '';
      process.stderr.write(
        `${req.method ?? 'GET'} ${req.url ?? ''} cookie=${hasCookie ? '1' : '0'} names=${cookieNames(req.headers.cookie)} auth=${authorization ? '1' : '0'} xsrf=${req.headers['x-xsrf-token'] ? '1' : '0'} set-cookie=${String(setCookieCount(proxyRes))} -> ${String(status)}\n`,
      );
      try {
        writeProxyHead(proxyRes, res);
        proxyRes.pipe(res);
      } catch (error) {
        process.stderr.write(
          `proxy response failed: ${error instanceof Error ? error.message : 'unknown'}\n`,
        );
        if (!res.writableEnded) {
          if (!res.headersSent) {
            res.writeHead(502, { 'content-type': 'application/json' });
          }
          res.end(
            '{"errors":[{"code":"DEPENDENCY_UNAVAILABLE","message":"The API proxy could not complete the response."}]}',
          );
        }
      }
    },
  );

  proxyReq.on('error', () => {
    if (!res.headersSent) {
      res.writeHead(502, { 'content-type': 'application/json' });
    }
    res.end('{"errors":[{"code":"DEPENDENCY_UNAVAILABLE","message":"The API proxy could not reach Laravel."}]}');
  });

  req.pipe(proxyReq);
}

function serveStatic(req, res) {
  const filePath = safeStaticPath(req.url ?? '/');
  if (filePath === null) {
    res.writeHead(400, { 'content-type': 'text/plain; charset=utf-8' });
    res.end('Bad request');
    return;
  }

  const type = mime[extname(filePath)] ?? 'application/octet-stream';
  res.writeHead(200, {
    'content-type': type,
    'cache-control': 'no-store',
    'x-content-type-options': 'nosniff',
  });
  createReadStream(filePath).pipe(res);
}

const server = http.createServer((req, res) => {
  const path = req.url ?? '/';
  if (path.startsWith('/api/')) {
    proxyApi(req, res);
    return;
  }

  serveStatic(req, res);
});

server.listen(listenPort, '127.0.0.1', () => {
  const address = server.address();
  const port = typeof address === 'object' && address !== null ? address.port : listenPort;
  process.stderr.write(`Admin web E2E host listening on http://127.0.0.1:${String(port)}\n`);
});
