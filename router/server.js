/* global URL, process, console */
import fs from 'node:fs';
import http from 'node:http';
import httpProxy from 'http-proxy';

const ROUTE_ID = /^[A-Za-z0-9_-]{1,128}$/;
const MAX_ROUTES = 64;
const SERVICE_METHODS = [
  [/^\/graphql$/, new Set(['GET', 'HEAD', 'POST', 'OPTIONS'])],
  [/^\/healthz$/, new Set(['GET', 'HEAD', 'OPTIONS'])],
  [/^\/compose-api(?:\/|$)/, new Set(['GET', 'HEAD', 'PUT', 'POST', 'OPTIONS'])],
  [/^\/files(?:\/|$)/, new Set(['GET', 'HEAD', 'OPTIONS'])],
  [/^\/dav(?:\/|$)/, new Set(['GET', 'HEAD', 'POST', 'PUT', 'DELETE', 'MKCOL', 'COPY', 'MOVE', 'OPTIONS'])],
  [/^\/var\/log(?:\/|$)/, new Set(['GET', 'HEAD', 'OPTIONS'])],
];

function validRouteId(value) {
  return typeof value === 'string' && ROUTE_ID.test(value);
}

function normalizeAppUrl(value) {
  if (typeof value !== 'string' || value.length > 2048) return null;
  try {
    const url = new URL(value);
    if (!['http:', 'https:'].includes(url.protocol)) return null;
    if (!url.hostname || url.username || url.password || url.search || url.hash) return null;
    if (url.pathname !== '/' && url.pathname !== '') return null;
    return url.origin;
  } catch {
    return null;
  }
}

export function parseRegistry(text) {
  const value = JSON.parse(text);
  if (!value || value.version !== 1 || !Array.isArray(value.routes) || value.routes.length > MAX_ROUTES) {
    throw new Error('invalid registry schema');
  }
  const routes = new Map();
  for (const item of value.routes) {
    if (!item || !validRouteId(item.id) || routes.has(item.id)) throw new Error('invalid route id');
    const appUrl = normalizeAppUrl(item.appUrl);
    if (!appUrl) throw new Error('invalid appUrl');
    routes.set(item.id, { id: item.id, appUrl });
  }
  return routes;
}

function serviceAllows(pathname, method) {
  return SERVICE_METHODS.some(([pattern, methods]) => pattern.test(pathname) && methods.has(method));
}

function pathIsSafe(pathname) {
  if (pathname.includes('\\') || pathname.includes('\0')) return false;
  let decoded;
  try {
    decoded = decodeURIComponent(pathname);
  } catch {
    return false;
  }
  return !decoded.split('/').some((part) => part === '..' || part.includes('\0'));
}

function requestRoute(req, loadRegistry) {
  const queryIndex = req.url.indexOf('?');
  const rawPath = queryIndex === -1 ? req.url : req.url.slice(0, queryIndex);
  const query = queryIndex === -1 ? '' : req.url.slice(queryIndex);
  const match = rawPath.match(/^\/_servers\/([^/]+)(\/.*)$/);
  if (!match || !validRouteId(match[1]) || !pathIsSafe(rawPath)) return { error: 404 };
  const pathname = match[2];
  if (!serviceAllows(pathname, req.method || 'GET')) return { error: 404 };
  const route = loadRegistry().get(match[1]);
  if (!route) return { error: 404 };
  return { route, upstreamUrl: `${pathname}${query}`, pathname };
}

function gatewayOrigin(req) {
  const proto = req.headers['x-forwarded-proto'] === 'https' ? 'https' : 'http';
  return `${proto}://${req.headers.host || 'gateway.invalid'}`;
}

