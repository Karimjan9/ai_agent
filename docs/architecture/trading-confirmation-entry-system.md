# Trading Confirmation & Entry System

Status: **implemented as research/paper-observability contracts**  
Protocol: `confirmation_entry_contract_v1`  
Live/promotion authority: **none**

## Core decision law

```text
CONTEXT -> LOCATION -> SETUP -> CONFIRMATION -> TRIGGER
        -> INVALIDATION -> REWARD SPACE / CHASE / EVENT -> ENTRY or WAIT
```

The system treats these as distinct facts:

- **Location** says where evidence may be sought; an FVG, order-block proxy,
  support, resistance or range edge is not an automatic order.
- **Setup** says an opportunity is present.
- **Confirmation** says the market has begun supporting the thesis.
- **Trigger** is the exact predeclared event that may open an order.
- **Invalidation** defines where the thesis is wrong and owns stop geometry.
- **Admission** rejects correct-direction but late, expensive, news-exposed or
  poor-R:R entries.

The executable mnemonic is `WHERE -> WHY -> PROVE -> TRIGGER -> INVALIDATE`.

## Temporal ownership

| Role | Default owner | Question |
| --- | --- | --- |
| Context/direction | H4 | Which direction/regime is admissible? |
| Location | H1 | From where may a setup be considered? |
| Setup/reclaim | M15 | Did a sweep/false break reclaim the level? |
| Confirmation | M5 | Did structure shift with displacement? |
| Trigger/execution | M5 | Did the declared close/retest/reaction occur? |

`apply_closed_mtf_context()` exposes a higher-timeframe candle only after it
has closed. Missing, invalid or stale H4/H1/M15 input fails to `WAIT`; M5 data
is never resampled into fake higher-timeframe hindsight.

The executable strategy is M5-only. H1 owns the `range_sweep` regime label;
an M5 range classification cannot manufacture an H1 range setup. Invalid OHLC
geometry or negative volume invalidates the complete M5 entry stream or
supplied HTF stream rather than silently contributing evidence. Context hashes
include the exact swing, zone and H1 structure-regime values consumed by the
decision.

## Executable model matrix

| Model | Setup | Balanced trigger | Invalidation/target reference | Principal no-trade states |
| --- | --- | --- | --- | --- |
| `trend_continuation` | H4/H1 aligned, H1 POI, M15 sweep/reclaim | M5 MSS/displacement then structure/FVG retest with reaction | M15 trap extreme / next H1 liquidity | HTF conflict, outside POI, no trigger, low reward space |
| `breakout_retest` | Close beyond closed H1 boundary; wick alone is invalid | Break hold/retest with candle reaction | Retest/break structure / H1 measured move | wick-only break, weak expansion, no retest, chase |
| `false_break_reversal` | H1 edge plus M15 failed break/reclaim | M5 reversal shift then retest/rejection | Sweep extreme / opposite H1 edge | location missing, reclaim missing, invalid stop |
| `range_sweep` | Resolved range edge, never range middle | Sweep/reclaim then M5 shift/retest | Range-edge extreme / opposite range edge | unresolved range, middle entry, target too close |
| `htf_reversal` | Strong H1 edge against current H4 direction plus failed continuation | M5 structural reversal; balanced retest or conservative continuation | Sweep extreme / opposite H1 reference | weak location, fewer than declared independent families, late entry |

Each model supports:

- `aggressive`: act on the first structural/close event;
- `balanced`: require a later retest and reaction;
- `conservative`: require post-retest continuation through the reaction candle.

The three modes are separate hypotheses. A mode may improve win rate while
destroying reward space; the laboratory evaluates expectancy, not win rate
alone.

## Independent confirmation

The default minimum is three independent information families:

1. `price_reaction` — M15 false-break/reclaim or break/hold;
2. `market_structure` — M5 CHoCH/MSS/BOS close with minimum displacement;
3. `volatility_participation` — displacement/expansion evidence.

Pinbars, engulfing candles and repeated momentum labels may help the trigger,
but do not become additional independent families. The contract records:

- `raw_count`;
- `independent_count`;
- `redundancy_penalty = raw - independent`;
- exact family labels.

This prevents RSI, MACD, stochastic and CCI from masquerading as four separate
confirmations when they encode substantially the same momentum observation.
Structure direction and displacement participation are also separate facts:
a later weak structure event replaces and cannot inherit an earlier event's
displacement proof. For breakout models, the breakout close is structure plus
participation; price reaction is earned only by a later hold/retest reaction.
Therefore an aggressive breakout with the default three-family requirement
waits unless the declared research hypothesis explicitly uses the bounded
two-family minimum.

## Confirmation cost and admission

After the trigger, the contract still requires:

