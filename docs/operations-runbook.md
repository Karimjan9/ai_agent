# NeuroTrader operations runbook

## P0 release gates

1. Keep `SECONDARY_INTELLIGENCE_ENABLED=false`.
2. Set `MARKET_DATA_CANONICAL_PROVIDER=twelve` (or the explicitly approved replacement). Run `php artisan market-data:audit SYMBOL --timeframe=H1` and then `php artisan market-data:quality --json`. Continuity and historical quality use the same canonical session calendar, including scheduled FX holiday closures. Secondary providers are discrepancy evidence and never silently replace the canonical series.
3. Repair bounded ranges with `php artisan market-data:repair-gaps --dry-run`, inspect them, then run without `--dry-run`. The command accepts only the configured canonical provider; use the separate foundation-training archive lane for Dukascopy history. Repeat until the quality command passes.
4. Export each clean rolling dataset with `php artisan market-data:export-lab SYMBOL`. Keep the adjacent `.manifest.json`; it contains the full file SHA-256 and exact row count. Full validation seals a separate pre-2026 foundation archive per generation; H1 uses the long archive, while M15 uses its own preserved M15 slice and closed H1 regime context. Both are research-only and never promotion evidence.
5. Start clean generations only after quality passes. Pre-P0 sessions/models stay visible as `legacy_invalid` and are never promotion evidence.
6. Check paper gates with `php artisan paper:evidence-readiness --json`. Do not shorten the 90-day clock or manufacture observations.

## Access and secrets

- Run `php artisan security:generate-internal-token`, then restart the managed processes. The token is written to ignored protected storage; Laravel and Python read that file without placing the secret in PM2 metadata. Use `INTERNAL_API_TOKEN` only when a secret manager supplies it, and never persist it in the process dump.
- Create the first admin with `php artisan auth:create-user admin@example.com --role=admin`; the password is prompted without appearing in process arguments.
- Roles are `viewer`, `operator`, and `admin`. Only admins can approve/apply evolution mutations.
- Keep `.env` outside artifacts and never commit provider or Telegram credentials.

## Processes and Node 22

Heavy replay operations use the configured LAB_REPLAY_MUTEX_KEY (default
neurotrader-ai-heavy-replay) across queue middleware, direct portfolio replay,
and stale-lock recovery. A large foundation archive uses the
full_replay_runtime_budget_v1 policy; the default bounded cohort is two
candidates and always carries promotion_evidence=false. This is a runtime
budget, not a relaxed statistical or promotion gate.

If a worker is interrupted, first prove that no replay_worker child and no
active AI request remain. Then run:

    php artisan trading:recover-lab-replay-mutex --force-stale --stale-after=900

The command requeues only proven-stale reservations and closes their open
attempt evidence as retry_released; it never deletes jobs or strategy
evidence. Do not clear the mutex while a full queue reservation or AI replay
is live.

The managed deployment uses one priority replay coordinator for the shared AI
lane: `lab-full-validation` is read before `lab-screening`, followed by the
legacy symbol queues. Separate screen/full workers create avoidable mutex
release churn while one replay is active. Screen queue contention remains
operational evidence with a bounded six-hour retry window; if old serialized
jobs report `MaxAttemptsExceeded` after waiting behind full replay, use the
explicit bounded recovery command for the affected generation. Never turn
that queue error into a strategy rejection or lower a quality gate.

The ecosystem filters OpenAI, Codex, and inline internal-token prefixes from child
environments. The process scripts also launch PM2 through
scripts/pm2-clean-env.mjs, which removes those prefixes before PM2 stores its
daemon metadata. If an older daemon already contains credentials, rebuild it
only during a drained replay window with npm run process:kill, npm run
process:start, and npm run process:save. Rotate any credential that has
appeared in PM2 metadata. The runtime sync also refuses a rolling reload while
the AI replay-status endpoint reports an active replay, preventing a second
worker from inheriting a live mutex and creating a queue-release burst.
The headless scheduler performs post-tick garbage collection and exits cleanly
at `SCHEDULER_MEMORY_LIMIT_MB`, allowing PM2 to refresh a leaking long-lived
PHP process without interrupting an in-flight scheduled command.
Full-replay mutex recovery derives its stale threshold from the configured
full replay timeout plus `LAB_FULL_REPLAY_POST_PROCESSING_GRACE_SECONDS`; an
idle Python lane alone is not proof that Laravel has finished sealing evidence.

Install Node 22 (the repository has `.nvmrc` and an engine constraint), then run `npm ci`. On this Windows host the verified portable runtime is `../.runtime/node-v22.23.1-win-x64`; the process scripts explicitly use it so PM2 does not fall back to the system Node 18 installation. Set `PHP_BINARY` and `PYTHON_BINARY` when they are not on `PATH`. Use `npm run process:start`, `npm run process:status`, and `npm run process:stop`. Persist PM2 with the platform-specific startup integration after verifying every process is healthy.

Laravel logs use the daily channel with 14-day retention. `pm2-logrotate` caps process logs at 20 MB, retains 14 compressed rotations, and must remain online. PM2 restarts the Python service, scheduler, and queue workers on failure; the ecosystem filters `OPENAI_`, `CODEX_`, and inline internal-token values from child environments. The five-minute health check and one-minute feed check send rate-limited Telegram critical alerts when Telegram is configured. Market Reality analysis is a separate Phase 2 foundation flow (`MARKET_REALITY_ENABLED=true` by default); its 7,200-second H1 freshness window should be reviewed alongside `php artisan market:health --strict`.

Never run PHPUnit with the production configuration cache. The repository test configuration forces SQLite memory storage and `tests/TestCase.php` fails closed before `RefreshDatabase` if that invariant is broken.

## Backup and restore drill

- `php artisan ops:backup-database` creates an atomic SQL file and full SHA-256 manifest only under `DATABASE_BACKUP_PATH` (default `G:/NeuroTrader/backups`). It refuses C: and fails loudly if G: is unavailable or unwritable.
- The application scheduler runs this command daily at `DATABASE_BACKUP_SCHEDULE_TIME` (default `02:30`). `DATABASE_BACKUP_RETENTION=3` keeps the newest three SQL/manifest pairs and prunes older pairs after a successful backup.
- Copy backups to an encrypted off-host location; a local G: backup is not disaster recovery.
- Test quarterly in an isolated database: `php artisan ops:restore-database path/to/file.sql --confirm=RESTORE`.
- Restore refuses missing/mismatched manifests. Never point a restore drill at the production database.
- `php artisan system:health-check --strict` verifies the newest G: manifest/size and backup age; set `DATABASE_BACKUP_VERIFY_HASH_ON_HEALTH=true` only for an explicit deep integrity audit because production dumps are multi-gigabyte.

## Execution stages

Paper execution is permanently simulated in the current codebase. Live execution is intentionally not implemented. Its safety configuration defaults are `LIVE_TRADING_ENABLED=false`, `LIVE_KILL_SWITCH_ENGAGED=true`, and zero capital; changing those values alone does not add a live execution path. Any future external execution adapter requires a separate reviewed implementation, explicit human approval, a small capital limit, and a tested kill-switch.
