# System architecture

```text
Browser / scheduler / commands
  -> Laravel routes, controllers and services
  -> MySQL evidence, lifecycle and operational records
  -> HTTP contract when deterministic calculation is required
  -> FastAPI strategy, replay and execution-contract service
  -> Laravel persists evidence, gates and paper observations
```

Laravel owns request orchestration, persistence, lifecycle gates, operational
authority and the paper-trading record. Python owns deterministic calculation,
strategy execution and replay. Neither side may silently reinterpret the other
side's sealed contract.

Start with `project.yaml`, then use a module map or a root flow. Detailed
architecture remains in `docs/project-memory/architecture.md` and the
cross-runtime API contract remains in `docs/ai-service-contract.md`.
