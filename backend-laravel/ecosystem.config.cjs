const php = process.env.PHP_BINARY || 'php';
const python = process.env.PYTHON_BINARY || 'python';
const fs = require('fs');
const path = require('path');
const tokenFile = path.join(__dirname, 'storage', 'app', 'internal-api.token');
const laravelRouter = path.join(__dirname, 'vendor', 'laravel', 'framework', 'src', 'Illuminate', 'Foundation', 'resources', 'server.php');
if (!fs.existsSync(tokenFile)) {
  throw new Error(`Missing internal API token file: ${tokenFile}`);
}
const sharedEnv = { INTERNAL_API_TOKEN_FILE: tokenFile };
const secretPrefixes = ['OPENAI_', 'CODEX_', 'INTERNAL_API_TOKEN'];

const worker = (name, queue) => ({
  name,
  script: 'artisan',
  interpreter: php,
  args: `queue:work database --queue=${queue} --sleep=1 --tries=2 --timeout=2400`,
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
    worker('lab-full-validation', 'lab-full-validation'),
  ],
};
