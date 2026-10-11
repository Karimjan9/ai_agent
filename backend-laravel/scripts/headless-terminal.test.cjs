const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const backend = path.resolve(__dirname, '..');
const workspace = path.dirname(backend);
const vendor = fs.existsSync(path.join(backend, 'vendor', 'autoload.php'))
  ? path.join(backend, 'vendor', 'autoload.php')
  : path.resolve(backend, '..', '..', '..', 'backend-laravel', 'vendor', 'autoload.php');

function ecosystem(platform) {
  const module = { exports: {} };
  vm.runInNewContext(fs.readFileSync(path.join(backend, 'ecosystem.config.cjs'), 'utf8'), {
    module, __dirname: backend, process: { platform, env: { WEB_SERVER_MODE: 'external' } },
    require(name) {
      if (name === 'fs') return { existsSync: () => true,
        readFileSync: () => JSON.stringify({ original_ini: 'C:/configured/php.ini' }) };
      return require(name);
    },
  });
  return module.exports.apps;
}

test('every Windows runtime app inherits terminal dimensions without invoking a shell', () => {
  const php = process.env.PHP_BINARY || 'php';
  const code = [
    'namespace Symfony\\Component\\Console {',
    'function shell_exec($command) { throw new \\RuntimeException("Unexpected terminal shell probe"); }',
    'function proc_open(...$arguments) { throw new \\RuntimeException("Unexpected terminal process probe"); }',
    '}',
    'namespace { require $argv[1]; $terminal = new \\Symfony\\Component\\Console\\Terminal;',
    'echo json_encode([$terminal->getWidth(), $terminal->getHeight()]); }',
  ].join('\n');
  for (const app of ecosystem('win32')) {
    const env = { ...process.env, COLUMNS: app.env.COLUMNS, LINES: app.env.LINES };
    const result = spawnSync(php, ['-r', code, vendor], {
      cwd: workspace, env, encoding: 'utf8', shell: false, windowsHide: true, timeout: 5000,
    });
    assert.equal(result.error, undefined, app.name);
    assert.equal(result.status, 0, `${app.name}: ${result.stderr}`);
    assert.deepEqual(JSON.parse(result.stdout), [120, 30], app.name);
  }
});

test('non-Windows apps retain native terminal sizing', () => {
  for (const app of ecosystem('linux')) {
    assert.equal(app.env.COLUMNS, undefined, app.name);
    assert.equal(app.env.LINES, undefined, app.name);
  }
});

test('the fallback supplies dimensions before its first PHP gate call', () => {
  const source = fs.readFileSync(path.join(__dirname, 'run-laravel-workers-hidden.ps1'), 'utf8');
  const gate = source.indexOf('$controlResult = & $phpBinary');
  for (const name of ['COLUMNS', 'LINES']) {
    const assignment = source.indexOf(`$env:${name} =`);
    assert.ok(assignment >= 0 && assignment < gate, name);
  }
});
