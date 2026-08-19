# AI Laboratory bottleneck recovery

## Current diagnosis

The current funnel is blocked upstream, not by a lack of mutation volume:

| Area | Observed state | Interpretation |
| --- | ---: | --- |
| XAUUSD H1 G62 | 20 technical-recovery agents | No strategy verdict exists; learning must wait for technical evidence repair. |
| Learning pairs | 721 total, 4 paired, 612 missing control | Historical rows cannot be made causal without the exact frozen control. |
| Failure dojo | 711 total, 599 pending, repeat failure 70.47% | The same failure families are being queued before a repair has been falsified. |
| Skills | 3 provisional, 0 confirmed | The independent paired replay quorum has not been earned. |
| Forward | 0% | Correctly blocked until screen and full walk-forward evidence exists. |
| Gene interactions | 0 | Correctly blocked until two independent single-gene mentors exist. |
| Council | 16 unresolved, 0 resolved | Disagreement is recorded, but an evidence-backed adjudication lane is missing. |
| Temporal | G46 score 0.823 vs threshold 1.0 | Robustness is below the declared gate; lowering the threshold would manufacture progress. |

The safe dependency order is:

```text
technical recovery
  -> frozen controls and causal pairs
  -> micro replays and confirmed skills
  -> failure-family rescue
  -> single-gene mentors and interactions
  -> screen pass -> full validation -> forward -> paper
  -> parent candidate preparation -> counterfactual passport -> confirmed parent
  -> immutable successor
```

## 1. Technical recovery first

Do not requeue all 20 agents or classify them as strategy failures. First take a
baseline and inspect the exact generation contract:

```powershell
php artisan trading:baseline-snapshot
php artisan trading:lab-evidence-audit --json
php artisan trading:reconcile-lab-batches XAUUSD --timeframe=H1 --generation=62 --json
```

Then use exactly one recovery reason matching the recorded fault:

```powershell
php artisan trading:recover-lab-evaluation-errors XAUUSD --timeframe=H1 --generation=62 --mode=screen --after-service-repair
php artisan trading:recover-lab-evaluation-errors XAUUSD --timeframe=H1 --generation=62 --mode=screen --after-code-repair
php artisan trading:recover-lab-evaluation-errors XAUUSD --timeframe=H1 --generation=62 --mode=screen --after-dataset-contract-repair
```

The commands above are previews until `--apply --approved-by=... --approval-reason=...`
is supplied. If the immutable dataset/constructor contract is invalid, finalize
the quarantine and rebuild one clean cohort; never retry the same broken cohort
indefinitely:

```powershell
php artisan trading:repair-lab-integrity XAUUSD --timeframe=H1 --generation=62 --quarantine-contract-drift --rebuild-root
```

Exit criteria: `technical_recovery_agents=0`, no stale queue/batch, and a fresh
screen or an explicitly recorded terminal technical quarantine. Only then may
the learning velocity gate open.

## 2. Restore causal pairing without corrupting history

`missing_control` is not a failed causal experiment. It means the matching
control was never observed. Pair only when all of these match:

- immutable dataset/snapshot hash;
- execution-contract hash;
- temporal window and cutoff;
- strategy family, role and target;
- one declared mutation against an unchanged control.

Run bounded previews first:

```powershell
php artisan trading:materialize-learning-controls XAUUSD --timeframe=H1 --limit=50 --json
php artisan trading:reconcile-learning-control-pairs XAUUSD --timeframe=H1 --limit=50 --json
```

The current preview reports `pairable_missing_control=0`; therefore a bulk
materialization run cannot repair the 612 rows today. A new control-first probe
cohort is required before those rows can become causal pairs.

The durable planner records that exact contract without creating a synthetic
pair or dispatching a replay:

```powershell
php artisan trading:plan-causal-control-cohort XAUUSD --timeframe=H1 --limit=50 --json
php artisan trading:plan-causal-control-cohort XAUUSD --timeframe=H1 --limit=50 --apply --approved-by=<operator> --approval-reason="control-first cohort approved"
```

