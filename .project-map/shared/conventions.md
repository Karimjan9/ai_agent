# Map conventions

- Module IDs are lowercase kebab-case and match their directory name.
- `module.yaml` is intentionally compact. It records ownership, not every class.
- A flow describes observable sequence and boundaries; it does not duplicate
  method bodies or inferred call graphs.
- A state transition names its trigger, guard, owner and failure/compensation
  behavior whenever one exists.
- Generated files are rebuilt, never hand edited.
- Link to existing detailed docs instead of copying long policy text here.
- Source code and tests settle ambiguity.
