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

## Curator operating-practice panel (2026-10-15)

This is a new prospectively declared **reused-archive** question, not independent
validation. All 26 primary instrument methods and the new normalized BandWidth
alternative received one original attempt in each of two already-seen scopes,
plus one shared control per scope: 56 attempts. The same EMA50/200/RSI14 baseline,
1024 loaded / 200 warmup / 824 decision rows, risk and execution costs apply to
all arms. The 07–16 UTC session is a newly declared common scope restriction,
not a restriction selected after seeing outcomes. No original production agent,
archive row, authority gate or old receipt was edited.

Before-outcome spec SHA256:
`fa15e5411c3607bed4107aef7cef49f87180681270b83a9c9fddbd07ee739634`.
The exact source ZIP pins 567 files and its own SHA256 is
`927198c902d547415f40e4de43b7d4a35172f2c08dffe163e3677e6f76724aef`.
All 56 originals terminally closed: 52 completed, four technical. The two risk
failures were a report export error on an input-free constant's empty binding;
the two confidence failures exposed absence of a genuine native EMA score.
The latter is not replaced by the admission lane's compatibility confidence.

Each completed original passed actual clock, complete trace/event framing,
dataset/source fences and account/trade ledger verification. Values below are
net cash, not percentages, independent effect estimates or skill grades.

| Instrument / exact primary operation | October | December | Important execution limitation |
| --- | ---: | ---: | --- |
| Control | -116.41 | -236.67 | 13 / 24 mature trades |
| adaptive_entry_topology | -182.80 | -90.94 | Actual changed decisions in both scopes |
| atr_risk_envelope | technical | technical | Original reporter failed; separate completion below |
| bos_event | -119.28 | -90.94 | Actual confirmation vetoes |
| breakout_retest | -119.28 | -90.94 | Actual confirmation vetoes |
| choch_event | -119.28 | -90.94 | Actual confirmation vetoes |
| compression_expansion | -119.28 | -90.94 | Original true-range proxy, not normalized BandWidth |
| confidence_firewall | technical | technical | Genuine native EMA score absent |
| confirmed_swing | -116.41 | -236.67 | Available, no changed decisions |
| cost_aware_exit | -116.41 | -236.67 | 33 / 27 actual uses, no changed decisions |
| cost_firewall | -119.28 | -90.94 | Quote absent: WAIT effect is not filter utility |
| dynamic_cooldown | -116.41 | -247.09 | October has no changed decisions |
| dynamic_fibonacci_zone | -119.28 | -90.94 | Actual confirmation vetoes |
| high_volatility_firewall | -116.41 | -236.67 | Available, no changed decisions |
| liquidity_pool | -119.28 | -80.36 | Proxy operation; not exchange order-book evidence |
| liquidity_sweep | -182.80 | -90.94 | Actual changed decisions in both scopes |
| loss_streak_cooldown | -116.41 | -247.09 | October has no changed decisions |
| meta_label_filter | -119.28 | -90.94 | Real fitted model missing, no invented model scores |
| range_reentry | -154.16 | -280.02 | Worse than both matched controls |
| regime_router | -116.41 | -236.67 | Available, no changed decisions |
| session_breakout | -119.28 | -90.94 | Partly underpowered context; not complete rule utility |
| session_range | -184.34 | -247.09 | Partly underpowered context; not complete rule utility |
| support_resistance_zone | -119.28 | -90.94 | Actual confirmation vetoes |
| temporal_survival_filter | -116.41 | -236.67 | Available, no changed decisions |
| transition_protection | -116.41 | -236.67 | Available, no changed decisions |
| trend_pullback | -182.80 | -236.67 | December available without changed decisions |
| volume_confirmation | -119.28 | -90.94 | Observed volume absent, not a measured bad strategy |
| New normalized BandWidth alternative | -119.28 | -90.94 | Actual vetoes; distinct append-only method |

Original summary SHA256:
`1d2b7638e27ddcad4cb97fe3ad984743ac93eabe40dcbea5071a0bcfac90652e`;
original observations SHA256:
`abf74de8865b37b42c0a6b8d4ad815245b405b2ae44a60a4c81f5567b774c710`.
All original products remain under
`.runtime/curator-practice-20261015/.runtime/curator-practical-originals-20261015/`.

### Explicitly approved two-risk technical completion

