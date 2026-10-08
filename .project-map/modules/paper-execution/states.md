# Paper execution states

Native locked intake first requires `fresh_closed_marks + pinned_costs +
known_observed_peak -> marked_equity_ready`. Missing/stale/future candles or
unknown historical peak transition new intake to a typed dependency. They do
not block reconciliation or idempotent reservation retries. New spendable risk
uses the lesser of realized balance and marked equity; floating gains cannot
fund more risk. Actual peak is monotonic. Old nullable peaks with any filled
reservation, paper fill or cost history are never initialized from today's
balance as if that were historical evidence (ADR-032).

Native specialist intake additionally passes `identity_verified -> reserved ->
published -> managed -> released`. Reservation and order publication share the
account/candidate transaction; rejection/cancellation releases only the exact
owned reservation. Retired versions block new intake but do not transition an
existing open position to an unmanaged state. Position management remains
pinned until a reconciled terminal close; unsupported netting stays blocked.

Council `approved -> scheduled -> active` uses the existing paper monitor,
not a second scheduler. A due version becomes active only after the native
locked owner rechecks original assessment and every member's paper authority.
The opt-in/running-control fence leaves scheduled versions unchanged when
disabled, paused, stopped, draining or safety-halted. A rejected version is
reported and remains blocked without shadowing another council or suspending
management of old position pins; only future native entry is fenced.

| From | Trigger | To | Guard / owner | Failure or compensation |
| --- | --- | --- | --- | --- |
| signal_received | signal/contract is persisted | admission_pending | `PaperTradingExecutionService` owns intake | Missing sealed evidence cannot proceed. |
| admission_pending | external risk and discipline decisions pass | approved or shrunk | Risk sentinel and Smart Discipline own the decision | `VETO` yields a durable no-trade receipt, not an order. |
| approved or shrunk | attested order is created | open | Order, execution and management hashes agree | Hash mismatch is rejected/quarantined. |
| open | partial/trailing/time-stop/target/stop event | managed_open or closed | Python contract and Laravel persistence must remain attested | Stop widening or contract drift is invalid-process evidence. |
| managed_open | terminal execution event | closed | Paper state machine records fill/outcome | Outcome proceeds to process review. |
| closed | review completes | compliant_settled or quarantined | Process-integrity review owns classification | `BAD_*`, unattested or drifted outcome is not learning input. |

Exact enum/status values live in migrations, models and state-machine code. The
transition table is a change checklist, not an alternative authority.

Paper authority has a separate evidence rung around this order lifecycle. An
eligible pre-2026 candidate is frozen as E3 before observation;
`e4_evidence_ready` requires prospective-after-freeze orders, unchanged
parameters, discipline approval, paper-gate success and the original sealed
paper epoch contract. The legacy `paper_2026` boundary remains unchanged.
An explicitly authorized future period additionally requires approval before
candidate freeze, freeze before that period, and disjoint research validation.
Unknown, revoked, overlapping or not-yet-open future epochs are blocked
dependencies, not paper orders. E4 is evidence eligibility, not live authority.

E3/E4 reuse and `approved -> open` additionally require
`frozen_paper_candidate_identity_v1`: current persisted parameters, runtime
components, passport and current execution hash must equal the original freeze.
The captured signal carries that admission ID/hash. Drift or a missing legacy
identity is a typed blocked capture/fill, not a new freeze or implicit approval.
Caller-supplied unchanged flags cannot grant E4 when the persisted model drifted.
Future-period admissions preserve `paper_window_key` and the original
`authorized_prospective_paper_epoch_v1` seal; capture/fill checks observation
readiness before HTTP/order publication. After the period ends, new orders
stop but existing orders retain mandatory management/reconciliation. Future
E4 chronology includes open and close times; an out-of-period close cannot
silently enter the seal. The original E4 identity can remain evidence. Post-paper-trained
candidates still require original training/selection provenance; an unchecked
passport flag cannot replace it.

Future E3 provenance is derived server-side even when a caller labels training
pre-2026. An unchanged archive baseline needs its original modern compressed
request/model/response seal completed before 2027, not a later replay of old
CSV. The narrow modified-candidate route permits one confirmed post-paper
runtime-seal-preserving parameter trait only: exact total vector difference,
original archive baseline, current evaluator, common original instrument
treatment, and at least three completed distinct nonoverlapping source windows
before freeze. Original signed window/request identity and all actual four-MTF
CSV bytes and full chronology (including warmup) must agree. Unsupported input
planes or component-changing traits remain blocked dependencies.
`RELEASE_TRAIT_REVALIDATION_REQUIRED` preserves historical confirmation rather
than relabeling it as current-release proof. E3 reuse re-derives this original
provenance, so nested executable-passport drift cannot evade the older flat
candidate identity. No parent E3 prerequisite or new research/live authority
is introduced.