function rewriteDavDestination(req, route, pathname) {
  const destination = req.headers.destination;
  if (!destination) return true;
  if (!pathname.startsWith('/dav/')) return false;
  try {
    const absolute = /^https?:\/\//i.test(destination);
    const url = new URL(destination, gatewayOrigin(req));
    if (absolute && url.origin !== gatewayOrigin(req)) return false;
    const gatewayPrefix = `/_servers/${route.id}/dav`;
    let upstreamPath;
    if (url.pathname === gatewayPrefix || url.pathname.startsWith(`${gatewayPrefix}/`)) {
      upstreamPath = url.pathname.slice(gatewayPrefix.length);
    } else if (url.pathname === '/dav' || url.pathname.startsWith('/dav/')) {
      upstreamPath = url.pathname.slice('/dav'.length);
    } else {
      return false;
    }
    if (!pathIsSafe(upstreamPath)) return false;
    req.headers.destination = `${route.appUrl}/dav${upstreamPath}${url.search}`;
    return true;
  } catch {
    return false;
  }
}

function rewriteLocation(proxyRes, req) {
  const location = proxyRes.headers.location;
  const route = req.umRoute;
  if (!location || !route) return;
  try {
    const target = new URL(route.appUrl);
    const url = new URL(location, target);
    if (url.origin === target.origin && serviceAllows(url.pathname, 'GET')) {
      proxyRes.headers.location = `/_servers/${route.id}${url.pathname}${url.search}${url.hash}`;
    }
  } catch {
    delete proxyRes.headers.location;
  }
}

export function createRouter({ registryPath = process.env.REGISTRY_PATH || '/config/server-routes.json', graceMs = 5000 } = {}) {
  let lastGoodRegistry = new Map();
  let lastGoodAt = 0;
  function loadRegistry() {
    try {
      const parsed = parseRegistry(fs.readFileSync(registryPath, 'utf8'));
      lastGoodRegistry = parsed;
      lastGoodAt = Date.now();
      return parsed;
    } catch {
      // DAV 写入中途可能读到半文件:短窗使用最后一份完整白名单,超窗 fail-closed。
      return Date.now() - lastGoodAt <= graceMs ? lastGoodRegistry : new Map();
    }
  }

  const proxy = httpProxy.createProxyServer({
    changeOrigin: true,
    secure: true,
    ws: true,
    xfwd: false,
    // 不重定向、不限制大文件/Compose 长任务;前端自身有各操作超时与取消。
    followRedirects: false,
    proxyTimeout: 0,
    timeout: 0,
  });
  proxy.on('proxyRes', rewriteLocation);
  proxy.on('error', (_err, _req, res) => {
    if (typeof res.writeHead === 'function') {
      if (!res.headersSent) res.writeHead(502, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ error: 'upstream_unavailable' }));
    } else {
      res.destroy();
    }
  });

  const server = http.createServer((req, res) => {
    if (req.url === '/health' && req.method === 'GET') {
      res.writeHead(200, { 'Content-Type': 'text/plain' });
      res.end('ok\n');
      return;
    }
    const resolved = requestRoute(req, loadRegistry);
    if (resolved.error) {
      res.writeHead(resolved.error, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ error: 'route_not_allowed' }));
      return;
    }
    if (!rewriteDavDestination(req, resolved.route, resolved.pathname)) {
      res.writeHead(400, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ error: 'invalid_destination' }));
      return;
    }
    req.umRoute = resolved.route;
    req.url = resolved.upstreamUrl;
    proxy.web(req, res, { target: resolved.route.appUrl });
  });

  server.on('upgrade', (req, socket, head) => {
    const resolved = requestRoute(req, loadRegistry);
    if (resolved.error || resolved.pathname !== '/graphql') {
      socket.write('HTTP/1.1 404 Not Found\r\nConnection: close\r\n\r\n');
      socket.destroy();
      return;
    }
    req.url = resolved.upstreamUrl;
    proxy.ws(req, socket, head, { target: resolved.route.appUrl });
  });
  return server;
}

if (process.argv[1] && new URL(import.meta.url).pathname === process.argv[1]) {
  const port = Number(process.env.PORT || 8080);
  const server = createRouter();
  server.listen(port, '0.0.0.0', () => {
    console.log(`[router] listening on ${port}; registry=${process.env.REGISTRY_PATH || '/config/server-routes.json'}`);
  });
}
