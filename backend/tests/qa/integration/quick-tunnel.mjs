// Explicitly authorized, temporary public ingress. Never start this in regression tests.
// Fixed binary/version/digest and gateway only; no account/token/personal config.
import { spawn } from 'node:child_process';
import { createHash, randomBytes } from 'node:crypto';
import { readFileSync, mkdirSync, writeFileSync, lstatSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { resolve } from 'node:path';

if (process.argv.length !== 2) throw Error('NO_ARGUMENTS_ALLOWED');
process.umask(0o077);
const root = fileURLToPath(new URL('../../../../', import.meta.url));
const binary = resolve(root, '.qa/tools/cloudflared-2026.11.2/cloudflared');
const expected = '8654fbdc9057f3e13e95f7892f417f8643113bee1b91ee2388fd0acb05210d55';
if (createHash('sha256').update(readFileSync(binary)).digest('hex') !== expected) throw Error('BINARY_DIGEST_MISMATCH');
const check = await fetch('http://127.0.0.1:18081/login', { redirect: 'error', signal: AbortSignal.timeout(3000) });
if (check.status !== 404 || (await check.json()).code !== 'NOT_FOUND') throw Error('GATEWAY_NOT_READY');
const base = resolve(root, '.qa/tunnel-sessions');
mkdirSync(base, { recursive: true, mode: 0o700 });
for (const path of [resolve(root, '.qa'), base]) if (lstatSync(path).isSymbolicLink()) throw Error('NO_SYMLINK');
const directory = resolve(base, Date.now() + '-' + randomBytes(4).toString('hex'));
mkdirSync(directory, { mode: 0o700 });
const config = resolve(directory, 'empty-config.yaml');
writeFileSync(config, '{}\n', { mode: 0o600, flag: 'wx' });
const started = Date.now();
const state = { wrapperPid: process.pid, childPid: null, started: new Date(started).toISOString(),
  expires: new Date(started + 30 * 60 * 1000).toISOString(), url: null, status: 'starting',
  upstream: 'http://127.0.0.1:18081', version: '2026.11.2' };
const save = () => writeFileSync(resolve(directory, 'state.json'), JSON.stringify(state, null, 2) + '\n', { mode: 0o600 });
// Deliberately do not inherit HOME, credentials, proxy or TUNNEL_* variables.
const child = spawn(binary, ['tunnel', '--config', config, '--no-autoupdate',
  '--url', state.upstream, '--metrics', '127.0.0.1:18082', '--protocol', 'http2',
  '--origincert', resolve(directory, 'unused-origin-cert.pem'), '--loglevel', 'info'],
  { cwd: directory, env: { PATH: '/usr/bin:/bin:/usr/sbin:/sbin', TMPDIR: directory }, stdio: ['ignore', 'pipe', 'pipe'] });
state.childPid = child.pid; save();
console.log(JSON.stringify({ stateFile: resolve(directory, 'state.json'), ...state }));
let stopping = false;
let force;
let startup;
const stop = reason => {
  if (stopping) return;
  stopping = true; state.status = reason; save();
  child.kill('SIGTERM');
  force = setTimeout(() => child.kill('SIGKILL'), 5000);
};
const expiry = setTimeout(() => stop('expired'), 30 * 60 * 1000);
startup = setTimeout(() => { if (!state.url) stop('startup_timeout'); }, 60000);
for (const signal of ['SIGINT', 'SIGTERM', 'SIGHUP']) process.on(signal, () => stop('operator_stopped'));
// Drain but never persist raw cloudflared logs (may include request paths/errors).
for (const stream of [child.stdout, child.stderr]) {
  let tail = '';
  stream.on('data', bytes => {
    tail = (tail + bytes.toString()).slice(-8192);
    const match = tail.match(/https:\/\/[a-z0-9]+(?:-[a-z0-9]+)*\.trycloudflare\.com\b/);
    if (match && !state.url) {
      state.url = match[0]; state.status = 'hostname_assigned'; save(); clearTimeout(startup);
      console.log(JSON.stringify({ webhook: state.url + '/api/github/webhook', expires: state.expires, wrapperPid: process.pid }));
    }
  });
}
child.on('error', () => { state.status = 'start_failed'; save(); console.error('TUNNEL_START_FAILED'); });
child.on('close', code => {
  clearTimeout(expiry); clearTimeout(startup); clearTimeout(force);
  state.status = 'stopped'; state.stopped = new Date().toISOString(); state.exitCode = code; save();
  console.log('TUNNEL_STOPPED');
});
