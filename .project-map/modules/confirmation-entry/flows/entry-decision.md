# Closed-MTF entry decision

## Trigger

A replay or paper request supplies a declared entry model and sealed M5 plus
independently available H4/H1/M15 streams.

## Sequence

1. Validate temporal availability and OHLC geometry of every supplied stream.
2. Build H1-owned context/location and evaluate the model-specific setup.
3. Require independent confirmation and exact M5 trigger.
4. Compute logical invalidation, target reference, reward-space and chase checks.
5. Emit an explicit `BUY`, `SELL` or `WAIT` decision with contract evidence.
6. At fill time, recompute cost-aware geometry; admit or return a new `WAIT`.
7. Laravel verifies the sealed projection before paper execution observes it.

## Rules

- Setup is not confirmation; confirmation is not trigger.
- A closed-timeframe violation or unavailable input is `WAIT`.
- Replay and paper must use the same compiler semantics.

## Composition replay adapter

An ordinary strategy participating in a frozen composition is exposed through
the v3 executable composition program. The adapter cannot create or upgrade a
signal: it records ordered context/location/setup/confirmation/trigger evidence
and allows the frozen tactic scope only to convert an out-of-scope signal to
WAIT. A classified `unknown` regime is not itself missing context; the selected
tactic still owns whether that explicit regime is admissible. Missing classifier
data or an unready closed-MTF snapshot remains WAIT. Executable stop sizing
remains owned by the central risk governor.

Each strategy opportunity receives one stable `decision_id` bound to its
candle, compiled program and frozen contract hash. The runtime retains every
per-opportunity receipt and an ordered digest, with only the inspection sample
bounded. Typed-entry and runtime gates preserve the same decision/candle/hash
identity, exact first-veto reason and closed-trade management receipt. Idle
candles are not counted as failed opportunities. `consumed` requires one complete
decision witness through trade close; unrelated aggregate node counts cannot
construct that witness. Outcomes distinguish `compile_failed`,
`valid_unactivated`, `rejected_at_node`, `entry_authorized`,
`position_managed` and `consumed`.
