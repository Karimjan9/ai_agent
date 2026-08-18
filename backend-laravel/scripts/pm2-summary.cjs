const { execFileSync } = require('child_process');

try {
  const pm2 = process.argv[2] || 'pm2';
  const raw = execFileSync(pm2, ['jlist'], {
    encoding: 'utf8',
    shell: true,
    windowsHide: true,
    stdio: ['ignore', 'pipe', 'ignore'],
  });
  const apps = JSON.parse(raw);
  const summary = apps.map((app) => ({
    name: app.name,
    status: app.pm2_env?.status ?? null,
    pid: app.pid ?? null,
    restarts: app.pm2_env?.restart_time ?? 0,
    memory_mb: Math.round(((app.monit?.memory ?? 0) / 1048576) * 10) / 10,
  }));
  process.stdout.write(JSON.stringify({ items: summary }));
} catch (_) {
  process.exitCode = 2;
}
