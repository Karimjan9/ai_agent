# Operations states

`WAIT_DATASET_CONTINUITY -> bounded Academy preparation` may occur only when
a ready proposal explicitly names a verified clean discovery bundle. Parent
full-archive continuity remains blocked. A spent/not-ready slice cannot authorize
a generic full successor; the arbiter rechecks the original bad-source fence
(ADR-024).

A terminal, drained original clean-discovery cohort may receive one typed
unobserved MTF validator replacement from the existing Academy owner/arbiter.
Fresh full/Python source and exact original twenty vectors/data/cost are
required. Observed science, missing arm proof, a previous replacement or any
further replacement chain is rejected; old technical history stays sealed
(ADR-025).
The shared technical classifier re-attests the original cohort through its
Academy owner before treating the refusal as terminal diagnostic history.
It does not repeatedly lease the old validator failure as transient replay
debt, mutate old work items or grant the replacement itself any authority.

Academy draft publication is a two-step arbiter transition: prepare the trial's
durable draft intent, then dispatch that same generation canonically. A crash
before queue publication may fence `selected/dispatched -> publication_failed`
and create at most two transport retry decisions. A command execution failure
or semantic refusal remains failed/deferred and is not an undelivered outbox.
The original trial, generation and scientific receipt are not recreated.

Explicit `trading:admit-academy-experiment --contain-preparation` is a technical
operator action, not admission. A paused/pausing exact Academy timeout with
original sealed request/checkpoint and zero scientific output commits its
approved disposition before scoped batch cancellation. It remains draining
while active runs or generation-owned jobs exist; `--finalize-preparation`
requires both drained and terminalizes untouched agents without altering
original timeout receipts. The shared classifier revalidates that chain before
removing old preparation-only recovery debt. Fresh repair still needs the
single arbiter and its separately bounded unchanged-question allowance.

| From | Trigger | To | Guard / owner | Failure or recovery |
| --- | --- | --- | --- | --- |
| terminal lineage with no valid champion | archive-first policy and no higher-priority ready work | historical_research construction | Arbiter selects one bounded writer; constructor validates actual pre-2026 archive and ordinary admission | No 24-live-H1 wait; missing archive remains a dependency; source changes do not rewrite prior evidence. |
| historical writer deferred on archive dependency | archive bytes/mtime/manifest watermark changes | eligible arbiter reselection | No timer-only retries and no independence claim | Full archive validation and normal snapshot/control guards still apply. |
| pre-champion archive exploration ready | eligible powered MTF source exists | bounded powered-prior validation | Actual immutable source ID owns the decision watermark, with bounded cadence | Timer-only standalone maintenance cannot outrank historical exploration; no eligible source means historical construction. |
| healthy | health probe detects stale/missing dependency evidence | degraded | Runtime monitor records reason and scope | Do not infer success from a partial probe. |
| degraded | known recoverable condition is confirmed | recovering | Runbook/command owns bounded action | Preserve the original alert and operation receipt. |
| recovering | strict verification succeeds | healthy | Health command validates actual dependencies | Clear only the recovered condition. |
| recovering | verification fails or times out | degraded or failed | Explicit retry/backoff/runbook policy applies | Escalate; do not report green. |
| any | intentional policy pause/freeze | policy_paused | Configuration/policy owner is explicit | Report the pause as policy state, not hidden failure. |
| running | operator `ai:pause` | pausing, then paused | Durable `AutonomousModeService` control; arbiter and queued scheduler research children fence new actions | Current generation, queued evaluation and immutable receipts stay intact; already-running bounded children may finish. |
| paused | operator `ai:resume` | running | Existing lineage is reconciled before successor selection; control revision changes the arbiter key | No generation is created by RESUME itself. |
| paused or running | operator `ai:stop` | draining then stopped | New admission denied; admitted work may finish | STOP is distinct from PAUSE and retains evidence. |
| active generation, unchanged after completed settlement child | five-minute retry window | bounded settlement retry, then safety_halt | At most two retries for the same operational state hash | A third unchanged completion persists `UNCHANGED_GENERATION_AFTER_BOUNDED_SETTLEMENT_RETRIES`; no per-minute redispatch or automatic resume. |
| active research generation | causal arm reaches terminal technical disposition | causal settlement, then arbiter reselection | `ResearchLoopArbiterService` gives the dedicated disposition precedence over generic lifecycle settlement | Quarantine without authority; do not repeat a no-op active-generation tick. |
| terminal generation with actionable technical debt | arbiter evaluates the lineage head | bounded technical recovery, then arbiter reselection | The same learning-velocity authority used by generation admission must report `blocked_technical_recovery`; the lifecycle owner uses the one-shot frozen snapshot repair | Drift/new-data construction waits; a blocked exit-zero builder is `safety_blocked`, never a completed successor. |
| terminal pre-execution draft identity quarantine | original dispatcher event and matching generation attestation, with no run or response artifact | terminal diagnostic history | Shared technical classifier excludes this immutable construction failure from evaluator recovery | No replay or quality credit; a new attempt still requires its separate bounded admission policy. |
| terminal native historical candle-gap failure | original sealed model/request and zero-scientific-output response agree | immutable blocked-data dependency | Shared classifier withholds strategy judgment and closes transient retry debt | Identical actual M5 SHA remains fenced before fresh discovery and queue admission; source/hash-label changes are not provider repair. |
| fresh discovery would reuse known-bad M5 bytes | no current verified prospective data repair | WAIT_DATASET_CONTINUITY | Arbiter retains admitted closure/recovery/earned work priority; native source dependency owns dedup | No twenty-seat construction, same-input replay, fourth original cold attempt or fabricated independent evidence. |
| deferred successor decision | prior frozen replay hash is proven unrecoverable | fresh arbiter selection | A terminal technical-recovery receipt changes the operational state hash without rewriting the generation | No replay, strategy verdict or promotion credit is fabricated. |
| queued sealed replay | immutable source, dataset or execution identity differs before run creation | technical_quarantine | Queue evidence middleware emits one idempotent pre-execution refusal run; generation-owned open learning pairs/dispatches close without evaluator request | No retry storm, scientific verdict, release reseal or promotion credit. A merely stale worker boot hash remains a separate reload condition. |
| user logon with managed runtime absent | autonomous runtime task starts | recovering | Hidden supervisor owns a per-user mutex and restores only missing dependencies/lanes | Duplicate launch exits; strict runtime health remains the acceptance check. |

