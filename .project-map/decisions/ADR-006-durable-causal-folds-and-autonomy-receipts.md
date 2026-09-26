# ADR-006: Causal folds and autonomy proof are durable receipts

- Status: accepted
- Date: 2026-09-22

## Context

Nine causal confirmation folds previously ran inside one HTTP/process budget.
One slow fold invalidated the entire request, restart repeated already completed
work, and a terminal generation had no immutable proof that the arbiter, rather
than an operator, selected its successor. Scheduler decision identity also
included the current minute, so unchanged state could create repeated no-op
decisions.

## Decision

Execute every causal fold as a separate serialized queue job. One fold always
contains guided, blinded and frozen-control arms under the same frozen dataset,
execution and MTF authority. Laravel persists immutable request/response hashes
per fold and retries only that fold. Python aggregates metrics without replay
only after the exact complete fold set is present; partial folds grant no
learning credit.

Derive arbiter decision identity from operational watermarks rather than time.
The queue watermark covers downstream laboratory work and excludes the selected
scheduler delivery itself; otherwise the decision job creates a self-induced
0/1 queue transition and defeats no-op suppression.
After an arbiter command creates a successor, seal the predecessor's clean
audit, MTF admission and successor link in `generation_autonomy_receipts`.
Unattended acceptance requires at least two consecutive linked clean receipts;
scientific abstention is clean, technical or incomplete evidence is not.

## Consequences

- A timeout consumes one fold retry instead of losing an entire nine-fold run.
- Restart resumes from completed immutable receipts.
- Guided/blinded/control contracts are selected by LabAgent identity even when
  their executable strategy labels are equal.
- Generation history is not rewritten; new generations must earn new receipts.
- Operational autonomy is queryable independently of candidate success.
