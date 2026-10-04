import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const pm2Cli = path.join(projectRoot, 'node_modules', 'pm2', 'bin', 'pm2');
const cleanEnvironment = { ...process.env };
const require = createRequire(import.meta.url);
const managedProcessNames = require(path.join(projectRoot, 'ecosystem.config.cjs')).apps
    .map((entry) => entry.name);
// Queue topology is intentionally reconciled, not only reloaded. PM2 keeps
// removed ecosystem entries alive across a reload unless the old names are
// deleted first; leaving lab-screening/lab-full-validation online would
// preserve the very mutex contention this coordinator is meant to remove.
const staleNames = [
    'lab-eurusd', 'lab-gbpusd', 'lab-xauusd',
    'lab-screening', 'lab-full-validation',
];

for (const key of Object.keys(cleanEnvironment)) {
    if (key.startsWith('OPENAI_') || key.startsWith('CODEX_') || key === 'INTERNAL_API_TOKEN') {
        delete cleanEnvironment[key];
    }
}

const run = (args, options = {}) => spawnSync(process.execPath, [pm2Cli, ...args], {
    cwd: projectRoot,
    env: cleanEnvironment,
    windowsHide: true,
    stdio: 'inherit',
    ...options,
});

const assertDurableReplayIdle = (onFailure = null) => {
    const preflight = spawnSync(process.env.PHP_BINARY || 'php', [
        'artisan', 'system:runtime-reload-preflight', '--json',
    ], {
        cwd: projectRoot,
        env: cleanEnvironment,
        windowsHide: true,
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
    });
    if (preflight.status !== 0) {
        if (onFailure) {
            onFailure();
        }
        process.stderr.write(preflight.stdout || preflight.stderr
            || 'Durable replay preflight refused the PM2 rolling sync.\n');
        process.exit(2);
    }
};

let listed;
try {
    listed = spawnSync(process.execPath, [pm2Cli, 'jlist'], {
        cwd: projectRoot,
        env: cleanEnvironment,
        windowsHide: true,
        encoding: 'utf8',
        maxBuffer: 16 * 1024 * 1024,
        stdio: ['ignore', 'pipe', 'pipe'],
    });
} catch (_) {
    process.stderr.write('PM2 process list could not be read.\n');
    process.exit(1);
}

if (listed.status !== 0 || listed.error) {
    // PM2 persists process environments. Child errors or parse exceptions can
    // contain secret-bearing jlist bytes; never echo those diagnostics.
    process.stderr.write('PM2 process list could not be read.\n');
    process.exit(listed.status || 1);
}

let processes = [];
try {
    processes = JSON.parse(listed.stdout);
    if (!Array.isArray(processes) || processes.some((entry) => entry === null
        || typeof entry !== 'object' || Array.isArray(entry) || typeof entry.name !== 'string')) {
        throw new Error('PM2_PROCESS_LIST_SHAPE_INVALID');
    }
} catch (_) {
    process.stderr.write('PM2 process list is invalid.\n');
    process.exit(1);
}

