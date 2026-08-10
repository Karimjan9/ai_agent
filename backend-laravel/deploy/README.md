# Production process layout

Production should run Nginx + PHP-FPM in front of Laravel. Start PM2 with
`WEB_SERVER_MODE=external`; the PM2 ecosystem will then start only the AI,
scheduler, and queue workers and will not run `php -S`.

Recommended environment values:

```dotenv
APP_ENV=production
APP_DEBUG=false
WEB_SERVER_MODE=external
QUEUE_CONNECTION=redis
CACHE_STORE=redis
LAB_EVIDENCE_DISK=s3
REDIS_HOST=127.0.0.1
```

Copy `nginx/neurotrader.conf.example` to the Nginx sites configuration and
adjust the project path, hostname, TLS, and PHP-FPM socket. Copy the pool
example to PHP-FPM only after checking the host's PHP version.

The replay/backtest work is intentionally isolated in queue workers. Nginx
and PHP-FPM should never wait for a long Python replay request.
