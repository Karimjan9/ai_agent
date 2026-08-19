import { execFileSync, spawnSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const projectRoot = path.resolve(fileURLToPath(new URL('..', import.meta.url)));
const pm2Cli = path.join(projectRoot, 'node_modules', 'pm2', 'bin', 'pm2');
const rawArgs = process.argv.slice(2);
const allowLiveWorkers = rawArgs.includes('--allow-live-workers');
const testArgs = rawArgs.filter((arg) => arg !== '--allow-live-workers');
const protectedNames = new Set([
    'neurotrader-ai',
    'neurotrader-scheduler',
    'lab-replay',
    'lab-screening-a',
    'lab-screening-b',
    'lab-learning',
    'strategy-lab',
    'backtests',
]);

const cleanEnvironment = { ...process.env };
for (const key of Object.keys(cleanEnvironment)) {
    if (key.startsWith('OPENAI_') || key.startsWith('CODEX_') || key === 'INTERNAL_API_TOKEN') {
        delete cleanEnvironment[key];
    }
}

let live = [];
try {
    const rows = JSON.parse(execFileSync(process.execPath, [pm2Cli, 'jlist'], {
        cwd: projectRoot,
        env: cleanEnvironment,
        encoding: 'utf8',
        windowsHide: true,
    }));
    live = rows
        .filter((row) => protectedNames.has(row.name) && row.pm2_env?.status === 'online')
        .map((row) => row.name)
        .sort();
} catch (error) {
    if (!allowLiveWorkers) {
        console.error(`Refusing tests: PM2 live-worker state could not be verified (${error.message}).`);
        console.error('Run in an isolated environment or explicitly pass --allow-live-workers.');
        process.exit(2);
    }
}

if (live.length > 0 && !allowLiveWorkers) {
    console.error(`Refusing tests while live workers are online: ${live.join(', ')}.`);
    console.error('Use a separate test environment, or explicitly pass --allow-live-workers for a bounded diagnostic run.');
    process.exit(2);
}

if (live.length > 0) {
    console.warn(`WARNING: running tests alongside live workers: ${live.join(', ')}.`);
}

const result = spawnSync(process.env.PHP_BINARY || 'php', ['artisan', 'test', ...testArgs], {
    cwd: projectRoot,
    env: { ...process.env, APP_ENV: 'testing' },
    stdio: 'inherit',
    windowsHide: true,
});

if (result.error) {
    console.error(`Unable to launch PHPUnit: ${result.error.message}`);
    process.exit(1);
}
process.exit(result.status ?? 1);
