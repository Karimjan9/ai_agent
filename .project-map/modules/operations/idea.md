# Runtime operations, scheduler and recovery

## Idea

The system needs observable, bounded and recoverable background behavior. This
module reports health and drives only documented operational actions; it does
not manufacture trading, paper or promotion state to make a dashboard green.

## Goals

- Observe scheduler heartbeat, queue health, dependencies and data freshness.
- Make recovery state and safe operational actions explicit.
- Preserve the distinction between a policy-controlled pause and a failure.
- Protect runtime configuration, backup and access prerequisites.

## Non-goals

- It does not bypass laboratory lifecycle gates.
- It does not repair data with a non-canonical provider.
- It does not convert an incomplete job into successful evidence.

Read `docs/project-memory/operations.md` and `docs/operations/` before making
any recovery or deployment behavior change.