The user authorized exactly one bounded completion of the two risk arms after
the original panel closed. Only the private reporter's empty-binding view was
corrected. Request bytes, source, data, parameters, cost and risk were identical;
the original failures remain failures. Both completion products passed the same
full clock/trace/account verification within their fixed 300-second caps.

| Scope | Mature trades | Net cash | Matched control | Local delta | Actual risk reductions |
| --- | ---: | ---: | ---: | ---: | ---: |
| October | 13 | -96.18 | -116.41 | +20.23 | 1 |
| December | 24 | -172.92 | -236.67 | +63.75 | 2 |

This is reduced sizing on a losing sample, not positive economic skill or a
risk-normalized advantage. It does not establish better entries. Completion
manifest SHA256:
`7b0586fb12049a14cef9efd185906b87beea9de566cf67fedef1533902e421c9`;
summary SHA256:
`ecd2af184ffd58c25c162e37e2f9ffe978a47a7df6a7c7eb431978e4e1cf98b9`;
completion observations SHA256:
`7435766a7937acc753b1ba3fd3ea703adf536e9e6387a702e9fdd038ca02a9a6`.
Its separate products live under
`.runtime/curator-practice-20261015/.runtime/curator-practical-technical-completion-20261015/`.

The practical conclusion is context dependence and separate availability,
activation and utility states, not 26 profitable instruments. Null, harmful,
missing-input and technical observations remain useful bounded instruction.
All 2026-and-later market data remain paper-only.

### Separately declared native-score and observed-quote dependency studies

These follow-ups are fresh diagnostic questions on the same seen market events,
not retries that replace the original confidence or quote-unavailable rows.
Their controls use fresh BASELINE-only policies with the same exact libraries
as treatments. Every question pins original requests and source bytes before
execution, with 300 seconds per arm, 1300 seconds total, one serial hidden
single-threaded attempt per arm, and raw responses flushed before verification.

The native-score question separates scoreless EMA from the existing genuinely
scored trend20/50 producer. The latter's parameters come from the earlier exact
frozen entry-panel control, not outcome-tuned confidence settings. Both use the
unchanged October rows and common execution hash. Its before-outcome spec is
`e3083d7fb76e3adf69f7fb086e51d898649df3ca00f970306cbe2f6e940a6dbe`,
source archive
`824f5e1deebc3a55673da1cf09074bd30a737e53e879995e58264d14067c825c`.
The fresh EMA control must reproduce the original physical trade/account ledger;
its additional default-policy trace may differ honestly.

The quote question reconstructs synchronized genuine BID/ASK observations from
173 pre-existing checkpoint payloads through the existing decoder and matcher.
Each original price/provider cell is preserved in new hash-bound CSVs. Every
quote must precede candle closure, be at most 60 seconds old, and have a BID
matching the original close within `1e-6`. Both scopes contain 1024/1024 matched
rows and 824/824 evaluation observations. No network request, volume estimate,
new fill or change to modeled execution cost was introduced. The question is
whether those observed inputs enable the exact `cost_firewall` operation, not
whether modeled execution now reproduces real broker fills.

The new October CSV SHA256 is
`a17a75eae1d912eb98c24de87650e8318eb91c76660337b2a87d947565c3b39c`;
December is
`b2fc10c89dc3613a3edf8829c4ffe3f4a7afc3f3351e3c7ee63108a65506be56`.
Quote source manifest
`f71f7563d1418c8de961981c9b9fa7fb1b160218b488eadf70ac52a7abc4fa18`
binds the exact checkpoint/decoder/dependency/parent identities. Before-outcome
spec is `f2c689a6c5cf0d4039be40ddffa37b51b9e6b17e195686a0a621d150b42bce79`,
source ZIP
`bb47789671c6292c7bfc727abb576116a40732fa043f71d761f65dd4ed1dcd4b`.
Old CSVs and old unavailable-input observations remain unchanged.

All four native-score originals completed. The fresh EMA control exactly
reproduced the old physical ledger. EMA treatment reached six actual score
requests, all explicitly unavailable; five-trade scored trend treatment reached
no entry-method evaluation (ten exit-phase target-unreached events). Genuine
native score transport is covered by the actual-producer software regression,
not proved as market activation by this unactivated archive treatment.

| October score question | Mature trades | Net cash | Reached / available | Classification |
| --- | ---: | ---: | ---: | --- |
| Scoreless EMA control | 13 | -116.41 | — | Matched physical control |
| Scoreless EMA + confidence | 13 | -119.28 | 6 / 0 | Missing score, not harmful-rule evidence |
| Scored trend20/50 control | 5 | -260.00 | — | Separate family control |
| Scored trend20/50 + confidence | 5 | -260.00 | 0 / 0 | Unactivated, not proof of null utility |

