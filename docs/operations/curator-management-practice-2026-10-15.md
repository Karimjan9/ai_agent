# Curator practice: ATR trail and Chandelier full-close management

## Qisqa amaliy yo‘riqnoma

Bu paketni men o‘rgangan va sinagan curator ko‘nikmasi sifatida o‘qing;
agentning o‘z tajribasi yoki tasdiqlangan foydali trading skill deb emas.

1. Avval entry, boshlang‘ich stop, target, risk va xarajatni belgilang.
   Chandelierning o‘zi entry strategiyasi emas. Boshqa setupga o‘tkazish
   yangi savol bo‘ladi; bu yerdagi EMA50/200–RSI14 natijasini ko‘chirmang.
2. ATR turini aniq yozing: bu native trail mean-TR14 ishlatadi; Chandelier
   Wilder ATR22 ishlatadi. Nom bir xil bo‘lgani hisoblash bir xil degani emas.
3. Qarorda faqat oldingi yopilgan shamdan foydalaning. Chandelier exit
   so‘rovi keyingi open’da bajariladi; gapdagi native stop/target ustunligi
   saqlanadi. Grafikdagi chiziq — kafolatlangan fill narxi emas.
4. Native trailing stopni orqaga bo‘shatmang. Chandelier chizig‘i esa o‘zi
   orqaga siljishi mumkin: uni native ratchet bilan adashtirmang.
5. 1R aktivatsiya, stopni entryga ko‘chirish va xarajatlardan keyingi
   breakeven — uch xil qoida. Bittasining natijasi qolganini isbotlamaydi.
6. Session, trend/range va volatilityni yozing, lekin kam kuzatuvni “bu yerda
   hech qachon ishlamaydi”ga aylantirmang. Calendar orqali keyin topilgan
   sessiya labeli agent haqiqatan ishlatgan input bilan bir xil dalil emas.
7. Faqat foydali misolni emas, erta kesilgan winner, yo‘qotish, nolga yaqin
   farq va davr oxiridagi ochiq pozitsiyani ham saqlang. Yopilgan cash natijasi
   to‘liq marked equity yoki tugagan iqtisodiy imtihon bo‘lavermaydi.
8. Bu ko‘nikma yangi research savolini tanlashga xizmat qilishi mumkin.
   Agent keyin o‘z muhrlangan replayida foydalanib, o‘z natijasini yig‘adi;
   mening kuzatuvim unga soxta shaxsiy tajriba qilib yozilmaydi.

Quyida aniq usullar, original sinovlar, manbalar va cheklovlar keltirilgan.

## Status and purpose

This is assistant-curated practical research, separate from an agent's own
experience or policy. Preparation precedes this question's original outcomes.
The four raw October–December 2025 windows were already inspected and replayed
for other questions. They are reused discovery, not blind or independent tests.
No production release, broker demo, Master qualification or economic credit is
authorized by this document.

The question is: with the existing EMA50/200–RSI14 entries and initial risk
unchanged, what happens when a prior-closed ATR stop ratchet, a closed Chandelier
exit, or both manage positions? A method may improve realized cash yet cut
subsequent opportunities, or reduce one loss while truncating a larger winner.
All such outcomes, null effects, censored positions and technical failures stay
in the record. No universal winning moment is assumed.

## Reviewed material, not claimed video/book completion