// PM2's Windows reload is rolling: the replacement worker can start before
// the old PHP worker exits. That is unsafe for the single replay lane because
// the replacement sees the old shared lock and turns every queued candidate
// into a release/defer burst. Refuse a rolling sync while the AI service
// reports an active replay; the operator/scheduler can retry after the lane
// is idle, and stale-lock recovery remains the backstop for a killed worker.
assertDurableReplayIdle();
if (processes.some((entry) => entry.name === 'neurotrader-ai' && entry.pm2_env?.status === 'online')) {
    try {
        // ecosystem.config.cjs and run-ai-service.py prefer the coordinated
        // workspace runtime secret after rotation.  The legacy protected
        // file can remain present for rollback, but probing with it would
        // report a false 401 and allow a rolling reload during an active
        // replay.
        const runtimeTokenFile = path.resolve(projectRoot, '..', 'runtime', 'internal-api.token');
        const legacyTokenFile = path.join(projectRoot, 'storage', 'app', 'secrets', 'internal-api.token');
        const tokenFile = fs.existsSync(runtimeTokenFile) ? runtimeTokenFile : legacyTokenFile;
        const token = fs.readFileSync(tokenFile, 'utf8').trim();
        const response = await fetch('http://127.0.0.1:9000/api/replay-status', {
            headers: { 'X-Internal-Token': token },
            signal: AbortSignal.timeout(3000),
        });
        if (response.ok) {
            const status = await response.json();
            if (Number(status?.active_requests ?? 0) > 0) {
                console.error('Replay lane is active; PM2 rolling sync was refused to prevent a mutex contention burst.');
                process.exit(2);
            }

            // An idle probe can land in the small hand-off gap after one
            // replay finishes and before the queue worker opens its next
            // request. Confirm the lane remains idle before a rolling reload;
            // otherwise the replacement worker can terminate that next job
            // after it has acquired the shared mutex but before Python sees
            // the request, leaving an open immutable run behind.
            await new Promise((resolve) => setTimeout(resolve, 5000));
            const confirmation = await fetch('http://127.0.0.1:9000/api/replay-status', {
                headers: { 'X-Internal-Token': token },
                signal: AbortSignal.timeout(3000),
            });
            if (!confirmation.ok) {
                console.error(`Replay liveness confirmation returned HTTP ${confirmation.status}; PM2 sync was refused.`);
                process.exit(2);
            }
            const confirmationStatus = await confirmation.json();
            if (Number(confirmationStatus?.active_requests ?? 0) > 0) {
                console.error('Replay lane became active during the idle grace window; PM2 rolling sync was refused.');
                process.exit(2);
            }
            // Close the queue-preparation blind spot as late as possible. A
            // worker may reserve a job after the first durable check but
            // before Python increments active_requests.
            assertDurableReplayIdle();
        } else {
            console.warn(`Replay liveness probe returned HTTP ${response.status}; continuing with the configured sync.`);
        }
    } catch (error) {
        console.warn(`Replay liveness probe unavailable; continuing with the configured sync: ${error.message}`);
    }
}

const stale = staleNames.filter((name) => processes.some((entry) => entry.name === name));
if (stale.length > 0) {
    const deleted = run(['delete', ...stale]);
    if (deleted.status !== 0) {
        process.exit(deleted.status ?? 1);
    }
}

// Freeze cadence before touching workers. A whole-ecosystem reload can take
// several minutes on Windows; if the scheduler restarts near the beginning it
// can reserve fresh work that a later worker reload then kills. The scheduler
// is deliberately the last ecosystem entry, so it stays stopped until every
// queue consumer has accepted the new runtime.
const schedulerWasOnline = processes.some((entry) => entry.name === 'neurotrader-scheduler'
    && entry.pm2_env?.status === 'online');
if (schedulerWasOnline) {
    const stoppedScheduler = run(['stop', 'neurotrader-scheduler']);
    if (stoppedScheduler.status !== 0) {
        process.exit(stoppedScheduler.status ?? 1);
    }
}
assertDurableReplayIdle(() => {
    if (schedulerWasOnline) {
        run(['start', 'neurotrader-scheduler']);
    }
});

// PM2 reload on Windows fork-mode can leave the previous PHP queue:work child
// alive indefinitely even after the replacement is online. That orphan can
// later reserve a scheduled job with stale application code. This script is
// already guarded by the durable idle preflight above, so a deterministic
// restart is safe here and guarantees one worker per configured PM2 process.
// Restart consumers first; start the cadence source only after every consumer
// has accepted the new runtime.
const consumerNames = managedProcessNames.filter((name) => name !== 'neurotrader-scheduler');
const restarted = run([
    'restart', 'ecosystem.config.cjs', '--only', consumerNames.join(','), '--update-env',
]);
if (restarted.status !== 0) {
    if (schedulerWasOnline) {
        run(['start', 'neurotrader-scheduler']);
    }
    process.exit(restarted.status ?? 1);
}
if (schedulerWasOnline) {
    const startedScheduler = run([
        'start', 'ecosystem.config.cjs', '--only', 'neurotrader-scheduler', '--update-env',
    ]);
    if (startedScheduler.status !== 0) {
        process.exit(startedScheduler.status ?? 1);
    }
}

// Restarting fixes the live daemon, but it does not protect the next daemon
// restart unless the reconciled topology is persisted. Save only after a
// successful restart so a failed/partial sync can never overwrite the last
// known-good PM2 resurrection set.
const saved = run(['save']);
process.exit(saved.status ?? 1);
