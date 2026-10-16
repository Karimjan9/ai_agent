# EMA pullback: source fidelity and opportunity study

2026-10-16. The product of this cycle is a practical, bounded teaching guide
and an original-evidence diagnostic, not another strategy framework. Current
status: **research-only know-how; no independently qualified economic skill**.
The curator owns this guide. It is not an agent's earned experience and is not
attached to production models.

## What was actually studied

I read Galen Woods' [intraday moving-average lesson](https://www.tradingsetupsreview.com/day-trading-with-20-period-moving-average/)
and [candlestick/average lesson](https://www.tradingsetupsreview.com/candlestick-patterns-with-a-moving-average/),
including their embedded charts and counterexamples. The first teaches
contextual average use, several entry styles and two trailing alternatives;
it does not supply one fully specified algorithm. The second distinguishes
pattern formation from boundary-triggered entry. Its examples include daily
charts, an untriggered bullish pattern and a losing setup. These are selected
teaching illustrations, not audited trades or proof of an XAUUSD scalping edge.

One visual fidelity issue is retained rather than silently corrected: the
intraday text discusses a 20-period average, but its ES range charts label
EMA14 and its four-entry chart labels SMA20. Those pictures cannot verify
our EMA20 calculations. Neither a complete YouTube video nor a paid course
was viewed. [sources.json](sources.json) records the exact review boundary.

## Our current method is a lab specification, not an exact author clone

| Decision | Existing executable programme | Source-to-code boundary |
| --- | --- | --- |
| Context | EMA20/50 order and a four-closed-bar pretrend | The author uses contextual average/price interpretation; our numeric proxy is our hypothesis. |
| Pullback | Prior close inside the fast/slow zone plus fast-average touch | This is one restricted close-based condition, not every pattern touching an average. |
| Confirmation | Prepared ADX at least 20 and RSI14 above/below 50, then confidence | Neither additional indicator is required by the selected teaching pages. |
| Entry | Reclaim at a closed signal candle, then next modeled open | A pattern-boundary stop trigger is a different execution rule. |
| Protection/exit | Control: ATR 1.5/2.5; F treatment: three-bar structure, one-point buffer, nominal 2R; both capped at six observed rows | These are declared lab management choices, not an exact reconstruction of the illustrated trailing methods. |
| Session | Execution UTC 07:00 inclusive to 14:00 exclusive | This fixed XAUUSD window is a lab restriction, not established by those examples. |
| Costs | Existing modeled spread/slippage/commission, no observed quotes in these months | We cannot qualify minute-scalp execution fidelity from these candles alone. |

Only the old programme is executable in this study. No source interpretation
silently replaces its entry, session, confirmation, management or account.

## Exact old-programme practice card

For BUY, let `F` be the close EMA20 and `S` the close EMA50. Use four actual
contiguous closed M5 candles ending at signal row `t`:

1. At `t-3` and `t-2`, require `F > S`; both averages must rise between them.
2. At `t-1`, require `S < close <= F`, `low <= F` and `F > S`.
3. At `t`, require `close > F`, `close > previous_close` and `F > S`.
4. Require the actual prepared ADX and RSI gates. SELL mirrors these rules.
5. Apply the original entry owner at the next observed execution candle,
   including actual portfolio state, all gates and unchanged costs.

This does **not** require a bullish current candle body. A wick touching F
without the required previous close is not enough. The recursive `adjust=False`
averages and actual RSI/ADX implementation, rather than a charting platform's
unverified defaults, define these observations. The source owner is
[instrument_method_features.py](../../../../ai-service-python/app/services/instrument_method_features.py)
(`ClosedInstrumentMethodFeatures`), with strategy admission in
[laboratory.py](../../../../ai-service-python/app/strategies/laboratory.py)
and execution gates in
[backtester.py](../../../../ai-service-python/app/services/backtester.py).

### Confidence is not calibrated win probability

Here `confidence = clip((ADX - 20) / 30, 0, 1)`. A minimum of `0.35` therefore
imposes **ADX >= 30.5**, although the earlier strategy gate is ADX >= 20.
This is an actual combined constraint, not a measured 35% success chance.
The explicit variant does not consume `pullback_atr_fraction` or the configurable
RSI buy/sell bounds; it uses its own zone rule and fixed RSI50 split. Changing
those unused genes would not test the supposed entry change.

## How to read a candidate without inventing a trade

The audit uses the original 200 warmup rows. Signal row 199 can first feed
execution row 200; the last source row is `N-2`. A signal at 13:55 UTC that
would execute at 14:00 is outside the original session. Use execution time,
not the signal's hour or an assumed London/New York label. DST and local
session names have not been inferred from a fixed UTC gate.

Record separately:

- A geometric precursor before ADX/RSI: possible setup, not an admitted signal.
- A raw strategy signal: the strategy's final direction, before account admission.
- An originally flat opportunity: actual original history was available for entry.
- The first original rejection: later gates may also fail but were censored.
- An accepted fill: the original account actually entered, not a static gate pass.

The diagnostic counts session/confidence intersections explicitly. It must not
add rejected categories as if they were mutually exclusive missed profits.
An occupied signal cannot be promoted to a flat opportunity; different exits
can alter later state even when the observed fill indices coincide.

For the last 20 closed bars, overlap counts and average-slope direction changes
are descriptive reading aids only. No threshold labels a trend/range, changes
admission or forecasts a profitable context. Missing continuity stays missing.

## What to do / what not to do

Use this card to reproduce the old programme's decision and locate a genuine
source-to-code gap. WAIT when required inputs are unavailable, the declared
setup/trigger is absent, the original account/gates refuse entry, or modeled
execution cannot support the intended claim. Do not force an entry to reach a
daily trade quota.

Do not lower confidence, open more hours, reinterpret unused genes or add a
trailing exit after seeing these months' PnL. A changed question needs a new
prospective declaration. Do not call a candle proxy order flow, a daily-chart
example verified scalping, or internal simulation a broker demo.

## Evidence and next decision

[question.json](question.json) was frozen before the new diagnostic. It permits
two original months and one bounded read-only feature/signal pass, **not a new
economic backtest**. Original requests, responses, full-close witnesses,
process receipts, data and source bytes must match before observations are
published. [results.md](results.md) records the run and fixed chronological
case cards once verified; a missing category remains absent.

The decision is not to abandon EMA or to loosen restrictions until a winning
month appears. Retain the old programme and its losses unchanged. Before a
new market experiment, choose one setup/trigger/management combination,
quantify every discretionary input, verify its chronology on both accepted
and rejected examples, and explicitly name deviations from the source. A
next-open entry cannot pass an acceptance test for a pattern-boundary order.
Unsupported order semantics must be identified before dispatch, not imitated
by borrowing future candle highs/lows.

Useful interpretation and implementation know-how can remain in this guide
without confirmed skill status. Agent use would be a later, separately admitted
research action with its own receipts. No credit, Master, stronger child,
Champion Council or paper/live permission follows from reading this guide.
All 2026 and later market observations remain paper-only.
