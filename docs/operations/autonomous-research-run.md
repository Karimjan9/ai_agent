# Autonomous research run

`ai:start` enables the durable XAUUSD research lineage. The scheduler invokes
`trading:run-research-loop` every minute; only the arbiter selects new research
actions. The Windows logon task `NeuroTrader Autonomous Runtime` keeps the
runtime available. It checks `ai:runtime-gate` before restoring missing
processes, and the launcher is duplicate-safe. If PM2 already owns project
workers, the fallback does not create a second queue consumer while PM2
recycles a lane.

Use `php artisan ai:pause --json` for an intentional break. PAUSE is a graceful
admission fence: it preserves the active generation and checkpoints and stops
new arbiter/scheduler research actions. A child already executing can finish
its bounded attempt. While such a child is still observed, status is
`pausing`; it becomes `paused` after the bounded child leaves. Do not interpret
that completion as a pause failure.
While paused, a fresh Windows logon does not start missing runtime processes.
`php artisan ai:status --json` shows the persisted control and current
generation. `php artisan ai:resume --json` restores admission for **that same
generation**; it does not create a generation directly. `ai:stop` is different:
it terminates new research admission and lets admitted work drain.

After restart or resume, inspect `ai:status --json`, then
`trading:run-research-loop --dry-run --json`. The arbiter should first settle
the active generation. A completed lifecycle command alone is not proof of
progress: verify the generation state, queue watermark and latest arbiter
decision. For an unchanged settlement state, at most two spaced retries are
allowed; `UNCHANGED_GENERATION_AFTER_BOUNDED_SETTLEMENT_RETRIES` persists a
`safety_halt`, not a scientific verdict. Diagnose its terminal boundary rather
than manually changing agent status or rewriting immutable evidence. After
repair, an explicit `ai:start` acknowledges and clears the halt; `ai:resume`
cannot clear it.

Expired durable work leases are reconciled by the existing conversion owner
before the mutable closure guard: at most 100 per pass, with row-lock and
token/fence/expiry rechecks. Recovery preserves the original attempt, result,
question and scientific budget; fresh readiness and a new canonical claim are
still required. Live or undated leases are not released, and old queued tokens
cannot execute or publish. Read-only/dry-run closure does not perform recovery.

## Archive-first research before champion

`XAUUSD_HISTORICAL_RESEARCH_UNTIL_CHAMPION=true` (default) lets the existing
arbiter open `historical_research` after current work and higher-priority ready
learning/recovery have settled. The constructor validates the real pre-2026
foundation and does not require new live H1 bars. Admitted learning-confirmation
work uses the same archive readiness. Both dispatchers retain frozen dataset,
MTF, control, loaded-source and risk gates; only live-feed continuity is no
longer a prerequisite of this historical lane.

Inspect `trading:run-research-loop --dry-run --json` for
`OPEN_HISTORICAL_RESEARCH_GENERATION` and its archive dependency. A changed
archive watermark allows reselection after data repair, not a timer-only retry.
Missing data remains a dependency. A technically complete zero-pass report
still records its required lifecycle-owned audit before successor construction.

The admission seals `historical_research_admission`: archive hash/manifest,
cutoff `2026-01-01T00:00:00Z`, research-only status and no-independent-evidence
claim. Prequeue admission rechecks the frozen foundation hash and all four
research stream periods. Live candle counts remain separate bookkeeping, so a
later live drift lane is not compared against a historical row count.

Only a valid, non-invalidated champion ends this archive-first root policy.
This does not create a champion or relax E3 paper/E4 champion admission. The
2026 snapshot remains paper-only; reused archive data is not new independent
causal evidence. Scientific negative results continue bounded discovery,
while real technical/safety failures still require their normal recovery or
terminal disposition. Use PAUSE/STOP to end or suspend the autonomous search.

## Safe source deployment

Before champion, standalone MTF/portfolio maintenance timers do not own
exploration. Only an actually eligible powered MTF prior can precede the
archive-root writer; its immutable source ID participates in deduplication.
The historical cohort still contains MTF/instrument experiments. Current work,
technical recovery and ready causal follow-ups retain their higher priority.

For maintenance that must let the admitted generation finish, use
`php artisan ai:stop --json` while it still owns the lane. STOP denies new
work while the existing arbiter continues its admitted replay and settlement.
PAUSE blocks arbiter and queued scheduler research children, including new
settlement children; it is an intentional break, not this drain procedure.
Keep source bytes unchanged until the latest generation and any owning trial have
naturally closed, its agents are terminal, and generation-owned queues,
batches, active replays and constructor children have drained. Recheck the
latest generation ID after the fence; if a successor already acquired ownership,
defer deployment and drain that actual owner through the same arbiter.

