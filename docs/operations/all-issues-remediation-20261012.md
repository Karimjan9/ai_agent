# Audit remediation — 12 October 2026

This change fixes the executable time contract, permanent paper boundary,
dependency handling, vulnerable dependency locks and expensive provenance
inventory. It does not issue unsupported scientific authority. Original G263
responses, release seals, question budgets and outcomes are preserved.

## Contract and evidence

- New independent questions explicitly select `historical_first_exposure_v2`.
  Registration uses today's real clock before first model/request exposure;
  historical market events must finish before `2026-01-01T00:00:00Z`. V1
  registrations retain their original clock rules and hashes. See ADR-038.
- Registration is not certification. New ingress cannot attest previous
  training, selection, MTF context, warmup and holding exposure. Missing original
  non-exposure proof blocks issuance with
  `HISTORICAL_FIRST_EXPOSURE_PROVENANCE_NOT_ATTESTED`. An empty inventory,
  manifest, caller label or new download cannot grant that proof.
- Every event from 2026 onward, including 2027 and all later years, remains
  paper-only. The old 2027 research escape was removed from the original quote
  calendar, council plan admission and follow-up data policy. Rescue snapshots
  and actual holdout CSV events must also stay before 2026. `ready`/`sealed`
  labels cannot substitute for an original file and its event clock.
- No production certificate or paper-eligible strategy was manufactured.
  The original observed certificate count is zero. A real earned paper life
  cycle still requires independent evidence; synthetic tests prove mechanics.

## Runtime, security and cost

- Only the exact native Dukascopy `DUKASCOPY_NATIVE_HTTP_202_UNRESOLVED`
  dependency from `market-data:update` / `market-data:sync-volume` becomes a
  deferred dependency. Authentication, malformed input, generic HTTP 202 and
  other command failures retain their failure handling. Historical failed-job
  records are retained.
- Composer locks Laravel 12.69.3, CommonMark 2.10.3 and Flysystem 3.36.0.
  The complete npm graph includes patched js-yaml 4.3.2 and related overrides;
  PM2 7.0.3 retains its public API with Chokidar 4.0.3. A real directory watcher
  event was checked. The live pm2-logrotate dependency graph was also patched.
- Python uses an isolated environment and the actual
  `requirements.lock.txt`, with FastAPI 0.143.0 and Starlette 1.7.0. The lock is
  included in Laravel/Python source fingerprints. CI audits Composer, the
  complete npm graph and the pinned Python graph and runs all pytest tests.
- Composer, npm, Python and pm2-logrotate audits reported zero known
  vulnerabilities on the checked locks. This is a dependency audit result.
- Provenance request references use one decode per row at a fixed watermark,
  in bounded 32-row chunks. For the same first 100 runs, old/new summaries and
  their hashes matched: 1.7291886 s versus 0.0416968 s. All 6,432 original runs
  completed in 10.1299136 s with 60,817,408 bytes peak memory. This inventory
  exposes missing history; it does not prove unused validation data.
- `LabPopulationService` is 506 lines. Its 159 extracted methods live in
  seven composed traits, the largest 3,374 lines. During that split, all 169
  remaining method signatures, comments and bodies were checked byte-for-byte,
  and the reflected API and
  constants matched. Admission ownership and state stay in the same class.
  The large agent factory remains a maintenance cost; this is physical
  decomposition, not a claim of complete architectural simplification.
  Root portfolio calculation is separately extracted into
  `RootExperimentPortfolioPlannerService`.
- Short ADX/ATR histories now remain unknown warmup instead of indexing beyond
  the frame. Mature calculations retain the original indicator results.

## One bounded original question

Question: did the twenty original G263 screening responses record any executed
trade? All twenty immutable response artifacts and their original response
hashes were verified. All twenty record zero trades and empty trade ledgers.
The answer is zero recorded executions, with an inconclusive low-power
economic result. It establishes neither profitability, a negative strategy
effect, full validation nor useful inheritance.

