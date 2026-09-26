# Operations history

## 2026-08 — Evidence-aware health and recovery hardening

Health reporting gained canonical-provider checks, queue/lifecycle boundaries,
backup/access prerequisites and policy-aware handling of intentionally frozen
subsystems. See `docs/project-memory/operations.md` for the full timeline.

## 2026-09 — Project Map adoption

Operations now has a compact ownership/flow/state entry point. Existing runbooks
remain authoritative for commands and recovery procedures.

## 2026-09 - Causal terminal settlement precedence

The canonical arbiter settles a terminal technical causal arm before invoking
the generic active-generation lifecycle. This prevents a delivered but no-op
scheduler command from shadowing the action that can actually close the cohort.
