# Project Map navigation

`.project-map/` is the canonical navigation layer. It accelerates discovery;
source code, migrations, runtime configuration and tests remain authoritative.

## Solution Arena — explicit opt-in only

Arena is the coding assistant's proposal/critique workflow, not a trading
runtime feature. It is OFF by default, including for complex tasks. Activate
it only when the user explicitly asks to use Arena for a named problem, e.g.
"arena bilan o'yla" or "arena bilan fikr qil". Mentions, quotations, questions
about Arena, "davom et" and "joriy qil" alone are not activation requests.
Do not run an automatic solution swarm/debate or rename it as review to bypass
this gate. Ordinary tasks keep normal map-first analysis and verification.

On activation, read the "Opt-in solution arena" section of
`.project-map/README.md`: three independent proposals, bounded cross-critique,
evidence-based filtering and one ranked recommendation. Activation lasts only
for that analysis task and expires when its recommendation is delivered; it
does not enable Arena on future tasks or automatically rerun it during coding.
Arena alone authorizes no implementation. Use existing explicit change
authorization if present; otherwise hand off the recommendation for direction.

## Task-specific navigation

1. Read `.project-map/project.yaml` and identify the relevant module or
   cross-module flow.
2. Read the module's `module.yaml`.
3. For a bug/fix, search its `generated.json` for the relevant symbol, path or
   test; do not load the entire index. Then inspect exact source and focused
   tests.
   For config or process bugs, use `.project-map/generated/runtime-index.json`
   with a targeted search to locate the relevant section or script before
   opening the exact source.
4. For a feature, lifecycle or contract change, read the relevant `idea.md`,
   flow and `states.md` before implementation.
5. Read module history or an ADR only when prior architectural reasoning affects
   the decision.

Never implement from the map alone. Verify behavior in real source and tests.

Use bounded source reads around the named function and narrow `rg` scopes to
the mapped files or owner directory. Expand only to relevant callers, consumers
and focused tests. Do not repeatedly scan the whole codebase or read every
module, index and history for one task.

Within the same task, reuse already-inspected project/module facts and verified
source paths; do not rediscover them on every tool call. Re-read when the owner,
question or relevant files change. Cached navigation is never fresh runtime
health or loaded-worker evidence.

When the map cannot answer a question, state the verified gap (`missing_anchor`,
`missing_test_link`, `stale_map`, `scope_miss` or `other`) before expanding the
search. After verifying the real source/test relationship, repair only the
missing owner, anchor or test link when a change is in scope; otherwise report
that exact gap. Do not infer dependencies from filenames alone.

## Navigation measurement — bounded and opt-in

Use `scripts/project-map/measure.mjs` for a small pilot of ten paired read-only
questions, alternating map-first and ordinary-search order. This is a separate
navigation benchmark, not live research/trading and not a requirement to
duplicate every task. Code-changing tasks remain map-first.

During a measured session, use the wrapper's bounded `read` and scoped `search`
commands for all file navigation; record the first verified source with `hit`
and map misses with `fallback`. Out-of-wrapper reads, prior familiarity and
truncated results limit comparison. Actual token telemetry is optional; never
derive tokens, API cost or trading progress from bytes or a small time sample.
See `.project-map/README.md` for the protocol and examples.

## Map Impact Check — required before completing a change task

For read-only status, explanation or diagnosis, do not regenerate indexes or
edit the map merely because it was consulted. A read-only `check` can verify
freshness when relevant.

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

For change tasks, finish with:

```powershell
node scripts/project-map/project-map.mjs impact
node scripts/project-map/project-map.mjs generate
node scripts/project-map/project-map.mjs check
```

Generated indexes are scanner output: do not hand-edit `.project-map/generated/`
or `modules/*/generated.json`.