Rows missing a dataset hash, execution hash or temporal window remain
`blocked`; they must be repaired at the evidence source before a control can be
created.

Apply only the rows whose control is hash-verified. The 612 historical rows
without a control must remain diagnostic; they need a new control-first probe,
not a guessed parent baseline. For completed causal probes, use the bounded
control dispatcher:

```powershell
php artisan trading:dispatch-causal-probe-controls XAUUSD
```

Operational targets: at least 90% of *new* observations paired, missing-control
rate below 10%, and zero pairs with a parent/anchor substituted as causal proof.

## 3. Turn the dojo into a failure curriculum

Do not replay 599 pending rows. First collapse them into stable failure families,
then allocate at most one bounded rescue cohort per family:

```powershell
php artisan trading:compile-failure-signatures --limit=1000
php artisan trading:consolidate-failure-curriculum --dry-run
php artisan trading:study-lab-failures XAUUSD --timeframe=H1 --persist --json
php artisan trading:materialize-failure-wound-set XAUUSD --timeframe=H1 --json
```

Use the existing rescue circuit breaker (three cohorts, twelve siblings per
hypothesis). A mutation direction that fails the same wound twice is frozen and
down-ranked; it must not be regenerated under a different numeric value and
counted as a new idea. Measure repeat-failure rate, not raw replay count.

Targets: repeat failure below 30%, actionable dojo backlog below 100, and every
pending row assigned to a named failure signature plus a next experiment.

## 4. Earn confirmed skills before inheritance

Keep the current strict contract: two independent confirmations, three micro
windows, and at least two positive windows. Do not lower it to make the count
non-zero. After verified controls exist:

```powershell
php artisan trading:dispatch-learning-lane XAUUSD --timeframe=H1
php artisan trading:pump-learning-lane XAUUSD --timeframe=H1
php artisan trading:monitor-learning-lane
```

A provisional skill is research memory only. A skill becomes a mentor only after
independent windows and a non-regression check are recorded. Parent selection
must consume confirmed mentors only.

## 5. Re-open forward evidence in order

Keep `PROMOTION_FREEZE_CHAMPION=true`. Forward evidence must be earned through
the normal chain; no command should manufacture paper or champion state:

```powershell
php artisan trading:dispatch-full-validation XAUUSD --timeframe=H1
php artisan paper:evidence-readiness
php artisan trading:paper-monitor XAUUSD --timeframe=H1
```

The first milestone is one screen-passing candidate with completed full replay,
then three independent chronological forward windows, then the configured
paper sample quorum. Until that happens, 0% forward is a correct safety result.

## 6. Enable gene interactions only after mentors exist

The interaction service intentionally returns zero until two independently
confirmed single-gene mentors share role, family and target. Once that precondition
is true:

```powershell
php artisan trading:prepare-gene-interactions XAUUSD --timeframe=H1 --limit=50
```

Interaction probes remain research-only until their own paired replay and
non-regression evidence is complete.

## 7. Prepare parent candidates without promoting them

Council membership is only an experiment role. The preparation lane is opened
only for a council agent whose current performance already passes the strict
parent pre-check: valid evidence, PF >= 1.3, drawdown <= 15%, risk of ruin <=
10%, at least 30 samples, three rolling windows, three rolling forward wins,
no overfit, and no near-duplicate behavior. A mentor or a successful child is
not thereby a genetic parent.

Preview the bounded ideas (two per eligible candidate):

```powershell
php artisan trading:prepare-parent-candidates XAUUSD --timeframe=H1 --limit=20 --json
```

The first idea runs autonomous vs mentored vs ablated reproduction. The second
is a one-gene successor probe. Both require the same snapshot and execution
contract, no non-target regression, independent forward evidence, and a fresh
parent-passport recheck. To persist a planned preparation, use operator
approval:

```powershell
php artisan trading:prepare-parent-candidates XAUUSD --timeframe=H1 --limit=20 `
  --apply --approved-by=<operator> `
  --approval-reason="council candidate preparation approved" --json
```

