# ADR-001: Project Map is a maintained navigation layer

## Decision

`.project-map/` is the repository's canonical navigation layer. Existing
`docs/` files remain detailed policy, contract and operational references.

## Why

The repository spans Laravel, FastAPI, migrations, queues, scheduler workflows
and stateful evidence lifecycles. A concise map reduces broad source discovery
without replacing executable truth.

## Consequences

- Manual intent/flow/state files change only when their semantic reality changes.
- Generated indexes change with indexed source and are freshness-checked in CI.
- Source, migrations, configuration and tests remain authoritative.
- A task log is not stored in module history; only durable reasoning is.
