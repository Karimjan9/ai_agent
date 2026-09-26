# Paper order lifecycle

## Trigger

An eligible research/paper signal arrives with its execution, strategy and (if
applicable) entry/management contract evidence.

## Sequence

1. Admit and freeze an eligible pre-2026 E3 candidate, then persist and attest
   the signal and sealed contract.
2. Apply external risk and Smart Discipline gates.
3. Record `APPROVE`, `SHRINK` or `VETO` with a decision receipt.
4. Create a paper order only after an allowed, attested decision.
5. Advance the order against the original management contract.
6. Persist fill/outcome evidence and perform immutable process review; paper
   observation timestamps must be inside the sealed 2026 epoch.
7. Promote the admission to E4 only when parameters are unchanged and paper was
   not used for screening, mutation, selection or posterior updates.
8. Feed only compliant, attested outcomes into performance/economic authority;
   invalid paper evidence remains quarantined.

## Rules

- A no-trade decision is valid evidence and must not be converted into an order.
- A changed/missing hash fails closed at the relevant boundary.
- 2026 is prospective paper/forward evidence only. It cannot tune or rewrite
  the frozen candidate, and a modified candidate needs a new untouched epoch.
- Paper execution is not live-trading authorization.
