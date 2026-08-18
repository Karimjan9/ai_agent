const php = process.env.PHP_BINARY || 'php';
const python = process.env.PYTHON_BINARY || 'python';
const fs = require('fs');
const path = require('path');
const runtimeTokenFile = path.resolve(__dirname, '..', 'runtime', 'internal-api.token');
const legacyTokenFile = path.join(__dirname, 'storage', 'app', 'secrets', 'internal-api.token');
const tokenFile = process.env.INTERNAL_API_TOKEN_FILE
  || (fs.existsSync(runtimeTokenFile) ? runtimeTokenFile : legacyTokenFile);
const laravelRouter = path.join(__dirname, 'vendor', 'laravel', 'framework', 'src', 'Illuminate', 'Foundation', 'resources', 'server.php');
if (!fs.existsSync(tokenFile)) {
  throw new Error(`Missing internal API token file: ${tokenFile}`);
}
const sharedEnv = {
  INTERNAL_API_TOKEN_FILE: tokenFile,
  // Two bounded screening child slots; full validation remains exclusive in
  // the Python lane admission guard.
  AI_SCREEN_REPLAY_CONCURRENCY: process.env.AI_SCREEN_REPLAY_CONCURRENCY || '2',
  // Replay cache is rebuildable operational state. Keep it bounded so legacy
  // JSON artifacts cannot consume the disk while immutable evidence remains
  // in Laravel's evidence disk.
  AI_REPLAY_CACHE_RETENTION_DAYS: process.env.AI_REPLAY_CACHE_RETENTION_DAYS || '14',
  AI_REPLAY_CACHE_MAX_BYTES: process.env.AI_REPLAY_CACHE_MAX_BYTES || '1610612736',
  AI_REPLAY_CACHE_CLEANUP_INTERVAL_SECONDS: process.env.AI_REPLAY_CACHE_CLEANUP_INTERVAL_SECONDS || '300',
};
// The managed execution shell can deny the protected token file to a newly
// spawned Python process even though Laravel already has the cached secret.
// When the operator supplies the secret to PM2 for a controlled restart, pass
// it explicitly only to the AI service; never write it to this file.
const aiEnv = {
  ...sharedEnv,
  ...(process.env.INTERNAL_API_TOKEN ? { INTERNAL_API_TOKEN: process.env.INTERNAL_API_TOKEN } : {}),
};
const secretPrefixes = ['OPENAI_', 'CODEX_', 'INTERNAL_API_TOKEN'];
// Production web traffic is served by Nginx/Apache + PHP-FPM. The built-in
// server remains available only for the local Windows development profile.
const externalWebServer = process.env.WEB_SERVER_MODE === 'external' || process.env.NODE_ENV === 'production';
// Redis is the canonical local/production transport. Laravel's .env is not
// loaded by Node/PM2, so the fallback must not silently resurrect the slower
// database queue when PM2 is started without an inherited environment.
const queueConnection = process.env.QUEUE_CONNECTION || 'redis';

const worker = (name, queue, timeoutSeconds = 1200) => ({
  name,
  script: 'artisan',
  interpreter: php,
  // PHP is a console-subsystem executable on Windows. PM2 must create it
  // without an attached console or every scheduler/data-fetch child can
  // briefly materialize a visible conhost window.
  windowsHide: true,
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
  // Never let PM2 recycle the coordinator while a legitimate replay is
  // inside Laravel's timeout/post-processing window. A restart during a
  // reserved Redis job leaves the visibility score in the future and makes
  // the job look missing until retry_after expires. Keep the worker lease
  // longer than its queue timeout plus a recovery margin; the explicit
  // replay timeout and retryUntil() remain the real safety bounds.
  args: `queue:work ${queueConnection} --queue=${queue} --sleep=1 --tries=0 --timeout=${timeoutSeconds} --memory=2048 --max-time=${Math.max(3600, timeoutSeconds + 1800)}`,
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
      windowsHide: true,
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
      restart_delay: 5000,
      kill_timeout: 30000,
      windowsHide: true,
      // The bounded child can return a large immutable ledger through the
      // parent before the HTTP response is committed. Keep PM2 above that
      // legitimate peak; the replay child and request hard timeout remain
      // the actual safety bounds.
      max_memory_restart: '2G',
      time: true,
      env: aiEnv,
      filter_env: secretPrefixes,
    },
    {
      name: 'neurotrader-scheduler',
      script: 'artisan',
      interpreter: php,
      args: 'schedule:headless-work',
      autorestart: true,
      restart_delay: 5000,
      windowsHide: true,
      // Schedule ticks may materialize generation/foundation manifests and
      // briefly exceed 512M before the command's own 256M bounded-memory
      // rotation can return cleanly. Keep PM2 above that internal gate so a
      // Windows memory restart cannot strand the Redis scheduler lease.
      max_memory_restart: '768M',
      kill_timeout: 30000,
      time: true,
      env: sharedEnv,
      filter_env: secretPrefixes,
    },
    // Full validation remains one priority coordinator. Screening has two
    // bounded workers over the separate Python screening semaphore; the
    // immutable snapshot and per-agent state stay shared/independent as
    // required by the evidence contract. Legacy symbol queues remain visible
    // so already-serialized jobs cannot be stranded after a deploy.
    worker('lab-replay', 'lab-full-validation,lab-frontier', 4200),
    worker('lab-screening-a', 'lab-screening,lab-xauusd,lab-eurusd,lab-gbpusd', 2400),
    worker('lab-screening-b', 'lab-screening,lab-xauusd,lab-eurusd,lab-gbpusd', 2400),
    worker('lab-learning', 'lab-learning', 900),
    worker('strategy-lab', 'strategy-lab', 2400),
    worker('backtests', 'backtests', 900),
  ],
};
