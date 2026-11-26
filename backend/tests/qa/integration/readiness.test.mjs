// Opt-in local integration: real SQLite/HTTP/gateway/worker/NLP, GitHub strictly fake.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import { readFile, stat } from 'node:fs/promises';
import { createHash, createHmac, randomBytes } from 'node:crypto';
import net from 'node:net';
import { once } from 'node:events';
import { fileURLToPath } from 'node:url';
import { resolve } from 'node:path';

const root = fileURLToPath(new URL('../../../../', import.meta.url));
const php = '/opt/homebrew/opt/php@8.3/bin/php';
const profile = 'integration-tests/' + randomBytes(8).toString('hex');
const dir = resolve(root, '.qa', profile);
const fixture = resolve(root, 'backend/tests/qa/integration/test-fixture.php');
const env = { ...process.env, QA_TEST_PROFILE: profile, QA_GITHUB_NETWORK: '', DB_CONNECTION: 'mysql', DB_DATABASE: 'MUST_NOT_ACCESS', DB_URL: 'mysql://denied.invalid/db', SESSION_CONNECTION: 'mysql', DB_CACHE_CONNECTION: 'mysql', DB_QUEUE_CONNECTION: 'mysql' };
function command(mode) {
  const r = spawnSync(php, [fixture, mode], { cwd: root, env, encoding: 'utf8', timeout: 120000 });
  assert.equal(r.status, 0, 'Test subprocess failed: ' + mode + '\n' + r.stderr);
  return JSON.parse(r.stdout);
}
async function free(port) {
  const s = net.createServer(); s.listen(port, '127.0.0.1'); await once(s, 'listening'); await new Promise(r => s.close(r));
}
function launch(args, binary = php) { return spawn(binary, args, { cwd: root, env, stdio: ['ignore', 'ignore', 'ignore'] }); }
async function stop(child) {
  if (child.exitCode !== null || child.signalCode !== null) return;
  const exit = once(child, 'exit'); child.kill('SIGTERM'); await exit;
}
async function waitHttp(url, child) {
  for (let i = 0; i < 60; i++) {
    if (child.exitCode !== null) throw new Error('Local server exited');
    try { return await fetch(url); } catch {}
    await new Promise(r => setTimeout(r, 100));
  }
  throw new Error('Local server unavailable');
}
function browserSession() {
  const jar = new Map();
  return async (path, options = {}) => {
    assert.ok(path.startsWith('/') && !path.startsWith('//'));
    const res = await fetch('http://127.0.0.1:18080' + path, { ...options, redirect: 'manual', headers: { Cookie: [...jar].map(([k,v]) => k+'='+v).join('; '), ...options.headers } });
    for (const cookie of res.headers.getSetCookie()) {
      const pair = cookie.split(';')[0]; const at = pair.indexOf('='); jar.set(pair.slice(0, at), pair.slice(at+1));
    }
    const text = await res.text();
    return { status: res.status, headers: res.headers, text, json: () => JSON.parse(text) };
  };
}
const csrf = page => { const token = page.text.match(/name="_token"[^>]*value="([^"]+)"/); assert.ok(token, 'CSRF input present'); return token[1]; };
const form = values => ({ method: 'POST', body: new URLSearchParams(values) });
const json = (values, token, method = 'POST') => ({ method, headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...(token ? { Authorization: 'Bearer '+token } : {}) }, body: JSON.stringify(values) });