Score summary SHA256:
`195c55106041f41406e5b67dd63873f4638ceff098343ce4f65ac5434193ca43`;
observations SHA256:
`8a0a584c4bd345d80ffeea66a3bdcc1a8d3b887303f041afa6a5e0ac7f5487c0`.
Products remain in
`.runtime/curator-practice-20261015/.runtime/score-practice-originals-20261015/`.

All four observed-quote originals also completed, with no timeout or altered
execution cost. The exact primary rule reached six October and three December
decisions with all required observed inputs available. Every reached invocation
vetoed; those nine actual evaluations are not nine independent experiments.

| Quote scope | Arm | Mature trades | Net cash | Reached / available / changed |
| --- | --- | ---: | ---: | ---: |
| October | Same-library BASELINE control | 13 | -116.41 | 0 / 0 / 0 |
| October | Exact observed-quote firewall | 13 | -119.28 | 6 / 6 / 6 |
| December | Same-library BASELINE control | 24 | -236.67 | 0 / 0 / 0 |
| December | Exact observed-quote firewall | 20 | -90.94 | 3 / 3 / 3 |

Local deltas are -2.87 and +145.73, respectively; absolute returns are still
negative. The mixed outcome supports retaining scoped provisional know-how,
not a universal spread threshold, independently confirmed skill or Champion.
Quote summary SHA256:
`8aca6f5225ff2acd566e436b1b92307fb33a988a64d3524df4f4ba4eaf2789c8`;
observations SHA256:
`38f612c4745177b07a34beff1e8b899df50af4e5e2a8905fee36bd639804b124`.
Products remain in
`.runtime/curator-practice-20261015/.runtime/quote-practice-originals-20261015/`.

Read-only reconstruction through the same closed feature owner matched all
9/9 full `feature_inputs_hash` values in the original reached events. This
explains the rule, rather than inferring a cause from trade totals:

| 2025 UTC decision | Observed spread | Closed TR14 mean | Spread / mean |
| --- | ---: | ---: | ---: |
| Oct 15 08:30 | 0.680 | 4.036357 | 0.168469 |
| Oct 15 08:35 | 0.680 | 4.313286 | 0.157652 |
| Oct 15 08:40 | 0.667 | 4.403286 | 0.151478 |
| Oct 15 09:00 | 0.610 | 4.336714 | 0.140659 |
| Oct 15 09:05 | 0.660 | 4.466000 | 0.147783 |
| Oct 15 09:10 | 0.667 | 4.550786 | 0.146568 |
| Dec 05 09:40 | 0.530 | 2.855500 | 0.185607 |
| Dec 05 09:45 | 0.560 | 2.666929 | 0.209979 |
| Dec 08 08:25 | 0.560 | 3.345000 | 0.167414 |

These are raw price units; the denominator is an arithmetic mean of 14 closed
true ranges, not Wilder's smoothed ATR. Displayed values are rounded only after
exact comparison. Every ratio exceeds the lab's strict `<0.10` threshold;
equality would also veto. Smaller nominal December spreads therefore remain
more expensive relative to volatility. This is a useful operating observation,
not evidence that the threshold is optimal. No available-input pass case was
observed, and no risk/cost guard was weakened to manufacture one.

### Verification of this curator-practice delivery

The final disjoint focused Laravel panels passed 72 tests / 2,352 assertions
and 17 curator tests / 1,188 assertions: **89 tests / 3,540 assertions** total.
They include original generation/teaching compatibility, exact old v2 pack
identity, all 85 new actual PHP-to-Python synthetic operator cases, selected
original/completion/dependency projection, immutable unused-child handoff,
scoped advice's real compiled policy effect, and exact source-artifact inclusion
with private market products excluded. The final focused Python panels passed
75 existing method regressions, 12 BandWidth tests and seven native-score tests:
**94 tests**, not a whole-project suite or independent market observations.
Changed PHP lint, focused Python compilation and Project Map impact/generate/
check passed (1715 indexed sources). No paper/live qualification was tested.

