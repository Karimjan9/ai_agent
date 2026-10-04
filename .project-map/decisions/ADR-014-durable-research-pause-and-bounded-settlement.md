# ADR-014: Durable research pause and bounded settlement continuation

- Status: accepted
- Date: 2026-09-27

## Context

The operator had START/STOP, but no distinct PAUSE/RESUME. Windows autostart
could restore missing workers without checking whether their absence was
intentional. Separately, a completed lifecycle child could leave its generation
active; state-hash deduplication then suppressed every future settlement tick.
G236 demonstrated the latter with all 20 agents terminal but the generation
still projected as screening.

## Decision

Keep the existing MySQL-backed autonomy control as the sole operator switch.
PAUSE denies new arbiter actions and queued scheduler research children without
deleting the generation, queue receipts or evidence. The fallback supervisor
checks that control before launching processes. RESUME changes the control
revision, allowing the arbiter to reconcile the existing generation before it
selects a successor. STOP remains drain-first and distinct from PAUSE.

For an unchanged active generation after a completed settlement child, retry
at most twice with a five-minute separation. If still unchanged, report an
durable no-progress `safety_halt`; never spin each minute or silently freeze.
Only the canonical lifecycle terminal boundary may close a generation, and a
technical quarantine remains failed evidence.

## Consequences

- A user pause is not a crash or a technical evaluation failure.
- Already-running bounded children may complete; this is a graceful admission
  pause, not an instantaneous process kill or a rollback of reserved jobs.
- A missing DB gate prevents the Windows fallback from starting new processes.
- A live PM2-owned project suppresses fallback worker creation during rolling
  PM2 restarts; PM2 autorestart is responsible for its own missing lanes.
- A no-progress safety halt requires diagnosis and an explicit START after
  repair; RESUME cannot clear it. It does not fabricate a successful generation
  or bypass a scientific gate.