The same original responses contain 296,000 signal decisions. They record
274,787 decisions without a strategy signal, and 21,213 raw strategy signals
before tactic evaluation. All 21,213 were withheld at the scope boundary:
19,575 `venue_phase_outside_scope`, 875 `regime_outside_scope`, 390
`volatility_outside_scope` and 373 `liquidity_observation_missing`. Tactic
evaluations, setup stages and accepted entries are zero. This answers where
the recorded pipeline stopped; it does not establish that a veto was wrong
or that removing a veto would improve results. No gate was relaxed to force
executions. See the locally retained `g263-diagnostics-summary.json`.

G263 is generation ID 357, status `screened`, with 20/20 original screening
runs completed (database IDs 6413–6432). The original seven-field run facts hash
is `110576ec4b8f8703cb3715add909d851e5b2cc5cc0964c0c37d8081cd01bf58f`.
No replay, original budget reset, seal replacement or evidence rewrite was
performed for this observation.

## Verification and maintenance

- Python: 598 tests and 36 subtests passed; two existing Pandas future warnings.
- Node: 39 tests passed, including 25 Windows/PM2 checks.
- Frontend build passed. Authenticated browser checks passed for all 37 GET
  routes, including ten detail routes, using a separate SQLite database and
  synthetic records. They grant no market authority.
- Laravel final focused verification: 190 tests / 1,057 assertions passed,
  including all six failure/error cases found during the broad run, backup
  retention, reload guards, scheduler isolation and original source drift.
  The initial 2,512-test run was interrupted after its 2,135 progress marker;
  it had two failures. The remaining segment first passed 126 cases before a
  synthetic 15,000-bar fixture exhausted memory by retaining duplicate full
  payloads. Keeping only the arm identities and counts required by its
  assertions fixed that fixture; its real native replay then passed. The next
  253-case segment exposed four additional stale-fixture errors/failures.
  All six cases now pass in the final focused run. A single clean complete
  Laravel suite run is not claimed. Failed and interrupted receipts remain.
- Final Project Map impact, generation and freshness check passed, with no
  unmapped changed source/test paths. Main-runtime publication is verified
  separately below.
- The new complete database backup restored successfully: all 17,868,252,544
  raw bytes matched their SHA, the native client exited zero, all 348 tables
  matched the current source inventory, and the original twenty G263 records
  and still-live source facts matched exactly. The temporary database and
  restricted account were removed.

Validation artifacts are retained locally under
`.runtime/all-issues-remediation-20261011/.runtime/`, including
`pytest-final-frozen.xml`, `phpunit-stall-restart.json`,
`phpunit-tail.xml`, `phpunit-final-verification.xml`, `browser-audit-all.json`,
`history-benchmark.json`, `population-decomposition.json`,
`g263-original-question.json`, `fresh-backup.json` and
`restore-drill-fresh-backup.json`.

The original pre-release backup contains 346 tables. The current schema has
348: `scoped_research_certificates` and `research_exposure_capture_records`
were added after that snapshot, and both currently have zero rows. The first
drill restored every original SQL byte and matched G263 but correctly refused
the strict current inventory check. The second drill failed because MySQL
collation ordering differed from Python's ordinal ordering; the 346 native
table names match as a set. All inventories now use the same ordinal sort
before strict comparison. Failed-drill receipts are preserved.

A fresh compressed backup was created on 12 October, with its complete gzip
CRC, raw stream SHA and compressed SHA verified. Its compressed SHA is
`526cd810c29d8f46eaa5c79bb822b2fd2ad5a9cb4074716bc6c9058bb6919eeb`;
its raw SHA is
`e3f9fd826e05deb5632fdfb6ab280b04d71a390d41886fbbcf691419424ba791`.
The new backup's isolated restore passed the complete 348-table check and
matched the exact original twenty G263 records. Its complete compressed
SHA was independently checked again before the old SQL snapshot and manifest
were deleted at 10:42:38 UTC to preserve disk reserve for the remaining restore.
One verified database snapshot remains. Full restoration finished at 10:57:40 UTC.
The operator's retention setting is now one. This is a SQL database restore
exercise; it does not attest restoration of external evidence files.

The scratch server was shut down, its database/account were removed, and its
private credentials file was deleted. Automatic approval review rejected
recursive deletion of the empty project-restore directory on G:, without a
detailed reason. The stopped scratch server's system directory remains; it
contains no restored project database and is not another project DB snapshot.

