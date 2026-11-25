import { test } from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import net from 'node:net';
import { once } from 'node:events';
import { createGateway, MAX_BODY } from './webhook-gateway.mjs';

const headers = { 'content-type': 'application/json', 'x-hub-signature-256': 'sha256=' + 'a'.repeat(64), 'x-github-delivery': '11111111-2222-3333-4444-555555555555', 'x-github-event': 'issues' };
async function setup(t, handler, options = {}) {
  const upstream = http.createServer(handler || ((_req, res) => res.end(JSON.stringify({ code: 'ACCEPTED' }))));
  upstream.listen(0, '127.0.0.1'); await once(upstream, 'listening');
  const gateway = createGateway({ upstreamPort: upstream.address().port, ...options });
  gateway.listen(0, '127.0.0.1'); await once(gateway, 'listening');
  t.after(() => { gateway.closeAllConnections(); upstream.closeAllConnections(); gateway.close(); upstream.close(); });
  return { gateway, upstream, port: gateway.address().port };
}
function request(port, { path = '/api/github/webhook', method = 'POST', body = '{}', extra = {}, chunked = false } = {}) {
  return new Promise((resolve, reject) => {
    const req = http.request({ host: '127.0.0.1', port, path, method, headers: { ...headers, ...(!chunked ? { 'content-length': Buffer.byteLength(body) } : {}), ...extra }, agent: false }, res => {
      let data = ''; res.on('data', c => { data += c; }); res.on('end', () => resolve({ status: res.statusCode, headers: res.headers, data }));
    });
    req.on('error', reject); if (chunked) req.write(body); req.end(chunked ? undefined : body);
  });
}

test('exact route denies UI, extension, normalized/encoded/query/absolute targets', async t => {
  let calls = 0;
  const { port } = await setup(t, (_q, s) => { calls++; s.end(); });
  for (const path of ['/', '/login', '/api/extension/v1/me', '/integrations/github/callback', '/health', '/database.sqlite', '/api/github/webhook/', '/api/github/webhook?x=1', '/api/github/%77ebhook', '//api/github/webhook', '/api/x/../github/webhook', 'http://localhost/api/github/webhook']) {
    assert.equal((await request(port, { path })).status, 404, path);
  }
  assert.equal(calls, 0);
});
test('only POST allowed', async t => {
  const { port } = await setup(t);
  for (const method of ['GET', 'HEAD', 'OPTIONS', 'PUT', 'DELETE', 'PATCH', 'TRACE']) assert.equal((await request(port, { method })).status, 405);
});
test('raw UTF8 bytes preserved; only required headers forwarded; response secrets stripped', async t => {
  const body = '{ "body": "uji 🌍\\n", "spaces":  1 }\n';
  let received;
  const { port } = await setup(t, (req, res) => {
    const chunks = []; req.on('data', c => chunks.push(c)); req.on('end', () => {
      received = { bytes: Buffer.concat(chunks), headers: req.headers, url: req.url };
      res.writeHead(202, { 'Set-Cookie': 'secret', Location: 'https://example.invalid' }); res.end('{"code":"ACCEPTED","secret":"must not forward"}');
    });
  });
  const response = await request(port, { body, extra: { Cookie: 'private', Authorization: 'Bearer private', 'X-Forwarded-For': '1.2.3.4', Host: 'public.example' } });
  assert.deepEqual(received.bytes, Buffer.from(body));
  for (const key of ['x-hub-signature-256', 'x-github-delivery', 'x-github-event']) assert.equal(received.headers[key], headers[key]);
  for (const key of ['cookie', 'authorization', 'x-forwarded-for']) assert.equal(received.headers[key], undefined);
  assert.equal(received.headers.host, '127.0.0.1:18080');
  assert.equal(response.status, 202); assert.equal(response.headers['cache-control'], 'no-store');
  assert.equal(response.headers['set-cookie'], undefined); assert.equal(response.headers.location, undefined);
  assert.equal(response.data, '{"code":"ACCEPTED"}');
});
test('fixed length and chunked payload limits plus empty input', async t => {
  const { port } = await setup(t);
  for (const chunked of [false, true]) assert.equal((await request(port, { body: 'x'.repeat(MAX_BODY + 1), chunked })).status, 413);
  assert.equal((await request(port, { body: '' })).status, 400);
});
test('media type, encoding and malformed signature/delivery are rejected before forwarding', async t => {
  const { port } = await setup(t);
  assert.equal((await request(port, { extra: { 'content-type': 'text/plain' } })).status, 415);
  assert.equal((await request(port, { extra: { 'content-encoding': 'gzip' } })).status, 415);
  for (const extra of [{ 'x-hub-signature-256': '' }, { 'x-github-delivery': '../bad' }, { 'x-github-event': 'issues\tbad' }]) assert.equal((await request(port, { extra })).status, 422);
});
test('backend HMAC rejection preserved', async t => {
  const { port } = await setup(t, (_req, res) => { res.writeHead(401); res.end('{"code":"INVALID_SIGNATURE"}'); });
  assert.equal((await request(port)).status, 401);
});
test('upstream connection failure returns safe 502', async t => {
  const { port, upstream } = await setup(t); await new Promise(resolve => upstream.close(resolve));
  assert.deepEqual(JSON.parse((await request(port)).data), { code: 'UPSTREAM_FAILURE' });
});
test('upstream deadline is bounded', async t => {
  const { port } = await setup(t, () => {}, { upstreamTimeout: 50 });
  assert.equal((await request(port)).status, 504);
});
test('redirects, unexpected JSON, HTML and oversized upstream output are not relayed', async t => {
  for (const [status, body] of [[302, ''], [200, '<secret>'], [202, '{"code":"SECRET"}'], [202, 'x'.repeat(4097)]]) {
    const { port } = await setup(t, (_req, res) => { res.writeHead(status); res.end(body); });
    assert.equal((await request(port)).status, 502);
  }
});
test('duplicate header rejected', async t => {
  const { port } = await setup(t);
  const socket = net.connect(port, '127.0.0.1'); await once(socket, 'connect');
  let response = ''; socket.on('data', c => { response += c; });
  socket.end('POST /api/github/webhook HTTP/1.1\r\nHost: localhost\r\nX-GitHub-Event: issues\r\nX-GitHub-Event: ping\r\nContent-Length: 2\r\n\r\n{}');
  await once(socket, 'close'); assert.match(response, /400 Bad Request/);
});
test('slow body has absolute deadline', async t => {
  const { port } = await setup(t, undefined, { bodyTimeout: 50 });
  const socket = net.connect(port, '127.0.0.1'); await once(socket, 'connect');
  let response = ''; socket.on('data', c => { response += c; });
  socket.write('POST /api/github/webhook HTTP/1.1\r\nHost: localhost\r\nContent-Type: application/json\r\nX-Hub-Signature-256: '+headers['x-hub-signature-256']+'\r\nX-GitHub-Delivery: '+headers['x-github-delivery']+'\r\nX-GitHub-Event: issues\r\nContent-Length: 10\r\n\r\n{');
  await once(socket, 'close'); assert.match(response, /408 Request Timeout/);
});
test('in-flight capacity bounded and released', async t => {
  const { port } = await setup(t, () => {}, { maxInflight: 1, upstreamTimeout: 100 });
  const first = request(port); await new Promise(r => setTimeout(r, 20));
  assert.equal((await request(port)).status, 503); assert.equal((await first).status, 504);
  assert.equal((await request(port)).status, 504);
});

