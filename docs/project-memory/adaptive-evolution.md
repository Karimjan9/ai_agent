---
aliases:
  - Adaptive Parent Ecosystem
  - Evolution Governor
tags:
  - ai-learning
  - evolution
  - parent-frontier
updated: 2026-08-09
---

# Adaptive Parent Ecosystem

This is the laboratory's evolutionary core. A champion is the best current
production candidate, not the only ancestor. The system keeps a convergent
anchor, exact semantic islands, diversity contributors, young experiments and
failure evidence in separate roles.

```text
global champion / local island champion
       + convergence frontier
       + diversity frontier
       + young and curiosity lineages
       + failure archive (diagnostic only)
                    |
          Evolution Governor
                    |
       Adaptive Parent Frontier (K is dynamic)
                    |
       capability genome + source provenance
                    |
     child replay -> PBO/DSR -> holdout -> paper gates
```

## Parent modes

| Lane | Parent rule | Why |
| --- | --- | --- |
| Causal repair, G98, differential router | Exactly one exact-cell parent | The changed gene must remain attributable. |
| Champion-guided ordinary evolution | One anchor, optionally a small frontier | Converges quickly while allowing a niche alternative. |
| Robust crossover | Dynamic 2–5 parents | Modules can come from different proven capabilities. |
| Architecture/curiosity discovery | Dynamic 1–4 parents plus young/archive candidates | Avoids premature convergence and opens new topology lines. |
| Runtime regime ensemble | 3–8 sealed members as a router policy | The runtime selects a regime specialist; unknown/disagreement means `WAIT`. |

The parent selector receives candidates only after the strict
`symbol + timeframe + family + role + regime + volatility + direction` check.
Dynamic selection can diversify within that cell, never across cells.

## Persistent memory

`lab_evolution_archive_entries` stores four independent archive roles:

- `convergence`: strong local candidates and local champion history;
- `diversity`: behaviorally or parametrically distinct candidates;
- `young`: recent architecture/curiosity lineages that have not earned a full
  passport yet;
- `failure`: rejected, overfit, abandoned or stagnated evidence. It is never
  returned as genetic material.

`lab_evolution_islands` summarizes each exact semantic cell. It tracks the
local champion, archive counts, diversity, progress and stagnation.

`lab_parent_selection_decisions` is the queryable handoff ledger: candidate
IDs, selected IDs, dynamic K, mode, scores, governor snapshot and the
`promotion_evidence=false` boundary are recorded for every constructed agent.
`lab_agent_parent_links` remains the canonical parent graph; the old
`parent_a`/`parent_b` columns are compatibility projections.

## Governor rules

`EvolutionGovernorService` observes the last three terminal generations. It
computes progress, parameter/behavior diversity, parent concentration, lineage
entropy, archive coverage, market drift and repeated failed-mutation
fingerprints. Diversity collapse, parent concentration, drift or repeated
no-progress increases exploration; normal progress keeps the search
champion-guided. This changes allocation, not statistical gates.

The first ordinary generation remains the historical causal baseline. Starting
with a later generation, the governor protects a configurable causal seat
floor (default eight) and reallocates only the mutable tail to
`robust_crossover`, `architecture` and `curiosity_probe` lanes.
Coverage-rescue, role-complete and explicitly sized populations are not
silently rewritten. Every adaptive slot is marked research-only until
independent replay.

`AdaptiveParentFrontierService` ranks quality first, then rewards marginal
parameter distance and lineage novelty subject to a lineage cap. Archive
entries are screened again at selection time: a convergence/diversity entry
must pass the parent passport, while a young entry is allowed only in an
explicit research lane. Its `capability_genome` names entry, exit, risk,
execution/cost, confidence-calibration and router modules. Every copied gene
stores source parent, module, evidence ID, confidence, contribution weight,
scope and parameter hash; the parent graph records the same provenance.

Island migration is a knowledge handoff, not an unrestricted cross-cell edge.
An exact compatible cell may enter frontier selection and must still pass the
passport; a broader compatible cell is diagnostic-only.

Runtime activation is a separate sealed layer. Genetic parent IDs never become
`portfolio_members`. `RuntimeEnsemblePolicyService` activates a portfolio
only when the member performance rows match the sealed specs, each member has
a passed independent statistical passport, and the combined portfolio
passport is passed. Holdout, paper and incremental health checks all consume
this single contract. If the contract is absent or invalid, the request is
fail-closed and returns `WAIT`.

## Safety invariants

1. A child never inherits promotion evidence or a parent's score as its own.
2. Every multi-parent child is re-evaluated from scratch with the same costs,
   sealed holdout, PBO/CSCV, Deflated Sharpe and paper gates.
3. Causal lanes never use multi-parent mixing, even when a large archive exists.
4. Runtime ensemble/router metadata is a sealed routing contract, not a
   bypass around independent specialist passports.
5. `WAIT` is the default for unknown regimes, missing specialists and
   specialist disagreement.

## Implementation map

- `backend-laravel/app/Services/EvolutionGovernorService.php`
- `backend-laravel/app/Services/AdaptiveParentFrontierService.php`
- `backend-laravel/app/Services/EvolutionArchiveService.php`
- `backend-laravel/app/Services/LabPopulationService.php`
- `backend-laravel/app/Services/LabAgentPreflightService.php`
- `backend-laravel/app/Services/RuntimeEnsemblePolicyService.php`
- `backend-laravel/app/Services/SealedHoldoutService.php`
- `backend-laravel/app/Services/PaperTradingExecutionService.php`
- `backend-laravel/database/migrations/2026_08_09_190000_create_adaptive_evolution_tables.php`
- `backend-laravel/tests/Feature/AdaptiveParentEcosystemTest.php`
- `backend-laravel/tests/Feature/RuntimeEnsemblePolicyTest.php`
- `ai-service-python/app/services/backtester.py`
- `ai-service-python/app/strategies/laboratory.py`
- `ai-service-python/tests/test_regime_ensemble.py`

The design follows the established research direction of multi-parent
recombination, diversity preservation, archive-based quality-diversity search
and dynamic islands. Useful references are [multiparent recombination](https://pubmed.ncbi.nlm.nih.gov/10021763/),
[premature convergence and diversity](https://pubmed.ncbi.nlm.nih.gov/18255718/),
[Two-Archive Evolutionary Algorithm](https://arxiv.org/abs/1711.07907),
[dynamic island models](https://arxiv.org/abs/1801.01620), and
[MAP-Elites / quality-diversity](https://www.frontiersin.org/journals/robotics-and-ai/articles/10.3389/frobt.2016.00040/full).