Maintenance uses drain-first STOP, scheduler suspension, terminal/empty durable
preflight and two authenticated idle replay probes. After drain, Laravel's
transport queue pause prevents another dequeue without cancelling any payload;
only maintenance-owned pause flags are resumed. These semantics are also
documented in the [Laravel queue documentation](https://laravel.com/framework/docs/12.x/queues#pausing-and-resuming-queue-workers).

During an attempted PM2 smoke check, Windows PM2 used the global named pipe
despite a separate PM2_HOME. The check stopped the main daemon. This was an
operator mistake. The canonical background processes were recovered; two
original operational reservations were returned atomically to their queues
with their exact original payloads after boot/idle checks. G263 scientific work
was not replayed. PM2_HOME is not used as Windows daemon isolation again.

The existing `mysql.db` privilege index was repaired with `REPAIR ... QUICK`
after original file backups. CHECK returned OK and all four original privilege
rows and their values matched. Storage header bytes changed; byte preservation
is not claimed.

## Main runtime publication

Implementation commit `35d1eb7ec2ab0df7b0b2cc41a3de9710646d2fa8` was
fast-forwarded into the main workspace after a fresh durable idle preflight,
two authenticated idle Python probes, live STOP/transport-pause checks and
verification that PM2 managed only this project's processes and log rotation.
The three existing user edits were preserved byte-for-byte. The known daemon
was stopped before publication; the source MySQL server remained running.

Main Composer and npm dependencies were installed from their updated locks;
the main frontend build passed. Consumers started first with the private GUI
PHP and Python executables. All twelve actual queue-worker PID boot receipts
match the main PHP runtime hash
`d5f53779f3e151d8f3f24311f9a2657ad5f1968675990e7f5b85f7e3f4ea02ba`.
The running Python service's disk and boot hashes both match
`acc68cae6e0275c32c4dda6b6c3a7700d1828bad965b9bc245186b2fa167428e`.
These are actual main-workspace hashes; Git's normal line-ending conversion
means they are not reused from the isolated worktree.

Only the fourteen maintenance-owned transport pauses were resumed. Autonomous
control is RUNNING with its original `lightweight_monitor` profile. The
scheduler started last and PM2 saved the fifteen-process online topology:
twelve queue workers, the AI service, scheduler and log rotation. The separate
backup worker is online and the effective database retention is one. A desktop
inspection of the exact daemon PID/start identity found zero visible owned
console windows. This is an observed check, not a guarantee against every
possible future external process.

Final loaded-worker and authenticated Python checks passed after resume, and
all original twenty G263 facts still match the original hash. Certificate
count remains zero. This publishes software fixes and restores background
services; it creates no new scientific or economic authority. Main workspace
Project Map freshness also passed. Local receipts include
`cold-stop-before.json`, `loaded-workers-final.json`, `loaded-python-final.json`,
`control-final.json` and `runtime-final-save.json`.

An additional audit of the actual main Python environment found twelve advisory
records in its bootstrap `pip 24.3.1` installer (some IDs were duplicated),
although the thirty-package application lock audit had passed. Both private
environments now use `pip 26.2`, a published release in the
[official pip changelog](https://pip.pypa.io/en/stable/news/). The development
requirements pin the installer and CI audits the whole installed environment
as well as the application lock, so bootstrap packages are covered too.
Both final full-environment audits passed with zero known vulnerabilities;
their receipts are `python-security-main-final.json` and
`python-security-all-tools-final.json`. Updating this installer changes no
application source or market evidence and required no second runtime restart.

## Remaining original-data dependencies

The verified native M5 foundation still has seventeen unresolved source gaps:
fifteen lack original ticks, and two have M1/raw-tick price conflicts. The
verified repair adds 237 rows, yielding 200,237 verified rows. The mixed
200,254-row discovery file cannot acquire native economic authority. There is
no interpolation, fabricated candle, calendar exception or promotion override.

Complete original historical exposure provenance and an eligible untouched
pre-2026 validation window remain unavailable. These are evidence dependencies
that a clock change, faster query or software patch cannot truthfully close.
Full validation, economic authority and useful inheritance remain blocked
until their original proof exists.