test('total deadline includes header/body time and is not reset for upstream', async t => {
  let forwarded = 0;
  const { port } = await setup(t, (_req, res) => {
    forwarded++;
    setTimeout(() => { if (!res.destroyed) { res.writeHead(202); res.end('{"code":"ACCEPTED"}'); } }, 350);
  }, { connectionTimeout: 500, bodyTimeout: 1000, upstreamTimeout: 1000 });
  const socket = net.connect(port, '127.0.0.1'); await once(socket, 'connect');
  let response = ''; socket.on('data', c => { response += c; });
  const started = performance.now();
  socket.write('POST /api/github/webhook HTTP/1.1\r\n');
  await new Promise(r => setTimeout(r, 150));
  socket.write('Host: localhost\r\nContent-Type: application/json\r\nX-Hub-Signature-256: '+headers['x-hub-signature-256']+'\r\nX-GitHub-Delivery: '+headers['x-github-delivery']+'\r\nX-GitHub-Event: issues\r\nContent-Length: 2\r\n\r\n{');
  await new Promise(r => setTimeout(r, 150));
  socket.write('}');
  await once(socket, 'close');
  assert.equal(forwarded, 1);
  assert.match(response, /504 Gateway Timeout/);
  assert.match(response, /TOTAL_TIMEOUT/);
  assert.ok(performance.now() - started < 900);
});

test('absolute deadline also bounds incomplete headers without forwarding', async t => {
  let calls = 0;
  const { port } = await setup(t, () => { calls++; }, { connectionTimeout: 80 });
  const socket = net.connect(port, '127.0.0.1'); await once(socket, 'connect');
  let response = ''; socket.on('data', c => { response += c; });
  socket.write('POST /api/github/webhook HTTP/1.1\r\nHost:');
  await once(socket, 'close');
  assert.match(response, /408 Request Timeout/);
  assert.equal(calls, 0);
});
