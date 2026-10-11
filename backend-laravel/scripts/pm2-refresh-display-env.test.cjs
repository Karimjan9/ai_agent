const assert = require('node:assert/strict');
const path = require('node:path');
const test = require('node:test');
const { idle, projectApps, refreshed } = require('./pm2-refresh-display-env.cjs');
const backend = path.resolve(__dirname, '..');
const app = { name: 'lab-replay', pm2_env: { pm_cwd: backend,
  pm_exec_path: path.join(backend, 'artisan'), args: ['queue:work', 'redis', '--queue=lab-full-validation,lab-frontier'] } };

test('display refresh refuses a busy, unknown or unpaused lane', () => {
  const snapshot = { control: { enabled: false, state: 'paused' },
    queues: { 'lab-full-validation': 0, 'lab-frontier': 0 } };
  assert.equal(idle(app, snapshot), true);
  assert.equal(idle(app, { ...snapshot, control: { enabled: false, state: 'pausing' } }), true);
  assert.equal(idle(app, { ...snapshot, queues: { ...snapshot.queues, 'lab-frontier': 1 } }), false);
  assert.equal(idle(app, { ...snapshot, queues: {} }), false);
  assert.equal(idle(app, { ...snapshot, control: { enabled: false, state: 'draining' } }), false);
  assert.equal(idle(app, { ...snapshot, control: { enabled: true, state: 'paused' } }), false);
  assert.equal(idle({ ...app, pm2_env: { ...app.pm2_env, args: ['unrecognized'] } }, snapshot), false);
});

test('only the exact project artisan/API paths can receive display environment changes', () => {
  const unrelated = { ...app, pm2_env: { ...app.pm2_env, pm_cwd: path.join(backend, '..', 'another') } };
  const otherScript = { ...app, pm2_env: { ...app.pm2_env, pm_exec_path: path.join(backend, 'another.php') } };
  assert.deepEqual(projectApps([app, unrelated, otherScript]), [app]);
});

test('partial or late PATH shell configuration is not accepted as refreshed', () => {
  const directory = path.resolve(backend, '..', '.runtime', 'headless-shell');
  const env = { COLUMNS: '120', LINES: '30', PHPRC: 'C:/original/php.ini', COMSPEC: path.join(directory, 'cmd.exe'), PATH: `${directory};C:/Windows/System32` };
  const configured = { ...app, pm2_env: { ...app.pm2_env, exec_interpreter: path.join(directory, 'php.exe'), env } };
  assert.equal(refreshed(configured), true);
  assert.equal(refreshed({ ...configured, pm2_env: { ...configured.pm2_env, env: { ...env, COMSPEC: 'C:/Windows/System32/cmd.exe' } } }), false);
  assert.equal(refreshed({ ...configured, pm2_env: { ...configured.pm2_env, env: { ...env, PATH: `C:/Windows/System32;${directory}` } } }), false);
  assert.equal(refreshed({ ...configured, pm2_env: { ...configured.pm2_env, exec_interpreter: 'C:/original/php.exe' } }), false);
});
