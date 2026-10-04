const { execFileSync } = require('child_process');
const path = require('node:path');

function collectSummary(run = execFileSync, externalBinary = null) {
  const environment = { ...process.env };
  for (const key of Object.keys(environment)) {
    if (key.startsWith('OPENAI_') || key.startsWith('CODEX_') || key === 'INTERNAL_API_TOKEN') delete environment[key];
  }
  const project = path.resolve(__dirname, '..');
  const binary = externalBinary || process.execPath;
  const args = externalBinary ? ['jlist'] : [path.join(project, 'node_modules', 'pm2', 'bin', 'pm2'), 'jlist'];
  const raw = run(binary, args, {
    encoding: 'utf8',
    cwd: project,
    env: environment,
    maxBuffer: 16 * 1024 * 1024,
    shell: false,
    windowsHide: true,
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  const apps = JSON.parse(raw);
  if (!Array.isArray(apps)) throw new Error('PM2_STATUS_SHAPE_INVALID');
  const summary = apps.map((app) => ({
    name: app.name,
    status: app.pm2_env?.status ?? null,
    pid: app.pid ?? null,
    restarts: app.pm2_env?.restart_time ?? 0,
    memory_mb: Math.round(((app.monit?.memory ?? 0) / 1048576) * 10) / 10,
  }));
  return { items: summary };
}

function safeReport(run = execFileSync, externalBinary = null) {
  try { return { exitCode: 0, output: JSON.stringify(collectSummary(run, externalBinary)) }; }
  // JSON parse and child-process errors can embed the entire jlist, including
  // persisted secrets. Never print the exception, stdout, stderr or raw input.
  catch (_) { return { exitCode: 2, output: '{"status":"unavailable","reason":"PM2_COMPACT_STATUS_UNAVAILABLE"}' }; }
}

module.exports = { collectSummary, safeReport };
if (require.main === module) {
  const result = safeReport(execFileSync, process.argv[2] || null);
  process.stdout.write(result.output);
  process.exitCode = result.exitCode;
}