- logical directional invalidation;
- minimum target space in R (`minimum_reward_space_r`, default `1.5`);
- maximum favorable chase distance in ATR (`max_chase_atr`, default `1.25`);
- no `news_veto` or `risk_veto`;
- normal downstream spread/cost and Risk Sentinel approval.

The A+/A/B score is diagnostic only. It cannot open a trade, resize risk or
relax a gate. A candidate enters only when every boolean admission check passes.

## Transport and fill-time enforcement

The row-level projection includes reference, invalidation, target, trigger
anchor, ATR, causal-context timestamps/hash and a deterministic contract hash.
For a confirmation-entry paper candidate, Laravel requires exact equality for
both the top-level contract and `entry_fill_admission` against their copies
sealed inside `execution_contract_preview`. It also recomputes the contract's
cross-runtime canonical SHA-256. A missing, changed or hash-invalid copy fails
closed; it cannot fall back to the legacy route projection.

Paper requests for this strategy supply independent H4/H1/M15 candle streams.
The normalized contract checks supported model/mode/order state, direction,
confirmation-family counts, redundancy arithmetic and directional price
geometry before the cognitive funnel may execute it.

Signal-close admission is not the last check. Replay and paper recompute at the
actual executable price, including both spread/slippage legs and round-trip
commission:

- structural invalidation remains the stop reference;
- the declared structural liquidity reference owns the target;
- effective reward/risk must still meet `minimum_reward_space_r`;
- fill distance from the frozen trigger anchor must still satisfy
  `max_chase_atr`.

A gap or cost-induced failure becomes an explicit fill-admission `WAIT`. The
original valid setup/trigger evidence remains available for opportunity and
filter-regret learning; it is not rewritten as if the setup never occurred.

## Learning evidence

Backtests return both funnels:

- `entry_funnel`: raw trade signals accepted/rejected by execution/risk;
- `entry_contract_funnel`: context, location, setup, confirmation, trigger,
  invalidation and admission conversions, including WAIT opportunities.

`entry_contract_funnel` preserves good no-trade decisions and reports:

- no-trade reasons;
- setup-to-confirmation, confirmation-to-trigger and trigger-to-entry rates;
- grade distribution;
- average independent/raw/redundant evidence;
- average R:R space and chase distance after a trigger.

Every one of the five models is registered in the research catalogue and must
run against the same frozen M5 control, immutable data bundle and execution
hash. Results are priors only: independent windows and paper-shadow evidence
remain mandatory, and `promotion_evidence=false` is invariant.

## Runtime ownership

- Python calculation: `app/strategies/confirmation_entry.py`
- Closed MTF merge: `app/services/multitimeframe_stack.py`
- Replay/paper parity: `app/services/backtester.py`, `app/main.py`
- Laravel normalization: `ConfirmationEntryContractService.php`
- Cognitive/funnel integration: `TradingCognitiveStackService.php`,
  `OpportunityFunnelLedgerService.php`
- Frozen comparisons: `StrategyResearchCatalogueService.php`,
  `MtfPlaybookFrozenControlService.php`

The main unattended research process dispatches one unique toolbox prior on
`lab-frontier` every thirty minutes. Full-validation keeps queue priority, the
five confirmation-entry hypotheses are completed before the general catalogue,
and the job reselects against the current Python/runner hashes when it actually
starts. Repeated scheduler ticks cannot accumulate duplicate expensive work.
The runner hash is an explicit semantic contract seal: payload, power or
verdict behavior requires a deliberate seal bump, while whitespace-only PHP
formatting cannot invalidate evidence and trigger wasteful replay.
`MtfPlaybookLearningDirectorService` may turn a measured default-run activity
bottleneck into exactly one causal follow-up: only
`minimum_independent_confirmations: 3 -> 2` changes, the immutable source run is
recorded, and the repair is terminal even when activity remains insufficient.
The repair restores the source run's content-addressed snapshot bundle; it may
not export a fresh market snapshot. Source and repair must therefore have the
same data hash, execution hash, Python runtime hash and semantic runner seal.
Any historical pair that used different snapshots remains diagnostic-only and
cannot earn causal attribution.
It then advances to the next model instead of entering an unbounded relaxation
or replay loop.

An attractive result below the eight-trade frozen-pair floor is projected as
`promising_underpowered_observation`, not a skill and not a failed idea. The
toolbox preserves it for an agent-owned independent-window pair while keeping
inheritance and promotion authority false. Zero-activity candidates remain an
explicit activity bottleneck and may receive only the bounded funnel repair.
The resulting row is still prior-only (`agent_owned_evidence=false`,
`promotion_evidence=false`); an agent must later earn E2+ through its own paired
windows and paper-shadow outcomes.

## Production causal check (2026-08-28)

All arms used data hash
`f1afae1821fb840b73fd84a9ada4b196c34ea562aa41cee9d65cf60bd597029b`
and execution hash
`04403537588afd0e22efffc3e210b572ca660e8d751ab8d4dbdc7225a8fed7b5`.

