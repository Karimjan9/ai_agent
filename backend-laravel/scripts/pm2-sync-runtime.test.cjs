const test = require('node:test');
const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const { pathToFileURL } = require('node:url');
const path = require('node:path');

const secret = 'PM2_PERSISTED_SECRET_MUST_NOT_BE_PRINTED';
const script = pathToFileURL(path.join(__dirname, 'pm2-sync-runtime.mjs')).href;

// Exercise the executable script, not a copied parser. The isolated child
// replaces spawnSync before ESM binding resolution and intercepts exit, so no
// real PM2/preflight/restart can execute in these tests.
function simulate(mode, payload = null) {
    const harness = `
      const cp = require('node:child_process');
      const { syncBuiltinESMExports } = require('node:module');
      const calls = []; let output = ''; let exitCode = null;
      const mode = ${JSON.stringify(mode)};
      const payload = JSON.parse(require('node:fs').readFileSync(0, 'utf8'));
      const secret = ${JSON.stringify(secret)};
      const originalOut = process.stdout.write.bind(process.stdout);
      cp.spawnSync = (binary, args, options) => {
        calls.push({ args, maxBuffer: options.maxBuffer,
          secretsRemoved: !Object.keys(options.env).some(key => key.startsWith('OPENAI_') || key.startsWith('CODEX_') || key === 'INTERNAL_API_TOKEN') });
        if (args.at(-1) === 'jlist') {
          if (mode === 'throw') throw new Error(secret);
          if (mode === 'failed') return { status: 3, stdout: secret, stderr: secret, error: new Error(secret) };
          if (mode === 'overflow') return { status: null, stdout: secret, stderr: secret, error: new Error(secret) };
          return { status: 0, stdout: payload, stderr: secret };
        }
        if (args[0] === 'artisan' && args[1] === 'system:runtime-reload-preflight') {
          return { status: 2, stdout: 'SAFE_TEST_PREFLIGHT_BLOCKED\\n', stderr: '' };
        }
        throw new Error('UNEXPECTED_MUTATING_COMMAND');
      };
      syncBuiltinESMExports();
      process.stderr.write = value => { output += String(value); return true; };
      process.exit = code => { exitCode = code; throw new Error('TEST_INTERCEPTED_EXIT'); };
      import(${JSON.stringify(script)}).then(() => {
        originalOut(JSON.stringify({ unexpectedSuccess: true, calls, output, exitCode }));
      }).catch(error => {
        originalOut(JSON.stringify({ intercepted: error.message === 'TEST_INTERCEPTED_EXIT', calls, output, exitCode }));
      });
    `;
    const result = spawnSync(process.execPath, ['-e', harness], {
        encoding: 'utf8', maxBuffer: 20 * 1024 * 1024, windowsHide: true,
        input: JSON.stringify(payload),
        env: { ...process.env, OPENAI_TEST_SENTINEL: secret, CODEX_TEST_SENTINEL: secret, INTERNAL_API_TOKEN: secret },
    });
    assert.equal(result.status, 0, result.stderr);
    const report = JSON.parse(result.stdout);
    assert.equal(report.intercepted, true);
    assert.equal(result.stdout.includes(secret), false);
    assert.equal(result.stderr.includes(secret), false);
    assert.equal(report.calls[0].maxBuffer, 16 * 1024 * 1024);
    assert.equal(report.calls[0].secretsRemoved, true);
    return report;
}

test('PM2 read failure, buffer overflow and thrown errors never echo raw diagnostics', () => {
    for (const mode of ['failed', 'overflow', 'throw']) {
        const report = simulate(mode);
        assert.equal(report.calls.length, 1);
        assert.equal(report.output, 'PM2 process list could not be read.\n');
        assert.equal(report.exitCode, mode === 'failed' ? 3 : 1);
    }
});

test('truncated, empty and invalid-shaped PM2 lists refuse sync without leaking input', () => {
    for (const payload of [`[{"name":"${secret}"`, '', JSON.stringify({ secret }), '[null]', '[["name"]]', '[{"name":12}]']) {
        const report = simulate('success', payload);
        assert.equal(report.calls.length, 1);
        assert.equal(report.output, 'PM2 process list is invalid.\n');
        assert.equal(report.exitCode, 1);
    }
});

test('large valid PM2 list still reaches the durable idle fence before any mutation', () => {
    const payload = JSON.stringify([{ name: 'lab-replay', pm2_env: { status: 'online', persisted: 'x'.repeat(2 * 1024 * 1024) } }]);
    const report = simulate('success', payload);
    assert.equal(report.calls.length, 2);
    assert.equal(report.calls[1].args[0], 'artisan');
    assert.equal(report.calls[1].args[1], 'system:runtime-reload-preflight');
    assert.equal(report.output, 'SAFE_TEST_PREFLIGHT_BLOCKED\n');
    assert.equal(report.exitCode, 2);
});
