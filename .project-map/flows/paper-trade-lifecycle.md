# Cross-module flow: paper-trade lifecycle

## Purpose

Observe an eligible paper opportunity through attestation, risk/discipline
admission, managed execution and process review.

```text
Frozen pre-2026 E3 candidate
  -> Laravel/Python contract attestation
  -> Risk + Smart Discipline decision
  -> Paper order creation or durable NO_TRADE receipt
  -> Sealed management and fill progression
  -> 2026 outcome/process/epoch review
  -> E4 performance evidence or quarantine
```

## Modules

- `confirmation-entry`: provides a closed-stream signal or `WAIT`.
- `laravel-python-contract`: attests strategy/execution/management identities.
- `paper-execution`: owns signal, order, fill and outcome lifecycle.
- `market-data`: supplies attributable market context.
- `operations`: monitors the runtime lane.

## Invariants

- `WAIT` and `VETO` are valid terminal decisions for an opportunity.
- A changed contract cannot create an order.
- Paper timestamps outside 2026, post-freeze parameter changes or paper reuse
  for research selection cannot create E4 evidence.
- Good P&L cannot make an unattested/invalid process outcome learning-eligible.
- This flow creates no live-trading authority.