Deployment provenance has its own guarded sequence: pause admission, drain existing bounded work,
gracefully restart workers and the idle API, verify boot-source health, then
resume the existing lineage. Source/cost/dataset drift after a prospective
release seal is terminal technical failure, not an automatic retry under a
different program. A healthy API source projection is not a replay receipt.

An explicit source-artifact build follows finalized source/tests before the
next new seal: collect actual allowlisted bytes, reject source/path drift,
publish immutable content-addressed archive and verify it. An existing archive
may remain valid historical bytes after current source changes, but its current
pointer cannot certify the changed program. Missing/invalid archive evidence is
reported separately from worker attestation and scientific outcomes; neither
archive building nor verification changes an old generation seal or Git state.

Runtime probes, command exit codes, configuration and runbooks are authoritative
for the exact labels. This table prevents a state change from being implemented
without its detection and recovery counterpart.

An unchanged arbiter state moves to `duplicate_suppressed`: generation,
terminal-agent, open-run, downstream laboratory queue and settlement watermarks
must change before a new child is emitted. The scheduler-constructor delivery
itself is not a state change. A clean terminal generation plus its arbiter-created
successor moves to `autonomy_receipt_sealed`; otherwise the missing receipt and
reason remain observable.
An in-flight `selected`/`dispatched`/`running` decision suppresses a competing
child only when its matching `RunScheduledArtisanCommandJob` unique cache lock is
still held and its lease window is current. If the lock is absent, the orphaned
decision is fenced as `failed` with `RESEARCH_DECISION_UNIQUE_QUEUE_LOCK_MISSING`
and the current arbiter snapshot may be selected again. A database status alone
is not proof of live queue ownership; old rows outside the unique lease window
cannot block successor selection.
For a zero-pass final report that requests an audit, select the lifecycle owner
only when the report is final, technical completion is 100%, and pipeline
failures are zero. The report/audit disposition participates in the generation
gate watermark so an exit-zero deferred audit can be reselected once. Fresh-data
retry state changes only after the 24-H1/96-M15 candle admission window is met
on the live/new-data lane. Before a valid champion, the archive-first policy
selects historical exploration instead; it is independent of that live watermark.
The strict technical-integrity guard includes causal fold receipts with a
retry-ready/error state or more than one execution attempt. A later successful
fold retry does not erase that generation's historical technical failure.
