import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const pm2Cli = path.join(projectRoot, 'node_modules', 'pm2', 'bin', 'pm2');
const cleanEnvironment = { ...process.env };
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

const listed = spawnSync(process.execPath, [pm2Cli, 'jlist'], {
    cwd: projectRoot,
    env: cleanEnvironment,
    windowsHide: true,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
});

if (listed.status !== 0) {
    process.stderr.write(listed.stderr || 'PM2 process list could not be read.\n');
    process.exit(listed.status ?? 1);
}

let processes = [];
try {
    processes = JSON.parse(listed.stdout || '[]');
} catch (error) {
    process.stderr.write(`PM2 process list is not JSON: ${error.message}\n`);
    process.exit(1);
}

// PM2's Windows reload is rolling: the replacement worker can start before
// the old PHP worker exits. That is unsafe for the single replay lane because
// the replacement sees the old shared lock and turns every queued candidate
// into a release/defer burst. Refuse a rolling sync while the AI service
// reports an active replay; the operator/scheduler can retry after the lane
// is idle, and stale-lock recovery remains the backstop for a killed worker.
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

const reloaded = run(['reload', 'ecosystem.config.cjs', '--update-env']);
if (reloaded.status !== 0) {
    process.exit(reloaded.status ?? 1);
}

// Reloading fixes the live daemon, but it does not protect the next daemon
// restart unless the reconciled topology is persisted. Save only after a
// successful reload so a failed/partial sync can never overwrite the last
// known-good PM2 resurrection set.
const saved = run(['save']);
process.exit(saved.status ?? 1);