All 66 actual attempts remain visible as 56 originals, two approved technical
completions and eight fresh diagnostics; 62 completed, with the four original
technical histories retained. These are not 66 independent experiments. Real
observed volume and an eligible fitted meta-model remain distinct missing
inputs; the observed quote study did not create either. Future production
activation additionally requires a safe drained boundary, verified byte artifact
and actual loaded-worker source proofs. Tests alone never assert activation.

## Curator's contextual DMI/ADX research, 2026-10-15 — in progress

This is the assistant-curator's own source study, implementation and original
archive execution, not an assignment for future agents to invent the methods.
The source/formula and reviewed manual/transcript scopes are recorded in
`instrument-method-learning.md` and the pinned `context-adx-dmi-v1.json` design.
There are two algorithms and 24 exact context declarations, not 24 qualified
skills. They add directional DI confirmation, with or without strict ADX14>25,
to the same frozen EMA50/200 RSI14 baseline. They do not implement full Wilder
entry/stops or Bollinger Method IV, and do not generate new entries.

### Original technical history and one bounded replacement

The first original panel was frozen at spec
`e119e04c1482fa377339ec921a8ced9dbf9cfd373df99268abdf232e1ab5d10a`.
Its first two cases timed out at 300 seconds with no response product. The
curator stopped only that private panel; the remaining 26 cases are explicitly
uncompared/operator-stopped, including its interrupted third case. Old spec,
ZIP, request bytes and technical products are unchanged. Stop intent
`a1965cde33a74463a049dbf74dd4c6b011871b96dcfdc1c04582043413209857`
and confirmation
`e32b7b2bb9ae83caef0b1c51ddaa363bfc5bc6f39e1adadd254aaa30308e0864`
retain that boundary. This was not production STOP or a market-loss finding.

The software repair reuses the canonical calendar answer only within one
bounded simple replay. Complete actual cached/unwrapped fixture responses,
including ledger, trace and clock, compare exactly; eight focused tests pass.
Four repeated coordinate queries become one calendar call in that fixture,
not a claim of four-times replay speed. Calendar semantics, data, parameters,
costs, risk and per-arm/global caps are unchanged.

One separately frozen technical replacement owns all 28 fresh requests under
the repaired source. Spec:
`5c3ac482449898e5c1d54012bc583b14125f289caa9059640558e5576e99a8c4`;
before-outcome source ZIP:
`f0a2b13a58e9440a9eae8dd06fe12a442359865b4b406539d0d6a22c8b8f8d37`.
Actual backtester SHA:
`4d28814bcd71961cbb38cfb04a03fdb5f128f337c9229385674ba7ad3208ab67`.
It retains one attempt per original, 300 seconds per arm, 9000 seconds global,
one thread and hidden, serial, below-normal execution. Fresh native-calendar
audit `f9c621da06a8c940065dabd1ce980dbdfccdd3d47b6391953a915b9c0a2fd87b`
attests the selected unchanged windows, not the old whole archive's continuity.
Raw-window custody manifest is
`b91435027999a1468066611c5ff053020c52ba6373bd0bb69cef3a193efc8c92`.
No old/new arm products are merged, no old approval is borrowed, and no
automatic further replacement or independent-validation claim is created.

All original responses remain private under the isolated worktree's
`.runtime/context-practice-completion-20261015/products/`; exact request,
response, process, clock, framed method events and complete account ledger are
verified before comparison. This uses the actual PHP-compiled contracts and
local Python executor, not the running HTTP service or a broker demo account.
All 2026-and-later market data remain paper-only.

### First window's measured partial results

The following are completed originals from the pre-panel calendar-selected
October 13–17, 2025 window, not a finished 28-arm study. They use 200 separate
warmup rows and 1356 decision rows, the same initial 10000 account, newly sealed
common 01–22 UTC schedule and identical modeled execution costs/risk.

| Original intervention | Mature trades | Net cash | Drawdown % | Available method evaluations | Added vetoes |
| --- | ---: | ---: | ---: | ---: | ---: |
| Control | 67 | +601.99 | 4.64 | 0 | 0 |
| London DI+ADX>25 | 64 | +570.77 | 4.06 | 22 | 20 |
| Overlap DI alignment | 62 | +817.57 | 4.15 | 13 | 8 |
| Overlap DI+ADX>25 | 61 | +906.86 | 4.15 | 13 | 9 |

The London DI-only original hit its unchanged 300-second cap and has no valid
economic comparison. It is not a null or harmful strategy result. Remaining
New York and later-window originals were still pending when this partial
record was appended; their results must come from original terminal products.

