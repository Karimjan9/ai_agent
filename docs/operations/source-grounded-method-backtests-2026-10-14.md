# Source-grounded instrument methods — actual archive diagnostics

This batch implements established indicator/management ideas as finite research
methods, not empirically qualified trading skills. Internet-library revisions
and the agent's own learned selection policy remain separate. No old G265/G266
attempt, receipt or failed arm was replayed or reclassified.

## Reviewed sources and executable meanings

- [Fidelity MACD](https://www.fidelity.com/learning-center/trading-investing/technical-analysis/technical-indicator-guide/macd): EMA12 minus EMA26, EMA9 signal;
  an adjacent closed directional crossing confirms an existing proposal.
- [Fidelity RSI](https://www.fidelity.com/learning-center/trading-investing/technical-analysis/technical-indicator-guide/RSI): Wilder RSI14 with conventional30/70 boundaries.
  Strict recovery across a boundary is this lab's interpretation, not a
  publisher-proven entry system. Flat history maps50; one-sided histories0/100.
- [StockCharts Chandelier](https://chartschool.stockcharts.com/table-of-contents/technical-indicators-and-overlays/technical-overlays/chandelier-exit): rolling22-period extremes and3*Wilder ATR22.
  A held-position close beyond its level requests next-open exit. This is an
  explicit M5 close-confirmed adaptation, not an intrabar broker protective stop.
- [CME order types](https://www.cmegroup.com/education/courses/futures-trading-mechanics-and-regulation/futures-order-types): partial-position exits support a
  separately declared native single-partial ATR-runner adapter. Fraction0.50,
  partial1ATR, trail2ATR and24-bar time stop are lab settings, not an optimum
  copied from CME. The native partial uses current candle ATR and terminal
  weighted bookkeeping; it is neither entry-frozen1R nor a realized-cash
  partial-close ledger. Trail may run before and after the partial.

The existing [stop-aware account-risk sizing](https://www.cmegroup.com/education/courses/trade-and-risk-management/proper-position-size)
kernel was preserved. Its external risk, leverage, costs, original hard stops
and gap-stop/target priority were not weakened. The new library never invents
direction, confidence, volume or quotes; external text is not executable code.

Declarations are append-only under `resources/research/instrument-methods/`:
`closed-macd-cross`, `closed-rsi-recovery`, `closed-chandelier-exit`,
`native-single-partial-runner`. MACD/Chandelier additionally has a hybrid.
Register through existing `trading:instrument-methods source/method/recipe`;
compile/attach only to a fresh eligible policy/model. The adapter requires fresh
construction; it cannot change a sealed model at runtime. Merely adding these
records does not replace an agent's pinned library, earned policy or authority.

## Preregistered actual panel

Two original512-row pre-2026 CSV scopes were selected before the new outcomes:

| Window | Loaded UTC interval | Evaluated start | Raw SHA256 |
| --- | --- | --- | --- |
| October | 2025-10-13 07:10 →10-15 03:45 | 10-14 00:50 | `185b7c360ad1bf5dcf26a1c02534144577c79b9e057397c6598c06f9c819d008` |
| December | 2025-12-03 21:00 →12-05 17:35 | 12-04 14:40 | `0c227a2eb82cf608ca89cebe8f28f169cf3f457b4beb44f0f1a82b2fc9f6c53f` |

Each arm used200 execution warmup rows and312 actual decisions. The original
EMA50/200/RSI baseline,10000 initial account,1% risk input and canonical modeled
cost contract were identical within each comparison. Typed methods changed
no physical genes. Only the prospective partial-runner declared its four-gene
construction delta. Twelve original requests and80 source hashes were sealed
before replay; each had one120-second cap, no outcome-selected retries.

The first preparation hit an import-path error before any worker or replay:
the pure verifier helper put the prior tree ahead of the new tree. That spec
and exact preparation/runner bytes remain retained, SHA
`25cd34bdd901e2ac77f8fac0e50274a823ed8145d3b97653ecb2b3fc95ad5fc7`.
One linked pre-execution replacement restored the correct isolated application
imports with unchanged data/parameters/risk/costs. Replacement spec SHA
`6397ee5bafdea405879708ff265f874671de51ccc1bc94ecc1a6cf4b44716484`.
It created no renewed scientific attempt or independent evidence.

All12 originals completed within their120-second caps. Full trade/account
chains,312-decision clock coverage, calendar bounds, trace hashes and actual
method event chains were verified. Summary SHA
`3d58720f38673588bc6394476216e9658fcafefea84d36140059fe8d8cc9be4a`.
Products remain in the isolated tree's
`.runtime/source-grounded-actual-panel-20261014-v2/products/`; private CSVs,
specifications and execution products are excluded from Git/source artifacts.

| Variant | October trades | October net cash | December trades | December net cash |
| --- | ---: | ---: | ---: | ---: |
| Exact control |17|305.10|12|-123.24|
| MACD cross |17|305.10|12|-123.24|
| RSI recovery |17|305.10|11|-167.09|
| Chandelier owned close-exit |17|305.10|12|-123.24|
| MACD+Chandelier |17|305.10|12|-123.24|
| Single-partial ATR runner |23|82.14|20|-185.19|

No closes were censored. These accounts across arms are counterfactual aliases,
not independent episodes to pool for statistical power. Windows are reused
discovery data; neither is an untouched final examination. December has genuine
observed quote fields, but this experiment retained the same modeled physical
execution costs in every arm; observed quotes were not substituted for costs.

## What the results actually say

- MACD had0 October target uses and1 December exit-phase use without behavior
  change. That exit-phase count is not an evaluated entry confirmation or a
  positive signal. No control advantage was measured.
- RSI had0 October uses; December had3 entry vetoes and local cash delta-43.85.
  The exact recipe did not improve this baseline/window.
- Chandelier evaluated13 and6 owned exits, all false: no forced exit and no
  account change. Hybrid used13/7 targets without behavior change. This is valid
  reached/null evidence, not proof of utility or individual component credit.
- Partial/trail/time treatment changed real behavior. It was worse than control
  by222.96 and61.95 cash. Its four genes changed together, so their individual
  causal contributions are not separated. The complete original trade ledgers
  contain16/13 `partial_target+...` exits. The private summary's generic
  `partial_trade_count=0` read a non-public field and is not partial-use evidence;
  that original summary was not rewritten. Exit reasons supply this read-only
  correction. Final cash/account and contrast checks were unaffected.

Nothing is promoted from these findings. Scope is pinned: MACD/Chandelier use
trend_up/London/normal, RSI range/London/normal. Missing applicable entry context
is recorded, not solved by widening predicates after outcomes. Formula/guard
tests additionally exercise genuine native EMA consumption on explicit synthetic
fixtures; those fixtures are not market qualification or a profitable recipe.

## Verification and remaining boundary

- Source-grounded declarations plus existing expanded/trend/quote regression:
 14 tests /704 assertions; single-partial owner:3 /149.
- Source artifact allowlist:25 tests /574 assertions; only the exact13 new JSON
  declarations and4 new software tests are added, no provider-data globs.
- New formula tests12, real native-EMA runtime tests5, existing feature tests15
  passed. The actual archive panel is separate evidence from these fixtures.
- Maps and exact diff/lint checks are required before commit/delivery.

This batch adds executable, reviewed research methods. It does not create a
Master, useful confirmed component, stronger child, independent causal credit
or Champion Council. Runtime/registry deployment must use a drained sealed
release and fresh loaded-worker proof, never a hot-fix to an active generation.
All2026 and later data remains paper-only. No YouTube transcript was reviewed
in this batch and no unrestricted crawler or independent holdout was fabricated.

## Subsequent all-instrument operating curriculum acceptance

The user's corrected goal is a source-taught operating corpus for ALL existing
tools, separately from agent-owned contextual practice. The three pinned parts
now cover26 lessons in five groups,34 scoped reference records and90 finite
operator exercises. Reference records include metadata-only book resources;
they are not34 fully read books/videos. The relevant official Fidelity webinar
transcript passage, creator rules, exchange/broker instruction, author trading
plan excerpts and primary model/calibration documentation are explicitly scoped.

Every lesson teaches calculation/conventions, operating steps, alternate uses,
data and timing requirements, WAIT/misuse conditions and agent practice. Its
primary and additional method identities resolve to existing executable code.
The actual PHP-compiled primary operators pass all90 synthetic exercises. This
demonstrates the taught decision-rule mechanics, not original market feature
availability, a fitted model, profitable use or an agent's Master qualification.

A NEW unused ordinary child can receive a server-derived exact teaching pack
inside its signed existing generation handoff before its own policy is sealed.
Only its selected recipes are taught/active; discovery of26 manuals does not
give each child26 active recipes. Original own events and uncensored cost-inclusive
outcomes still develop agent preference independently of the curator corpus.
Old models, policies, receipts and catalog bytes are unchanged. A same-source
familiar-key method with a substituted threshold refuses despite valid registry
hashes. Teaching neither fits weights nor borrows economic authority.

### Original actual reused-archive panel

Before any outcome, control plus26 typed primary variants were frozen in
`.runtime/instrument-operating-skills-20261014/.runtime/operating-original-panel-20261014/`.
SpecSHA256`486aa3451b03069c366f989c0803172d60977eddc6f449a1d82cec6ae46d4470`;
original summarySHA256`ecdd97d615ae150c6293dd12af0996408ad6188c42bc6d6d34345b4a846d6c66`.
The66 declared kernel/curator/helper bytes matched before and after execution.

All27 actual products completed in their single declared120-second caps. They
use the unchanged registered EMA baseline, physical parameters/account/cost/risk
and the same original512 rows, SHA256
`185b7c360ad1bf5dcf26a1c02534144577c79b9e057397c6598c06f9c819d008`.
Source coverage is2025-10-13 07:10 through2025-10-15 03:45UTC;200 execution warmup
rows are excluded, leaving312 actual decision rows beginning2025-10-14 00:50UTC.
The clock, account, original event framing and method receipts were verified.
It is already-seen archive diagnostic evidence, not independent validation or
native MTF/full/paper qualification; observed quote/volume availability is not
manufactured to activate a rule.

| Variant | Trades / mature / censored | Net cash | Actual method use / behavior changes |
| --- | --- | ---: | --- |
| Unchanged control |17 /17 /0 |+305.10 |0 /0 |
| Owned adverse-regime exit |17 /17 /0 |+305.10 |13 /0 |
| Each other25 primary variants |17 /17 /0 |+305.10 |0 /0 |

The positive account result belongs to the common baseline, NOT26 proven useful
skills. Most variants did not reach a matching applicable entry target, and the
observed exit conditions were false. All26 have90 program-level operating checks;
this market slice does NOT provide26 exercised trade effects or agent practice.
Context/data/target reach remains a prerequisite of later predeclared practice,
not a reason to widen predicates retrospectively. No original is rewritten or
replayed to select success, and no local result is inserted as production credit.

The native temporal helper also had a real timing defect: its original ordinary
path observed current execution-bar extrema before the current open assessment.
Future update/registration now use the prior completed row/index, preserve index
zero, and pass prefix/maturity/expiry/default-control parity tests. Old original
results remain historical; this fix is not a new economic edge claim.

Final scoped suites:23 PHP tests/881 assertions for curriculum/teaching/generation;
27 source-artifact tests/701 assertions for exact instruction packaging and
historical archive immutability. Python90 exercises,15 feature-owner tests and
9 focused temporal cases passed. Safe production delivery still requires the
actual artifact, cold idle boundary and fresh loaded-worker proofs. All2026+
market observations remain paper-only.

## Source-practice correction (2026-10-15)

The old27-arm account result is NOT26 verified instrument skills. Reopening the
original products proves all26 treatments had17 entry `policy_default_baseline`
events. Most methods were eligible only in an exit/holding context, where their
confirmation/risk target could not execute. The exact first entry context was
trend_up/new_york/normal; the old recipes were London-scoped. Original products,
declarations and counts remain immutable. A zero-use trial does not reject its
formula and cannot qualify the method or train a useful-selection claim.

Actual additional source reading produced two executable questions, not merely
more instruction text:

- [Fidelity EMA](https://www.fidelity.com/learning-center/trading-investing/technical-analysis/technical-indicator-guide/ema)
  describes directional EMA support/resistance and lag. The new four-closed-bar
  recipe requires both EMA20/50 to trend in the candidate direction BEFORE the
  touch/reclaim. Current close above an EMA already implies its current rise;
  that redundant test is deliberately not sold as a new capability. Exact
  periods, recursive seed, sequence and NY scope remain lab adaptations.
- [TradingView ATR%](https://www.tradingview.com/support/solutions/43000734653-how-are-adr-and-atr-calculated/)
  defines price-normalized volatility. The new closed owner exposes
  `100*SMA14(TR)/close`, explicitly retaining the lab's SMA rather than silently
  claiming the publisher's RMA14. The fixed0.10% cap is a prospective hypothesis,
  not a profitable parameter copied from a source. Price scaling preserves the
  ratio; an absolute price-unit ceiling does not have that property.

New scope is explicit trend_up/new_york/normal, chosen from already-seen
opportunities before NEW outcomes. No wildcard, forbidden-regime rule, risk
limit or old receipt was relaxed to obtain activation. Full books, videos and
masterclasses not actually inspected remain unreviewed. Two tutorial readings
do not establish26 empirical skills or a successful curriculum.

### All26: the remaining practical question, not a skill-count claim

| Instrument | What a real practice trial must reach or distinguish |
| --- | --- |
| trend_pullback | Prior rising/falling trend, closed touch/reclaim; matched same-scope slope ablation. |
| breakout_retest | Prior20-bar boundary, later retest of its frozen level; breakout bar is not its own retest. |
| compression_expansion | Existing5/20 true-range proxy versus genuinely different BandWidth squeeze; these are not the same algorithm. |
| session_breakout | Genuine session identity/calendar and an available prior range, followed by entry-phase breakout. |
| range_reentry | Applicable range entry, completed outside/inside reentry; no automatic fade of a trending band tag. |
| adaptive_entry_topology | Separate break and reclaim opportunities; OR composition is not a trained universal selector. |
| dynamic_fibonacci_zone | Two confirmed anchors, positive leg and a subsequent directional retracement; no future pivots. |
| confirmed_swing | Right-hand confirmation bars closed before the pivot becomes available. |
| bos_event | Fresh directional crossing of an already-known pivot, not a retrospectively chosen level. |
| choch_event | Prior directional state followed by a fresh opposite structure crossing. |
| support_resistance_zone | Known pivot, actual touch count/age and normalized distance at a reached decision. |
| liquidity_pool | Equal-level OHLC proxy only; actual resting-liquidity claims need order-book observations. |
| liquidity_sweep | Known-level excursion then closed reclaim; OHLC does not prove intrabar stop/fill ordering. |
| session_range | Mature actual session width and ATR; distinguish developing range from completed opening range. |
| volume_confirmation | Canonical observed volume and units, mature positive baseline; no fabricated volume from OHLC. |
| cost_firewall | Synchronized observed quotes at a reached entry; modeled execution costs do not create quote observations. |
| high_volatility_firewall | Normalized ATR% availability and real entry veto; absolute and percentage thresholds are different rules. |
| transition_protection | Observed regime boundary and elapsed closed bars, then a matching entry. |
| loss_streak_cooldown | Same-agent mature uncensored loss history available before the next entry. |
| confidence_firewall | A programme that genuinely emits its native score; score is not calibrated probability. |
| temporal_survival_filter | Observed same-direction closed signal sequence; heuristic decay is not survival-model proof. |
| dynamic_cooldown | Mature owned adverse sequence and subsequent recovery; compare the fixed schedule, not an imagined optimum. |
| atr_risk_envelope | Reached risk stage; a constant0.5 treatment is not another stop-aware sizing algorithm. |
| cost_aware_exit | Owned live holding and actual exit condition; trailing-only and partial/trailing/time bundles need distinct tests. |
| regime_router | Known point-in-time regime and original proposal direction; alignment alone is not selector superiority. |
| meta_label_filter | Real original owned labels, chronological fitting/calibration and reached cells; no caller/CSV score. |

The new actual panel is separate from the old studies. Its first preparation
refused two operators on the same target before any outcome. The replacement
composes one explicit joint AND operator through the existing grammar; no
single-target guard was removed. Original failure and first raw slice remain
retained, with zero replay attempts for that preparation.

Before-outcome spec SHA256:
`59c143cdd1469a929e588d345554bd534c582b3eb3c59f742b9f4ebfc43ccea0`.
It fixes two1024-row scopes,200 warmup/824 decisions, unchanged EMA50/200/RSI14
account/cost/risk, five arms per scope and one300-second original attempt per
arm. Arms: unchanged baseline, a NEW NY-scoped legacy two-bar ablation,
four-bar pretrend, ATR% and an explicit joint method. The old London method is
never relabelled as this ablation. Source524-file snapshot and raw-parent hash
`caae3824e1296f880da15ca5cdaff8724216e7694129cf257eeb06c68e5a4bc5`
precede outcomes. Original products live in isolated
`.runtime/source-practice-20261015/.runtime/source-practice-originals-20261015-v2/`.
This is previously seen discovery, not untouched evaluation or native/full/MTF
qualification. All ten originals subsequently completed under their declared
300-second caps, with the frozen524-file source snapshot still exact.

| Previously seen discovery scope | Arm | Mature trades | Net cash | Method uses / behavior changes |
| --- | --- | ---: | ---: | ---: |
| October | Baseline | 42 | +804.96 | 0 / 0 |
| October | NY legacy two-bar ablation | 42 | +707.52 | 1 / 1 |
| October | NY four-bar pretrend | 42 | +707.52 | 1 / 1 |
| October | NY ATR percent | 42 | +707.52 | 1 / 1 |
| October | NY joint | 42 | +707.52 | 1 / 1 |
| December | Baseline | 35 | -190.21 | 0 / 0 |
| December | NY legacy two-bar ablation | 35 | -190.21 | 0 / 0 |
| December | NY four-bar pretrend | 35 | -190.21 | 0 / 0 |
| December | NY ATR percent | 35 | -190.21 | 0 / 0 |
| December | NY joint | 35 | -190.21 | 0 / 0 |

Summary SHA256:
`d2e813760297ed3900c921f16096641746b6112a39785227db7fcfa9e697ad16`.
Every original reports1024 loaded rows,200 warmup and824 decision rows. Complete
actual clocks, account/trade chains and framed method events were verified.
There are no censored trades in these rows. October's extra rules lost97.44
cash relative to the unchanged control; pretrend added no difference over its
two-bar ablation. December had no in-scope method use, so its null contrasts
cannot assess method usefulness. Maximum drawdown was2.01% for all October
arms and3.32% for all December arms. A single changed observation is not a
power calculation, independent replication or an instrument-wide harmful ban.
The overlapping vetoes do not establish a beneficial joint interaction.

These are real executable-method observations, but they do not warrant a
profitable skill label. Importantly, all four treatments are confirmation
filters on the old EMA50/200/RSI14 proposal owner. That is a valid filter test,
not proof that a trend-pullback tactic generated its own entries. A role-correct
entry implementation must be named and compared separately through the
existing strategy/compiler, without opening an entry port in the typed filter
DSL, changing old strategy semantics or retroactively replacing these results.

### Genuine entry programme: separately declared six-arm comparison

The new opt-in `source_ema_pretrend_pullback_v1` is an actual trend entry owner,
not another confirmation filter. It requires exact EMA20/50 and generates
BUY/SELL only for the same closed pretrend/touch/reclaim, retaining the original
ADX strength, RSI direction, confidence, session, cost, account and risk guards.
The fresh canonical constructor and normal native member compiler were tested
with the exact three-gene declaration; incompatible frozen architecture refuses.
The ordinary random topology roster and old trend branch are unchanged.

This is a NEW previously-seen-archive question: all three arms explicitly use
`strategy=trend_v1`, `base_strategy=trend`, with ordinary trend strength20 and
pullback0.75. It is not compared as if it were the old EMA/RSI programme. Arms
separate the period-pair effect from the source-entry rule. The first request-only
preparation refused an empty baseline delta encoded as an array; no replay or
outcome occurred. That declaration and exact helper bytes remain retained.
The linked preparation fixes only that wire shape and keeps all data/controls.

Before-outcome spec:
`71b33425f466bd9fd9dee15747630db66b82cf310d6df98231080599af470324`.
It pins519 app/helper files and an actual before-outcome byte source archive,
the same raw1024-row scopes/200 warmup/824 decisions, six original300-second
caps and no retry. All6 originals completed, without timeout or post-outcome
source changes. Actual clocks, complete account/trade chains and trace hashes
passed; source entries were linked to their exact original ledger entry times
and direction, not inferred from method names or the typed-method use counter.

| Seen scope | Programme | Mature trades | Net cash | Max drawdown |
| --- | --- | ---: | ---: | ---: |
| October | Frozen trend50/200 | 4 | -50.95 | 1.25% |
| October | Frozen trend20/50 | 9 | -132.68 | 3.09% |
| October | Source entry20/50 | 1 | -50.01 | 0.50% |
| December | Frozen trend50/200 | 0 | 0.00 | 0.00% |
| December | Frozen trend20/50 | 6 | -61.41 | 1.32% |
| December | Source entry20/50 | 1 | -54.10 | 0.54% |

Actual source-programme trace coverage is824/824 candles in each scope;
815/814 have mature contiguous rule inputs. There are15/17 directional
patterns,7/5 ADX/RSI-admitted proposals and1/1 exact linked mature trades.
Proposals are not automatic execution: session/confidence/account/holding gates
still apply. These are genuine source-rule activation and account observations,
not profitable skill, sufficient power or stronger-agent evidence. December's
flat frozen control must not be hidden when comparing the new negative result.

Original summary SHA256:
`d3873a64e861f9eefb668a04b0865e6a4af166c2cf5e0d13da00ddf016e9399e`.
Products and the exact source-archive reference remain under isolated
`.runtime/source-practice-20261015/.runtime/source-entry-originals-20261015-v2/`.
No production generation, old receipt, credit or research/paper epoch changed
to obtain this result. All2026+ observations remain paper-only.

Focused verification for this delivery: source-practice/old teaching17 tests /
1022 assertions; actual fresh-entry/structural/hybrid/mutation35 /256;
phase-selection/old binding11 /163; source-artifact/ATR declaration34 /979.
The broad focused Python method/exercise panel passed70 tests, genuine-entry
suite9, and phase-selection/old-learning18. Overlapping earlier local suites
are not additional independent tests or market evidence.