- `trend_continuation` default: 174 setups, 1 confirmation, 1 trigger,
  0 entry-ready and 0 trades. The director identified confirmation as the
  measured bottleneck.
- Its one-gene repair increased confirmations from 1 to 4, but only one trigger
  remained and entry-ready/trades stayed at 0. The repair was closed as
  `bounded_repair_terminal`; no second relaxation or promotion was allowed.
- The director then advanced to `breakout_retest`: 14 confirmations, 22 trigger
  observations, 4 entry-ready decisions and 3 executed trades. The observed
  PF 54.78, net +28.14% and 0.49% drawdown are promising but statistically
  underpowered, so the result is retained only for independent-window research.

Focused verification passed 69 Laravel tests / 455 assertions and 63 Python
tests. The managed AI service, scheduler, replay, screening and learning
workers were online after the controlled run; scheduler state was saved in PM2.

This layer does not enable live trading and does not change the kill switch.

## Seven-block Trading OS scorecard

The Confirmation & Entry funnel is now consumed by a broader evidence-first
Trading Operating System scorecard. It measures seven dependent blocks:

1. edge and market context;
2. selection, including opportunity recall and abstention precision;
3. executable cost/fill quality;
4. drawdown and risk-of-ruin safety;
5. same-entry trade-management value;
6. process/data/execution-contract integrity;
7. independent learning and adaptation evidence.

The formula is multiplicative, but an overall score and A+/A/B/C grade are
withheld until all seven blocks are observed and powered. Missing management
counterfactuals or independent windows stay `null`; they are scheduled as
evidence work and are never converted into an optimistic zero or a strategy
failure. This prevents a profitable three-trade replay from looking like a
complete professional system.

Canonical learning now stores this scorecard with the immutable pair
projection. Component attribution is derived from measured block deficits,
replacing fixed strategy/tactic/execution/risk percentages. The weakest fully
measured block may nominate one bounded repair axis; incomplete or underpowered
scorecards can request evidence only and cannot mutate or promote. Process
outcomes also retain a separate error taxonomy (`strategy`, `execution`,
`management`, `risk`, or `discipline`) so a compliant loss remains a good loss
and a profitable rule violation remains a quarantined bad win.

The canonical projection protocol is versioned separately from the immutable
replay. Reprojection may refresh derived scorecards and attribution without
creating a second settlement or rerunning market data. Incomplete or
underpowered scorecards publish `primary_cause=evidence_completion` with no
component weights; only a complete powered 7/7 scorecard may name a component
as causal. This prevents downstream consumers from treating a measured weak
block as proven while another block is still unknown.

Replay now emits per-trade initial-risk distance, MFE-R, MAE-R, realized-R and
winner MFE-capture ratio. The aggregate management block is powered only after
at least eight attested trade paths and five winner paths; total trade count
alone cannot make it green. Since OHLC does not reveal the order of extremes
inside the exit candle, the evidence declares
`candle_extrema_including_exit_bar`, keeps stop-efficiency and premature-stop
rate null until a same-entry post-exit counterfactual exists, and never grants
promotion authority by itself.

## Professional fitness and causal learning

`ConfirmationEntryCapabilityEvidenceService` converts the executable funnel
into three explicit capability observations: setup selection, confirmation
quality and entry precision. Grade mix, independent-to-raw confirmation ratio
and chase geometry are process scores only. Conversion rate is never treated
as quality, because both excessive filtering and weak filtering can produce a
misleading percentage. Rule authority requires the same setup/risk contract,
a paired ablation and at least six powered OOS windows.

These observations feed the 15-dimension `EvolvingTraderFitnessService`. A
missing dimension remains a research task; a process-only Confirmation score
is visible but underpowered. Only a complete powered passport can send its
weakest dimension to `LearningReflectionService` as one bounded causal repair.
The fast trade loop may observe, score and tag mistakes but cannot rewrite a
rule. The slow loop requires repeated pattern -> hypothesis -> paired test ->
OOS -> paper -> settlement -> inheritance. Risk may be reduced immediately by
a sentinel or drift event, but this passport never authorizes a live-risk
increase.

The capability projection is derived from the immutable replay and can be
reprojected without rerunning market data or creating another settlement.

## Bounded MTF runtime

Frozen MTF priors retain ordered trade-ledger hash/count and streaming
signal/event digests, but no longer transport the full trade ledger or
materialize a full per-candle decision trace. The full
trace forced pandas Series access on every M5 candle and caused a 10,000-row
Confirmation prior to hit the 300-second child limit with no result. Metric
mode preserves behavioral identity and learning evidence while using the fast
stateful record path. The semantic runner seal changes whenever this payload
contract changes, so an older timeout row cannot be mistaken for new evidence.
