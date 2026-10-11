const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

const backend = path.resolve(__dirname, '..');
const shellDirectory = path.resolve(backend, '..', '.runtime', 'headless-shell');
const shell = path.join(shellDirectory, 'cmd.exe');
const privatePhp = path.join(shellDirectory, 'php.exe');
const normalized = value => path.resolve(value || '').replaceAll('\\', '/').toLowerCase();
const normalizePath = value => String(value || '').split(';').map(normalized);

function projectApps(apps) {
  return apps.filter(app => normalized(app.pm2_env?.pm_cwd) === normalized(backend)
    && (normalized(app.pm2_env?.pm_exec_path) === normalized(path.join(backend, 'artisan'))
      || normalized(app.pm2_env?.pm_exec_path) === normalized(path.join(__dirname, 'run-ai-service.py'))));
}

function refreshed(app) {
  const env = app.pm2_env?.env || {};
  return env.COLUMNS === '120' && env.LINES === '30'
    && normalized(env.COMSPEC || env.ComSpec) === normalized(shell)
    && normalizePath(env.PATH || env.Path)[0] === normalized(shellDirectory)
    && (normalized(app.pm2_env.pm_exec_path) !== normalized(path.join(backend, 'artisan'))
      || (normalized(app.pm2_env.exec_interpreter) === normalized(privatePhp)
        && typeof env.PHPRC === 'string' && env.PHPRC !== ''));
}

function queuesFor(app) {
  const args = app.pm2_env?.args;
  const text = Array.isArray(args) ? args.join(' ') : String(args || '');
  const match = text.match(/(?:^|\s)--queue=([^\s]+)/);
  return match ? match[1].split(',') : [];
}

function idle(app, snapshot) {
  if (snapshot.control?.enabled !== false || !['paused', 'pausing'].includes(snapshot.control?.state)) return false;
  const queues = queuesFor(app);
  return queues.length > 0 && queues.every(queue => snapshot.queues?.[queue] === 0);
}

