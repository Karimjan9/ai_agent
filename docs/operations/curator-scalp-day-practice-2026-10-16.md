# Scalp and day trading source study

Scalp and day trading are now the two primary curator research tracks. The
assistant develops source-grounded operating knowledge; lab agents retain
their own separate policy and subsequent experience. This study uses the
existing XAUUSD M5 engine and already-seen pre-2026 archive. It does not
qualify second-level scalping, independent economic skill or broker fills.

## Sources and practical interpretation

[John Bollinger's rules](https://www.bollingerbands.com/bollinger-band-rules)
distinguish relative extremes from entry signals. A band touch alone is not a
reversal; prices can remain near a band in a strong trend. The usual 20-period,
two-standard-deviation settings are defaults, not a universal optimum. These
rules support studying a completed-price recross separately from an outside
fade, without assuming either is profitable.

[Fidelity's Bollinger guide](https://www.fidelity.com/learning-center/trading-investing/technical-analysis/technical-indicator-guide/bollinger-bands)
describes the moving-average envelope and a return inside the bands after an
outside move. It also cautions that trends can hug the bands. The lab adapts
that idea into two successive closed-bar conditions with directional price
movement. The exact entry, confidence, holding and ATR geometry below are lab
hypotheses, not a reproduced author-certified trading system.

[StockCharts' Price Channels guide](https://chartschool.stockcharts.com/table-of-contents/technical-indicators-and-overlays/technical-overlays/price-channels)
uses prior-period highs/lows, excluding the current bar, and discusses both
breakouts and whipsaws. This supplies a complementary continuation question.
The executable lab uses a strict closed-price break and next-open execution;
it does not assume a touched order was filled. Textual rules and calculations
were reviewed; chart pixels, linked books and videos were not reviewed.

### Further webinar learning after the declaration

The original question was already sealed when the assistant additionally read
the relevant extracted text from Fidelity's
[Technical analysis for volatile times webinar transcript](https://www.fidelity.com/bin-public/060_www_fidelity_com/documents/learning-center/Transcript_Tech%20analysis.pdf),
printed pages 20–34. This did not change any request. The useful distinctions
are momentum-zone exit versus merely being extreme, volume as confirmation
rather than an automatic reversal, band riding versus range oscillation, and
ATR as move magnitude rather than direction. ATR-based stop distances still
need position sizing and actual gap/cost treatment; no multiple guarantees a
good stop.

The transcript's classroom two-SD/95% shorthand is **not** adopted as a market
probability. Bollinger's own rules explicitly warn against normal-distribution
assumptions. Provider BID volume is also not interchangeable with exchange
traded volume or order flow. These are teaching limits and candidates for
separate prospective experiments, not extra tested components in these eight
originals. The video, chart slide pixels and cited textbook were not reviewed.

## Executable use rules

The two existing registered programmes remain distinct:

- `session_v1`, base `session`: BUY when the completed close exceeds the
  preceding 20 highs; SELL when it falls below the preceding 20 lows. Current
  high/low cannot define its own breakout threshold.
- `session_mean_reversion_v1`, base `session_mean_reversion`: calculate SMA20
  and sample standard deviation over completed closes. BUY requires the prior
  close below its prior lower two-SD band, the current close back at/above its
  current lower band, and a higher current close. SELL mirrors those conditions.
  The sample-SD convention is the existing lab implementation, not a claim
  that the sources prescribe the same numeric convention.

Both use the prospectively selected UTC 07:00–14:00 signal and entry schedule.
That is not a London/New York local-session label and does not automatically
follow daylight-saving time. Decisions use the preceding completed candle and
execute at the next modelled open. Existing confidence `.35`, spread/ATR,
cooldown, volatility and risk gates still apply. A recross near the mean may
therefore remain WAIT; confidence is a quality score, not a calibrated win rate.

## Entry and risk checklist

1. Verify the intended registered strategy **and its base**. Setting the reentry
   programme's base to `session` would select breakout, not recross.
2. Require sufficient completed history and positive band width. Flat history
   produces WAIT with zero confidence. A numeric missing value now avoids the
   former pandas object-comparison exception; it does not invent a setup.
3. Distinguish a wick touch, outside close and completed recross. Do not fade
   every band tag or interpret low sample count as a harmful-instrument verdict.
4. Keep the same initial 10,000 cash account, 1% risk, ATR stop 1.5, target 2.5,
   leverage ceiling, commission, spread, slippage and carry assumptions across
   compared arms. No trailing or partial exit is enabled in this question.
5. Treat missing quotes/order flow as unavailable evidence. The M5 quote
   sidecar is not a complete intrabar path; these originals retain the existing
   fixed modelled execution costs rather than claim broker-demo realism.
6. Record daily activity, losing/zero-trade days, holding, EOF exposure and
   context alongside net cash. More trades alone is not improvement.

## Prospective eight case comparison

| Arm | Entry programme | Time stop |
| --- | --- | --- |
| breakout_scalp6 | Prior-20 high/low breakout | 6 observed M5 row transitions |
| reentry_scalp6 | Closed Bollinger recross | 6 observed M5 row transitions |
| breakout_day24 | Same breakout | 24 observed M5 row transitions |
| reentry_day24 | Same recross | 24 observed M5 row transitions |

Each runs once on 13–17 October and 8–12 December 2025. Full loaded rows are
1,556 and 1,568, including exactly 200 execution warmup rows; actual decision
rows are 1,356 and 1,368. There is no hidden shorter tail or new independent
holdout. Account/clock/price/cost evidence is compared within each week, never
by pooling overlapping arm trades into an independent sample.
Existing outcome-driven calibration and cooldown may evolve differently after
different entries/exits. Contrasts therefore measure the whole account under
each frozen programme, not an isolated contribution on identical trade paths.

Six and 24 rows nominally represent 30 and 120 minutes on contiguous M5 data.
The report separately counts actual same-UTC-date closes, over-cap holdings,
zero-activity dates and open exposure. The ordinary engine has no general
session-flat contract: entry hours and row timers cannot guarantee flatness
across missing candles or broker outages. Its existing time-stop-open branch
precedes gap/SL/TP checks. Same-bar entry/exit timestamps do not resolve the
intrabar fill time. These limits remain part of the operating guidance.

## Original evidence and research boundary

All eight PHP-prepared request bytes passed actual Python schema, execution
hash and registry-function validation before outcome access. The before-outcome
spec is `87e1b294f12ef9a2360e0a7c02b7f45bf9c69a0de2009c7c4f3ef1bdf83ace8c`.
Original private products are under `.runtime/intraday-originals-20261016/` in
this isolated research tree. Request/source/data/runtime bytes and the raw
source archive precede dispatch. The study allows one serial hidden child,
600 seconds per original and a 5,400-second global cap, with no renewed budget.

The new helper checks both UTC and monotonic absolute deadlines at short
intervals, before/after computation and before publication. Late completion or
a regressed clock is technical/unknown, not zero PnL. An unreaped owned child
aborts further launches. This replaces no old original evidence and makes no
claim that the production supervisor was changed.

All eight originals completed under their original caps. Independent read-only
reopening reverified every response, witness, full clock, account and comparison;
the source archive remained unchanged. Original summary SHA256 is
`ab2081764e26db5942297d81c5826b63e1213a900d4ec86bd6ef0f5c27f9d2f6`;
source ZIP is `4f3a26dd32fcd7f2a2d2d0392931a303d8fb8e8ebb5ece27727556ec3d197f64`;
artifact roster is `c774c2b984ce784be8709495913d0ed8aa197c58a0abb2b8fa4ae3423c233d24`.
No original was rerun, relabelled or replaced.

## Observed account results

Net values below are modelled account cash after the unchanged costs, not
broker-demo currency statements. Initial cash is 10,000 in every arm.

| Variant | October closes | October net | December closes | December net |
| --- | ---: | ---: | ---: | ---: |
| Breakout scalp6 | 22 | +188.83 | 26 | -0.53 |
| Bollinger recross scalp6 | 17 | -138.34 | 19 | -149.49 |
| Breakout day24 | 20 | +396.37 | 23 | -152.69 |
| Bollinger recross day24 | 15 | -483.34 | 18 | -241.08 |

Every mature close was on the entry's UTC date, with no EOF-open position.
All scalp holdings were at most 30 reported minutes; all day24 holdings were at
most 120. Breakout scalp daily closes were `4/4/5/4/5` and `3/6/3/4/10`.
Recross scalp daily closes were `3/2/4/3/5` and `3/3/3/5/5`. Both short profiles
thus produced several completed trades on each of the ten observed dates,
without a mandatory trade quota. These are overlapping strategy comparisons,
not 160 independent observations to pool across arms.

The 24-row timer improved October breakout by +207.55 but worsened December
by -152.16. It worsened recross by -345.00 and -91.59 respectively. Holding
longer is not a universal intraday improvement, nor is October's profit a
replicated profitable skill.

## Contextual know how and next tests

The following are **post-outcome exploratory trade groupings**, not an
experiment that traded only in those contexts. Different entry filters would
change account state, sizing, calibration and subsequent opportunities.

| Scalp method and entry context | October sample and cash | December sample and cash |
| --- | --- | --- |
| Breakout, trend-up + normal volatility | 5 / +292.76 | 5 / +117.93 |
| Breakout, range + low volatility | 4 / -67.90 | 2 / -92.48 |
| Recross, range + low volatility | 3 / +26.54 | 3 / +103.87 |
| Breakout, London/COMEX overlap phase | 7 / +310.76 | 5 / -85.93 |

This is useful operating knowledge, not a phase permission or harmful ban.
Breakout's trend/normal slice is a candidate for a prospectively declared
context test; its losing range/low slice cautions against indiscriminate
continuation. Recross lost at account level but had small positive range/low
groups: investigate whether source-relevant range recognition and exit logic
explain that observation instead of declaring Bollinger useless. The overlap
sign flip explicitly contradicts an unconditional "London works" rule.

Next, freeze context eligibility and the source-relevant exit comparison
**before** a new original question, preserving exact controls, all attempts
and risk. In particular, a band-recross adaptation with a generic ATR target
does not test every range-to-midline or band-to-band use described by the
sources. A later filter must execute its own whole-account control/ablation;
it cannot inherit a selected subgroup's cash as proven benefit.

The reusable curator record is
`backend-laravel/resources/research/instrument-operating-skills/curator-scalp-day-observations-v1.json`.
It retains original hashes, unrounded positive/negative outcomes, daily counts,
holding and scoped context examples. It is not an automatic curriculum update:
agents must still generate their own subsequent original experience.

Verification: 36 new declaration/deadline/strategy tests, 47 existing focused
schema/tactic/router/metrics tests, and 3 actual PHP parity tests / 31 assertions
passed. The first old-metrics invocation had one test-helper import failure;
the correct test-directory import path passed the full 47 without source or
market-request changes. Four observation-integrity tests also passed against
the retained originals: 87 distinct Python tests plus the 3 PHP tests overall.
PHP lint, changed-Python compilation, diff checks and final Project Map
impact/generate/check passed. No full-project suite is implied.

Broker demo is not configured. The user explicitly chose to continue within
the project, so no broker order is submitted. Production, agent experience,
credit and trading permissions are unchanged. All 2026-and-later market
observations remain paper-only.
