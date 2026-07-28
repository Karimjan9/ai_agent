# NeuroTrader operations runbook

## P0 release gates

1. Keep `SECONDARY_INTELLIGENCE_ENABLED=false`.
2. Set `MARKET_DATA_CANONICAL_PROVIDER=twelve` (or the explicitly approved replacement). Run `php artisan market-data:audit SYMBOL --timeframe=H1` and then `php artisan market-data:quality --json`. Continuity gates evaluate only the canonical series; secondary providers are discrepancy evidence and never silently replace it.
3. Repair bounded ranges with `php artisan market-data:repair-gaps --dry-run`, inspect them, then run without `--dry-run`. Repeat until the quality command passes.
4. Export each clean dataset with `php artisan market-data:export-lab SYMBOL`. Keep the adjacent `.manifest.json`; it contains the full file SHA-256 and exact row count.
5. Start clean generations only after quality passes. Pre-P0 sessions/models stay visible as `legacy_invalid` and are never promotion evidence.
6. Check paper gates with `php artisan paper:evidence-readiness --json`. Do not shorten the 90-day clock or manufacture observations.

## Access and secrets

- Run `php artisan security:generate-internal-token`, then restart the managed processes. The token is written to ignored protected storage; Laravel and Python read that file without placing the secret in PM2 metadata. Use `INTERNAL_API_TOKEN` only when a secret manager supplies it, and never persist it in the process dump.
- Create the first admin with `php artisan auth:create-user admin@example.com --role=admin`; the password is prompted without appearing in process arguments.
- Roles are `viewer`, `operator`, and `admin`. Only admins can approve/apply evolution mutations.
- Keep `.env` outside artifacts and never commit provider or Telegram credentials.

## Processes and Node 22

Install Node 22 (the repository has `.nvmrc` and an engine constraint), then run `npm ci`. On this Windows host the verified portable runtime is `../.runtime/node-v22.23.1-win-x64`; the process scripts explicitly use it so PM2 does not fall back to the system Node 18 installation. Set `PHP_BINARY` and `PYTHON_BINARY` when they are not on `PATH`. Use `npm run process:start`, `npm run process:status`, and `npm run process:stop`. Persist PM2 with the platform-specific startup integration after verifying every process is healthy.

Laravel logs use the daily channel with 14-day retention. `pm2-logrotate` caps process logs at 20 MB, retains 14 compressed rotations, and must remain online. PM2 restarts the Python service, scheduler, and queue workers on failure; the ecosystem filters `OPENAI_`, `CODEX_`, and inline internal-token values from child environments. The five-minute health check and one-minute feed check send rate-limited Telegram critical alerts when Telegram is configured.

## Backup and restore drill

- `php artisan ops:backup-database --retain=14` creates an atomic SQL file and full SHA-256 manifest under `storage/app/backups`. It is scheduled daily at 02:15.
- Copy backups to an encrypted off-host location; a local backup is not disaster recovery.
- Test quarterly in an isolated database: `php artisan ops:restore-database path/to/file.sql --confirm=RESTORE`.
- Restore refuses missing/mismatched manifests. Never point a restore drill at the production database.

## Execution stages

Paper execution is permanently simulated in the current codebase. Live execution is intentionally not implemented. Its safety configuration defaults are `LIVE_TRADING_ENABLED=false`, `LIVE_KILL_SWITCH_ENGAGED=true`, and zero capital; changing those values alone does not add a live execution path. Any future external execution adapter requires a separate reviewed implementation, explicit human approval, a small capital limit, and a tested kill-switch.
