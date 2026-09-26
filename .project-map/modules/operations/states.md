# Operations states

| From | Trigger | To | Guard / owner | Failure or recovery |
| --- | --- | --- | --- | --- |
| healthy | health probe detects stale/missing dependency evidence | degraded | Runtime monitor records reason and scope | Do not infer success from a partial probe. |
| degraded | known recoverable condition is confirmed | recovering | Runbook/command owns bounded action | Preserve the original alert and operation receipt. |
| recovering | strict verification succeeds | healthy | Health command validates actual dependencies | Clear only the recovered condition. |
| recovering | verification fails or times out | degraded or failed | Explicit retry/backoff/runbook policy applies | Escalate; do not report green. |
| any | intentional policy pause/freeze | policy_paused | Configuration/policy owner is explicit | Report the pause as policy state, not hidden failure. |
| active research generation | causal arm reaches terminal technical disposition | causal settlement, then arbiter reselection | `ResearchLoopArbiterService` gives the dedicated disposition precedence over generic lifecycle settlement | Quarantine without authority; do not repeat a no-op active-generation tick. |
| terminal generation with actionable technical debt | arbiter evaluates the lineage head | bounded technical recovery, then arbiter reselection | The same learning-velocity authority used by generation admission must report `blocked_technical_recovery`; the lifecycle owner uses the one-shot frozen snapshot repair | Drift/new-data construction waits; a blocked exit-zero builder is `safety_blocked`, never a completed successor. |
| deferred successor decision | prior frozen replay hash is proven unrecoverable | fresh arbiter selection | A terminal technical-recovery receipt changes the operational state hash without rewriting the generation | No replay, strategy verdict or promotion credit is fabricated. |
| user logon with managed runtime absent | autonomous runtime task starts | recovering | Hidden supervisor owns a per-user mutex and restores only missing dependencies/lanes | Duplicate launch exits; strict runtime health remains the acceptance check. |

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
retry state changes only after the 24-H1/96-M15 candle admission window is met.
The strict technical-integrity guard includes causal fold receipts with a
retry-ready/error state or more than one execution attempt. A later successful
fold retry does not erase that generation's historical technical failure.