test('isolated integration readiness (GitHub HTTP fake; actual local NLP)', async t => {
  for (const port of [18080, 18081]) await free(port);
  assert.equal((await fetch('http://127.0.0.1:18765/health')).status, 200, 'Start offline NLP first');
  const original = createHash('sha256').update(await readFile(resolve(root, '.qa/interactive/database.sqlite'))).digest('hex');
  const primary = createHash('sha256').update(await readFile(resolve(root, '.qa/github-integration/database.sqlite'))).digest('hex');
  await t.test('new DB migrations, separate private files, no synthetic connection', async () => {
    const initial = command('init'); assert.equal(initial.github_connections, 0); assert.equal(initial.issues, 6);
    for (const name of ['database.sqlite', 'accounts.json', 'key', 'github-config.json', 'private-key.pem']) assert.equal((await stat(resolve(dir, name))).mode & 0o777, 0o600);
  });
  await t.test('inherited DB env and outbound escape attempts rejected', () => assert.equal(command('isolation').isolation, 'PASS'));
  await t.test('private config, overwrite, symlink, redacted logs and offline credential guards', () => assert.equal(command('safety').safety_checks, 12));
  const app = launch(['-d', 'display_errors=0', '-S', '127.0.0.1:18080', '-t', 'backend/public', fixture]);
  t.after(() => stop(app));
  await waitHttp('http://127.0.0.1:18080/login', app);
  const gateway = launch(['backend/tests/qa/integration/webhook-gateway.mjs'], process.execPath);
  t.after(() => stop(gateway));
  await waitHttp('http://127.0.0.1:18081/', gateway);
  const accounts = JSON.parse(await readFile(resolve(dir, 'accounts.json'), 'utf8')); // Synthetic test accounts only.
  const maintainer = browserSession();
  const reporter = browserSession();
  await t.test('both synthetic roles login with real CSRF/session', async () => {
    for (const [session, email] of [[maintainer, accounts.maintainer], [reporter, accounts.reporter]]) {
      const page = await session('/login'); assert.equal(page.status, 200);
      const result = await session('/login', form({ _token: csrf(page), email, password: accounts.password }));
      assert.equal(result.status, 302); assert.ok(!result.headers.get('location').endsWith('/login'));
    }
    assert.equal((await reporter('/projects/1/sources')).status, 403);
  });
  await t.test('actual OAuth flow with PKCE and fake GitHub HTTP creates only test DB mapping', async () => {
    const source = await maintainer('/projects/1/sources'); assert.equal(source.status, 200);
    const start = await maintainer('/projects/1/sources/github/app/connect', form({ _token: csrf(source) })); assert.equal(start.status, 302);
    const auth = await maintainer('/integrations/github/authorize'); assert.equal(auth.status, 302);
    const url = new URL(auth.headers.get('location')); assert.equal(url.origin, 'https://github.com');
    assert.equal(url.searchParams.get('code_challenge_method'), 'S256');
    assert.ok(url.searchParams.get('code_challenge'));
    const callback = await maintainer('/integrations/github/callback?' + new URLSearchParams({ code: 'test-only-code', state: url.searchParams.get('state') }));
    assert.equal(callback.status, 302); assert.equal(command('status').github_connections, 1);
  });
  let token;
  const repository = 'valentinokevin/dupissue-testing';
  await t.test('extension CSRF pairing, authorized mapping, real NLP Top3, vote/unvote', async () => {
    const page = await reporter('/extension/connect'); assert.equal(page.status, 200);
    const paired = await reporter('/extension/pairing', { ...json({}), headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf(page) } });
    assert.equal(paired.status, 201);
    const exchange = await reporter('/api/extension/v1/pair', json({ pairing_code: paired.json().pairing_code })); assert.equal(exchange.status, 200); token = exchange.json().access_token;
    const mapping = await reporter('/api/extension/v1/repositories/resolve', json({ repository }, token)); assert.equal(mapping.status, 200); assert.equal(mapping.json().projects.length, 1);
    const recommendation = await reporter('/api/extension/v1/recommendations', json({ repository, project_id: 1, title: 'Checkout payment fails', description: 'Clicking the payment button does not complete checkout.' }, token));
    assert.equal(recommendation.status, 200); assert.equal(recommendation.json().recommendations.length, 3);
    const vote = { repository, project_id: 1, issue_id: 1 };
    assert.equal((await reporter('/api/extension/v1/votes', json(vote, token))).json().vote_count, 1);
    assert.equal((await reporter('/api/extension/v1/votes', json(vote, token, 'DELETE'))).json().vote_count, 0);
    assert.equal((await reporter('/api/extension/v1/recommendations', json({ repository, project_id: 2, title: 'Checkout payment fails', description: 'Clicking the payment button does not complete checkout.' }, token))).status, 403);
  });
  const secret = 'test-only-webhook-secret-with-more-than-32-characters';
  const raw = '{ "action": "opened", "installation":{"id":12}, "repository":{"id":34}, "issue":{"number":7} }\n';
  async function webhook(body = raw, event = 'issues', signature) {
    return fetch('http://127.0.0.1:18081/api/github/webhook', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-GitHub-Event': event, 'X-GitHub-Delivery': '11111111-2222-3333-4444-555555555555', 'X-Hub-Signature-256': signature || 'sha256='+createHmac('sha256', secret).update(body).digest('hex') }, body });
  }
  await t.test('gateway hides all other endpoints; invalid signature and ping do not persist delivery', async () => {
    for (const path of ['/login', '/api/extension/v1/me', '/integrations/github/callback', '/database.sqlite']) assert.equal((await fetch('http://127.0.0.1:18081'+path)).status, 404);
    assert.equal((await webhook(raw, 'issues', 'sha256'+'='+'0'.repeat(64))).status, 401);
    const ping = await webhook('{"zen":"synthetic ping"}', 'ping'); assert.equal(ping.status, 202); assert.equal((await ping.json()).code, 'IGNORED');
    assert.equal(command('status').deliveries, 0);
  });
  await t.test('raw signed webhook -> durable delivery -> actual database worker -> fake verified fetch -> real NLP', async () => {
    assert.equal((await webhook()).status, 202);
    let state = command('status'); assert.equal(state.deliveries, 1); assert.equal(state.jobs, 1);
    assert.equal(command('drain').exit, 0);
    state = command('status'); assert.equal(state.tasks_completed, 1); assert.equal(state.github_issues, 1);
    assert.equal(state.completed, 1); assert.equal(state.sync_logs, 1); assert.ok(state.candidates > 0); assert.ok(state.last_synced);
    assert.equal(state.jobs, 0); assert.equal(state.failed_jobs, 0); assert.equal(state.foreign_key_errors, 0);
  });
  await t.test('redelivery and manual preview preserve unique issue', async () => {
    assert.equal((await webhook()).status, 202); const state = command('status');
    assert.equal(state.deliveries, 1); assert.equal(state.github_issues, 1); assert.equal(state.jobs, 0); assert.equal(state.sync_logs, 1);
    const source = await maintainer('/projects/1/sources');
    const preview = await maintainer('/projects/1/sources/github/app/preview', form({ _token: csrf(source) })); assert.equal(preview.status, 200);
    assert.match(preview.text, /sudah diimpor|ALREADY_IMPORTED/i);
    assert.equal(command('status').github_issues, 1);
  });
  await t.test('recovery cadence and worker operate inside same test DB', () => {
    command('age-pending');
    const commands = command('schedule').executed; assert.equal(commands.length, 3); assert.deepEqual(command('schedule').executed, []);
    assert.equal(command('status').jobs, 6);
    assert.equal(command('drain').exit, 0);
    const recovered = command('status'); assert.equal(recovered.completed, 7); assert.equal(recovered.jobs, 0); assert.equal(recovered.failed_jobs, 0);
  });
  await t.test('disconnect revokes Bearer session', async () => {
    assert.equal((await reporter('/api/extension/v1/session', json({}, token, 'DELETE'))).status, 200);
    assert.equal((await reporter('/api/extension/v1/me', { headers: { Accept: 'application/json', Authorization: 'Bearer '+token } })).status, 401);
  });
  await t.test('fixture DB and primary integration DB untouched by test flow', async () => {
    assert.equal(createHash('sha256').update(await readFile(resolve(root, '.qa/interactive/database.sqlite'))).digest('hex'), original);
    assert.equal(createHash('sha256').update(await readFile(resolve(root, '.qa/github-integration/database.sqlite'))).digest('hex'), primary);
  });
  console.log('Retained synthetic evidence profile: '+profile+'; GitHub was HTTP fake, no external requests.');
});