- [StockCharts, Chandelier Exit](https://chartschool.stockcharts.com/table-of-contents/technical-indicators-and-overlays/technical-overlays/chandelier-exit):
  reviewed calculation and interpretation. The daily default combines a
  22-period extreme and three times Wilder ATR22. The plotted level can move
  against the position; it is not automatically a monotonic trailing stop.
  An exit tool does not supply an entry system.
- [Fidelity, Plan your escape webinar transcript](https://www.fidelity.com/bin-public/060_www_fidelity_com/documents/learning-center/100721_Plan_%20your_escape_TRANSCRIPT.pdf):
  reviewed PDF pages 8–18 and 34–37, not the full recording. A trailing stop
  follows favorable prices and then holds; stop-market and stop-limit have
  different fill risks. Sizing depends on stop distance and cash risk.
- [CME, Proper Position Size](https://www.cmegroup.com/education/courses/trade-and-risk-management/proper-position-size):
  reviewed the public lesson. Stop location and allowed cash risk determine
  size. No suggested percentage overrides the laboratory's frozen risk.

No full Elder/Wilder book, paid course or full YouTube video is claimed as
studied. Publisher examples are not our performance evidence. Exact M5 timing,
context coverage, initial stops/targets and trial budgets below are laboratory
choices, not a reproduced profitable masterclass.

### Additional source study during the fixed activation panel

[CME / Jeff Quinto, Getting Started](https://www.cmegroup.com/content/dam/cmegroup/education/files/education_team_booklet.pdf),
extracted text on printed pages 4–20, was reviewed; the associated three videos
and chart pixels are not claimed as watched. It presents trend/retracement
setups with EMA21/Keltner references, protective stops and favorable-price
trailing on 377-tick ES charts. Entry location, stop, trading hours and logging
belong to the complete plan, not an indicator-only signal. The sample is not
an audited performance record. “Near the average” is still an underspecified
limit-order rule. The short flag example's protective-order wording is
inconsistent with its short side and must be resolved, not blindly compiled.
Its eight-ES-tick setting is not an XAUUSD risk rule. Our M5 OHLC and BID-volume
field cannot reconstruct a 377-tick chart or its intrabar order fills. An
exact reproduction therefore has input/execution dependencies; a later M5
adaptation would be a separately declared curator hypothesis. Nothing from this
new reading changed the running twelve requests or their budgets.

[Fidelity's ATR calculation](https://www.fidelity.com/learning-center/trading-investing/technical-analysis/technical-indicator-guide/atr)
uses recursively smoothed true range. ATR measures magnitude, not direction.
That formula is distinct from the existing mean-TR14 native management series
used in these controls; its name cannot silently justify changing the series
mid-study. [CME's position/risk lesson](https://www.cmegroup.com/education/courses/things-to-know-before-trading-cme-futures/position-and-risk-management)
also separates contract choice, contract count and stop placement. More
available margin is not evidence that more units are appropriate. These are
operating cautions, not newly backtested variants or a relaxed external limit.

## Executable operating instructions

### Native ATR2 ratchet

1. Preserve the existing entry, cash-risk sizing, initial ATR stop and target.
2. Before the current execution bar, read only the preceding completed close
   and the existing management mean-TR14 ATR. This is not Wilder ATR22.
3. BUY: replace the stop by `max(old_stop, previous_close - 2 * ATR14)`.
   SELL: use `min(old_stop, previous_close + 2 * ATR14)`.
4. Never loosen an existing stop. This native implementation can tighten it
   immediately on the entry bar; it does not require profit or a partial exit.
5. Native gap, stop and target execution still apply with the existing
   conservative intrabar policy and modeled spread/slippage/commission/carry.

This is position management, not a new entry signal. A tight trail can turn
normal noise into exits and change every subsequent entry in a shared account.
Its whole-program comparison is therefore not an isolated per-trade effect.

### Chandelier22x3 close-confirmed exit

1. Use only original completed OHLC. Compute rolling HH22 and LL22, and Wilder
   ATR22 with the existing arithmetic seed. Require positive available ATR.
2. Long level is `HH22 - 3 * ATR22`; short level is `LL22 + 3 * ATR22`.
3. On an already owned BUY, strict completed close below the long level requests
   exit. On an owned SELL, strict close above the short level requests exit.
   Equality or unavailable history does not request a Chandelier exit.
4. Execute that request at the following bar's open, not retrospectively at
   the signal close or at the plotted line. A native stop/target breached at
   that open takes pricing precedence. The entry candle has no typed exit.
5. Select the method by the **current** closed context, not the entry context:
   four declared regimes, four calendar-owned sessions, normal/high volatility.
   Thirty-two scalar recipes represent **one algorithm**, not thirty-two
   empirically qualified skills. Low/unknown contexts retain BASELINE; the
   native protective stop and target are never disabled by this exclusion.
6. Preserve real session and venue-phase labels, including DST. Do not relabel
   an unavailable context or remove the independent target to make this exit
   win. The line itself is not ratcheted; the hybrid adds a separate native
   ratchet with the precedence above.

## Before-outcome design

| Arm | Native ratchet | Chandelier policy |
| --- | --- | --- |
| control | off (`0`) | BASELINE |
| native_atr_ratchet | multiplier `2` | BASELINE |
| chandelier_close | off (`0`) | declared current-context recipes |
| hybrid | multiplier `2` | same declared recipes |

Each of four windows has all four freshly compiled contracts before the first
original starts. The existing physical baseline retains initial account 10000,
risk 1%, initial ATR stop 1.5, target 2.5, no partial, no time stop, session
schedule 01–22 UTC and its exact existing costs and risk gates. Only the declared
ratchet gene and exit policy differ. All arms have the same 32-recipe pool;
control/native have no selection rules, avoiding extra unknown library changes.
Sixteen regime/session rules each list the two exact volatility recipes,
respecting the existing 16-rule bound; low volatility matches neither recipe.
The entry and sizing **rules** stay unchanged, not every realized entry or size:
different exit timing, available capital and existing outcome-dependent gates
can legitimately change later trading. This is why the primary contrast covers
the whole continuous account rather than forced matched individual trades.

The bounded private process runs serially, hidden and below-normal priority,
one thread, at most 16 originals, one attempt each, 600 seconds per original
and 10400 seconds total. Those are offline curator budgets, not signed API or
full-validation admission. A timeout remains technical history; no observed
result can obtain a larger deadline or a replacement from this declaration.
No winning parameter, window or fixture is selected after seeing outcomes.

Original request bytes, source/helper snapshot ZIP, runtime identity, selected
CSV bytes and calendar proof precede execution. Every raw response and stderr
is retained before validation. The existing executor, not a new replay engine,
produces the trades. A process-local read-only witness observes existing raw
entry/close events and restores its hook on exit; synthetic complete-response
parity must pass before the source barrier is frozen.

## What the evidence can and cannot say

- Primary contrast: realized net cash of each whole continuous account versus
  its matched control. Conditional ratchet/Chandelier and interaction contrasts
  require all four valid originals for that window. No window averaging hides
  a missing arm or creates another independent sample.
- Strict entry-linked stage hashes and unrounded full-close events permit
  checks of executable-price returns, size, initial risk, commission/carry,
  cash compounding, chronology and open-at-EOF maturity. Modeled fills are not
  observed broker fills. Explicit commission/carry is not the total spread cost:
  spread/slippage are already embedded in executable prices.
- Drawdown reconstructed here is **closed-cash drawdown**, not marked account
  drawdown. Open EOF exposure remains censored and is not forced into cash.
  A completed software response with an open position is not a fully matured
  economic account. Its realized-cash contrast may be displayed as observed
  cash only; unequal terminal exposure forbids a total-utility winner claim.
- Completed-bar pre-exit MFE/MAE are conservative bounds. The entire exit-bar
  high/low can contain movement after an open-time exit; it is not a known
  intrabar path or a verified profit-capture percentage.
- Original exit-event contexts explain reach, availability, requested exits
  and BASELINE exclusions. Entry-context PnL or sums of event cells are not
  context-specific causal profit. Small cells remain unknown/underpowered.
  A requested typed exit is not automatically a typed-exit fill: a native
  stop/target breached at that open can retain pricing/reason precedence.
- Ten closed trades is a descriptive diagnostic floor, not a power calculation,
  p-value, independent replication or skill certificate. Positive local results
  become curator know-how for another prospective question, not trading authority.

## Explicitly separate next engineering work

The existing partial runner records a partial price and mixes it into the final
return. It does not settle cash, reduce live units, timestamp a separate fill or
allocate carry/fees by leg. It cannot validate genuine scale-out, pyramiding,
averaging down or a risk-normalized addition skill. A separately versioned real
fill/units/cash owner and its invariant tests are required before those claims.
This full-close experiment neither fixes nor disguises that limitation.

The curator library remains independently versioned. Agent-owned experience
must come from that agent's original admitted execution, never copied curator
outcomes. All 2026 and later market observations remain paper-only.

## Prospective refinement question, not a replacement attempt

The first October originals already reveal a practical failure mode: immediate
ATR2 ratcheting sometimes exits a later target winner, although it also improves
other individual exits. These selected same-entry examples explain a mechanism;
they do not independently establish the best activation threshold. A second
question is therefore fixed **before its own outcomes**: does waiting for a
completed close at one initial price-risk distance improve this management plan?

The optional `trailing_activation_r=1.0` hypothesis uses a prior **completed
market close** relative to the executable entry and immutable initial price-risk
distance. It is not one net account-R after spread, fees and sizing. The closed
bar must belong to the holding period; pre-entry highs and the current bar's
already-updated MFE must not arm it. Once armed it stays armed, then uses the
unchanged native ATR2 ratchet and never loosens the existing stop. Exact one-R
and this M5 implementation are curator hypotheses, not settings recommended by
the webinar. Missing/zero activation retains legacy behavior.

Activation is **not a break-even stop instruction**. For an illustrative BUY
with constant ATR, an initial 1.5-ATR risk means the one-R trigger is entry plus
1.5 ATR. Subtracting a two-ATR trail there proposes entry minus 0.5 ATR; the
ratchet can therefore still leave a losing stop. Changing ATR, spread, fees,
gap execution and slippage further separate price-R activation from net
break-even. This is arithmetic of the declared rule, not a claim about one
selected winning trade. A break-even floor would be another intervention and
must not be inserted into these twelve originals after seeing outcomes.

The proposed separate panel is three arms in each of the same four already-seen
raw windows: no trail, immediate ATR2, and ATR2 after the closed one-R trigger.
All twelve originals must use the same new source. There is no Chandelier or
agent-policy selection in this question; initial risk, entries, stop/target,
costs and full-close accounting stay fixed. Caps are 600 seconds per original,
7800 seconds total, serial hidden execution and one attempt each. The earlier
sixteen originals will remain unchanged, and cannot be merged across source
versions to fill a missing new arm. A fresh physical-clock/custody inspection
and source/request barrier precede execution.

Preparation happens in a second isolated worktree while the original sixteen
run. No feature is copied into their frozen source. Ordinary random mutation,
default vectors and existing capability cards must remain unchanged; broader
meta-model, canonical study-deadline or adapter acceptance is a separate typed
integration dependency, not silently bypassed by an offline prototype.

## Retained interim observations from the first completed window

These curator notes were recorded after the original first October window,
before all sixteen were terminal. They are retained alongside the completed
whole-batch results below, not selected as the final favorable window.

- Chandelier made ten exit requests: two in a current `trend_up`, three in
  `range`, five in `trend_down`. Entry justification and current management
  context are different things. A trend position can need protection after its
  initial regime changes; a London/trend-up-only exit can miss that situation.
  No transition observation was reached here, so transition utility is unknown.
- Requests appeared in all four calendar labels: Asia two, London two,
  overlap three, broad residual `new_york` three. Counts show actual reach,
  **not which session has causal economic edge**. The original venue phases and
  unknown low-volatility eligibility remain attached to the event cells.
- Same-entry illustrative records include an immediate ATR stop cutting the
  October 13 08:00 BUY before the control's later target, and another October
  14 07:30 BUY where the trail closes positively before the control's loss.
  A shared entry timestamp does not establish identical preceding account
  history or an isolated matched-trade effect. The full-account comparison is
  still primary. Both kinds of example belong in instruction, not just wins.
- Native trailing is not activated by the old partial flag. Assuming that
  would teach a rule the program never executes. A profit-delayed version
  needs its own closed-price state and separate prospective comparison.

Practical instruction supported by mechanics is already usable: distinguish
the two ATR estimators, specify trigger time and fill time, retain independent
hard protection, keep current context distinct from entry context, and count
actual closes separately from evaluated methods. The economically preferable
settings remain an empirical research question, not a publisher guarantee.

## Completed original sixteen: cash observations, not total utility

All sixteen originals completed on the fixed source and budgets. Their exact
requests, raw responses, full-close witnesses, physical clocks, cost identities
and factorial arithmetic were reopened before this additive export. Original
summary SHA256 is `5033e6744652605b5101e6a06c78419712903eef5a5d043e76f96cc482860497`;
the curator-only observation resource SHA256 is
`e2f93af1bfa7a82effe0b6caf0f6fd87eb20dfc078bc93c72f7ffc0982a0856f`.
Original spec SHA256 is
`d3fece9fce251742210598ee04840eaef5e395a73da60114c4ac38a2122ccb2c`.

Each cell shows realized net cash, followed by mature full closes. Initial
account is 10000 per original; open positions are not liquidated to improve
the comparison.

| Already-seen 2025 week | Control | Immediate ATR2 | Chandelier | Hybrid |
| --- | ---: | ---: | ---: | ---: |
| October 13–17 | +601.99 (67) | -97.97 (83) | +424.34 (72) | -97.12 (84) |
| October 27–31 | +739.06 (63) | +835.56 (74) | +508.62 (71) | +643.11 (79) |
| November 3–7 | -1051.14 (71) | -1010.35 (86) | -993.99 (78) | -959.03 (90) |
| December 8–12 | -174.31 (48) | -835.10 (85) | -523.27 (81) | -772.79 (93) |

October 13 control, all November accounts, and December control/Chandelier
retain one EOF-open position each. Only the second October comparison is
fully closed across all four arms. Equal open-position counts do not establish
equal open exposure or terminal marked value. These figures are therefore
realized-cash observations, not a total account-utility ranking.

### Practical conclusions retained, including contradictions

- Immediate ATR2 is not uniformly protective economically: its control cash
  contrasts are -699.96, +96.50, +40.79 and -660.80. It changes subsequent
  opportunities as well as individual stop prices. The second October week
  does not prove that DST mismatch causes a trailing advantage.
- Chandelier's control cash contrasts are -177.66, -230.44, +57.14 and
  -348.97. In the second October week its closed-cash drawdown fell from
  4.55% to 3.76% while realized profit also fell. Return and risk objectives
  must be specified separately; neither metric alone earns a universal skill.
- Hybrid interaction varies by week (+178.51, +37.99, -5.83, +411.28 cash).
  A positive interaction does not mean the hybrid beats control: it did not
  in either October week or December. November's smaller loss remains a loss.
- Actual Chandelier closes, not merely evaluations, were 10/10/17/22 without
  the native trail and 6/8/12/18 with it. This proves use of this declared exit
  path; it does not establish a profitable session/regime specialization.
- No session gets a learned economic ban or preference from event counts.
  Current-context reach, small cells, missing quotes and modeled execution
  remain attached to the original records. No genuine order-flow, partial-fill
  management, marked drawdown, broker demo or independent confirmation follows.

The next one-R activation question is a new curator hypothesis informed by
these observations, not another attempt to replace any of the sixteen results.

## Separate activation panel: fixed before new outcomes

All twelve explicit float-valued requests passed the real PHP-to-Python
schema/cost/data contract without replay. They were frozen under spec SHA256
`a4dbdc292f9239e8f5fd298a647be8449dcd55112f82ef8c3976bce50b02335e`,
source ZIP SHA256
`7f0c0fb495d4be4a548aaee313044693ca03b51107700a02a72c790e416e150e`.
The new backtester SHA256 is
`57806ccdc76507fb036213363174017e7194b4f2ac9f3d6842b3f6078a36e8c2`;
source-bound selected-calendar audit SHA256 is
`56939bbdb421f9ad810a3caebe3a07b15cc73112275df013d7fb92477ed41b05`.
The hidden below-normal parent was launched 2026-10-15 17:00:33 UTC; original
replay starts only after frozen-source validation. This is not a broker demo,
running HTTP service test or canonical cohort dispatch.

The source roster, unchanged original inputs and ZIP are checked per original.
Workers share the parent's monotonic global deadline and have a separate
600-second ceiling, including a direct-invocation watchdog. No arm can obtain
a larger deadline from a failed or favorable result. Original wire responses
precede validation; actual entry-linked activation, valid pre-trigger holds,
unavailable input, pre-entry ineligibility and counterfactual shadow calls
remain separate. Only original full closes contribute realized cash.

Preparation verification: 43 combined focused Python tests passed, including
same-input whole-response legacy parity, BUY/SELL/latch/as-of/shadow behavior,
raw-event tampering and full-close mechanics. Eleven focused Laravel tests /
956 assertions passed. Nineteen private runner and twelve private exporter
self-tests are software checks, not additional market cases. Project Map
generation/check passed before the new panel began.

## Completed activation refinement: all twelve originals

All twelve completed, without a timeout, replacement attempt, changed source
or enlarged budget. Original summary SHA256 is
`54d9565735f6ec24a404c075b033b60c96ce9604498efee725230ef484ac016c`.
The parent's actual bounded execution/verification interval was 2204.05
seconds, not a broker execution latency or an API-cost estimate. Every control
and immediate-ATR2 result reproduced its earlier physical result on the new
source; the older sixteen were not substituted into this new comparison.

| Already-seen 2025 week | Control cash | Immediate ATR2 cash | Closed-1R ATR2 cash | Actual positions armed at 1R |
| --- | ---: | ---: | ---: | ---: |
| October 13–17 | +601.99 | -97.97 | +368.13 | 33 of 79 |
| October 27–31 | +739.06 | +835.56 | +582.10 | 25 of 72 |
| November 3–7 | -1051.14 | -1010.35 | -1050.56 | 18 of 74, including one open |
| December 8–12 | -174.31 | -835.10 | -234.49 | 20 of 59, including one open |

The first October control, all November arms, and December control/delayed
arms retain one EOF-open position. Mature full closes in the delayed arm were
79, 72, 73 and 58; open positions are not extra settled trades. The second
October triplet has no EOF-open position. Cash is not total marked utility.

Relative to immediate trailing, delayed activation changed realized cash by
+466.10, -253.46, -40.22 and +600.62. Relative to control, its changes were
-233.86, -156.96, +0.57 and -60.18. It therefore did not establish consistent
superiority over either comparator. November's +0.57 is not meaningful proof
of an economic edge. No pooling, changed target, selected week, break-even
floor or extra tuning is used to turn these observations into a winner.

### What the curator now teaches

- Distinguish immediate protection, price-R activation and net break-even.
  Each has a different condition and can produce different subsequent trades.
- Delaying a trail can preserve movement an immediate trail cuts, but it can
  also surrender an early protection benefit. The four complete comparisons
  retain both effects rather than a universal instruction to wait for 1R.
- Count actual latch/stop actions separately from evaluation and shadow
  opportunities. Counterfactual shadows are not trades or agent experience.
- Retain a useful executable practice and its contradictory results for new
  research. Do not promote it into a default economic recommendation, Master
  certificate, harmful global ban or agent-owned positive outcome.
- A session label derived later from a timestamp is descriptive metadata,
  not proof that the policy consumed that session or had a causal session
  advantage. The next contextual question must have its own explicit design.

The new optional gene remains an explicit offline research prototype, not a
new generic mutation axis or a delivered native-council/inheritance adapter.
This batch completed assistant-curated practical management research; it did
not complete empirical mastery of all 26 instruments, genuine partial cash
fills, independently confirmed economic skill, broker demo or Champion Council.

## Final retained knowledge and context coverage

Post-terminal inspection reopened all twelve original requests, responses,
full-close/activation witnesses, exact physical clocks and the frozen source
archive. All three contrasts per week were rederived. Technical/unattempted
cases are zero; no economic authority was issued. The separate calendar
projection reopened the same twelve and preserved every raw context field.

- [Management observations](../../backend-laravel/resources/research/instrument-operating-skills/curator-management-observations-v1.json):
  SHA256 `e2f93af1bfa7a82effe0b6caf0f6fd87eb20dfc078bc93c72f7ffc0982a0856f`.
- [Activation observations](../../backend-laravel/resources/research/instrument-operating-skills/curator-activation-observations-v1.json):
  SHA256 `cd7e00d0c31d1cc35990434660f1f5de3bf8b46103dc7579bfba63027cf95594`.
- [Descriptive calendar usage](../../backend-laravel/resources/research/instrument-operating-skills/curator-activation-session-usage-v1.json):
  SHA256 `f8bd8c81e1bc0e7064d99f2ddebd9bdd1eccf72129c0664575603b7aadf71c95`.
  Original private projection SHA256 is
  `b4a08c552bed72eee9e159a7f7cafa2577e0c4b4e29fda9e06c095908291f193`.

Across all four delayed-activation originals, the calendar description yields:

| Default calendar label | Actual owned calls | Newly armed positions | Stop-tightening calls |
| --- | ---: | ---: | ---: |
| asia | 1404 | 43 | 73 |
| london | 701 | 19 | 41 |
| new_york | 870 | 19 | 36 |
| overlap | 338 | 15 | 17 |

All 3313 actual calls, 96 arming events and 167 tightenings reconcile to the
original verifier; all twelve cases' actual and shadow totals reconcile in
the public usage resource. Counts are not exclusive profitable trades. Raw
session fields were absent and remain absent in the original witness.
The projection uses the archived ordinary edge-calendar point semantics at
the preceding candle's **open timestamp**, duration zero. It is not a new
close timestamp, whole-candle specialist membership or proof that a management
decision consumed a session input. The broad residual `new_york` label is the
existing calendar coordinate, not an independently defined FX session edge.
Configured rulebook/holiday behavior is not independent historical verification
of every holiday. No PnL is assigned to these session cells.

This is retained curator instruction and research know-how, independently
versioned from agent experience. Ordinary teaching defaults and older v1–v5
observations were not rewritten. Code and evidence remain on the isolated
curator branch; this delivery does not claim a production merge, worker boot,
broker demo, new generation, authorized holdout or current autorun health.

## Final focused software verification

One combined final Python run passed **49 tests in 79.58 seconds**: activation,
shadow/as-of/legacy parity, full-close witness and mechanics, plus six immutable
observation/session-total regressions. These are software tests, not forty-nine
additional market experiments. The earlier actual PHP catalog/context/schema
regression passed **11 tests / 956 assertions**; all four affected PHP files
linted. Each panel completed on its own frozen execution source; no original
outcome was rewritten. Post-terminal observations/tests/docs are additive.
Neither full-project coverage nor a production/broker acceptance is claimed.
