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

const worker = (name, queue, timeoutSeconds = 1200) => ({
  name,
  script: 'artisan',
  interpreter: php,
  // EvaluateLabAgentJob has a bounded two-hour recovery window.  A CLI
  // Shared-AI contention is recoverable, but an evaluator outage must not
  // retry a single candidate for hours. Full validation gets a longer worker
  // lease; market screening remains bounded separately.
    // retryUntil() on EvaluateLabAgentJob is the wall-clock safety bound.
    // A high attempt ceiling is required because release-based queue
    // fairness/AI-lane mutex middleware consumes attempts during contention.
    args: `queue:work database --queue=${queue} --sleep=1 --tries=240 --timeout=${timeoutSeconds}`,
  autorestart: true,
  max_memory_restart: '512M',
  time: true,
  env: sharedEnv,
  filter_env: secretPrefixes,
});

module.exports = {
  apps: [
    {
      name: 'neurotrader-web',
      script: php,
      interpreter: 'none',
      args: ['-S', '127.0.0.1:8000', laravelRouter],
      cwd: path.join(__dirname, 'public'),
      autorestart: true,
      max_memory_restart: '256M',
      time: true,
      env: sharedEnv,
      filter_env: secretPrefixes,
    },
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
      max_memory_restart: '256M',
      time: true,
      env: sharedEnv,
      filter_env: secretPrefixes,
    },
    worker('lab-xauusd', 'lab-xauusd'),
    worker('lab-eurusd', 'lab-eurusd'),
    worker('lab-gbpusd', 'lab-gbpusd'),
    worker('lab-full-validation', 'lab-full-validation', 2400),
  ],
};