Measured full-account deltas versus the same original control are -31.22,
+215.58 and +304.87 respectively. London's lower drawdown is a tradeoff, not
proof of the primary cash objective. The overlap observations are worth keeping
as provisional know-how, not independently confirmed skill. Positive absolute
cash alone is not incremental value. A 10-mature-trade diagnostic floor is not
statistical power, and all four later calendar blocks need honest reporting,
including losses, nulls, unavailable observations and technical cases.

Each style/session intervention contains four exact regime-by-volatility
methods. Its cash contrast belongs to the **whole original intervention**;
it is never copied into four individual-method benefit grades. Event-level
context counts are descriptive. The legacy `new_york` calendar label is not
the entire New York core session; original venue phase and DST identity remain
attached. Position/account effects can cross sessions.

### Existing 26-instrument evidence inventory

A separate outcome-unranked inventory reopened six pinned v3 resources:
SHA `2d62ee6354c9718ed81c5b8d2d4e44f74c2ccea464e95a32d5a6e002609c2c9a`.
Its 52 primary originals contain 48 completions and four technical cases;
two risk completions, four dependency-method diagnostics and two supplemental
BandWidth originals remain separate. It did not inspect the new DMI outcomes.
All 26 primary declarations were London/normal (25 trend-up, one range).
Their mixed-price input lacked observed volume, quotes or an eligible fitted
model; missing-input vetoes do not prove those instruments economically bad.
Some methods were usable but never changed behavior; counts of contracts,
exercises or invocations are not empirically qualified skills.

For the newer archive, the repository's legacy `tick_volume` semantic label
means Dukascopy's provider BID-volume measure in millions, not cTrader's count
of updates, executed exchange volume or real order-book flow. Candle aggregation
and tick fallback preserve provider quantities; the parent manifest is not a
raw per-bar tick witness. Old data/receipts are not relabeled. Curator knowledge
and the agents' future own outcomes remain separate.

### All-terminal contextual panel and curator interpretation

The replacement panel finished all **28 terminal attempts: 26 completed and two
timeouts**, in 4753.285 seconds. No cap, parameter, window or outcome was changed.
The original final source check was `unchanged`. Summary SHA:
`d28d59edc9a18e22b0df9e3d8bf6fc65e63b6178855f61c2a7c7180cdae64384`.
The original private parent and its workers were absent before export. A bounded
post-terminal exporter reopened every original request/process/response,
complete account ledger, framed trace and actual clock, including paired clock
parity, and rechecked original source/archive bytes before and after reading.
Its verification SHA is
`f20bba4b63c0089983023f993322abe7c1250cc93e2b656aa42f7980f4cf59fe`.
Original source and products were not rewritten for publication.

The complete net-cash matrix below uses a fresh 10000 account per original.
Numbers are account units under the common modeled execution contract, not
broker/demo cash or isolated session profits. Columns are the four original
calendar-selected windows, not independent qualification windows.

| Original intervention | Oct 13-17 | Oct 27-31, DST mismatch | Nov 3-7 | Dec 8-12 |
| --- | ---: | ---: | ---: | ---: |
| Exact control | +601.99 | +739.06 | -1051.14 | -174.31 |
| London DI alignment | timeout | +739.06 | -1048.09 | -174.31 |
| London DI + ADX>25 | +570.77 | +739.06 | -1014.78 | -355.54 |
| Overlap DI alignment | +817.57 | +1017.24 | timeout | -202.51 |
| Overlap DI + ADX>25 | +906.86 | +813.92 | -1010.67 | -179.64 |
| New York label, DI alignment | +601.99 | +739.06 | -1051.14 | -412.47 |
| New York label, DI + ADX>25 | +601.99 | +739.06 | -915.30 | -570.56 |

Of the 24 original intervention/control contrasts, eight were locally positive,
six locally negative, eight had no behavior change, and two remain technical
unknowns. These are correlated discovery comparisons, not 24 independent
hypotheses or a statistical success rate. Completed originals had 44-71 mature
trades; the declared ten-trade floor is descriptive eligibility, not power.

What the curator actually learned from these originals:

- Overlap directional confirmation improved cash in both October windows
  (+215.58 and +278.18 versus exact control), but worsened December by 28.20.
  November's DI-only timeout gives no economic answer.
