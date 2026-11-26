import http from 'node:http';
import { pathToFileURL } from 'node:url';

export const WEBHOOK_PATH = '/api/github/webhook';
export const MAX_BODY = 1048576;
const allowedCodes = new Set(['ACCEPTED', 'IGNORED', 'INVALID_SIGNATURE', 'INVALID_HEADERS', 'INVALID_PAYLOAD', 'INVALID_IDENTITY', 'UNCONNECTED_REPOSITORY', 'DELIVERY_CONFLICT', 'PAYLOAD_TOO_LARGE', 'NOT_CONFIGURED']);

// Parameters are only for isolated tests. CLI has no configurable target/host/path.
export function createGateway({ upstreamPort = 18080, upstreamTimeout = 8000, bodyTimeout = 5000, connectionTimeout = 8000, maxInflight = 16 } = {}) {
  if (!Number.isInteger(upstreamPort) || upstreamPort < 1 || upstreamPort > 65535
      || upstreamTimeout < 1 || upstreamTimeout > 8000 || bodyTimeout < 1 || bodyTimeout > 5000
      || connectionTimeout < 1 || connectionTimeout > 8000
      || maxInflight < 1 || maxInflight > 16) throw new Error('INVALID_GATEWAY_CONFIG');
  let inflight = 0;
  const deadlines = new WeakMap();
  const server = http.createServer({ maxHeaderSize: 16384, requestTimeout: 10000, headersTimeout: 5000 }, (req, res) => {
    let finished = false;
    let counted = false;
    let upstream;
    let timer;
    const finish = (status, code) => {
      if (finished) return;
      finished = true;
      clearTimeout(timer);
      clearTimeout(deadlines.get(req.socket)?.timer);
      if (counted) { inflight--; counted = false; }
      upstream?.destroy();
      if (!res.destroyed) {
        res.writeHead(status, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store', 'Connection': 'close' });
        res.end(JSON.stringify({ code }));
      }
    };
    const deadline = deadlines.get(req.socket);
    // Every response closes its connection. Never grant pipelined requests a fresh budget.
    if (!deadline || deadline.finish) { req.socket.destroy(); return; }
    deadline.finish = () => finish(upstream ? 504 : 408, upstream ? 'TOTAL_TIMEOUT' : 'REQUEST_TIMEOUT');
    res.on('close', () => { if (!finished) finish(499, 'CLIENT_CLOSED'); });
    req.on('error', () => finish(400, 'INVALID_REQUEST'));
    req.on('aborted', () => finish(400, 'INVALID_REQUEST'));
    if (req.socket.remoteAddress !== '127.0.0.1') return finish(403, 'LOOPBACK_ONLY');
    if (req.url !== WEBHOOK_PATH) return finish(404, 'NOT_FOUND');
    if (req.method !== 'POST') return finish(405, 'METHOD_NOT_ALLOWED');
    const seen = new Set();
    for (let i = 0; i < req.rawHeaders.length; i += 2) {
      const name = req.rawHeaders[i].toLowerCase();
      if (seen.has(name)) return finish(400, 'DUPLICATE_HEADER');
      seen.add(name);
    }
    if (!/^application\/json(?:\s*;\s*charset=utf-8)?$/i.test(req.headers['content-type'] || '')
        || req.headers['content-encoding']) return finish(415, 'UNSUPPORTED_MEDIA_TYPE');
    if (!/^sha256=[a-f0-9]{64}$/.test(req.headers['x-hub-signature-256'] || '')
        || !/^[a-fA-F0-9]{8}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{12}$/.test(req.headers['x-github-delivery'] || '')
        || !/^[a-z_]{1,64}$/.test(req.headers['x-github-event'] || '')) return finish(422, 'INVALID_HEADERS');
    if (req.headers['content-length'] && Number(req.headers['content-length']) > MAX_BODY) return finish(413, 'PAYLOAD_TOO_LARGE');
    if (inflight >= maxInflight) return finish(503, 'GATEWAY_BUSY');
    inflight++; counted = true;
    timer = setTimeout(() => finish(408, 'REQUEST_TIMEOUT'), bodyTimeout);
    let bytes = 0;
    const chunks = [];
    req.on('data', chunk => {
      if (finished) return;
      bytes += chunk.length;
      if (bytes > MAX_BODY) return finish(413, 'PAYLOAD_TOO_LARGE');
      chunks.push(chunk);
    });
    req.on('end', () => {
      if (finished) return;
      clearTimeout(timer);
      if (bytes === 0) return finish(400, 'EMPTY_BODY');
      const headers = { 'Content-Type': req.headers['content-type'], 'Content-Length': bytes, 'Host': '127.0.0.1:18080' };
      for (const name of ['x-hub-signature-256', 'x-github-delivery', 'x-github-event']) headers[name] = req.headers[name];
      timer = setTimeout(() => finish(504, 'UPSTREAM_TIMEOUT'), upstreamTimeout);
      upstream = http.request({ hostname: '127.0.0.1', port: upstreamPort, path: WEBHOOK_PATH, method: 'POST', headers, agent: false }, response => {
        let size = 0;
        const parts = [];
        response.on('data', part => {
          size += part.length;
          if (size > 4096) { response.destroy(); finish(502, 'UPSTREAM_FAILURE'); }
          else parts.push(part);
        });
        response.on('error', () => finish(502, 'UPSTREAM_FAILURE'));
        response.on('end', () => {
          if (finished) return;
          if (response.statusCode === 429) return finish(429, 'RATE_LIMITED');
          try {
            const parsed = JSON.parse(Buffer.concat(parts).toString('utf8'));
            if (!allowedCodes.has(parsed.code) || ![202, 401, 403, 409, 413, 422, 503].includes(response.statusCode)) throw new Error();
            finish(response.statusCode, parsed.code);
          } catch { finish(502, 'UPSTREAM_FAILURE'); }
        });
      });
      upstream.on('error', () => finish(502, 'UPSTREAM_FAILURE'));
      upstream.end(Buffer.concat(chunks, bytes));
    });
  });
  // Absolute budget starts at TCP acceptance, including slow headers/body AND upstream.
  // It is never reset by progress, unlike an inactivity timeout.
  server.on('connection', socket => {
    const deadline = { finish: null, timer: null };
    deadline.timer = setTimeout(() => {
      if (deadline.finish) deadline.finish();
      else socket.end('HTTP/1.1 408 Request Timeout\r\nConnection: close\r\nContent-Length: 0\r\n\r\n');
      // Bound a client that will not read the small error response, too.
      const close = setTimeout(() => socket.destroy(), 100);
      close.unref();
      socket.once('close', () => clearTimeout(close));
    }, connectionTimeout);
    deadlines.set(socket, deadline);
    socket.once('close', () => clearTimeout(deadline.timer));
  });
  server.maxConnections = 32;
  server.keepAliveTimeout = 1000;
  server.on('checkContinue', (_req, res) => { res.writeHead(417, { Connection: 'close' }); res.end(); });
  server.on('upgrade', (_req, socket) => socket.end('HTTP/1.1 405 Method Not Allowed\r\nConnection: close\r\n\r\n'));
  server.on('clientError', (_error, socket) => { if (socket.writable) socket.end('HTTP/1.1 400 Bad Request\r\nConnection: close\r\n\r\n'); });
  return server;
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  if (process.argv.length !== 2) throw new Error('Gateway accepts no arguments');
  const server = createGateway();
  server.on('error', () => { process.stderr.write('GATEWAY_START_FAILED\n'); process.exitCode = 1; });
  server.listen(18081, '127.0.0.1', () => process.stdout.write('Webhook-only gateway: 127.0.0.1:18081 -> 127.0.0.1:18080 (no request logs)\n'));
  for (const signal of ['SIGINT', 'SIGTERM']) process.on(signal, () => { server.closeAllConnections(); server.close(); });
}
