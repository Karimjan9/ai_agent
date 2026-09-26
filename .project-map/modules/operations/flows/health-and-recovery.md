# Runtime health and recovery

## Trigger

The scheduler, a strict health command or an operator detects stale heartbeat,
queue/dependency issue, unhealthy market feed or a required operational
prerequisite failure.

## Sequence

1. Collect the scoped health evidence and distinguish policy pause from failure.
2. Record the actual affected dependency and current state.
3. Follow the matching bounded runbook/command; do not use unrelated recovery.
4. Re-run strict verification after remediation.
5. Mark only the verified condition recovered; retain audit context.

On the local Windows autonomous profile, a per-user logon task starts the
duplicate-safe hidden supervisor. The supervisor prefers the already-running
PM2 topology and only fills missing Redis, AI, scheduler or queue ownership;
small hosts retain a single CPU-heavy screening lane.

For the autonomous research scheduler, each tick freezes exactly one child
action. A terminal technical causal arm is settled before the generic active
generation path, then the arbiter reselects from a fresh evidence snapshot.
If the terminal lineage head still has actionable technical-recovery debt, the
arbiter selects the lifecycle owner's bounded recovery before a drift or
fresh-data constructor. A constructor's exit-zero `generation blocked` output
is a safety block, not a completed successor writer. A candidate that never
opened its own replay after a frozen-control failure remains quarantined; once
the exact control later completes, that candidate has no independent transport
recovery debt or strategy credit. While the control's repair is still actionable,
the candidate waits under a distinct upstream reason and cannot spend its own
timeout-retry seat. If the frozen snapshot is sealed unrecoverable, that
terminal disposition outranks the old timeout message for both control and
dependent; neither receives a strategy verdict.
The arbiter deduplicates by generation, terminal-agent, open-run, downstream
laboratory queue and settlement watermarks rather than wall-clock minute. The
selected scheduler job is deliberately excluded from its own state hash so its
enqueue/dequeue cycle cannot generate an endless 0/1 feedback loop. When a clean terminal
generation receives its arbiter-created successor, the runtime seals an
immutable autonomy receipt only after verifying the source generation's own
creation provenance and the exact arbiter decision that selected its successor.
The creation provenance is retained even when the predecessor fails audit, but
that failure cannot produce a clean receipt. The strict proof command requires
two consecutive linked receipts.
Before suppressing a matching in-flight child, the arbiter verifies the
scheduled job's unique cache lock within that job's lease window. If a recent
database decision says `selected`, `dispatched` or `running` but the lock is
gone, it fences the orphan as failed with
`RESEARCH_DECISION_UNIQUE_QUEUE_LOCK_MISSING`, then reselects from current
evidence. Historical rows beyond the unique-lock lease are ignored; they cannot
keep the research loop permanently in flight.
When a zero-pass final report requests a data-edge audit and its technical
completion/pipeline checks pass, the arbiter delegates to the lifecycle owner.
The audit changes the generation gate watermark, allowing one successor
selection after the lifecycle command is deferred; below-threshold candle
counts do not create repeated new-data attempts.
Only an audit authored by that latest generation counts as completed for this
handoff. An inherited predecessor audit is provenance and cannot trigger an
unbounded succession of data-edge root cohorts.
An unrecoverable same-generation dataset mismatch seals one terminal technical
recovery disposition. Its agent ID is part of the arbiter state watermark:
unchanged retries remain suppressed, but this new durable refusal permits one
fresh successor selection. A lifecycle child returning `running` because it
only dispatched technical recovery is deferred, not a completed successor.

## Rules

- A dashboard status is not enough; relevant strict command evidence decides health.
- Recovery must not mutate research, paper or promotion evidence to look healthy.
- Consult the matching runbook before changing Redis, queues, backups or provider recovery.
- A successfully delivered child command is not lifecycle progress by itself;
  the owned generation or work receipt must change or close.
- Bounded causal-fold budget expiry is a typed replay transport timeout. Its
  one-shot recovery selector and admission gate must use the same reason code;
  an unclassified transient must not create an empty recovery loop.
- Scientific abstention may be a clean terminal outcome, but a technical run,
  missing MTF admission or incomplete causal-fold aggregate cannot seal an
  autonomy receipt.