This command never mutates the parent, never marks `promotion_evidence`, and
never creates a genetic parent. A successor is a new immutable `ModelVersion`
only after the existing `ParentAwareCreditService` proves mentored >
autonomous and mentored >= ablated, followed by forward evidence and the full
passport. With the current runtime (zero counterfactuals and zero forward
promotion evidence), a dry-run returning zero candidates is the expected safe
result.

## 8. Resolve council disagreements as evidence, not a vote rewrite

The current `CouncilDisagreementService` records disagreements but does not
provide an adjudication command, which is why 16 rows remain unresolved. Add a
small append-only adjudication lane with these rules:

1. four role votes are required: entry, risk, regime and volume/temporal;
2. risk veto or insufficient quorum produces an explicit `WAIT`, not approval;
3. a disagreement is `resolved` only after an ablation/counterfactual replay
   explains which role was correct;
4. every resolution stores the source event, evidence run and adjudicator;
5. the same disagreement key is idempotent and cannot be resolved twice.

Target: fewer than four unresolved disagreements, no silent vote overwrite,
and 100% of resolved rows linked to immutable evidence.

The append-only adjudication command defaults to a read-only listing. A state
change requires the event key, replay hash, at least one sealed window,
operator approval and an evidence run. A disputed or risk-vetoed row can only
resolve to `WAIT`:

```powershell
php artisan trading:adjudicate-council-disagreements XAUUSD --timeframe=H1 --limit=20 --json
php artisan trading:adjudicate-council-disagreements XAUUSD --timeframe=H1 `
  --event-key=<event-key> --decision=WAIT --evidence-run=<run-id> `
  --replay-hash=<sha256> --windows=<window-a>,<window-b> --apply `
  --approved-by=<operator> --approval-reason="sealed counterfactual replay reviewed" --json
```

## 9. Replace wait/cooldown churn with bounded diversity

Do not simply increase population size. Reserve explicit research seats and make
novelty measurable:

- 50% directed repair of a named failure wound;
- 30% structural/architecture probes;
- 20% adversarial regime probes;
- no same gene/direction repeated inside one failure family until falsified;
- at least three distinct genes and two distinct structural variants per 20-seat
  cohort;
- wait/cooldown-only mutations below 25% of a cohort.

Run this in the shadow/research lane first. Promotion, paper and parent gates
must remain unchanged. The existing `LAB_HYBRID_*` and risk-bounded settings are
the correct control surface; changing them is a search-allocation experiment,
not a gate bypass.

## 10. Repair temporal robustness with sealed ablation

The 0.823 result must be treated as a failed robustness hypothesis. Build three
non-overlapping chronological windows from an independent foundation, then run
the four-variant ablation (control, state-only, calibration-only, interaction):

```powershell
php artisan trading:build-temporal-foundation-windows XAUUSD --timeframe=H1
php artisan trading:temporal-ablation XAUUSD --timeframe=H1 --manifest=<manifest> --json
php artisan trading:temporal-ablation XAUUSD --timeframe=H1 --manifest=<manifest> --execute
php artisan trading:reconcile-temporal-ablation-audit --json
```

Keep the threshold at 1.0, require all three windows and the declared minimum
trades, and require a measurable margin improvement before opening another
rescue cohort. A parameter that helps one window but harms another is not a
temporal solution.

## Control-plane SLOs

Track these daily in `trading:lab-kpi` and page on breach:

| SLO | Green target |
| --- | ---: |
| technical recovery agents | 0 active; <5% of new cohort |
| new observations with verified control | >=90% |
| repeat-failure rate | <30% |
| actionable dojo backlog | <100 |
| confirmed skills | >=2 before parent expansion |
| forward-valid candidates | >=1 before champion unfreeze |
| unresolved council rows | <4 |
| temporal worst-window score | >=1.0 |

The key decision rule is simple: repair the earliest blocked boundary, then
measure the next boundary. Never compensate for an upstream evidence failure by
weakening a downstream gate.
