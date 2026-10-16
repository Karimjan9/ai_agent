# One next question: reproduce a trigger before judging its economics

Status: **chosen follow-up, not an executable backtest declaration**.
There is no new arm, request, outcome or dispatch permission in this file.

Question: Can one explicitly defined EMA20-overlap inside-bar setup and its
subsequent boundary-triggered entry be represented causally, including an
untriggered or invalidated setup, rather than filled at the next open merely
because a setup exists?

This follows the entry distinction in the [reviewed lesson](https://www.tradingsetupsreview.com/day-trading-with-20-period-moving-average/).
The numerical translation below would be a **lab adaptation**, not a claim to
reconstruct the author's entire discretionary method or an already useful
skill. It is selected for fidelity, not because we found its best historical
PnL. This cycle does not replace it with whichever variant wins on exposed
June/September outcomes.

## Definitions to fix before any market replay

- Choose one context and pattern definition. A candidate operationalization is
  EMA20 rising/falling over three completed observations; a mother candle whose
  range overlaps EMA20; then a strictly inside completed candle. These exact
  numerical choices have not been demonstrated profitable or author-exact.
- LONG trigger is the inside candle's high plus one existing execution point;
  SHORT is its low minus one point. No entry on the pattern candle itself.
  Freeze that price before looking at the following bar. The candidate expiry
  is one following observed bar; invalidation is the opposite mother-candle
  extreme. Expiry and invalidation are lab choices requiring explicit sealing.
- Fix gap handling, quote side, spread/slippage, source-data continuity,
  trigger/invalidation ordering and cancellation. A bar that reaches both
  boundaries without observable ordering is ambiguous; it is not silently a
  favorable fill. Never use a later closed candle as if known at order creation.
- Preserve the external risk/capital ceilings. Fully specify initial protection,
  sizing, exit and holding before economics. Unspecified management cannot be
  borrowed from an unrelated source and called the author's complete method.

## Small acceptance gate

Use deterministic chronological case selection with success defined as correct
state/price chronology, **not profit**. Include a triggered setup, an untouched
expired setup, an invalidated setup and a gap/both-boundaries ambiguity fixture;
keep genuinely absent market categories absent. Synthetic fixtures verify
software semantics only. Validate the hand-labeled decision against the exact
closed input and actual execution owner, including an unfavorable example.

The inspected existing confirmation-fill helper revalidates geometry at the
next modeled open; it does not establish this pending-order lifecycle. Do not
advertise an existing field as a verified stop-order implementation. If a
minimal bounded execution change is necessary, scope it to this question and
test it separately; do not add a new scheduler or broad strategy framework.

Only after those semantics and all choices are fixed should one two-arm,
multi-period market question be compiled through the existing offline owner.
Record every attempted variant, original source/cost/resource seal and the
decision it is supposed to inform. Old exposed months are discovery only;
no unattended tuning, automatic agent attachment, independent skill, broker
demo or paper/live authority follows. 2026 and later data remain paper-only.
