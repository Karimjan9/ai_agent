# Laboratory lifecycle states

The persisted models and lifecycle services are authoritative. This compact map
captures the operational meaning needed before changing a transition.

| From | Trigger | To | Guard / owner | Failure or compensation |
| --- | --- | --- | --- | --- |
| draft | population construction sealed and canonical dispatcher seals snapshots | queued | `LabPopulationService` constructs; `trading:dispatch-lab` exclusively owns price/volume/MTF sealing and queue admission | Pause before evaluator dispatch; do not create a duplicate cohort or bypass the sealing authority. |
| queued | worker claims bounded work | screening | Queue/lifecycle orchestration owns the claim | A stale claim is recovered only through evidence-safe recovery. |
| screening | measured host suspend interrupts a batch HTTP replay | queued | The evaluator records `retry_released` and preserves the frozen request | A real within-budget transport timeout remains technical error, not a host-suspend retry. |
| queued single-agent screening recovery | exact frozen control is pending or incomplete | queued wait or technical_quarantine | `EvaluateLabAgentJob` reuses `FrozenControlScreeningAdmissionService` before evaluator execution | Pending projection records `retry_released`; incomplete control records a skipped run and withholds strategy evidence. |
| screening | screening evidence settles | full_validation or rejected | Gate evidence and immutable identity must agree | Incomplete/invalid evidence remains diagnosable, not promotable. |
| full_validation | replay, statistical, learning and holdout gates settle | completed, paper_candidate, rejected or technical_quarantine | Laravel gates evidence; Python computes deterministic results; terminal boundary requires no open run/queue/settlement watermark | Missing or mismatched receipt fails closed; terminal technical work is quarantined rather than left active. |
| paper_candidate | paper admission succeeds | paper_observation | Paper authority/admission owns the boundary | No live authority is created. |
| paper_observation | required paper evidence settles | completed, rejected or archived | Lifecycle gate owner records an immutable outcome | Invalid process evidence is quarantined. |
| any active state | unrecoverable/ineligible evidence | quarantined or archived | Explicit lifecycle decision | Preserve evidence and reason; do not silently reopen. |

Terminal labels vary across persisted records (`rejected`, `overfit`,
`stagnated`, `archived` and related statuses). Before adding a state, inspect the
actual model, migration, lifecycle service and focused tests.

After a clean terminal generation, an arbiter-created successor may seal an
`autonomy_receipt`. The source generation must also carry verifiable creation
provenance from the preceding generation's exact completed arbiter writer
decision. Record successor-creation provenance even when the predecessor audit
fails, but seal no autonomy receipt in that case. A clean receipt requires the
source audit, frozen MTF admission, both arbiter decision identities and an
immutable adjacent-generation link. A second consecutive linked clean receipt
moves the operational proof to `autonomy_streak_passed`; any technical or
incomplete generation breaks that streak.

Arbiter child process completion and lifecycle transition completion are
distinct: a successful process that returns a typed pause is recorded as
`deferred`. It cannot seal successor evidence or masquerade as a completed
state transition.

A final `data_edge_audit_completed` report may open one root successor only
when its audit's source generation number equals the latest generation. The
successor's copied audit is historical provenance; it cannot reopen the same
transition. Its own final report and newly recorded audit are required for a
later data-edge handoff.

`technical_quarantine` is terminal for scheduling but failed for technical
acceptance. Monitoring must not report it as still running, and it must not
reinterpret its incomplete evidence as scientific rejection or promotion.
An autonomous recovery whose prior immutable run used a different foundation
hash cannot replay as the same experiment. It seals a terminal technical
disposition and consumes the one bounded repair allowance; the agent stays
quarantined while a fresh generation can be selected.
`AUTONOMOUS_MTF_BUNDLE_MISSING` is an immutable admission/construction failure,
not recoverable replay transport debt. The affected generation remains failed
history while the next generation must pass snapshot admission before queueing.
Failed queue rows are admission debt only when their source agent belongs to
the current technical-recovery lookback or active lineage head. Once outside
that scope, the row remains append-only historical technical evidence but does
not keep the generation-admission state blocked indefinitely.
An immutable composition identity, required-node or aggregate-authority
mismatch is terminal construction/configuration failure for that candidate; it
must not be retried as a transient replay transport error.

Seat allocation is orthogonal to lifecycle state. Reallocating a later
generation from discovery pairs to replication/factorial/descendant blocks does
not change the guards above, and a missing block/control receipt still fails
closed.
