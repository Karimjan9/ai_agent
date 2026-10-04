# NeuroTrader Lab Project Map

This is the canonical navigation layer for the repository. It is intentionally
smaller than the codebase: it helps a human or coding agent reach the relevant
module, business flow, state model, contract, source files and tests quickly.

It is not a second implementation. Migrations, runtime configuration, source
code and tests remain authoritative whenever they disagree with this map.

## Read order

1. Read `project.yaml` to identify a module or a cross-module flow.
2. Read that module's `module.yaml`.
3. For a bug, search `generated.json` for the relevant symbol, source or test,
   then inspect the real source and focused test. Do not read the whole index.
   For runtime configuration or process ownership, use
   a targeted search in `generated/runtime-index.json` to locate the exact
   config section or script.
4. For a feature or lifecycle change, read `idea.md`, the relevant flow and
   `states.md` before deciding on implementation.
5. Read history or an ADR only when a prior architectural decision matters.

Follow the task's route, not every route: a bug does not normally need all
flows/history, and a lifecycle change does not need every generated index.
Read bounded source ranges around the located function, then its relevant
callers and focused tests. Search only the mapped files or owner directory
first. Widen the scope only when the evidence shows a missing caller or owner.

Reuse project/module facts and verified source paths already inspected within
the same task. Reload them when the question, owner or relevant files change,
not before every tool call. This avoids repeated map-reading overhead; cached
navigation is not current runtime health or loaded-worker evidence.

If the map misses, identify the concrete gap before broadening the search:
`missing_anchor`, `missing_test_link`, `stale_map`, `scope_miss` or `other`.
Verify the real source/test relationship, then fix only that owner, anchor or
test link when implementation is in scope. For a read-only question, report
the verified gap without silently changing the map. Repeated repository-wide
searches and blanket history loading defeat the map's purpose.

## Map Impact Check — required before a change task is finished

Read-only status, explanation and diagnosis do not require regeneration or
manual map edits. Use `check` when freshness matters; do not create new scanner
output merely because you used the map.

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
| Pure internal fix with unchanged observable behavior | No manual semantic edit; regenerate if indexed source changed. |

For a change task, run these commands from the repository root:

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

The runtime index includes Laravel `config/`, process scripts and their file
hash coverage. It records top-level config section names and line numbers,
not values or secrets. A shared config file is not assigned wholesale to one
module; follow its section to the real consumer. The index does not prove that
a long-lived worker loaded the same code currently on disk.

## Measuring navigation cost (opt-in pilot)

Use `scripts/project-map/measure.mjs` for a small pilot of ten *paired read-only
navigation questions* (twenty sessions). The pilot is separate from active
trading/research and does not mean performing every implementation task twice.
Use varied bug, runtime and lifecycle lookup questions. Run each once with the
map and once with ordinary source search, alternating order. The second attempt
may benefit from familiarity even with alternating order; this is a limitation,
not evidence that the map alone caused the difference. Do not use a map-free
baseline for code-changing tasks; `AGENTS.md` still requires map-first navigation
there.

For a measured session, use only the wrapper's `read` and `search` commands for
file navigation; otherwise read-byte and search counts will be incomplete.
`read --from=N --to=N` uses inclusive, one-based line bounds. `search` accepts
repeatable `--scope=FILE_OR_DIR` arguments, `--max-results=N` and `--context=N`;
`max-results` counts returned **output lines**, including context/separators,
not matched records (default 80, maximum 500). Context is 0-8 lines. Caps bound
output and the event records truncation; they do not bound the underlying file
read or search I/O. Read-only source inspection is required before `hit` and the
tool rejects a map-only or unread source hit. Example map-first route:

```powershell
node scripts/project-map/measure.mjs start --case=locate-mtf-admission --mode=map --kind=bug
node scripts/project-map/measure.mjs read --session=SESSION_ID --path=.project-map/project.yaml
node scripts/project-map/measure.mjs read --session=SESSION_ID --path=.project-map/modules/laboratory/module.yaml
node scripts/project-map/measure.mjs search --session=SESSION_ID --pattern=mtfBundleReasons --scope=.project-map/modules/laboratory/generated.json --max-results=8 --context=3
node scripts/project-map/measure.mjs read --session=SESSION_ID --path=backend-laravel/app/Services/GenerationSnapshotAdmissionService.php --from=100 --to=142
node scripts/project-map/measure.mjs hit --session=SESSION_ID --path=backend-laravel/app/Services/GenerationSnapshotAdmissionService.php
node scripts/project-map/measure.mjs finish --session=SESSION_ID
node scripts/project-map/measure.mjs report
```

Replace `SESSION_ID` with the ID returned by `start`. The example source range
is illustrative: inspect the current generated line anchor before choosing a
range. Runtime questions target `generated/runtime-index.json`; lifecycle and
feature questions additionally read only their relevant idea/flow/states.

If an anchor is absent, record a categorical fallback before a wider search:

```powershell
node scripts/project-map/measure.mjs fallback --session=SESSION_ID --reason=missing_anchor --path=.project-map/modules/laboratory/generated.json
node scripts/project-map/measure.mjs search --session=SESSION_ID --pattern=mtfBundleReasons --scope=backend-laravel/app/Services --scope=backend-laravel/tests/Feature --max-results=12 --context=2
```

