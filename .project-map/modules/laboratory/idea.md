# AI Laboratory and adaptive evolution

## Idea

The laboratory turns a bounded research hypothesis into independently assessed
replay, holdout and paper-observation evidence. It may create, compare and
archive candidates, but does not treat an apparent result as a production
trading entitlement.

A versioned council candidate evaluates scalp/hour/day/swing specialists on
one shared native account, not a sum of independent backtests. Typed support
roles and bounded learned operators remain research products until original
independent evaluation authorizes prospective adoption. Open positions retain
their original management version, including after rollback. See
`docs/architecture/specialist-council.md` for the executable boundary and data
dependencies; registration or deployment alone grants no trading authority.

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
