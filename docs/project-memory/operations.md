---
aliases:
  - Operations and Validation
tags:
  - operations
  - testing
updated: 2026-08-10
---

# Operations and Validation

## Service locations

- Laravel application: `backend-laravel/`
- Python service: `ai-service-python/`
- Historical datasets: `datasets/`

## Focused validation

Run tests from the component that changed:

```powershell
Set-Location ai-service-python
python -m unittest discover -s tests

Set-Location ../backend-laravel
$env:APP_ENV='testing'; $env:DB_CONNECTION='sqlite'; $env:DB_DATABASE=':memory:'
$env:APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='
$env:QUEUE_CONNECTION='sync'; $env:APP_CONFIG_CACHE=(Join-Path (Get-Location) 'bootstrap/cache/config.testing.php')
php vendor/bin/phpunit --configuration phpunit.xml --filter=<RelevantTestName>
```

Use `php artisan test` only when broad regression coverage is warranted. API/service field expectations are documented in `docs/ai-service-contract.md`.

For operational probes, use the strict variants so an external monitor receives a non-zero exit code:

```powershell
php artisan system:health-check --strict
php artisan market:health --strict
```

The system health probe also verifies that an active administrator exists, that the latest G: SQL backup has a matching manifest and is within `DATABASE_BACKUP_STALE_AFTER_SECONDS` (default 172,800 seconds), and that the lab lifecycle has no stalled queue/boundary state. Refresh it with `php artisan ops:backup-database` before investigating an operational alert. The command refuses C: and retains only `DATABASE_BACKUP_RETENTION` (default 3) newest backup pairs.

The scheduler writes a ten-minute liveness heartbeat after a successful minute tick. A registered schedule without a fresh heartbeat is warning/critical evidence, not a healthy scheduler claim.

PM2 is launched through scripts/pm2-clean-env.mjs so OpenAI, Codex, and
inline internal-token environment prefixes are removed before daemon metadata
is persisted. If an
older daemon was started with credentials, rebuild it only after the heavy
replay lane is drained, then persist the clean PM2 state.

The Laravel app requires a fresh `Idempotency-Key` per normalized backtest payload. Reusing a key with different dates, symbol, strategy or risk settings returns HTTP 409.

Market Reality is enabled independently with `MARKET_REALITY_ENABLED=true` by default. Its analysis freshness default is 7,200 seconds because H1 candles can be 60-120 minutes old while the canonical feed remains healthy; `market:health` remains the feed outage probe.

Reality Verification is intentionally frozen while `SECONDARY_INTELLIGENCE_ENABLED=false`; `reality_loop=ok` with `policy_state=frozen_by_p0` is an explicit policy state, not a missing-run failure. Canonical Market Reality remains active independently.

Historical quality and live continuity share the canonical market-session calendar, including Sunday reopen and provider holiday closures, so a scheduled closure cannot remain stuck as a feed recovery alert.

Full replay uses a generation-frozen canonical rolling snapshot plus a separate,
hash-verified pre-2026 foundation archive. The archive is research-only and
never promotion evidence; XAU's first Sunday session may validly begin at
23:00 UTC on 2005-01-02 under the one-day market-open tolerance.

The heavy replay lane is keyed by LAB_REPLAY_MUTEX_KEY and the same key is
used by queue middleware, direct portfolio replay, and stale recovery. A
foundation archive at or above the configured runtime budget uses a
full_replay_runtime_budget_v1 bounded cohort (default maximum two candidates).
The runtime cap is recorded as operational metadata with
promotion_evidence=false; it cannot make a candidate promotion-eligible.
After an interruption, stale recovery requires an idle AI service, no replay
child, and a reservation older than the full-replay threshold.

Tests force `APP_ENV=testing`, `DB_CONNECTION=sqlite`, and an in-memory database. `tests/TestCase.php` refuses to enter `RefreshDatabase` if the resolved application is not on SQLite; never remove this guard.

Final verification on 2026-08-10: Python `unittest` 83 passed; focused Laravel evidence/lifecycle tests passed; Composer/npm audits were clean, and the portable Node 22 Vite build passed. Full replay now includes separate rolling/foundation coverage, real file plus manifest hashes, a bounded runtime policy, and shared mutex recovery.

## Update checklist

- New route/controller/service: update [[modules]] and `project-index.json`.
- New externally visible Python endpoint: update [[architecture]], `project-index.json`, and the API contract.
- New migration/model: add the affected module's persistence note.
- Finished implementation: record the date and test command/result in the relevant module note or `change_log`.
 2026-08-10: Python `unittest` 83 passed; focused Laravel evidence/lifecycle tests passed; dependency audits clean; portable Node 22 Vite build passed. Market Reality was decoupled from frozen secondary intelligence, its H1 freshness threshold was aligned with feed cadence, the suite gained a production-DB safety guard, live continuity was aligned with the canonical session calendar, and full replay now monitors separate rolling/foundation dataset coverage with the same XAU session tolerance in PHP and Python. The operational health model includes active-admin access, verified database-backup freshness, policy-aware reality-loop state, evidence-preserving lab-pipeline lifecycle monitoring, bounded full-replay budgets, and shared mutex recovery.

## Scope warning

The repository currently has many uncommitted implementation changes. Before modifying code, inspect the target file's diff and preserve unrelated work.
