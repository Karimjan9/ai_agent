# ADR-021: Archive-first pre-champion research

Status: accepted

## Context

The research evaluator already uses pre-2026 M5/H4/H1/M15 archives. Selecting
live market drift made generation admission wait for 24 new H1 candles even
though those candles cannot enter research. The operator requested uninterrupted
archive-based search before champion/paper, not relaxed scientific authority.

## Decision

Keep `ResearchLoopArbiterService` as the sole scheduler. Current settlement,
technical recovery and ready learning work retain priority. With the default
archive-first policy enabled and no valid non-invalidated XAUUSD champion,
the exploration owner opens one bounded `historical_research` root. An MTF
powered-prior validation precedes it only with an actually eligible immutable
source, not an elapsed timer. That source ID is part of its decision watermark.
Periodic standalone MTF/portfolio and historical-backlog maintenance cannot
starve this root; MTF/instrument experiments remain embedded in the cohort.
A clean final report's
required data-edge audit still runs first and remains single-use.

`GenerationAdmissionDecisionService` owns this scheduling policy. The constructor
validates the actual pre-2026 foundation, seals its hash/manifest and keeps live
candle bookkeeping separate. Admitted causal confirmation uses the same archive
readiness. Active work, technical debt, protocol and operator pauses still deny
new construction. Neither `--force` nor a special admission can override a
denied historical policy. Both dispatchers skip live continuity only for this
prospective archive contract and retain canonical snapshot/control admission.
Prequeue admission checks the sealed foundation identity and research stream
periods. A cheap archive dependency watermark permits reselection after repair;
it is not data validation and does not allow timer-based compute repetition.

Successors of terminal technical generations retain arbiter creation provenance
without upgrading the failed predecessor to a clean autonomy proof.

## Consequences

Research exploration no longer needs live candles before champion. A scientific
zero pass may close one hypothesis and allow another bounded experiment. Actual
missing data or technical failures remain explicit dependencies/dispositions.
The operator can still PAUSE or STOP. The post-champion live freshness path is
unchanged and can be restored earlier by disabling this policy.

E3 paper, E4 champion, economic/risk, exact-control and causal credit gates are
unchanged. 2026 stays paper-only; repeatedly using an archive cannot manufacture
untouched validation or guarantee a profitable champion. This decision changes
scheduling/readiness, not market-evidence standards.

## Acceptance

Focused tests cover zero-live-candle construction, scientific zero-pass
continuation, drift-independent arbiter selection, dependency-change dedup,
archive hash/UTC-cutoff rejection, valid-champion stopping, pause/technical fences
and successor provenance after technical quarantine. Runtime acceptance requires
an arbiter-created new generation with the historical contract, sealed MTF,
matching loaded source and real archived replay; a test or healthy process alone
is not a market-skill proof.
