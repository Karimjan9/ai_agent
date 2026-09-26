# Paper execution states

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
parameters, discipline approval, paper-gate success and a valid sealed 2026
epoch contract. E4 is evidence eligibility, not live authority.
