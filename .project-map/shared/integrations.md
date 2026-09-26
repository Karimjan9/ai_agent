# Integrations and runtime boundaries

## Laravel ↔ FastAPI

Laravel calls the local FastAPI service for backtest, replay, paper signal and
execution-contract calculations. The exact request/response authority is
`docs/ai-service-contract.md`. The generated integrations index locates known
HTTP endpoints and static client call sites; inspect source for environment URL,
timeouts, retries and error behavior.

Research replay requests also carry sealed specialist context plus one aggregate
composition execution authority binding component identities, instrument
assignment, dataset/MTF bundle, execution assumptions, symbol and timeframe.
Laravel derives assignment source components from the frozen passport and
invalidates a cached assignment if any source identity changes. Python compares
all four source components and selected keys against the sealed contract before
replay, even when the assignment's own hash is otherwise valid.
Python compiles the frozen typed nodes into a versioned executable DAG and
rejects invalid ports, unsatisfied edges or an empty strategy/tactic activation
scope before replay. The v2 graph is explicit in the sealed request; legacy v1
passports are not silently upgraded. Its tactic and typed-entry pipeline own
the final pre-risk
signal and its management adapter owns the open-position lifecycle. Python
returns a v3 trace with gate-emitted, hash-bound per-node receipts and every per-opportunity
decision ID/reason; only the inspection sample is bounded. Laravel refuses
composition credit unless the compiled program and runtime receipts are valid,
every required node was actually reached, and one decision-ID witness spans
MTF permission, risk, fill and a completed managed trade.
For a preregistered four-arm activation probe, the Python receipt also carries
a candle-derived `paired_context_id` distinct from its arm-specific
`decision_id`, a shared opportunity-universe hash and explicit no-signal count.
Laravel requires the four immutable responses, identical MTF/data/execution
identity and exact C/A/B/A+B parameter deltas before recording a science-only
conversion receipt. This boundary does not grant economic or live authority.
Execution cost/stress diagnostics derive their own child composition binding
after canonical validation. Their altered execution hash is accepted only in
that diagnostic lane and never re-seals the parent or grants promotion credit.
Exact specialist venue phases are normalized from the replay decision timestamp
on the Python side and compared with the Laravel-declared context contract.
The same boundary canonicalizes volatility labels before instrument activation
and attribution. Laravel does not promote a portfolio lane's diagnostic
transition-homework label into a live instrument predicate; an explicit sealed
specialist/capsule declaration is still enforced.

Ordinary XAUUSD generation dispatch freezes an M5 execution stream and H4/H1/M15
context streams as one content-addressed manifest. Laravel admission, screening
and full replay preserve that manifest; Python verifies all declared paths and
SHA-256 values before compiling the closed-candle MTF stack. H1 remains only the
laboratory/storage identity for this organism. Volume-dependent replay receives
explicit `volume_available` markers in the frozen historical M5/H4/H1/M15 files
plus a provenance receipt bound to their content hashes. Laravel derives
`volume_context` from that manifest. The rolling/live volume audit is operational
evidence for its own time range and cannot attest pre-2026 history. A price-only
lane crosses this boundary as `not_requested`, not `volume_unavailable`.
The autonomous lifecycle does not own a second queue shortcut: it resumes the
same canonical generation dispatcher, and pauses fail-closed unless that
dispatcher has moved the generation through snapshot/MTF admission.

Autonomous causal confirmation crosses this boundary one fold at a time through
`/api/backtest/run-all`; each request carries all three arms and one global fold
index. Laravel owns retry/checkpoint durability in `causal_fold_receipts`.
One three-arm fold has its own bounded 720-900s Python child, 960s Laravel
transport and 1020s queue-job default; this is not the nine-fold monolithic
request. Queued jobs refresh their operational timeout when deserialized, so
an older serialized 360s cap cannot interrupt the updated child budget. A
stale lower Python timeout setting cannot undercut the declared three-arm
per-fold budget.
After the complete fold set is sealed, Laravel calls
`/api/backtest/aggregate-causal-folds`; Python validates identities, independent
windows and complete ledgers without re-reading market data. The aggregate is
then projected through the ordinary immutable evidence boundary.
If the triplet becomes terminal before all three immutable screening receipts
exist, Laravel invalidates it before any FastAPI fold call and grants no partial
causal credit.

The scheduler wrapper treats Laravel command transport success separately from
the lifecycle JSON outcome: `running`, `paused`, `blocked` and `deferred` remain deferred
research-loop decisions even when Artisan exits with code zero. Only an
achieved lifecycle transition may close successor/autonomy evidence.

## Research and paper epoch boundary

Historical discovery, mutation, screening, repair and causal confirmation end
before `2026-01-01T00:00:00Z`. The frozen 2026 block is prospective paper-only:
it may support forward/performance authority but cannot update research
selection, mutation or instrument posteriors.

## Market data providers

Laravel owns provider selection, canonical-data continuity and recovery state.
Twelve Data is the current canonical promotion-evidence provider; Dukascopy is
secondary archive/discrepancy evidence. Read
`docs/project-memory/market-data-continuity.md` before changing that boundary.

## Queue and scheduler

Laravel commands, jobs and queue lanes drive long-running laboratory and
operations work. Queue dispatch and event listeners are dynamic discovery
boundaries, so generated indexes cannot prove their complete graph.
