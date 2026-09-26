# NeuroTrader Lab Project Map

This is the canonical navigation layer for the repository. It is intentionally
smaller than the codebase: it helps a human or coding agent reach the relevant
module, business flow, state model, contract, source files and tests quickly.

It is not a second implementation. Migrations, runtime configuration, source
code and tests remain authoritative whenever they disagree with this map.

## Read order

1. Read `project.yaml` to identify a module or a cross-module flow.
2. Read that module's `module.yaml`.
3. For a bug, open `generated.json`, then the real source and the focused test.
4. For a feature or lifecycle change, read `idea.md`, the relevant flow and
   `states.md` before deciding on implementation.
5. Read history or an ADR only when a prior architectural decision matters.

## Map Impact Check — required before every task is finished

Do not update documentation mechanically for every internal edit. Inspect the
actual diff and update only the semantic layer it changed:

| Changed reality | Required update |
| --- | --- |
| Class, route, migration, endpoint, test or file location | Run the generator. |
| Module ownership, entry point or dependency | Update `module.yaml`. |
| User/business/runtime sequence | Update a module or root `flows/*.md`. |
| Lifecycle state, transition, guard, terminal state or compensation | Update `states.md`. |
| Laravel/Python, queue, provider or API boundary | Update `shared/integrations.md` and the relevant flow. |
| Durable architectural tradeoff | Add an ADR and a short history entry. |
| Pure internal fix with unchanged observable behavior | No manual map edit; regenerate only if indexed code moved. |

Run these commands from the repository root:

```powershell
node scripts/project-map/project-map.mjs impact
node scripts/project-map/project-map.mjs generate
node scripts/project-map/project-map.mjs check
```

`impact` reads the actual Git diff and reports likely affected module anchors and
manual review layers. It makes no documentation decision by itself. `generate`
is required in the same commit as indexed source changes. `check` validates
paths, map links, module dependencies and the generated source digest.
The generated indexes are navigation evidence only; they do not claim to be a
complete Laravel dependency or call graph.

## Ownership of this directory

Manual files explain intent, flows, state and decisions. `generated/` and every
module `generated.json` are scanner output and must not be edited by hand.
`docs/` remains the detailed policy, operational and contract reference linked
from this map. There is only one navigation authority: this directory.
