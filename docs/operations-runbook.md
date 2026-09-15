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
After any PHP application-code change, run `npm run process:workers-restart`
before dispatching new lab work. Laravel's restart signal lets an active job
finish and makes every PM2-managed queue worker exit cleanly afterward; PM2
then starts a fresh worker that loads the new classes. A source edit on disk
does not update an already-running `queue:work` process. Use
`npm run process:reload` in the next fully drained replay window to refresh the
scheduler and the rest of the ecosystem too.
The headless scheduler performs post-tick garbage collection and exits cleanly
at `SCHEDULER_MEMORY_LIMIT_MB`, allowing PM2 to refresh a leaking long-lived
PHP process without interrupting an in-flight scheduled command.
Full-replay mutex recovery derives its stale threshold from the configured
full replay timeout plus `LAB_FULL_REPLAY_POST_PROCESSING_GRACE_SECONDS`; an
idle Python lane alone is not proof that Laravel has finished sealing evidence.

Install Node 22 (the repository has `.nvmrc` and an engine constraint), then run `npm ci`. On this Windows host the verified portable runtime is `../.runtime/node-v22.23.1-win-x64`; the process scripts explicitly use it so PM2 does not fall back to the system Node 18 installation. Set `PHP_BINARY` and `PYTHON_BINARY` when they are not on `PATH`. Use `npm run process:start`, `npm run process:status`, and `npm run process:stop`. Persist PM2 with the platform-specific startup integration after verifying every process is healthy.

Laravel logs use the daily channel with 14-day retention. `pm2-logrotate` caps process logs at 20 MB, retains 14 compressed rotations, and must remain online. PM2 restarts the Python service, scheduler, and queue workers on failure; the ecosystem filters `OPENAI_`, `CODEX_`, and inline internal-token values from child environments. The five-minute health check and one-minute feed check send rate-limited Telegram critical alerts when Telegram is configured. Market Reality analysis is a separate Phase 2 foundation flow (`MARKET_REALITY_ENABLED=true` by default); its 7,200-second H1 freshness window should be reviewed alongside `php artisan market:health --strict`.

Never run PHPUnit with the production configuration cache. The repository test configuration forces SQLite memory storage and `tests/TestCase.php` fails closed before `RefreshDatabase` if that invariant is broken.

### Autonomous Edge-to-Mastery runtime

`trading:advance-learning-progress XAUUSD --timeframe=H1` is the read-only
operator probe. Add `--apply --json` only to advance one bounded checkpoint;
the production scheduler runs that form once per minute on the
`scheduler-critical` lane. The queued command is unique and the Director also
owns a distributed symbol/timeframe lock, so repeated ticks cannot open
parallel cohorts.

Admission is fail-closed. Redis, queue transport, AI replay liveness and the
headless scheduler must each report `ok`; the canonical replay queue and all
AI replay counters must be zero in two observations at least ten seconds
apart; Failure Dojo queries and settlement watermarks must be consistent; and
no retry storm may be present. Delayed research by itself is observable debt,
not a retry storm. Actionable Failure Dojo items inform hypothesis compilation
but do not block Genesis when the Dojo itself is healthy.

Initial Genesis freezes the canonical pre-2026 foundation manifest, the MTF
bundle and M5 execution contract automatically. A cohort identity includes
symbol, data hash, MTF bundle hash, execution hash, architecture revision,
packet-definition hash and frozen-window-plan hash. The database unique key
and pre-registration transaction form the exactly-once boundary before any
agent job is dispatched.

Evaluation uses one frozen fourteen-fold universe split into disjoint 2-fold
discovery, 3-fold causal replication and 9-fold authority stages. The first
two stages may only allocate compute; only the nine-fold stage can establish
Edge authority. When fixed repair packets are exhausted, the evidence compiler
opens one immutable, single-structural-axis packet with exact and negative
controls. Professional knowledge remains proposal prior, never promotion
evidence. All replay fitness is pre-2026; 2026 is sealed paper-only coverage.

