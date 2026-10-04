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
function simulate(mode, payload = null, configuration = {}) {
    const harness = `
      const cp = require('node:child_process');
      const { syncBuiltinESMExports } = require('node:module');
      const calls = []; const probes = []; const events = []; const waits = [];
      const timeouts = []; let output = ''; let exitCode = null;
      const mode = ${JSON.stringify(mode)};
      const fs = require('node:fs');
      const input = JSON.parse(fs.readFileSync(0, 'utf8'));
      const payload = input.payload; const configuration = input.configuration;
      const preflights = [...(configuration.preflights || [2])];
      const secret = ${JSON.stringify(secret)};
      const originalOut = process.stdout.write.bind(process.stdout);
      cp.spawnSync = (binary, args, options) => {
        calls.push({ args, maxBuffer: options.maxBuffer,
          secretsRemoved: !Object.keys(options.env).some(key => key.startsWith('OPENAI_') || key.startsWith('CODEX_') || key === 'INTERNAL_API_TOKEN') });
        if (args.at(-1) === 'jlist') {
          events.push('jlist');
          if (mode === 'throw') throw new Error(secret);
          if (mode === 'failed') return { status: 3, stdout: secret, stderr: secret, error: new Error(secret) };
          if (mode === 'overflow') return { status: null, stdout: secret, stderr: secret, error: new Error(secret) };
          return { status: 0, stdout: payload, stderr: secret };
        }
        if (args[0] === 'artisan' && args[1] === 'system:runtime-reload-preflight') {
          events.push('preflight');
          return { status: preflights.shift() ?? 2, stdout: 'SAFE_TEST_PREFLIGHT_BLOCKED\\n', stderr: '' };
        }
        if (configuration.allowMutations) {
          events.push('pm2:' + args[1]);
          return { status: 0 };
        }
        throw new Error('UNEXPECTED_MUTATING_COMMAND');
      };
      const readFileSync = fs.readFileSync.bind(fs);
      const existsSync = fs.existsSync.bind(fs);
      fs.existsSync = file => String(file).endsWith('internal-api.token')
        ? String(file).includes('secrets') || configuration.runtimeTokenExists !== false : existsSync(file);
      fs.readFileSync = (file, ...readArguments) => {
        if (!String(file).endsWith('internal-api.token')) return readFileSync(file, ...readArguments);
        if (configuration.tokenFailure) throw new Error(secret);
        return configuration.token ?? secret;
      };
      AbortSignal.timeout = milliseconds => {
        timeouts.push(milliseconds);
        return new AbortController().signal;
      };
      globalThis.setTimeout = (callback, milliseconds) => {
        waits.push(milliseconds); events.push('wait'); callback(); return 0;
      };
      globalThis.fetch = async (url, options) => {
        probes.push({ url, authenticated: options.headers['X-Internal-Token'] === secret,
          bounded: options.signal instanceof AbortSignal });
        events.push('probe');
        const probe = configuration.probes?.[probes.length - 1];
        if (!probe || probe.kind === 'network') throw new Error(secret);
        if (probe.kind === 'timeout') throw new DOMException(secret, 'TimeoutError');
        return { ok: probe.kind !== 'http', status: probe.status ?? 200,
          json: async () => {
            if (probe.kind === 'json') throw new SyntaxError(secret);
            if (probe.kind === 'nonfinite') return { active_requests: NaN };
            return probe.body;
          } };
      };
      syncBuiltinESMExports();
      process.stderr.write = value => { output += String(value); return true; };
      process.exit = code => { exitCode = code; throw new Error('TEST_INTERCEPTED_EXIT'); };
      import(${JSON.stringify(script)}).then(() => {
        originalOut(JSON.stringify({ unexpectedSuccess: true, calls, probes, events, waits, timeouts, output, exitCode }));
      }).catch(error => {
        originalOut(JSON.stringify({ intercepted: error.message === 'TEST_INTERCEPTED_EXIT', calls, probes, events, waits, timeouts, output, exitCode }));
      });
    `;
    const result = spawnSync(process.execPath, ['-e', harness], {
        encoding: 'utf8', maxBuffer: 20 * 1024 * 1024, windowsHide: true,
        input: JSON.stringify({ payload, configuration }),
        env: { ...process.env, OPENAI_TEST_SENTINEL: secret, CODEX_TEST_SENTINEL: secret, INTERNAL_API_TOKEN: secret },
    });
    assert.equal(result.status, 0, result.stderr);
    const report = JSON.parse(result.stdout);
    assert.equal(report.intercepted, true);
    assert.equal(result.stdout.includes(secret), false);
    assert.equal(result.stderr.includes(secret), false);
    assert.equal(report.calls[0].maxBuffer, 16 * 1024 * 1024);
    assert.equal(report.calls.every(call => call.secretsRemoved), true);
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

const onlineProcesses = JSON.stringify([
    { name: 'neurotrader-ai', pm2_env: { status: 'online' } },
    { name: 'neurotrader-scheduler', pm2_env: { status: 'online' } },
    { name: 'lab-screening', pm2_env: { status: 'online' } },
]);
const idleProbe = { kind: 'ok', body: { active_requests: 0 } };

function assertProbeRefusal(probe, confirmation = false) {
    const report = simulate('success', onlineProcesses, {
        preflights: [0], probes: confirmation ? [idleProbe, probe] : [probe],
    });
    assert.equal(report.exitCode, 2);
    assert.equal(report.calls.length, 2, 'no PM2 mutation may precede a refused probe');
    assert.equal(report.probes.length, confirmation ? 2 : 1);
    assert.deepEqual(report.waits, confirmation ? [5000] : []);
    assert.deepEqual(report.timeouts, confirmation ? [3000, 3000] : [3000]);
    assert.equal(report.probes.every(probe => probe.authenticated && probe.bounded), true);
    assert.equal(report.output, 'Replay liveness could not be verified; PM2 sync was refused.\n');
}

test('both authenticated replay probes refuse HTTP, network, timeout and JSON failures before PM2 mutation', () => {
    for (const confirmation of [false, true]) {
        for (const probe of [
            { kind: 'http', status: 401 }, { kind: 'http', status: 500 },
            { kind: 'network' }, { kind: 'timeout' }, { kind: 'json' },
        ]) {
            assertProbeRefusal(probe, confirmation);
        }
    }
});

test('both probes require an object with a nonnegative integer active-request count', () => {
    for (const confirmation of [false, true]) {
        for (const body of [
            null, {}, [], 0, false, 'idle',
            { active_requests: null }, { active_requests: '0' }, { active_requests: false },
            { active_requests: -1 }, { active_requests: 0.5 }, { active_requests: secret },
            { active_requests: Number.MAX_SAFE_INTEGER + 1 },
        ]) {
            assertProbeRefusal({ kind: 'ok', body }, confirmation);
        }
        assertProbeRefusal({ kind: 'nonfinite' }, confirmation);
    }
});

test('an active first or confirmation probe refuses before any PM2 mutation', () => {
    for (const confirmation of [false, true]) {
        const activeProbe = { kind: 'ok', body: { active_requests: 1 } };
        const report = simulate('success', onlineProcesses, {
            preflights: [0], probes: confirmation ? [idleProbe, activeProbe] : [activeProbe],
        });
        assert.equal(report.exitCode, 2);
        assert.equal(report.calls.length, 2);
        assert.equal(report.probes.length, confirmation ? 2 : 1);
        assert.equal(report.output, 'Replay lane is active; PM2 sync was refused.\n');
    }
});

test('missing or empty authentication tokens refuse without exposing their read error', () => {
    for (const authentication of [{ tokenFailure: true }, { token: ' ' }]) {
        const report = simulate('success', onlineProcesses, { preflights: [0], ...authentication });
        assert.equal(report.exitCode, 2);
        assert.equal(report.calls.length, 2);
        assert.equal(report.probes.length, 0);
        assert.equal(report.output, 'Replay liveness could not be verified; PM2 sync was refused.\n');
    }
});

test('a durable refusal after both idle probes still precedes any PM2 mutation', () => {
    const report = simulate('success', onlineProcesses, {
        preflights: [0, 2], probes: [idleProbe, idleProbe],
    });
    assert.equal(report.exitCode, 2);
    assert.equal(report.calls.length, 3);
    assert.deepEqual(report.events, ['jlist', 'preflight', 'probe', 'wait', 'probe', 'preflight']);
});

test('two authenticated idle probes and late durable checks precede the simulated worker restart', () => {
    const report = simulate('success', onlineProcesses, {
        preflights: [0, 0, 0], probes: [idleProbe, idleProbe], allowMutations: true,
    });
    assert.equal(report.exitCode, 0);
    assert.deepEqual(report.events, [
        'jlist', 'preflight', 'probe', 'wait', 'probe', 'preflight',
        'pm2:delete', 'pm2:stop', 'preflight', 'pm2:restart', 'pm2:start', 'pm2:save',
    ]);
    assert.deepEqual(report.timeouts, [3000, 3000]);
    assert.deepEqual(report.waits, [5000]);
    assert.equal(report.probes.every(probe => probe.authenticated && probe.bounded), true);
});

test('legacy token fallback retains both probes and the same safe restart sequence', () => {
    const report = simulate('success', onlineProcesses, {
        preflights: [0, 0, 0], probes: [idleProbe, idleProbe],
        runtimeTokenExists: false, allowMutations: true,
    });
    assert.equal(report.exitCode, 0);
    assert.equal(report.probes.length, 2);
    assert.equal(report.probes.every(probe => probe.authenticated), true);
});

test('a post-scheduler durable refusal restores cadence without restarting consumers', () => {
    const report = simulate('success', onlineProcesses, {
        preflights: [0, 0, 2], probes: [idleProbe, idleProbe], allowMutations: true,
    });
    assert.equal(report.exitCode, 2);
    assert.deepEqual(report.events.slice(-3), ['pm2:stop', 'preflight', 'pm2:start']);
    assert.equal(report.events.includes('pm2:restart'), false);
    assert.equal(report.events.includes('pm2:save'), false);
});
