# Curator research: scalping practice, first bounded question

This is assistant-curated research, not agent-owned experience, trading advice,
broker demo, independent validation or a Champion qualification. Research date:
2026-10-16 (client clock). All 2026-and-later market observations remain paper-only.
The work is isolated from the active release and autonomous runtime.

## Aim and research order

Develop repeatable short-holding entry/exit practice using the existing tools,
with several quality opportunities on eligible active days, not a mandatory
trade quota. Study existing instruments first: EMA/trend and pullback, ATR/risk
and costs, volatility/range and confirmation, then true execution-supported
management. Higher-timeframe context is not permission to convert the main
product into hourly/day/swing trading. Secondary methods may supply ideas.

Keep three evidence levels distinct: correct executable mechanics; contextual
observed know-how usable in research; independently qualified economic skill.
Zero activation, selected profitable examples and repeated runs on seen data
cannot establish the final level. Retain null, negative, technical and unknown
results and all attempts. A failed variant does not ban an entire instrument.

## Source study and transfer fidelity

- [Fidelity EMA guide](https://www.fidelity.com/learning-center/trading-investing/technical-analysis/technical-indicator-guide/ema): reviewed calculation and interpretation. Rising/falling EMA gives lagging trend context; a retracement near it is not an unconditional entry. The publisher uses an SMA initial seed; the existing executable method uses `adjust=False` first-close recursion. This distinction remains explicit.
- [CME/Jeff Quinto companion booklet](https://www.cmegroup.com/content/dam/cmegroup/education/files/education_team_booklet.pdf): reviewed extracted text on printed pages 4–20, including the setup/stop/management and simulator-journal examples. Chart pixels and companion videos were not reviewed. The example uses ES, 377-tick charts, EMA21 and Keltner/ATR21. Its limit-entry wording is not a complete deterministic fill rule. Its tick distances and outcomes are not transplanted into XAUUSD.
- [StockCharts Keltner guide](https://chartschool.stockcharts.com/table-of-contents/technical-indicators-and-overlays/technical-overlays/keltner-channels): studied calculation and trend/range examples. Channel break and pullback are different events; parameter defaults differ from Quinto's example. Keltner is a queued hypothesis, NOT an executed component in this first question.

Our executable EMA20/50 four-bar pretrend/touch/reclaim and six-row time exit are
explicit curator hypotheses, not a reproduced profitable author system. M5 is
not a 377-tick chart. A touched limit is not guaranteed filled; this question
keeps the existing modeled next-open market execution, not invented limit fills.

## Question declared before outcomes

Does a prior-trend/touch/reclaim entry programme, and/or a six-row time exit,
change actual short-holding activity and after-cost results against the matched
existing proximity programme? Four arms on each of two already-seen weeks:

| Arm | Entry programme | Time exit |
| --- | --- | --- |
| proximity_unbounded | Existing EMA20/50 proximity + ADX/RSI | None |
| proximity_6bars | Identical proximity programme | Six observed row transitions |
| pretrend_unbounded | Existing closed pretrend/touch/reclaim | None |
| pretrend_6bars | Identical pretrend programme | Six observed row transitions |

Both entry programmes retain ADX strength20, RSI14 confirmation, confidence.35,
ATR stop1.5/target2.5, initial cash10000, base risk1%, leverage5 and the unchanged
modeled spread/slippage/commission/carry contract. No trailing, partial exit,
scale-in, agent policy or post-outcome threshold optimization is introduced.
PHP's actual schema prepares all requests; Python validates the physical vectors.
Differences are only `entry_topology_variant` and `time_stop_candles`.

The raw 2025-10-13–17 and 2025-12-08–12 windows are selected prospectively for
this question, but already used in older discovery. Raw parent/cells and their
custody/calendar receipts stay unchanged. Loaded rows1556/1568 include200
warmup each; actual decision rows1356/1368. No claim of untouched validation,
new independent samples or full-parent continuity is made.

All eight requests, data identities, source byte archive, runtime versions,
metrics and caps must be frozen before the first replay. One serial hidden
below-normal worker, one attempt/case,600 seconds/case,5400 seconds globally.
Timeout/error stays technical; no deadline extension, profitable replacement
or retry is authorized. Raw originals precede assessment and are never rewritten.

## Rules and WAIT conditions to learn

The proximity programme needs fast EMA above/below slow EMA, sufficient actual
ADX, close near fast EMA within.75 ATR and RSI on the directional side of50.
The closed pretrend programme additionally establishes both EMA slopes on the
earlier t-3/t-2 bars, then a t-1 touch and t reclaim in four contiguous bars;
BUY and SELL mirror. EMA20/50 must be mature. ADX/RSI, confidence, spread/ATR,
session, loss/cooldown and risk gates are NOT bypassed to create activity.
No current unfinished candle or future reclaim can be backdated into an entry.

Exact BUY sequence (all comparisons on completed bars):

1. At t-3 and t-2, EMA20>EMA50; both averages rise strictly between those bars.
2. At t-1, low<=EMA20 and EMA50<close<=EMA20; EMA20 remains above EMA50.
3. At t, close>EMA20 and close>close[t-1]; EMA20 remains above EMA50.
4. Actual ADX/RSI and downstream confidence/session/cost/risk gates still pass.
5. Only the next observed open can execute; SELL mirrors the geometry.

Four contiguous closed bars and mature fixed EMA20/50 are mandatory. This rule
is not a generic “price touched EMA, therefore BUY” shortcut. Native protective
ATR and indicator smoothing conventions stay exactly as implemented; a
publisher's Wilder ATR or alternate EMA seed is not silently substituted.

WAIT when setup, clock, required history or original confirmation is unavailable,
when spread/risk/session gates refuse, or when the exact context is untested.
The first study does not optimize a London-only/New-York-only selector from
its own outcomes. Entry-context tables are descriptive hypotheses for later
prospective questions, not a causal session profit certificate.

## Clock and accounting limits

Six M5 row transitions mean nominal30 minutes only in contiguous data. They do
not enforce wall-clock liquidation across maintenance/weekends. The existing
ordinary engine's time exit uses due-bar modeled open BEFORE its gap/SL/TP
branch. Preserve this behavior for matched diagnosis and report it; do not
present it as executable broker stop precedence. Session filters affect entry,
not automatic end-of-session liquidation. Same-candle entry/exit timestamps can
report0 minutes while their true intrabar duration is unknown. EOF positions
stay open/censored, never a fabricated mature close.

Measure complete round trips per UTC date, zero eligible dates, observed-clock
median/p90/max holding and gap exceptions, exact after-cost cash per close/day,
opportunities and WAIT gates, actual costs, closed-cash drawdown and unclosed
exposure. Cash-close drawdown is NOT marked account drawdown. Do not assign
unrealized EOF economics or use full exit-bar excursions as observed tick path.

Observed closing quotes are a sidecar, not actual fills. Fixed execution costs
are modeled assumptions, not a broker calibration. Sparse raw tick/M1 forensic
inputs do not qualify an entire research period for seconds/M1 scalping. No
synthetic M1 splitting, exchange orderflow inference or BID-volume-as-tick-count.

Read-only inventory found no matching native M1/full-tick files for these two
weeks in either known Dukascopy cache. Separate original HTTP-bound BI5 M1 days
exist for2024-10-09 BID/ASK,2024-12-12 BID/ASK,2025-03-03 BID and2025-12-01 BID;
they are forensic samples, not this panel. Ten original tick hours contain72297
BID/ASK records across sparse dates. Another31 BID-only cached M1 responses
contain30173 rows in August–September2023, but lack matching ASK/original HTTP
custody. These are candidate data-qualification inputs, not absent data to be
invented. The representative2023-08-10 payload SHA is
`0efce8ed8e066d4ead3388395017d0c6ef66df9d79fa64ba6f59955c0e495296`.
Later M1 research must separately qualify actual files, price side, gaps and
cost/fill semantics before freezing a new question. No download was performed.

## Learning separation and next decision

The curator retains source → exact rules → code → original control comparison
→ contextual guide. Agents later receive an explicitly pinned teaching pack
through their existing owner and must produce their OWN original outcomes.
This offline question writes no agent experience, policy, queue, credit or order.
No ordinary curriculum/defaults are silently changed.

Next work depends on evidence: insufficient setup reach → inspect the actual
gate; shorter holding but worse costs → cost/entry-quality hypothesis; apparent
improvement → bounded replication and independent evidence dependency, not
instant Master status. True partial exits, additions and scalp execution need
real leg/unit/cash/fee accounting before they can be claimed as practical skills.

## Outcome ledger

The original eight-request seal is
`d6c781a5908e67430c9d62d1f65f86c6a5357f8d6a71e730d2871dda3cefd6c4`.
Private originals live under `.runtime/scalp-originals-20261016/` in this
isolated worktree. The launch uses hidden, below-normal bounded processes.
Execution is terminal. Original process labels remain technical; the separate
reporting assessment below reopens retained calculation products, not replay.

The first original returned its response and full-close witness, then its
reporting projection failed with `SCALP_UTC_TIMESTAMP_REQUIRED`. The immutable
source manifest explicitly declares naive CSV cells as UTC M5 opens; the native
loader already uses `pd.to_datetime(..., utc=True)`. The new projection was
stricter than that source contract. This is a reporting error, not a strategy
loss. Source stays frozen until every original terminates. Original process
labels, raw products and attempted budgets will remain untouched; any corrected
assessment must be a separately versioned post-terminal appendix with no replay,
new attempt, independence, credit or successful-process relabel.

### Original termination and reporting correction

Seven originals returned response+witness, then failed only the CSV projection.
Their original600-second arm caps were respected. A requested40-second clock
wait later returned29960.4338 seconds; its cause is not proven. The eighth
worker remained alive after arm/global monotonic deadlines. Its exact own
PID/parent/executable/case/spec were verified, then only that expired worker
was terminated. The parent published all eight terminal records. No replay was
restarted, no cap enlarged and no process status overwritten.

The parent alone briefly used Normal priority AFTER all eight worker records
existed and zero Python children remained, solely to finish source checking and
summary publication. Scientific workers stayed hidden/BelowNormal. Supplemental
receipts record this boundary and the unexplained jump. Source/archive and all
original products passed the unchanged original inspector BEFORE correction.

Only the metrics module/test then changed: metrics v2 may decode naive CSV cells
as UTC only through the original hash-pinned manifest and matching window/path/
row/request identities. Witness/returned-event timestamps remain strict. The
appendix additionally rechecks original source ZIP, unchanged producer bytes,
all raw artifact hashes, physical clock, ledger, cash/cost math and budgets.
Over-budget, partial and unrelated failures stay unknown, never economic zero.

Original summary SHA:
`15341f0609617b83d107463b54d17b60daf8f76cceef373199a80ec22e611a94`.
Original source ZIP SHA:
`3ddb02013f8faaf069ec8c59e5273ad5ca6485c09969333aa744d73d4c0307f5`.
New appendix SHA:
`6ec7ba1fe3175530305b925c5ea579d558c9ed12c525bd3b5db7a5d9ec24e39c`.

### Measured contextual practice

Cash below is realized account units after the unchanged modeled costs, not
broker profit. All seven assessed products ended with zero open positions.

| 2025 week | Arm | Mature closes | Net cash | Median/max holding, minutes |
| --- | --- | ---: | ---: | --- |
| Oct13–17 | proximity_unbounded | 16 | +139.58 | 52.5 /210 |
| Oct13–17 | proximity_6bars | 23 | +123.57 | 30 /30 |
| Oct13–17 | pretrend_unbounded | 5 | +93.95 | 55 /180 |
| Oct13–17 | pretrend_6bars | 5 | −84.12 | 30 /30 |
| Dec08–12 | proximity_unbounded | 13 | −275.83 | 30 /170 |
| Dec08–12 | proximity_6bars | 17 | −143.50 | 30 /30 |
| Dec08–12 | pretrend_unbounded | 2 | −85.91 | 25 /35 |
| Dec08–12 | pretrend_6bars | unknown | unknown | expired original; no replacement |

What I learned for practical use:

- The proximity+six-row profile generated1/4/4/2/12 same-day full closes in October and1/3/3/8/2 in December. Four of five eligible observed days in each week had multiple round trips; none was forced. Its40 actual closed positions all reported<=30 minutes here, not a universal wall-clock guarantee.
- Shorter holding did not guarantee better economics: proximity time exit reduced October cash by16.01 and improved December by132.33; December still lost money. Risk/cost/holding must be judged together, not by activity alone.
- The strict pretrend pattern was selective: three zero-close days in October, four in December. It may remain a specialist setup hypothesis, but this sample does not support it as the only active daily scalp engine. Do not loosen safety gates or ban EMA from five/two trades.
- Capping the October pretrend at six rows changed+93.95 to−84.12. Simply truncating a longer-holding method is not a demonstrated scalp advantage. This is an account-level observation, not an isolated per-trade causal estimate.
- Entry-context summaries are retained. For proximity_6bars, London-interfix groups were+78.15 across5 closes in October versus−106.41 across5 in December. This contradicts a ready “London always works” rule. Asia-SGE-day groups were+79.71/5 versus+90.31/7; these are small, selected descriptive observations for a fresh hypothesis, not a positive session certificate. The calendar's broad `new_york` coordinate is not the entire economic New York session.

The complete October four-arm cash interaction is−162.07. December interaction
and contrasts requiring its eighth arm are unknown. Related arms share market
events; their trade counts cannot be pooled as independent statistical power.

The retained curator resource is
`backend-laravel/resources/research/instrument-operating-skills/curator-scalping-observations-v1.json`.
It is usable research know-how, not agent-owned practice or a qualified skill.
It does not automatically attach a policy or change ordinary curricula.

### Next bounded research decision

Retain proximity+time exit as a short-holding behavioral reference, not a
profitable champion. Test scalp-specific entry quality/cost exposure and a
complementary range setup prospectively; do not tune away the observed December
loss or force the sparse pretrend to trade. Calendar-aware close/entry fences
and genuinely qualified M1/quote data are prerequisites for stronger execution
claims. Independent economics still needs actual authorized unused evidence.

The original blocking Windows subprocess wait did not promptly enforce its
absolute deadline across the observed jump. It is not a qualified unattended
supervisor. No further originals are launched; a future source release must
test short polling/rechecked absolute deadlines across suspend/clock events
before using this runner unattended. No production runtime change is claimed.

## Qisqa amaliy yo‘riqnoma — faqat research

1. EMA20/50 yo‘nalishini, asl ADX/RSI tasdig‘ini va yopilgan shamni tekshiring; har bir EMA tegishini entry deb olmang.
2. Proximity va qat’iy pretrend setupni alohida saqlang. Ikkinchisi kam signal beradi; signal yo‘qligini xavfsizlikni yumshatib “tuzatmang”.
3. Entrydan oldin spread/ATR, sessiya, confidence, cooldown va umumiy risk shartlarini o‘tkazing. Mavjud SL/TP qoladi.
4. Olti M5 qatorli chiqishni faqat nominal30-daqiqalik research boshqaruvi deb biling. Kalender tanaffusi yoki haqiqiy fill noaniqligi bor joyda uni qat’iy broker muddati deb atamang.
5. Kunlik bitimlar, sof natija, haqiqiy holding va nol-bitimli kunlarni birga ko‘ring. Oktabrdagi musbat natija dekabrdagi zararni bekor qilmaydi; London/Asia yorlig‘i o‘zi entry vakolati emas.
6. Ushbu kuzatuvni kurator ko‘nikmasi sifatida saqlang. Agent keyin o‘z sinovi va o‘z natijasini olishi kerak; hozir unga soxta shaxsiy tajriba, Master yoki trading authority berilmaydi.

Verification:52 focused preparation/panel/metrics/reporting tests plus3 curator
observation tests passed;23 existing EMA/full-close tests and5 actual clock
tests also passed (83 distinct focused tests total). These include synthetic
software fixtures, not83 independent market experiments. PHP syntax and
Project Map impact/generate/check passed. No full-project suite, broker demo,
production release, independent skill or strengthened descendant is claimed.