Require `system:runtime-reload-preflight --json` to report safe idle before
recycling workers through the existing supervisor/PM2 topology. The PM2 sync
also requires two successful authenticated `/api/replay-status` probes five
seconds apart for an online AI owner. Each response must contain a nonnegative
integer `active_requests` equal to zero. HTTP/authentication, token-read,
timeout/network, JSON or count-shape failures refuse sync with a non-zero exit
before any PM2 mutation, without printing raw probe diagnostics. Durable
preflight runs again after the probes and after scheduler cadence is stopped.
An idle API response alone cannot certify that a queue owner has drained.

An observed-completion terminal-projection bug can deadlock this procedure even
when every original replay, comparison and feedback receipt is complete. The
explicit read-only cold-maintenance check is:

```powershell
php artisan system:runtime-reload-preflight --terminal-projection-recovery=ID --json
```

This is not a rolling reload override. It accepts only the sole named signed
observed-completion generation under drain-first STOP, with hash-valid original
six-arm evidence, withheld projections, terminal feedback and known-empty
reserved/pending/delayed queues. Confirm two fresh authenticated idle probes,
stop the exact existing owners, deploy a verified source artifact and verify
fresh boots. Start scheduler cadence last under STOP: the canonical boundary
appends only zero-authority episode settlements and preserves the technical
feedback in the generation projection. Verify closure and then use START.
No queue deletion, manual episode update, replay, old-result re-attestation,
second root completion, credit or independent authority is permitted. Default
`system:runtime-reload-preflight` and PM2 sync remain strict. See ADR-031.

Before a run opens, an admitted job may still be reserved by Redis after its
old worker has disappeared. `reserved_at` is then a visibility expiry, not a
claim time. The arbiter recognizes only exact owned payloads inside both that
lease and their original retry deadline; it waits for ordinary redelivery
instead of spending unchanged-settlement retries. Do not clear or force-pop
the reservation. Expired/unknown ownership returns to normal lifecycle
recovery, and the no-work safety halt remains strict.

Never kill an active replay to apply source changes. Verify actual PHP/Python
worker boot identities and `/health.research_source` reporting
`loaded_code_current=true` after recycling, then use `ai:start --json` to
restore admission to the existing lineage. `ai:resume` is for PAUSE and rejects
the stopped/draining state. START itself creates no generation.
The next unattempted generation seals its source/data/cost release before
queueing; source drift afterwards rejects admission. Old attempted generations
remain legacy-unsealed, not backfilled into clean proofs.

`php backend-laravel/scripts/audit-research-release.php --generation=N --strict`
checks Git artifact sealing and actual prospective PHP/Python run receipts
separately. Healthy source fingerprints are not a clean Git release or market
edge. Do not relabel historical receipts to make the audit pass.

After source/tests are final and admitted work is drained, explicitly build the
allowlisted reproducible source snapshot before the next new generation seal:

```powershell
php backend-laravel/scripts/audit-research-release.php --build-source-artifact
php backend-laravel/scripts/audit-research-release.php --verify-source-artifact=HASH
```

The build writes only `.runtime/research-source-artifacts/`; it does not write
DB rows, commit/push Git, reload workers or grant scientific/paper authority.
It excludes `.env`, credentials, data/storage, vendor and node_modules. Its
manifest binds actual file hashes, the exact full/Python source fingerprints,
Git HEAD/dirty provenance and selected non-secret settings/tool versions.
Matching fresh release seals reference it; old seals remain unchanged. Missing
archive evidence makes the strict generation audit incomplete even if workers
are attested. A dirty source archive can be reproducible without a clean Git
commit; follow the ordinary loaded-worker reload verification separately.

## Diagnose activation and independent-data dependencies

`python backend-laravel/scripts/diagnose-signal-boundary.py --help` describes
the read-only reconstruction of one frozen screening request. Its current-code
diagnostic is explicitly non-canonical and writes no credit. Inspect raw,
specialist-accepted and composition-accepted signals separately, plus exact
scope veto counts. Execution spread assumptions cannot replace missing observed
spread in liquidity predicates.

Monitoring and generation reports expose independent research-data readiness.
An empty authorized list or a future interval is
`awaiting_authorized_research_data`, not executable validation. Preserve the
2026 paper-only boundary. A reserved future question is not an independent
dataset; no manual credit or new-window relabeling is a recovery step.

## Immutable historical data dependencies

An original sealed `Historical data hard-gate failed: N unexpected candle gaps.`
refusal is a provider-data dependency, not a scientific strategy loss or a reason
to repeat identical bytes. Snapshot admission and the early arbiter share that
native failure guard. Fresh construction waits as `WAIT_DATASET_CONTINUITY`;
changing only source, generation or bundle labels cannot bypass it.

Use the explicit offline `backend-laravel/scripts/recover-frozen-m5-gap.php`
operation with exact frozen `--source` and `--source-sha256`. Dry-run is default.
Only agreeing real Dukascopy M5/M1/raw-tick observations may create missing M5
buckets; no synthetic empty minute is allowed. `--apply` creates a separate
content-addressed training fork, never changes the old CSV, training archive or
generation. Read full-archive and selected-screening gap counts separately.
`--batch` computes the actual canonical missing inventory (maximum 300 targets),
groups native day/hour requests and imports one fork. Collection is bounded to
1,800 seconds; unavailable, empty-tick or conflicting observations retain an
explicit residual dependency. Any residual full-archive gap blocks generic
MTF/full-fold readiness even if the latest 5,000-candle screen is clean.

