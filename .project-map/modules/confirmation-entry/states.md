# Entry-decision states

| From | Trigger | To | Guard / owner | Failure behavior |
| --- | --- | --- | --- | --- |
| input_pending | all declared closed streams supplied | context_ready | Python validates timeframe, OHLC and temporal availability | Invalid/missing stream becomes `WAIT`. |
| context_ready | location and setup detected | confirmation_pending | H1 context/location and model-specific setup are valid | Absence remains `WAIT`; no synthetic setup. |
| confirmation_pending | independent confirmations and trigger pass | candidate_signal | Compiler retains evidence family/count | Insufficient or redundant evidence is `WAIT`. |
| candidate_signal | invalidation, reward-space, chase and event checks pass | admitted_signal | Signal-close admission owns first executable decision | Failed check records explicit `WAIT` reason. |
| admitted_signal | price reaches execution boundary | fill_admitted or fill_wait | Fill-time geometry is recomputed with costs | Gap/chase/R:R change forces `WAIT`, not a stale entry. |
| fill_admitted | contract sealed and transported | paper_observable | Laravel attests its copy against Python preview | Any mismatch fails closed. |

`WAIT` is a first-class terminal decision for one opportunity, not an error or
implicit retry. Exact fields/protocols remain in source and contract tests.

Frozen composition replay uses a bounded adapter over the same ordered state
names. It never promotes WAIT to a signal: the strategy must first emit an
opportunity, the tactic may narrow it to WAIT, and executable invalidation/risk
remain downstream owners. Node receipt presence records evaluation, while a
positive stage count records passage.
