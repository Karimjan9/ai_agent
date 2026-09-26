# AI Laboratory and adaptive evolution

## Idea

The laboratory turns a bounded research hypothesis into independently assessed
replay, holdout and paper-observation evidence. It may create, compare and
archive candidates, but does not treat an apparent result as a production
trading entitlement.

## Goals

- Preserve provenance, control identity and decision receipts.
- Keep population construction and evaluation bounded and repeatable.
- Learn only from evidence that passes the applicable gates.
- Keep failed or incomplete evidence diagnosable rather than silently reused.

## Non-goals

- It does not execute live trades.
- It does not allow a strategy score to bypass replay, holdout or paper gates.
- It does not replace deterministic Python computation with Laravel policy.

For the complete policy, read `docs/project-memory/ai-learning-laboratory.md`
and `docs/project-memory/adaptive-evolution.md`.
