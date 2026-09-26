# Laravel → FastAPI → Laravel contract attestation

## Trigger

Laravel needs deterministic backtest, replay, entry-signal or paper-contract
calculation from FastAPI.

## Sequence

1. Laravel validates/canonicalizes the request and seals required identity data,
   specialist context and one aggregate composition execution authority. That
   authority binds component identities, instrument assignment, dataset,
   execution assumptions, symbol and timeframe. A composition assignment's
   source strategy, tactic, risk and management keys must equal the frozen
   passport, not merely share its composition ID. For
   an ordinary XAUUSD generation, dispatch also freezes one content-addressed
   M5/H4/H1/M15 bundle before queue admission. If a volume lane is declared,
   each frozen stream also receives an explicit availability marker and a
   dataset-scoped historical provenance receipt.
2. Laravel dispatches the versioned request to the declared FastAPI endpoint.
3. Python compiles the frozen typed nodes into the versioned executable DAG,
   validates port types/topological order and strategy-signal/tactic-scope
   overlap, then validates aggregate authority and MTF hashes before feature
   construction. An unbound component, unprovable/empty activation scope,
   missing node, legacy program protocol or drifted stream rejects replay before
   computation. The current graph is `xauusd_executable_composition_program_v2`;
   an older sealed passport is not silently upgraded.
4. Python computes the deterministic result under the compiled program. A
   composition tactic may narrow a strategy signal to WAIT, while its concrete
   management adapter owns partial, target, trailing and time-stop behavior.
5. The typed-entry gate emits its stage chain at signal admission/veto time.
   The backtester appends actual instrument, MTF permission, risk, fill and
   managed-trade close receipts. Final trace assembly consumes those emitted
   chains; it does not recalculate pre-entry predicates after replay. The full
   per-signal ledger is retained; only its human inspection sample is bounded.
   Each opportunity receipt shares one decision ID, candle and program hash; a
   typed rejection owns the final WAIT and cannot bypass into risk.
6. Python returns result, relevant projection, hashes/protocol metadata and the
   actual runtime activation trace for bound components/instruments.
7. Laravel validates expected protocol, sealed-copy equality and declared-versus-
   observed runtime binding completeness.
8. Laravel persists or forwards only an attested result to its owning lifecycle.

## Rules

- HTTP success is not attestation success.
- A present but different hash is rejected just like a missing hash.
- Assignment or inventory presence is not runtime use; only an attested
  activation/effect trace may enter component or instrument settlement.
- A composition trace may attest only the execution receipt emitted inside the
  backtester; it cannot reconstruct component use from result totals post hoc.
- A cost/stress counterfactual that changes execution assumptions derives a
  diagnostic-only child composition hash from the validated canonical parent.
  The child can bind its own execution contract for analysis but cannot replace
  canonical replay or become promotion evidence.
- `consumed` requires all required node receipts plus one full decision-ID
  witness from strategy signal to a completed managed trade. Aggregate counts
  from different signals cannot be combined to manufacture organism credit.
  Valid no-signal replays are `valid_unactivated`; emitted but vetoed signals
  are `rejected_at_node`; later states distinguish entry authorization and
  reached position management. Invalid manifests are `compile_failed`, not
  scientific abstention.
- H1 remains the laboratory storage key. It cannot be reused as the execution
  stream for the XAUUSD organism; replay is M5 and consumes closed H4 macro, H1
  structure and M15 setup/confirmation context from the same frozen bundle.
- The M5 execution lane consumes M15/H1 canonical volume quality as MTF context;
  its execution CSV also carries the audited M5 marker. None of these historical
  checks inherit the rolling/live coverage percentage.
- A price-only control is `not_requested`, non-blocking and has zero unavailable
  actionable rows; it is not a failed volume experiment.
- A declared venue-phase scope is matched against the decision candle's exact
  canonical venue phase; a missing transport field cannot turn every otherwise
  valid signal into `instrument_context_outside_scope`.
- Instrument volatility values use one canonical equivalence (`normal` and
  `normal_volatility`, likewise low/high) across Laravel declarations, Python
  runtime matching and attribution. Historical transition-homework labels are
  not live activation predicates; only an explicit capsule or specialist cell
  may declare that boundary.
- Binding proves executable configuration. Whole-composition consumption still
  requires every required node, including risk and management, to be reached.
- Runtime/configuration/source are checked when debugging an integration issue;
  static generated indexes only locate likely files and endpoints.