Fallback reasons are categorical rather than sensitive free text. The first
correct source is self-reported with `hit` and is not automatically proven
correct by the tool. The git-ignored local event log records paths, bounds,
read/search-output byte counts, fallback reasons and timestamps, never file
contents. `content_bytes_seen` includes selected source ranges and search
snippets, not unseen portions of a file. Total time includes wrapper overhead.
Sensitive/generated directories (including `.git`, `.runtime`, `.aws`,
`.codex`, dependencies and storage), `.env*` files and private-key containers
are excluded; searches ignore user ripgrep configuration and do not follow
symlinks.

The report exposes sessions, ranges, capped output, fallback and incomplete
work plus per-case comparisons. Unpaired and unfinished sessions must not be
silently treated as successful comparisons. Both modes must identify the same
first source file, inspected before `hit`; mismatched or unverified pairs are
excluded. Older v1 hit-only events without inspected-source evidence remain
visible but cannot qualify as completed comparable pairs. At least ten
completed comparable pairs are needed for a descriptive sample; they do not
prove causal savings or actual API billing. Optional `finish --tokens=N`
accepts *actual* token telemetry;
never estimate tokens or billed cost from bytes. A healthy map and fewer
searches do not prove improved trading performance.

After the bounded pilot, improve the exact anchors or source-to-test links
behind verified repeated fallbacks. Keep map-first navigation as the normal
workflow; do not retain duplicated baseline work as a permanent cost.

## Opt-in solution arena

Arena improves a proposed engineering solution before implementation. It is
the coding assistant's workflow, not a new application module, scheduler or
trading authority. Default: **OFF**. Read/run this procedure only after a
direct user request for the current problem, such as `arena bilan o'yla` or
`arena bilan fikr qil`. A mention, quoted example, negated request or ordinary
`davom et`/`joriy qil` does not turn it on. Never disguise automatic debate as
routine review. Permission covers one analysis task; its recommendation closes
the Arena. Later coding or a different problem needs a new explicit Arena
request to reopen debate.

1. **Frame the case.** Identify symptoms, verified root causes versus hypotheses,
   desired outcome and non-negotiable constraints. Use the map-first route to
   build a small shared packet: question, relevant source/receipt references,
   source revision or frozen identity where relevant, known facts, uncertainties,
   data/safety boundaries, scope and acceptance target. Do not provide a preferred
   solution as fact or send the whole repository/history to every participant.
2. **Independent proposals.** Use at most three proposal agents, with technical,
   integration and failure/testing perspectives. They receive the same facts
   but not one another's initial proposals; use isolated initial context rather
   than inheriting a transcript containing competing answers. Every agent
   proposes a solution, alternatives, benefits, costs, failure modes and a
   falsifying test, citing inspected source/evidence. No nested delegation,
   extra judge agent or unrequested model upgrade. If independent-agent tools
   are unavailable, disclose that limit; do not label simulated roles as an
   actual independent Arena.
3. **Bounded cross-critique.** Share the proposals after independent submission.
   One critique round is the default: seek counterexamples, hidden assumptions,
   contract breaks, unsafe data use, regressions and cheaper viable alternatives.
   The same participants may revise their proposals. A second and final round
   is permitted only for a decision-critical disagreement that new evidence can
   resolve; state that reason first. Cap individual proposals at roughly 400
   words and critiques at 250, unless the user specifies another budget. Stop
   at the budget; retain unresolved disagreement instead of looping for consensus.
4. **Filter, then rank.** Reject options violating safety, user scope, immutable
   history or authority/data rules; an option depending on unavailable evidence
   is not executable now. Compare remaining options by root-cause coverage,
   inspected evidence, cross-module impact, implementation/regression risk,
   cost, reversibility and verifiable acceptance. Majority, confidence and
   eloquence are not proof. The coordinating assistant adjudicates using source
   and tests, not an additional voting agent. Preserve a minority objection
   when it remains supported. If evidence cannot select a viable solution,
   recommend the smallest diagnostic or identify the dependency, not a fake winner.
5. **Deliver and hand off.** Give one primary recommendation and, only if useful,
   one alternative. Explain why it was chosen, why other proposals were rejected,
   remaining risks/unknowns and exact acceptance tests. Include meaningful
   benefits and costs, not a guarantee of the universally best solution. Arena
   analysis permits read-only investigation and isolated diagnostic tests, not
   patches, DB/API writes, service restarts or research dispatch. Implementation
   needs an explicit change request; if already authorized in the same task,
   proceed only after adjudication without seeking duplicate permission. Then
   use normal map impact, focused tests and relevant runtime verification.

No Arena consensus creates causal skill, independent market evidence, paper
admission or promotion authority. The existing arbiter and scientific gates
retain their ownership. Do not alter application runtime just to enable this
assistant workflow. Record tests not run and evidence not available honestly.

## Ownership of this directory

Manual files explain intent, flows, state and decisions. `generated/` and every
module `generated.json` are scanner output and must not be edited by hand.
`docs/` remains the detailed policy, operational and contract reference linked
from this map. There is only one navigation authority: this directory.
