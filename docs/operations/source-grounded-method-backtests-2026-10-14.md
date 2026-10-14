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
