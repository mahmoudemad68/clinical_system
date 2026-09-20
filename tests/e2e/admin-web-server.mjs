#!/usr/bin/env node
/**
 * Same-origin static host for the Admin production build plus an /api proxy
 * to Laravel. Vite preview's http-proxy-3 path is not used here because
 * cookie sessions must keep Cookie and Set-Cookie arrays intact on POST.
 */
import { createReadStream, existsSync, statSync } from 'node:fs';
import http from 'node:http';
import { extname, join, normalize, resolve, sep } from 'node:path';
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

function incomingHeaders(req) {
  const headers = {};
  for (const [name, value] of Object.entries(req.headers)) {
    if (hopByHop.has(name.toLowerCase()) || value === undefined) {
      continue;
    }
    headers[name] = value;
  }

  return headers;
}

function writeProxyHead(proxyRes, res) {
  const headers = {};
  for (const [name, value] of Object.entries(proxyRes.headers)) {
    const lower = name.toLowerCase();
    if (hopByHop.has(lower) || lower === 'set-cookie' || value === undefined) {
      continue;
    }
    headers[name] = value;
  }
  res.writeHead(proxyRes.statusCode ?? 502, headers);
  const cookies = proxyRes.headers['set-cookie'];
  if (Array.isArray(cookies)) {
    res.setHeader('Set-Cookie', cookies);
  } else if (typeof cookies === 'string' && cookies !== '') {
    res.setHeader('Set-Cookie', cookies);
  }
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
      process.stderr.write(
        `${req.method ?? 'GET'} ${req.url ?? ''} cookie=${hasCookie ? '1' : '0'} -> ${String(status)}\n`,
      );
      writeProxyHead(proxyRes, res);
      proxyRes.pipe(res);
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
  process.stderr.write(`Admin web E2E host listening on http://127.0.0.1:${String(listenPort)}\n`);
});
