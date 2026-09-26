# ADR-004: Separate laboratory storage identity from MTF runtime

## Decision

Keep XAUUSD `H1` as the compatibility and population storage identity, but run
ordinary autonomous screening and full replay on closed `M5` candles. Before
queue admission, Laravel freezes one content-addressed bundle containing M5,
H4, H1 and M15 streams. The same manifest must survive through screening and
full replay, and Python verifies each declared path and SHA-256 before feature
construction.

## Why

Using the storage key as the request timeframe disabled the existing MTF pilot,
so ordinary generations replayed only H1 while specialized test paths supplied
MTF data manually. This made H4 macro, H1 structure, M15 setup/confirmation and
M5 execution absent from autonomous evidence.

## Consequences

- H1 remains stable for laboratory lookup and lineage compatibility.
- XAUUSD autonomous runtime owns M5 execution with closed H4/H1/M15 context.
- Missing or changed bundle data is a technical failure before replay, not a
  weak strategy result.
- Paper/live timeframe decisions remain separately governed; this ADR changes
  the ordinary research generation lifecycle only.
