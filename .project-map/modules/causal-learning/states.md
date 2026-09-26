# Causal learning states

The module intentionally does **not** use one overloaded `status` field as its
entire model. Before changing a transition, identify its dimension.

| Dimension | Representative states | Owner / meaning |
| --- | --- | --- |
| Work lifecycle | planned → ready → leased → running → settling → settled | Work/outbox owner; lease, retry and idempotency behavior. |
| Evidence | valid, incomplete, invalid, selection_contaminated | Evidence/receipt owner; whether a result may be interpreted. |
| Behavioral claim | untested, unreachable, no_effect_on_probe, stage_controllable | Conversion policy; what the intervention established. |
| Economic claim | underpowered, harmful, inconclusive, positive_candidate, independently_replicated | Statistical/settlement policy; not a promotion shortcut. |
| Reuse authority | research_only, confirmed_component, mentor, descendant_proven, eligible_parent | Authority/skill owner; earned separately from claims. |
| Deployment eligibility | blocked, paper_observing, eligible | Paper/admission owner; never inferred from a single score. |
| Freshness | active, drift_suspected, hibernating, revoked | Runtime/evidence policy. |

Durable causal fold work has its own lifecycle:
`planned -> running -> completed`, with failures moving to `retry_ready` and,
after bounded retries, `technical_error`. `CausalFoldExecutionService` owns the
lease and preserves completed sibling folds.
An eventual `completed` receipt keeps its attempt count. A prior timeout can
therefore be recovered for scientific settlement without becoming a clean
unattended autonomy proof.
Once the experiment is `technical_quarantine` or
`invalid_counterfactual_contract`, any still-queued sibling fold exits before
creating or executing replay evidence.

## Core transition rules

1. A planned experiment becomes runnable only with a sealed, valid contract and
   a compatible data/execution context.
2. A worker may settle only the current leased attempt; a stale worker cannot
   overwrite newer evidence.
3. Terminal evidence creates an immutable conversion receipt plus exactly one
   explicit next durable action, deferred reason or terminal reason.
4. `INCONCLUSIVE`, `UNDERPOWERED`, `UNREACHABLE` and technical quarantine are
   valid terminal results; none may be re-labelled as positive authority.
5. Component credit needs a declared paired/attested effect rather than a
   whole-packet performance result.
6. `confirmed_component` requires the observed replay context to match the
   sealed source context; a cross-session/context result remains research-only.
7. A confirmed mentor proof is monotonic under later forward/economic receipts.
   It may be hibernated or revoked by the explicit freshness policy, but must
   not disappear merely because a later receipt omits repeated window counts.
8. Historical forward evidence can prepare a paper candidate, but Economic
   Parent/performance authority requires valid prospective evidence from the
   frozen 2026 paper epoch.
9. A durable causal experiment can settle only with the exact configured fold
   index set, complete request/response/data/execution hashes and a completed
   aggregate receipt. Partial folds are diagnostic only.
10. A pre-replay experiment whose three arms are terminal without three
    completed immutable screening runs moves from `ready_for_replay` to
    `invalid_counterfactual_contract`. It cannot stay open, dispatch folds or
    receive scientific/performance/inheritance credit.
11. A candidate quarantined only because its same-generation frozen control
    terminated inherits that control's disposition. It owns no separate replay
    recovery debt unless the referenced control is itself transient-recoverable.
12. A post-v2 causal experiment crosses `provisional -> confirmed` only when
    the component lattice is proof-carrying and every risk/context check passes.
    The confirmed triplet then writes one atomic repair-credit and causal-skill-
    credit pair keyed to its exact escrow; retries reuse those event identities.
    Mentor projection follows the credit receipt and remains research-only.
13. `activation_factorial` work can end as `underpowered_activation`,
    `local_activation_not_observed`, `activation_observed_nonexclusive`, or a
    joint tactic/entry activation hypothesis. These are behavioral states,
    not economic claims. A positive behavioral hypothesis creates a semantic
    receipt and blocked fresh-window validation item; it never reaches the
    credit bridge. Incomplete/mismatched arm evidence remains invalid and
    cannot be reinterpreted as a scientific no-effect result.
