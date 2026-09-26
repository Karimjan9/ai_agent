# Project Map navigation

`.project-map/` is the canonical navigation layer. It accelerates discovery;
source code, migrations, runtime configuration and tests remain authoritative.

## Before changing code

1. Read `.project-map/project.yaml` and identify the relevant module or
   cross-module flow.
2. Read the module's `module.yaml`.
3. For a bug/fix, use its `generated.json` and then inspect exact source and
   focused tests.
4. For a feature, lifecycle or contract change, read the relevant `idea.md`,
   flow and `states.md` before implementation.
5. Read module history or an ADR only when prior architectural reasoning affects
   the decision.

Never implement from the map alone. Verify behavior in real source and tests.

## Map Impact Check — required before completing a task

Inspect the actual diff, then update only the affected map layer in the same
commit:

- Run `node scripts/project-map/project-map.mjs generate` when indexed source,
  route, migration, endpoint, test or mapped path changes.
- Update `module.yaml` for ownership, dependency or entry-point changes.
- Update a module/root flow for changed business/runtime sequence.
- Update `states.md` for state/transition/guard/terminal/compensation changes.
- Update `shared/integrations.md` for changed Laravel/Python, queue, provider or
  API boundary.
- Add ADR + concise history only for durable architectural decisions.
- Do not create a history entry for a pure internal fix with unchanged behavior.

Finish with:

```powershell
node scripts/project-map/project-map.mjs impact
node scripts/project-map/project-map.mjs generate
node scripts/project-map/project-map.mjs check
```

Generated indexes are scanner output: do not hand-edit `.project-map/generated/`
or `modules/*/generated.json`.