Compiled hypotheses also carry semantic axis debt. A new generation ID or a
different failure label cannot reopen the same structural axis for the same
strategy/tactic/management composition; another causal axis must be tested
first. The compiler budget counts distinct semantic axes, so an old duplicate
row remains audit evidence but cannot consume a second exploration seat.
Debt is scoped to a frozen causal-baseline epoch: a behavior-deepening
composition may receive at most three downstream single-axis experiments,
while a ten-axis ceiling bounds each professional strategy/tactic island. On
island exhaustion the compiler rotates to the least-studied terminal island;
it does not keep polishing one Break/Retest threshold while Trend Pullback,
Liquidity Reversal or Range/Session knowledge remains untouched. The same
axis may receive at most two epochs for one failure diagnosis, preventing a
behavior-changing but economically inert threshold from consuming the whole
island budget.

`REGISTERED_EDGE_PACKETS_EXHAUSTED` is not a terminal Director state in v2.
After fixed repairs end, the Director must either return
`EDGE_HYPOTHESIS_COMPILED`, report an active settlement, or expose the exact
compiler admission reason as `EDGE_HYPOTHESIS_COMPILER_BLOCKED`. Only true
professional-island exhaustion is reported as
`EDGE_HYPOTHESIS_ISLANDS_EXHAUSTED`; operators must not treat either result as
a scheduler/hash failure.

Compiled settlement is control-relative and uses both canonical event/signal
hashes and full-fold observability counts. A parameter that changes only
off-audit-fold opportunities is therefore recorded as behavior-changing, but
it earns no Edge, parent or baseline authority without deeper executable-stage
or after-cost progress. When treatment and control are otherwise equivalent,
the exact professional control remains the next causal baseline.
Human-readable model names are deterministically bounded to the
production 96-character column while the packet hash and arm remain visible,
so identity truncation cannot roll back an otherwise valid cohort.

For runtime changes use `node scripts/pm2-sync-runtime.mjs`. It refuses a
rolling reload while a reserved worker job or active generation exists. On a
cold boot, start Redis with `scripts/start-redis.ps1`; never delete a stranded
payload. Requeue only the exact reservation after idle evaluator and dead-owner
proof, then run the normal PM2 sync after the reserved count reaches zero.

### Unattended generation acceptance

After a generation reaches a terminal state, run:

    php artisan trading:audit-autonomous-generation XAUUSD --timeframe=H1 --generation=N --strict

The command is read-only. It accepts scientific rejection and a zero-pass
generation, but fails for an incomplete 20-seat population, any technical
run/event/agent or blocked lifecycle cycle, missing immutable replay evidence,
broken candidate/control session parity, incomplete cooperative or causal
settlement, instrument credit without a consumed runtime activation, leaked
context authority, or an arbiter child that never reached a terminal status.
Use `--json` for a lightweight monitor. A correct uncertainty guard is sealed
locally as `WAIT`, creates immutable policy evidence, consumes no replay, and
is exempt only from replay-specific artifacts. Do not declare a cohort safe
for unattended monitoring until this command returns zero with
`unattended_ready=true` on a fresh post-change generation.

## Backup and restore drill

- `php artisan ops:backup-database` creates an atomic SQL file and full SHA-256 manifest only under `DATABASE_BACKUP_PATH` (default `G:/NeuroTrader/backups`). It refuses C: and fails loudly if G: is unavailable or unwritable.
- The application scheduler runs this command daily at `DATABASE_BACKUP_SCHEDULE_TIME` (default `02:30`). `DATABASE_BACKUP_RETENTION=3` keeps the newest three SQL/manifest pairs and prunes older pairs after a successful backup.
- Copy backups to an encrypted off-host location; a local G: backup is not disaster recovery.
- Test quarterly in an isolated database: `php artisan ops:restore-database path/to/file.sql --confirm=RESTORE`.
- Restore refuses missing/mismatched manifests. Never point a restore drill at the production database.
- `php artisan system:health-check --strict` verifies the newest G: manifest/size and backup age; set `DATABASE_BACKUP_VERIFY_HASH_ON_HEALTH=true` only for an explicit deep integrity audit because production dumps are multi-gigabyte.

## Execution stages

Paper execution is permanently simulated in the current codebase. Live execution is intentionally not implemented. Its safety configuration defaults are `LIVE_TRADING_ENABLED=false`, `LIVE_KILL_SWITCH_ENGAGED=true`, and zero capital; changing those values alone does not add a live execution path. Any future external execution adapter requires a separate reviewed implementation, explicit human approval, a small capital limit, and a tested kill-switch.
