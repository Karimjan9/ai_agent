# Causal learning, conversion and composition settlement

## Idea

This module makes the laboratory's learning loop explicit: a bounded
counterfactual experiment produces immutable evidence, a versioned conversion
decision classifies what it established, and an idempotent next-work decision
or terminal reason closes the loop.

It also keeps composition settlement honest: a whole composition result does
not automatically credit every component; a component needs its own declared,
attested causal evidence.

## Goals

- Bind guided, blinded and frozen-control arms to one declared experiment.
- Preserve data, execution, intervention and context identity through replay.
- Separate work status from evidence/claim/authority state.
- Convert settled evidence into durable receipts and idempotent follow-up work.
- Keep research, economic and promotion conclusions distinct.

## Non-goals

- It is not a second replay engine or a parallel scheduler.
- It does not grant a skill, mentor, parent or paper authority from a score.
- It does not rewrite historical evidence to fit a newer conclusion.

The active detailed implementation blueprint is
`docs/architecture/ai-lab-final-blueprint.md`; the current vertical-slice plan
is `docs/task1.txt` until its durable parts move into an ADR/architecture note.