- Adding ADX>25 to overlap DI helped more in the first October window but
  produced 203.32 less cash than DI-only in the second. More confirmation is
  not universally better. This is a conditional contrast between the declared
  bundles, not an isolated ADX effect or factorial interaction certificate.
- November overlap DI+ADX reduced loss by 40.47 but still lost 1010.67. A
  slightly better negative result does not make the method profitable.
- London DI was available in December but passed all nine reached candidates:
  genuine use without a behavior change. London DI+ADX instead changed three
  decisions and worsened cash by 181.23 and drawdown from 4.74% to 5.91%.
- The New York-labelled DI+ADX treatment was unchanged in both October
  windows, reduced November loss by 135.84, and worsened December by 396.25.
  There is no support for a universal New York rule. The calendar's broad
  residual label must not be advertised as the whole New York core session.
- A veto does not guarantee fewer eventual trades: December New York DI
  vetoed eight candidates yet finished 52 trades versus control's 48 because
  subsequent position/account paths differ. Whole-account attribution matters.

Session/regime/volatility are actual declared eligibility axes. Venue phases
are retained descriptive observations, not newly proven phase-specific rules.
For example, a London-labelled opportunity can retain an Asia venue phase;
neither label is silently substituted for the other. Per-cell cash or trade
utility was not manufactured from these event counts.

### Separately versioned observed teaching

The exact verified post-terminal resource is
`research/instrument-operating-skills/curator-context-observations-v5.json`,
445349 bytes, SHA
`3a62699181d30e0c549141e9099c0872366818b4831f31c80239e3f0fd15c231`.
Explicit `curator_context_observed_knowhow_20261015_v5` links these original
28 rows and 24 **whole four-method bundle** contrasts to the reviewed v4
instructions. It does not copy bundle cash into individual-method grades.
Selected teaching preserves all original bundle refs and its exact control;
it projects relevance, not the physical experiment identity. Technical cash,
trades, clock and nonexistent response identities remain null, not zeros.

V1-v4 remain immutable, including v4's literal `awaiting_original_panel` source
instruction. The v4 curriculum was captured before this extension as
`dc8b14ccf4d6e846d3517ceaaa3b8206fc51f15fddde6a5c132d97f977609f24`;
its selected London-normal/trend-up DI teaching pack was
`253ccdbfa5ae84b85a6cfcef77f4d8062f59a62f41fb69eb839821a2d4062b58`.
Publishing observations does not backdate that pending version. Default
curriculum and production environment are unchanged. No curator outcome is
an agent-owned experience, automatic preference, independent certificate,
skill credit, Master, Champion or paper/live authority.

This is the first deep contextual batch for an existing instrument, not the
completion of all 26 instruments. The separate inventory identifies remaining
missing-input, no-behavior-change and context-transfer questions. Further
curator batches need new bounded declarations; this panel is not repeatedly
tuned/replayed to select a winner. No actual broker demo or authorized
untouched validation was performed. Production code, DB and control were not
changed by this isolated research batch.

### Final software verification of this isolated batch

The combined contextual/source/practical-teaching/generation/old-curriculum
regression passed **89 tests / 4509 assertions** in 10m37.389s. This is one
combined focused run, not the sum of overlapping earlier checks. It includes
the genuine observed resource, original 28-row/24-bundle parsing, technical
null guards, clock integrity/parity, exact source-artifact inclusion, no
own-agent learning, all v1-v4 hash preservation and actual PHP-to-Python
canonical teaching metadata transport. The separate focused Python run passed
**57 tests** in 130.23s, covering DMI correctness/as-of/spoof refusal, existing
method/context behavior and complete cached/unwrapped replay-response parity.
Fourteen pure export-validator tests are software fixtures, not market cases.
Focused PHP lint, Python compilation and Project Map impact/generate/check
passed. No full-project suite, broker-demo, production activation, market
qualification or independent compounding acceptance is claimed.

### Separate management-practice follow-through, 2026-10-15

The next curator batch studied source-described trailing/Chandelier mechanics
and executed sixteen new originals: four management arms in each of the same
four already-seen 2025 weeks. All sixteen completed; negative and positive
continuous-account cash contrasts, current-context exit use, costs, closed-cash
drawdown and EOF censoring remain explicit. No old DMI result was replaced.
See [the management operating guide](curator-management-practice-2026-10-15.md)
for formulas, timing, exact declared comparisons, original hashes and limits.
An optional profit-delayed trailing question is separate prospective discovery,
not a larger-budget retry or independent validation of the original sixteen.
