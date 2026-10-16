# EMA source/opportunity diagnostic — completed

2026-10-16. **One declared read-only diagnostic completed in 83.757 seconds**
under its unchanged 300-second budget. No new account replay, order, position,
parameter change, database write or production action occurred. There is no
`failure.json`. The original four products, all declared source archive entries,
app roster/bytes and declared interpreter/package fingerprint matched before
preparation; all bound input/source bytes and runtime were checked again before
publication. This fingerprint is not a complete third-party binary attestation.

## Where the old programme loses opportunities

These are counts over the original evaluated source rows, not hypothetical
trades. June loaded 5,989 rows and evaluated 5,789; September loaded 6,241 and
evaluated 6,041. Both retained the original 200 actual warmup rows.

| Stage | June 2025 | September 2025 |
| --- | ---: | ---: |
| Closed two-bar EMA-zone touch/reclaim precursor | 228 | 236 |
| Same precursor with four-bar pretrend | 95 | 107 |
| Pretrend plus original ADX >= 20 | 64 | 57 |
| Plus directional RSI50 confirmation: actual raw signal | 64 | 57 |
| Raw signal inside UTC07–14 and confidence >= .35 | 7 | 5 |
| Original filled entries, each control/treatment | 7 | 5 |

Availability is separate from setup occurrence: the two-bar feature was
available on 5,767 / 6,019 rows and the four-bar feature on 5,723 / 5,975 rows.
Missing adjacency stays unavailable, not a failed trading hypothesis.
The precursor is not an alternative strategy replay; later account/gates
were not evaluated for those discarded rows.

RSI excluded **zero additional** setups after ADX in these two months. This
does not establish that RSI is unnecessary in another period. The original
confidence is `clip((ADX - 20) / 30, 0, 1)`, so .35 imposes ADX >= 30.5. It is
not calibrated success probability. No threshold was changed in this study.

## The rejection counts overlap

| Static intersection on all original raw signals | June | September |
| --- | ---: | ---: |
| In session, confidence passes | 7 | 5 |
| In session, confidence fails | 14 | 12 |
| Outside session, confidence passes | 14 | 11 |
| Outside session, confidence fails | 29 | 29 |

Thus the 43 / 40 outside-session first vetoes are **not** 43 / 40 otherwise
admissible trades. Twenty-nine in each month already have low confidence.
The remaining 14 / 11 pass only these two static checks; widening hours could
alter portfolio history and other gates. No fill, return or missed profit is
inferred for them.

June had 63 originally flat candidates in both arms: 43 session vetoes,
13 confidence vetoes and seven fills. One additional raw signal occurred
while occupied. September control had 56 flat: 40 session, 11 confidence,
five fills and one occupied raw signal. September structural had 57 flat:
the same 40/11/5 plus one `loss_streak_wait`. That last signal also had low
confidence, but the earlier state veto censored it.

The actual fill source-index sets matched between the two arms in each
month. This does not isolate an exit effect: sizing, costs, holding and later
state still belong to each complete original account. Their already retained
net cash remains -174.98 / -188.45 in June and -1.63 / -85.24 in September
(control / structural). These are old outcomes, not new economic evidence.

## Chronological practice cases, not profit-selected examples

Each is the first original candidate in the preregistered category for that
month. The table uses execution UTC; its closed signal is one row earlier.
No future PnL selected these examples.

| Month/category | Execution UTC | Direction | Confidence | Original lesson |
| --- | --- | --- | ---: | --- |
| June fill | 06-05 11:45 | BUY | 1.000000 | Both arms entered; this is not a 100% win probability. |
| June outside / confidence pass | 06-03 01:55 | SELL | .972755 | Session veto; no counterfactual fill asserted. |
| June outside / confidence fail | 06-04 00:30 | BUY | .086612 | Session hides an already failing second gate. |
| June inside / confidence fail | 06-04 09:55 | BUY | .006948 | Strategy signal does not imply admission. |
| June occupied | 06-30 08:25 | BUY | .342553 | Both accounts already held a position; also below .35. |
| June session boundary | 06-13 14:00 | BUY | .637063 | Signal at 13:55; execution at 14:00 is excluded. |
| September fill | 09-03 13:50 | BUY | .429097 | Both accounts entered. |
| September outside / confidence pass | 09-02 20:00 | BUY | .535672 | Passing confidence does not override session. |
| September outside / confidence fail | 09-01 15:05 | BUY | .016385 | Both static gates fail; first recorded veto is session. |
| September inside / confidence fail | 09-01 10:30 | SELL | .297273 | ADX 28.9182 passes strategy20 but not effective30.5. |
| September state difference | 09-23 11:20 | BUY | .327498 | Control occupied; structural flat but loss-streak veto. |
| September exact session boundary | absent | — | — | No example is invented. |

The first filled June case has four last-20 EMA20 candle-range overlaps and
two nonzero slope-sign changes; September's first fill has eleven and three.
Those observations do not establish a profitable overlap threshold. Original
raw `market_session` is absent in these cases. We do not retrofit London/
New York labels or turn descriptive EMA context into a learned entry filter.

## Decisions from this cycle

1. **Retain** the old programme and all negative outcomes unchanged. Its
   restrictive geometric and strength/session pipeline is measured, not a
   broken dispatcher or evidence that every EMA approach is untradeable.
2. **Rework the fidelity claim**, not the old receipts: our next-open
   EMA20/50 + ADX/RSI lab programme is not the author's pattern-triggered
   average technique. A successful/wrong/untriggered setup must be reproducible
   before calling a new version source-faithful.
3. **Do not repeat the same expensive question** or automatically relax hours,
   confidence or risk to increase trade count. Static opportunity counts are
   not economics. The single next question is specified in
   [next-question.md](next-question.md); it is not dispatched by this audit.
4. **Preserve this know-how for research**, not as an agent's earned skill.
   No useful economic skill, independently stronger child or Champion is
   established. Quote absence, reused-history exposure and 2026+ paper-only
   remain unchanged.

## Reproduction and custody

Original source/data: [prior study](../intraday-completion/results.md).
The private immutable originals remain in the separate original worktree;
they were neither imported into a new account nor rewritten. G's diagnostic
folder is `.runtime/ema-opportunity-audit-20261016/` (ignored, private).

- Question: `4e78282e4386278960d6d2bb4317f70a547dbdf2ad46d22a3521295748e7a866`.
- Reviewed auditor: `5fe442d68ff85b45e4ee41053e8683ea6e1e3e1758f2516deee891cd8ecf06ac`.
- Tests: `c8000b9b38c0ca8d068b95e1de846781a0750b5ca534c3c1089a0f1d7d0f54ac`.
- Diagnostic: `84147a7866fcd5548f5f2fcf259eb948abf83fad2c777d1d28b706be0e9bc90d`.
- Chronological cards: `57e634e6a9710dffa5b84508afa74b65dae940317561aa7147293c7a8fa0254f`.
- Final verification: `367749fbf499b2fac3ff92c2c908835ecd9333b5602bedf46f1ce80b8db293e7`.

The exclusive `--export --expected-source-sha256 HASH` command has already
been spent once. Reinspection means reading and hashing retained products,
not rerunning `--export`, renewing its budget or making independent samples.
Eleven new diagnostic tests and seventeen existing EMA/pretrend/source-runtime
tests passed. Project Map impact/generate/check passed: 1,777 indexed sources,
digest `7c66a6292a26`. No app runtime source, production release, broker demo
or agent policy was changed.
