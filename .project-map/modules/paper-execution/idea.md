# Paper execution and process integrity

## Idea

Paper trading observes whether a sealed strategy/execution decision can be
carried through a guarded order lifecycle. It is evidence collection and
process validation, not a shortcut to live trading.

## Goals

- Persist immutable signals, decisions, orders, fills and outcome evidence.
- Enforce risk, discipline and contract-attestation gates before an order exists.
- Bind partial profit, trailing and time-stop management to the original contract.
- Quarantine invalid process outcomes from learning.

## Non-goals

- It does not open a live broker order.
- It does not use a good P&L outcome to excuse missing/changed contracts.
- It does not set research promotion eligibility by itself.

Read `docs/architecture/smart-discipline-engine.md` and the paper-execution
sections in `docs/ai-service-contract.md` before changing this module.