Select a verified prospective dataset through `LAB_RESEARCH_M5_DATASET` only
while admission is paused and replay drained. The existing MTF data owner checks
native receipt, original/new hashes and actual stored economic-row digest.
Freeze new price-only MTF input first, use the existing bounded historical tick
quote freezer for those exact new M5 bytes, then freeze the quoted bundle.
Old quote evidence cannot be relabeled for a different price hash. Build and
verify the source archive, reload idle workers, verify actual boot identity,
then resume the existing lineage through the canonical arbiter. A new dataset
does not reopen the exhausted old Academy question or provide independent
validation, credit, paper or live authority.

`--batch --resume-dataset=DATASET` reuses only the previous recovery archive's
successful native proofs after checking original source, receipt, fork bytes
and SQL economic digest. It refetches unresolved targets and, with `--apply`,
publishes another fork. Provider HTTP failures and empty unattested tick hours
are not absence proof; the tick/quote boundaries reject them without persisting
empty checkpoints. Old recovery artifacts remain immutable.

## Explicit clean discovery while full continuity is unresolved

The existing `MultiTimeframeSnapshotService` exposes
`prospectiveCleanDiscoveryReadiness('XAUUSD', DATASET)` (read-only),
`forProspectiveCleanDiscovery('XAUUSD', DATASET)` (explicit new freeze), and
`discoveryBundleReadiness(MANIFEST)` (read-only actual-byte verification).
They accept only a verified native recovery parent and the fixed 15000 evaluated
plus 512 warmup budget. The shared calendar chooses the latest sufficient clean
segment without strategy outcomes; H4/H1/M15 context and exact selected price
bytes enter a new `prospective_clean_discovery_bundle_v1` manifest.

Select its stored bundle hash through `LAB_CLEAN_DISCOVERY_BUNDLE_HASH` only
while paused/drained, then build the source artifact and reload idle workers.
Do not replace `LAB_RESEARCH_M5_DATASET` with a short CSV or change full/fold
row requirements. The existing arbiter prepares/publishes a bounded Academy
question; eligible proof-carrying local Academy continuation may use the same
typed discovery scope. A spent/not-ready question never unlocks generic full
construction on the incomplete parent.

Single and batch screening send the sealed actual probe bounds to Python:
15000 evaluated rows, 512 excluded warmup, UTC bounds, month counts and exact
data/execution hashes. The single transport/job/mutex budgets are 1800/2100/2700
seconds, above the Python 1680-second ceiling; generic screens retain their
existing smaller limits. Full/replay mode rejects the typed discovery bundle
before cache/child execution. Missing quotes stay unavailable and cannot
justify liquidity-dependent claims. Matching quote sidecars require these
selected bytes' own SHA and a newly frozen bundle, not edits to this seal.

This is discovery, not a repaired full archive or untouched validation data.
Its cold budget binds physical parent/foundation/execution; slice, quote, label
or code changes do not renew it. It gives no independent, paper, promotion,
causal-skill or inheritance authority. Authorized unused research data remains
a separate dependency under the unchanged 2026 paper-only boundary (ADR-024).

API and native enabled-MTF feature preparation share the scope/probe guard.
The discovery protocol is accepted only after it succeeds, while all four
native stream paths/SHA and full-mode restrictions remain enforced.

For an original clean-discovery cohort rejected before science by the exact
native `AUTONOMOUS_MTF_MANIFEST_INVALID` validator, the existing materializer
may propose one `academy_unobserved_mtf_validator_replacement_v1`. First drain
and terminalize through the canonical owners under STOP, then deploy a tested
new full/Python seal. The arbiter re-attests attempted-control immutable
requests/runtime/technical responses and every never-executed dependent's
exact-control admission refusal. It preserves all twenty original vectors,
baseline/data/MTF/cost and the physical-parent budget. The canonical dispatcher
admits fresh IDs; the failed trial is never reopened. Original plus one is
the maximum, with no subsequent constructor/preparation/validator chain.
Any scientific output, missing proof or another error fails closed (ADR-025).

Council follow-up executor failures retain a separate sanitized
`specialist_council_executor_failure` SystemEvent under the original work/fence.
Use its stage, exception class, repository-relative location and message hash
for diagnosis; raw SQL, credentials, bindings and stacks are deliberately absent.
A diagnostic never resets a lease or scientific cap. An entirely unbuilt target
may use the existing verified source-only amendment after safe release; the
original resolution and dependency hold remain visible. The observed probe
completion's pristine witness has a distinct comparison-proof hash and explicit
null auxiliary proof, rather than fabricating an empty auxiliary proof.
