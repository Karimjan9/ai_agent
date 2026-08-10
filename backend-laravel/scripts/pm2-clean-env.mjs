import { spawn } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const pm2Cli = path.join(projectRoot, 'node_modules', 'pm2', 'bin', 'pm2');
const cleanEnvironment = { ...process.env };

// PM2 persists its daemon environment in the process list and dump file.
// These values are not needed by the local replay service or Laravel workers
// and must not leak from an operator/editor shell into PM2 metadata.
for (const key of Object.keys(cleanEnvironment)) {
    if (key.startsWith('OPENAI_') || key.startsWith('CODEX_') || key === 'INTERNAL_API_TOKEN') {
        delete cleanEnvironment[key];
    }
}

const child = spawn(process.execPath, [pm2Cli, ...process.argv.slice(2)], {
    cwd: projectRoot,
    env: cleanEnvironment,
    stdio: 'inherit',
    windowsHide: true,
});

child.on('error', (error) => {
    console.error('Unable to launch PM2: ' + error.message);
    process.exitCode = 1;
});

child.on('exit', (code, signal) => {
    if (signal) {
        process.kill(process.pid, signal);
        return;
    }
    process.exitCode = code ?? 1;
});
