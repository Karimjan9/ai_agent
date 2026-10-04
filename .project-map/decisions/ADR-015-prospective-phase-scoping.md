# ADR-015: Prospectively scope an unphased activation hypothesis

- Status: accepted
- Date: 2026-09-27

## Context

G234 contains passport-bound strategy signals and tactic vetoes, but its
screening request did not predeclare a venue phase. Assigning a phase to that
historical receipt after observing it would make the control invalid. The
existing four-arm discovery gate correctly requires a phase-bound source.

## Decision

Reserve at most one two-seat, research-only `phase_scope_probe` in a future
cold-start generation. Choose `london_comex_overlap` by fixed policy before
replay; do not infer it from historical signal timestamps. The control uses
the source's exact executable vector and passport, including any absent newer
schema defaults. The second arm changes exactly one declared structural gene
and is diagnostic, not a causal attribution claim. Queue admission re-reads
and hashes the immutable historical artifacts, verifies both arm manifests,
the source model/run, the generation's pre-2026 MTF cutoff and exact phase.

Both screening verdicts are `PHASE_SCOPE_RESEARCH_ONLY`; paired settlement
records no-signal, underpowered, tactic-veto, pre-entry or entry outcomes but
cannot award credit, open paper or create a parent. Only a complete phase-bound
tactic-veto control can supply a later source-owned C/A/B/A+B activation
experiment. That experiment remains discovery-only under ADR-009.

## Consequences

- Historical G234/G237 evidence is unchanged.
- A corrupt source, a 2026 paper-data cutoff or a hidden parameter change
  prevents dispatch; normal abstention does not become a technical error.
- Deterministic constructor/settlement tests prove wiring. Natural activation
  and any later economic confirmation still require new authorized evidence.

## 2026-09-28 correction: legacy passport scope

G239 proved that the G234 passport predates the mandatory
`strategy_signal_scope` field. Its phase-control replay failed technically
before a strategy decision. Keep that immutable run as failed history: adding
the field to G234 or G239 after construction would misrepresent the frozen
program. A subsequent v2 phase probe may use G234 only as a historical
parameter/hypothesis source. The current composition compiler freezes a new
passport before construction; old and prospective composition IDs and the
new passport hash are sealed separately. The exact candidate/control pair
shares this new passport. Admission re-compiles it and rejects drift. The v1
probe remains invalid for this legacy source; no retry can relabel it as a
scientific abstention. This correction grants no credit or promotion.
