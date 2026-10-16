# Bollinger Bands: source fidelity and context practice

Canonical library key: `range_reentry`. Curator research, 2026-10-16.
This dossier is not an agent-owned skill or an independent economic certificate.

## What was actually studied

The full available text of John Bollinger's four TradeStation manuals was
reviewed: Methods I–III, five pages each; Method IV, six pages. Formula and
trade-alert sections were distinguished from installation instructions. No
claim is made to have watched an embedded video, inspected the TradeStation
implementation, read the entire book or tested every author method.

| Source | Practical distinction | Fidelity dependency |
| --- | --- | --- |
| [Method I](https://www.bollingerbands.com/_files/ugd/58be43_f5e967053af44fa083340cacfc5e6226.pdf) | Recent Squeeze followed by volatility breakout, not automatic fading | Reproduce its 125-period normalization, 3% buffer and recent-Squeeze lookback in a separate adapter/question |
| [Method II](https://www.bollingerbands.com/_files/ugd/58be43_b120ddf0184540608baf19e2c0ae2019.pdf) | Trend/price action confirmed with money flow | Appropriate volume semantics and complete declared trigger rules |
| [Method III](https://www.bollingerbands.com/_files/ugd/58be43_377f4254baa04a19aaadb1735b45b6f0.pdf) | Band tag plus Intraday Intensity produces an alert; subsequent confirmation is still needed | True compatible inputs and an executable definition of confirmation |
| [Method IV](https://www.bollingerbands.com/_files/ugd/58be43_d09c50b6e8ea4afd9af0523ef94de876.pdf) | Two outside closes and ADX distinguish continuation/reversal | Declare ADX threshold and timing; do not invent an author default |

The current session recross implementation is not all four methods. Its sample
standard deviation and generic ATR exit are explicit. Source I's Squeeze is
not interchangeable with generic ATR compression. A midline/opposite-band exit
needs its own approved closed-row feature and was not implemented in this
batch. This distinction prevents one poor generic recross from being called a
refutation of Bollinger Bands.

The author's [22 usage rules](https://www.bollingerbands.com/bollinger-band-rules)
were also read. Practical guardrails: do not fade every tag; distinguish trend
continuation from reversal; do not count near-duplicate indicators as separate
confirmation; and do not turn band standard deviations into a trade-success
probability. The [TradeNavigator note](https://www.bollingerbands.com/tradenavigator-bbtk)
adds another differently specified Method IV. Source/platform fidelity matters
before asking whether a method works in our market.

## Selected question, not an author claim

Previous October/December discovery contained small descriptive range + low
volatility trade groups. That suggested, but did not prove, a context filter.
It is not valid simply to remove losing trades from the old ledger: the
account path, available entries, sizing and cooldowns can change.

The new question therefore replays the full policy:

- Unfiltered versus exact closed-row `range` + `low_volatility` eligibility.
- Six versus twenty-four observed M5-row holding cap.
- Same 07:00–14:00 UTC entry session, lookback 20, confidence 0.35, 1% risk,
  ATR stop 1.5 / target 2.5, account 10,000, no partial exit or trailing.
- No confidence/risk/cost reduction to force activity. Unknown context means
  WAIT for the gated variant. The gate only controls new signals, not exits.

The exact recross is not an intrabar touch: for BUY the preceding close is
below its own lower band, the current completed close returns to/above its
lower band and rises from the preceding close; SELL is symmetric. Bands use
20 closed prices and two sample standard deviations. The existing confidence
and downstream calibration/EV/cooldown checks can still refuse an entry.

In this experiment `range` is the frozen classifier's ADX14 < 20 label.
`low_volatility` means current ATR14/close is at/below its rolling 100-row
25th percentile, with the classifier's minimum-history rule. These labels
come from the actual M5 history here, not an independently supplied H1/MTF
regime and not a universal definition of a good range. They use current/past
closed inputs; entries are evaluated at the subsequent modeled open.
The pinned interpreter currently lacks optional `ta`, so its existing repository
fallback classifier is selected. No dependency was installed during originals.
This observation is not a separately captured before-outcome per-arm `ta`
attestation; the runtime manifest explicitly does not cover every third-party
binary. Do not equate its initialization/formula with an inspected commercial
chart package or claim the optional `ta` branch was exercised.

Six/twenty-four rows are nominal 30/120 minutes, not tick-level fill times or
a general day-flat guarantee. Compare complete account policies, not isolated
same-entry effects. Results may be negative or neutral.

## Different months and data custody

Before outcomes, a fixed rule searched complete UTC weeks in April→May→June
2025 and, separately, August. April and the first August week contained actual
missing bars; their refusals are retained. The first passing weeks were
May 5–9 and August 11–15. Both contain 1,556 original rows: 200 actual warmup
and 1,356 decision rows. Entire week edges and warmup are audited, not merely a
clean tail. No candle was fabricated or discarded to manufacture continuity.

The source remains original Dukascopy BID history. Neither week has observed
quote/spread coverage. Its provider volume field is not exchange order flow.
The unchanged modeled cost contract is used, so this does not qualify broker
execution or order-flow claims. These are reused historical discovery windows,
not untouched validation. All 2026+ market data remain paper-only.
May/August precede the October/December examples that motivated this question;
this is retrospective cross-month discovery, not a chronological forward or
out-of-sample validation result. A new month label does not remove that limit.

## Journal and original execution

1. [Source review and six hypotheses](events/2026-10-16-source-review.json).
2. [Pre-outcome context/holding question](events/2026-10-16-context-question.json).
3. [Subsequent source/version and stop-management review](events/2026-10-16-source-addendum.json).
4. [Author usage principles and platform/runtime boundaries](events/2026-10-16-practice-principles.json).
5. [Actual subsequent PDF captures](events/2026-10-16-source-capture.json).
6. [Visually reviewed original article and interview](events/2026-10-16-article-review.json).
7. [All eight original results and opportunity diagnosis](events/2026-10-16-terminal-results.json).

The additional [authorized web explanation](https://bollingerbands.us/help-methods.php)
describes Method IV differently from the TradeStation manual; its rules are not
silently merged into the sealed experiment. The [stop documentation](https://bollingerbands.us/help-stops.php)
also makes a useful distinction between a recalculated Chandelier and a
monotonic ratchet. These are future source-fidelity questions, not changes to
the running eight originals. No embedded video was reviewed.
The four public PDF files were subsequently downloaded and byte-hashed into a
private source-capture folder. That preserves the fetched files without
backdating them into the pre-outcome seal or redistributing entire manuals.

Two additional scanned originals were then rendered and all nine pages
visually inspected: [the February 1992 article](https://www.bollingerbands.com/_files/ugd/58be43_d0481bfee7424c91a089d4f0a7383133.pdf)
and [the 2014 interview](https://www.bollingerbands.com/_files/ugd/58be43_a75cc84043bd4233b5e929fc2c25487b.pdf).
The former explicitly uses population deviation in its formula/sidebar,
unlike our sample-deviation recross, and describes a two-extreme band pattern,
not only a single recross. The interview distinguishes adaptive tool roles
and notes volume-dependent limitations for forex. These reviews generated
BB-H008–010; none was retroactively added to the original question.

The second event pins the first event, the actual PHP draft, the selected raw
windows and the full parameter vector. The original owner seals all source
bytes and eight requests before dispatch. Only one hidden below-normal child
runs at a time; each original has a fixed 600-second cap and the whole panel
5,400 seconds, enforced by UTC and monotonic deadlines without renewal.

Original spec SHA256:
`3dff938a9fefbad49cbc509a074f71e4468c5ad79ada8d3fa2abf63097492e17`.
Private retained products are under
`.runtime/bollinger-context-originals-20261016/` in this isolated research tree.
All eight originals completed once and were independently reopened by the
read-only artifact inspector. It rederived original request/response/witness
hashes, clocks, full-close cash, single-attempt/deadline custody and all account
contrasts, with unchanged source/archive bytes. This is artifact reinspection,
not an independent market replication. Zero activation, technical failure and
economic loss remain different outcomes; an absent arm would stay unknown.

## Observed results: two weeks, not two complete months

Each arm starts separately at 10,000. Cash includes unchanged modeled costs.
Do not sum these separate account returns as a portfolio or treat duplicated
events across arms as independent trades.

| Policy | May 5–9: closes / net cash | August 11–15: closes / net cash |
| --- | ---: | ---: |
| Unfiltered, six-row hold | 15 / −259.00 | 12 / −26.34 |
| Range + low volatility, six-row hold | 5 / −134.88 | 0 / 0.00 |
| Unfiltered, twenty-four-row hold | 15 / −179.48 | 10 / −176.83 |
| Range + low volatility, twenty-four-row hold | 5 / −22.48 | 0 / 0.00 |

Every nonzero account loses. The filter's positive cash contrasts are loss
avoidance, **not proof of profitable active use**. May's six-row account loses
124.12 less after filtering, but activity drops by two-thirds. August's zero
is genuine non-activation, not an incomplete replay or a mastered skill.

Post-outcome feature-only diagnosis reopened the actual unchanged
`prepare_signal_snapshot`; no trade replay was performed. Its raw/joint signal
counts were required to match original funnels:

| Exact active-session input | May | August |
| --- | ---: | ---: |
| Closed decision-input rows in 07:00–14:00 UTC | 420 | 420 |
| Range rows | 208 | 166 |
| Low-volatility rows | 177 | 1 |
| Joint range + low-volatility rows | 143 | 0 |
| Unfiltered / eligible recross signals | 24 / 8 | 15 / 0 |

August therefore has no joint-context opportunity, not merely a vetoed entry.
The measured coverage does not establish *why* the all-hours volatility label
became rare during that session. Session-relative normalization is a new
unexecuted hypothesis, not a redefinition of these original labels.

Longer holding reduces May's unfiltered loss by 79.52, but worsens August by
150.49. Selecting a holding period from the already known month would be
hindsight. There is no universal holding improvement here.

The unfiltered six-row policy closes 4/4/2/2/3 trades on May's five dates and
1/4/1/5/1 in August. The gated policy closes 0/2/1/1/1 in May and none in
August. It does not deliver several opportunities every day. No trade quota
or confidence/cost/risk relaxation was used to force that target.

All final open counts are zero; every observed mature close occurs on its
entry UTC date. Six-row holds stayed within 30 minutes; twenty-four-row holds
within 120 minutes. Recorded drawdown is **closed-cash** drawdown, not an
independently marked-equity or tick-path result.

### What is retained as know-how

Do not turn this particular joint label into a default entry rule. Preserve
its exact coverage/negative observations for bounded research, without a hard
harmful ban on Bollinger Bands. Source-specific formula, confirmation and
management fidelity deserve separate questions. A promising descriptive
subset is not enough: replay the complete resulting account and check whether
the intended opportunities even exist in another month.

The result event pins summary SHA
`c740b14ae89956404568cc78baf9051647efbcbe28ee605247a5b6bc49335ef1`,
source ZIP SHA
`bfef59e16be4b830a483beccf9b600722ab6d0785ee5aec43fbc05981e710194`
and reverified artifact roster
`9995ce485f000ff4b23c254dc14309f4d6c285bbead5f93d32828227bc3ee4ca`.
All eight observed child elapsed times total 2,361.57 seconds; the maximum is
521.55 seconds, below the unchanged 600-second cap. These are elapsed times,
not CPU usage or evidence of financial/token savings.

## Software verification and unresolved test boundary

Actual PHP request preparation and new declaration/custody checks pass. PHP
context/runtime parity plus previous session parity: six tests / 164 assertions.
The separate focused research/window/gate/metrics/intraday/journal suite ran
71 tests: 70 passed and one optional-`ta` test was explicitly skipped.
The journal's twelve tests pass with one optional-`ta` check explicitly skipped;
private original-summary and PDF-byte checks execute locally. The selected
fallback classifier/gate passes prefix and future-price-perturbation checks.

The broad Python run is **not represented as green**: two executions retained
the same two short-lived Windows child-test failures. The ten-second
large-output fixture expired without output, and the 0.2-second sleeping-child
fixture's deadline assertions passed but temporary-input deletion hit
`WinError32`. The exact two tests passed once in isolation, then the mechanical
audit reproduced both failures alone as well. No cross-test contamination or
specific lock holder was proven; timers failed closed. All actual market
originals and their reinspection passed under their own unchanged budgets.
No timeout or safety limit was enlarged to turn those tests green. This
remaining launch/file-lock diagnostic is not a strategy loss or a reason to
ship unverified code.

Production/main, queues, DB, agent-owned experience and broker orders are
unchanged. The opt-in gate stays outside ordinary defaults/sampling. This is
an isolated research delivery, not a production release or automatic teaching
of an economically qualified skill.

## Follow-up questions retained, not claimed completed

Source-faithful Squeeze/confirmed breakout, proper volume confirmation and a
band-based exit remain distinct questions. Each requires declared rules and
another original design. Current results cannot silently tune or select a new
winner. Positive know-how can be retained provisionally; a qualified skill
still needs applicable validation and actual agent-consumption evidence.
