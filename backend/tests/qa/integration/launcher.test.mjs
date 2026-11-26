// Tests the real dedicated launcher in its synthetic, credential-free environment.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import { once } from 'node:events';
import { readFile } from 'node:fs/promises';
import { createHash } from 'node:crypto';
import net from 'node:net';
import http from 'node:http';
import { fileURLToPath } from 'node:url';
import { resolve } from 'node:path';
const root = fileURLToPath(new URL('../../../../', import.meta.url));
const php = '/opt/homebrew/opt/php@8.3/bin/php';
const launcher = 'backend/tests/qa/integration-app.php';
const env = { ...process.env, QA_GITHUB_NETWORK: '' };
function run(mode) { return spawnSync(php, [launcher, mode], { cwd: root, env, encoding: 'utf8', timeout: 120000 }); }
function data(mode) { const r = run(mode); assert.equal(r.status, 0, mode); return JSON.parse(r.stdout); }
async function stop(child) { if (child.exitCode !== null || child.signalCode !== null) return; const exited = once(child, 'exit'); child.kill('SIGTERM'); await exited; }
async function portFree(port) { const s=net.createServer(); s.listen(port, '127.0.0.1'); await once(s, 'listening'); await new Promise(r=>s.close(r)); }

test('dedicated launcher offline lifecycle and real NLP queue', async t => {
  await portFree(18080);
  const fixtureFile = resolve(root, '.qa/interactive/database.sqlite');
  const checksum = bytes => createHash('sha256').update(bytes).digest('hex');
  const original = checksum(await readFile(fixtureFile));
  const baseline = data('status'); assert.equal(baseline.github_connections, 0);
  await t.test('existing init refused and blank config rejected without printing values', () => {
    assert.equal(run('init').status, 1);
    const config = run('check-config'); assert.equal(config.status, 1); assert.match(config.stderr, /^QA_FAILED:/);
  });
  const app = spawn(php, [launcher, 'serve'], { cwd: root, env, stdio: ['ignore', 'pipe', 'pipe'] });
  t.after(() => stop(app));
  let appOut = ''; app.stdout.on('data', c => { appOut += c; }); app.stderr.on('data', c => { appOut += c; });
  for (let i=0; i<50; i++) {
    try { if ((await fetch('http://127.0.0.1:18080/login')).status === 200) break; } catch {}
    if (app.exitCode !== null) throw new Error('Launcher exited');
    await new Promise(r=>setTimeout(r,100));
  }
  const jar = new Map();
  async function request(path, options = {}) {
    const r=await fetch('http://127.0.0.1:18080'+path, {...options, redirect:'manual',headers:{Cookie:[...jar].map(([k,v])=>k+'='+v).join('; '),...options.headers}});
    for(const c of r.headers.getSetCookie()){const pair=c.split(';')[0];const at=pair.indexOf('=');jar.set(pair.slice(0,at),pair.slice(at+1));}
    return {status:r.status, text:await r.text(), headers:r.headers};
  }
  await t.test('real launcher login, host guard, CSRF and unconfigured webhook', async () => {
    const wrongHost = await new Promise((resolve, reject) => {
      const req = http.get('http://127.0.0.1:18080/login', { headers: { Host: 'unapproved.invalid' } }, res => { res.resume(); resolve(res.statusCode); });
      req.on('error', reject);
    });
    assert.equal(wrongHost,403);
    const account=JSON.parse(await readFile(resolve(root,'.qa/github-integration/accounts.json'),'utf8'));
    const page=await request('/login');assert.equal(page.status,200);
    const csrf=page.text.match(/name="_token"[^>]*value="([^"]+)"/)[1];
    assert.equal((await request('/login',{method:'POST',body:new URLSearchParams({email:account.reporter,password:account.password})})).status,419);
    assert.equal((await request('/login',{method:'POST',body:new URLSearchParams({_token:csrf,email:account.reporter,password:account.password})})).status,302);
    assert.equal((await request('/extension/connect')).status,200);
    const wh=await request('/api/github/webhook',{method:'POST',headers:{'Content-Type':'application/json'},body:'{"zen":"synthetic"}'});
    assert.equal(wh.status,503);assert.equal(JSON.parse(wh.text).code,'NOT_CONFIGURED');
  });
  await t.test('outbound NLP health and identical-content cosine smoke', () => {
    const result=data('nlp-smoke');assert.equal(result.health,200);assert.equal(result.nlp_status,200);
    assert.ok(Math.abs(result.candidates[0].similarity-1)<1e-6);
  });
  await t.test('guarded scheduler daemon starts and duplicate tick does not overlap', async () => {
    const daemon=spawn(php,[launcher,'scheduler'],{cwd:root,env,stdio:['ignore','pipe','pipe']});
    t.after(()=>stop(daemon));
    const [chunk]=await once(daemon.stdout,'data');assert.ok(Array.isArray(JSON.parse(chunk.toString()).executed));
    assert.deepEqual(data('schedule').executed,[]);await stop(daemon);
  });
  await t.test('bounded database worker drains recoverable synthetic work', () => {
    const r=run('drain');assert.equal(r.status,0);assert.match(r.stdout,/QA_WORKER_EXIT_0/);
    const state=data('status');assert.equal(state.github_connections,0);assert.equal(state.jobs,0);assert.equal(state.failed_jobs,0);
  });
  await stop(app);
  await portFree(18080); // Child PHP server must stop with launcher; no orphan.
  assert.equal(checksum(await readFile(fixtureFile)),original);
  assert.ok(!appOut.includes('/login')&&!appOut.includes('password='),'No HTTP access logs captured');
});
