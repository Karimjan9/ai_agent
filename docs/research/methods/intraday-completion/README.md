# Complete intraday methods and cross-month comparison

Three bounded questions receive the same complete-account test standard:
retained Bollinger recross, EMA trend pullback and confirmed breakout–retest.
The aim is useful reusable behavior, not more library entries. This directory
separates reviewed source rules, fixed laboratory choices and original results.

## Reviewed rules and operational choices

[FXOpen's price-action lesson](https://fxopen.com/blog/en/effective-price-action-trading-strategies/amp/)
places pullback entries within established price structure and protection beyond
that structure. The [break–retest lesson](https://fxopen.com/blog/en/how-can-you-use-a-break-and-retest-strategy-in-trading/amp/)
waits for a completed rejection of a previously broken level and gives
structural stops and risk-multiple targets as alternatives. Those broad
instructions are operationalized here; their missing numerical choices are
not attributed to the publisher. The 2026 retest article is used retrospectively
on 2025 history, not represented as a forecast available at that time.

The existing closed EMA20/50 pretrend, touch/reclaim, ADX and RSI entry remains
the pullback baseline. Its treatment replaces generic ATR protection with
the actual three-bar setup extreme, one execution point beyond it, and a
nominal 2R target. The breakout treatment freezes the prior20-bar channel
level, requires a later directional closed rejection within three contiguous
bars, then protects beyond that rejection's extreme. The old rolling-level
entry stays the comparison baseline rather than being silently fixed.
The new topology measures confidence relative to its own frozen level, with
the existing ADX/distance transform and unchanged threshold. Missing level
means WAIT, not a confidence boost.

Entry, stop, account size and exit form one complete method. Following the
[CME position-sizing principle](https://www.cmegroup.com/education/courses/trade-and-risk-management/proper-position-size),
size remains tied to the actual stop and existing account-risk ceiling, not
raised to manufacture larger profit. The target's 2R is a price-distance
multiple; commission and execution costs reduce net reward/risk.

## Fixed clocks and comparable accounts

Every arm uses XAUUSD M5, a separate 10,000 starting account, existing modeled
costs and maximum 1% executable stop-risk, 07:00–14:00 UTC entry hours and a
six-observed-row holding limit. Confidence, cooldowns, leverage and native
gap/intrabar risk guards are not loosened. Partial exits and trailing are off.
Six clean M5 rows imply nominal 30-minute holding, not a general session-flat
or tick-execution guarantee.

The periods are full June and September 2025, with 200 genuine warmup rows each.
They pass native month-edge and loaded-history calendar checks. January,
February and March refusals remain documented, without exporting fabricated
or incomplete full-month replacements. Neither selected month has observed
BID/ASK quotes, so cost-sensitive scalping conclusions remain conditional on
the modeled contract. These reused archives are not untouched validation.

## Test and handoff rules

All 12 requests, code/data/runtime identities and serial compute limits must
be sealed before the first original. Controls and recipes are compared on
complete accounts, with trade count, risk, maturity and clock reported apart
from PnL. Entry/management bundles cannot credit either part individually.
The Bollinger result cannot borrow the older October product for a new month.

A favorable result in one exposed month is provisional. Only a declared
compatible recipe with repeatable measured value can motivate fresh agent
practice; curator outcomes are never rewritten as agent-owned learning. Its
later admitted original use and trait-removal comparison remain separate
evidence. Missing or negative value produces no earned skill, authority or
automatic default. All 2026 and later observations remain paper-only.

The exact source ledger is [sources.json](sources.json); the predeclared
continuation criteria and fixed method priority are in [question.json](question.json).
They are research-practice prerequisites, not a statistical power calculation.
Original market
results will be recorded only after every declared attempt is terminal.
