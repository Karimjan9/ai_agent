# Market data continuity and canonical candles

## Idea

Research and paper decisions need a known, continuous and attributable candle
series. This module makes provider choice, gaps and recovery explicit rather
than treating an empty response as valid market history.

## Goals

- Persist canonical candles and provider recovery state.
- Distinguish a scheduled market closure from an outage or a missing range.
- Block new research construction when required continuity is not trustworthy.
- Retain secondary archives as evidence without silently replacing canonical data.
- Keep rolling/live volume coverage and historical replay volume attestation as
  separate populations.
- Materialize volume-capable historical CSVs with explicit row markers and a
  provenance receipt bound to the frozen snapshot hash.

## Non-goals

- It does not change strategy scoring or promote a candidate.
- It does not silently backfill a canonical series with a secondary provider.
- It does not infer historical volume availability from a live coverage percentage.

Read `docs/project-memory/market-data-continuity.md` before modifying provider,
calendar, gap or recovery behavior.
