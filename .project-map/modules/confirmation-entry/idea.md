# Closed multi-timeframe confirmation and entry

## Idea

The entry system separates context, location, setup, confirmation, trigger and
execution admission. It returns `WAIT` when required closed evidence or
executable geometry is missing instead of forcing a trade decision.

## Goals

- Use closed streams with explicit H4/H1/M15/M5 roles.
- Keep setup distinct from independent confirmation and exact trigger.
- Declare invalidation, reward-space and chase checks at signal and fill time.
- Preserve replay/paper compiler parity and frozen-control comparison.

## Non-goals

- It does not provide live trading authority.
- It does not treat a score as a risk or promotion gate.
- It does not fabricate a missing higher-timeframe stream.

The detailed source-of-truth design is
`docs/architecture/trading-confirmation-entry-system.md`.
