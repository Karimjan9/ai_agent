const php = process.env.PHP_BINARY || 'php';
const python = process.env.PYTHON_BINARY || 'python';
const fs = require('fs');
const path = require('path');
const tokenFile = path.join(__dirname, 'storage', 'app', 'secrets', 'internal-api.token');
const laravelRouter = path.join(__dirname, 'vendor', 'laravel', 'framework', 'src', 'Illuminate', 'Foundation', 'resources', 'server.php');
if (!fs.existsSync(tokenFile)) {
  throw new Error(`Missing internal API token file: ${tokenFile}`);
}
const sharedEnv = { INTERNAL_API_TOKEN_FILE: tokenFile };
const secretPrefixes = ['OPENAI_', 'CODEX_', 'INTERNAL_API_TOKEN'];
// Production web traffic is served by Nginx/Apache + PHP-FPM. The built-in
// server remains available only for the local Windows development profile.
const externalWebServer = process.env.WEB_SERVER_MODE === 'external' || process.env.NODE_ENV === 'production';
const queueConnection = process.env.QUEUE_CONNECTION || (externalWebServer ? 'redis' : 'database');

const worker = (name, queue, timeoutSeconds = 1200) => ({
  name,
  script: 'artisan',
  interpreter: php,
  // EvaluateLabAgentJob has a bounded two-hour recovery window.  A CLI
  // Shared-AI contention is recoverable, but an evaluator outage must not
  // retry a single candidate for hours. Full validation gets a longer worker
  // lease; market screening remains bounded separately.
  // EvaluateLabAgentJob uses retryUntil() as its wall-clock safety bound.
  // Release-based mutex contention must not exhaust a numeric attempt cap.
  // Laravel's queue worker has its own 128 MB default memory ceiling. That
  // limit is lower than a legitimate immutable screening/full-replay
  // projection and causes exit code 12 after a healthy replay. PM2's
  // 2048M ceiling below is only useful when the Laravel worker receives the
  // same explicit limit.
  args: `queue:work ${queueConnection} --queue=${queue} --sleep=1 --tries=0 --timeout=${timeoutSeconds} --memory=2048 --max-time=3600`,
  autorestart: true,
  restart_delay: 5000,
  // Screening responses carry immutable ledgers and can legitimately exceed
  // 768M on XAUUSD. Keep a real ceiling, but leave enough headroom so PM2
  // does not interrupt a healthy job after every few replay responses.
  // A 5k-candle screening response is built and projected in PHP before the
  // immutable artifact is externalized. Keep PM2 from killing a legitimate
  // bounded replay around the 1G mark; the queue worker is still bounded by
  // --timeout/--max-time and is restarted after a completed job when needed.
  max_memory_restart: '2048M',
  time: true,
  env: sharedEnv,
  filter_env: secretPrefixes,
});

module.exports = {
  apps: [
    ...(!externalWebServer ? [{
      name: 'neurotrader-web-dev',
      script: php,
      interpreter: 'none',
      args: ['-S', '127.0.0.1:8000', laravelRouter],
      cwd: path.join(__dirname, 'public'),
      autorestart: true,
      max_memory_restart: '256M',
      time: true,
      env: sharedEnv,
      filter_env: secretPrefixes,
    }] : []),
    {
      name: 'neurotrader-ai',
      script: 'scripts/run-ai-service.py',
      interpreter: python,
      cwd: __dirname,
      autorestart: true,
      max_memory_restart: '1G',
      time: true,
      env: sharedEnv,
      filter_env: secretPrefixes,
    },
    {
      name: 'neurotrader-scheduler',
      script: 'artisan',
      interpreter: php,
      args: 'schedule:headless-work',
      autorestart: true,
      restart_delay: 5000,
      // Schedule ticks may materialize generation/foundation manifests and
      // briefly exceed 256M. A healthy scheduler must not restart in the
      // middle of a dispatch window and leave queue reservations half-open.
      max_memory_restart: '512M',
      time: true,
      env: sharedEnv,
      filter_env: secretPrefixes,
    },
    // One coordinator owns the shared AI replay lane. Keeping screening and
    // full validation in separate workers makes every worker repeatedly pull
    // the same mutex-contended job while the other worker is replaying; those
    // releases are queue attempts, not useful work. Queue priority gives a
    // waiting sealed full replay the first turn, while the single coordinator
    // guarantees that only one queued replay can enter the Python service.
    // Legacy symbol queues remain visible so already-serialized jobs cannot be
    // stranded after a deploy.
    worker('lab-replay', 'lab-full-validation,lab-frontier,lab-screening,lab-xauusd,lab-eurusd,lab-gbpusd', 4200),
    worker('strategy-lab', 'strategy-lab', 2400),
    worker('backtests', 'backtests', 900),
  ],
};