async function main() {
  if (process.platform !== 'win32' || !fs.existsSync(shell)) throw Error('HIDDEN_SHELL_UNAVAILABLE');
  const build = JSON.parse(fs.readFileSync(path.join(shellDirectory, 'build.json'), 'utf8').replace(/^\uFEFF/, ''));
  const crypto = require('node:crypto');
  for (const [name, expected] of Object.entries(build.files)) {
    const actual = crypto.createHash('sha256').update(fs.readFileSync(path.join(shellDirectory, name))).digest('hex');
    if (actual.toUpperCase() !== expected.toUpperCase()) throw Error('PRIVATE_PHP_RUNTIME_DIFFERS');
  }
  // Read-only status probes inherit the same tested hidden shell.
  const probeEnv = { ...process.env, PHPRC: build.original_ini, COLUMNS: '120', LINES: '30', COMSPEC: shell,
    PATH: `${shellDirectory};${process.env.PATH}` };
  const snapshot = () => {
    const result = spawnSync(privatePhp, [path.join(__dirname, 'inspect-display-env-readiness.php')], {
      cwd: backend, env: probeEnv, windowsHide: true, shell: false, encoding: 'utf8', timeout: 15000,
    });
    if (result.error || result.status !== 0) throw Error('DISPLAY_READINESS_UNAVAILABLE');
    return JSON.parse(result.stdout);
  };
  const first = snapshot();
  if (!['paused', 'pausing'].includes(first.control?.state) || first.control.enabled !== false) throw Error('PAUSED_CONTROL_REQUIRED');
  const expectedHash = first.source_hash;
  const pm2 = require(path.join(backend, 'node_modules', 'pm2'));
  const call = (fn, ...args) => new Promise((resolve, reject) => fn(...args,
    (error, value) => error ? reject(Error('PM2_DISPLAY_ENV_ACTION_FAILED')) : resolve(value)));
  const alive = await new Promise(resolve => pm2.Client.pingDaemon(resolve));
  if (!alive) throw Error('EXISTING_PM2_DAEMON_REQUIRED');
  await call(pm2.Client.launchRPC.bind(pm2.Client));
  const deadline = Date.now() + 50 * 60 * 1000;
  const tokenFile = path.resolve(backend, '..', 'runtime', 'internal-api.token');
  const probeIdle = async () => {
    const token = fs.readFileSync(tokenFile, 'utf8').trim();
    if (!token) throw Error('REPLAY_IDLE_AUTHENTICATION_UNAVAILABLE');
    const response = await fetch('http://127.0.0.1:9000/api/replay-status', {
      headers: { 'X-Internal-Token': token }, signal: AbortSignal.timeout(3000),
    });
    if (!response.ok) throw Error('REPLAY_IDLE_UNAVAILABLE');
    const data = await response.json();
    return Number.isSafeInteger(data?.active_requests) && data.active_requests === 0;
  };
  const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
  let lastPending = '';
  try {
    while (Date.now() < deadline) {
      const apps = projectApps(await call(pm2.list.bind(pm2)));
      if (apps.length === 0) throw Error('PROJECT_PM2_OWNER_UNAVAILABLE');
      let current = snapshot();
      if (current.source_hash !== expectedHash || !['paused', 'pausing'].includes(current.control?.state)
        || current.control.enabled !== false) throw Error('DISPLAY_MAINTENANCE_STATE_CHANGED');
      const pending = apps.filter(app => !refreshed(app));
      if (pending.length === 0) {
        await call(pm2.dump.bind(pm2));
        process.stdout.write(JSON.stringify({ status: 'complete', apps: apps.map(app => app.name), source_hash: expectedHash }) + '\n');
        return;
      }
      for (const app of pending) {
        current = snapshot();
        if (current.source_hash !== expectedHash || !['paused', 'pausing'].includes(current.control?.state)
          || current.control.enabled !== false) throw Error('DISPLAY_MAINTENANCE_STATE_CHANGED');
        const api = normalized(app.pm2_env.pm_exec_path) === normalized(path.join(__dirname, 'run-ai-service.py'));
        const scheduler = String(app.pm2_env.args || '').includes('schedule:headless-work');
        if (scheduler && pending.some(other => other.name !== app.name)) continue;
        if (api) {
          if (current.active_replays !== 0 || !(await probeIdle())) continue;
          await delay(5000);
          if (!(await probeIdle()) || snapshot().active_replays !== 0) continue;
        } else if (!scheduler && !idle(app, current)) continue;
        const oldEnv = app.pm2_env.env || {};
        const oldPath = oldEnv.PATH || oldEnv.Path || process.env.PATH;
        const changes = { COLUMNS: '120', LINES: '30', COMSPEC: shell, PHPRC: build.original_ini,
          PATH: `${shellDirectory};${oldPath}` };
        if (!api) changes.current_conf = { exec_interpreter: privatePhp };
        await call(pm2.Client.executeRemote.bind(pm2.Client), 'restartProcessId', { id: app.pm_id, env: changes });
        process.stdout.write(JSON.stringify({ status: 'refreshed', name: app.name }) + '\n');
      }
      const names = pending.map(app => app.name).join(',');
      if (names !== lastPending) {
        process.stdout.write(JSON.stringify({ status: 'waiting_for_idle', apps: pending.map(app => app.name) }) + '\n');
        lastPending = names;
      }
      await delay(10000);
    }
    throw Error('DISPLAY_MAINTENANCE_WAIT_EXPIRED');
  } finally {
    pm2.disconnect();
  }
}

module.exports = { idle, projectApps, queuesFor, refreshed };
if (require.main === module) main().catch(() => {
  process.stderr.write('DISPLAY_ENV_REFRESH_REFUSED_OR_INCOMPLETE\n');
  process.exitCode = 2;
});
