# ADR-023: Explicit prospective future paper epochs

- Status: accepted
- Date: 2026-10-03

## Context

Instrument confirmation reserves post-2026 research data, while the previous
paper-admission/outcome contract accepted only `paper_2026`. A later-frozen
candidate cannot honestly obtain after-freeze observations from an expired
period. A rolling cutoff or reused research archive would create false proof.

## Decision

Preserve the canonical pre-2026 research boundary and archive-only 2026 paper
contract. Add `authorized_prospective_paper_epoch_v1` through the existing
epoch/admission/order services with an empty server authorization registry by
default. An approved period explicitly seals its purpose, authorization/date,
UTC interval and paper-only restrictions. Approval precedes candidate freeze;
freeze precedes the period. Paper intervals cannot overlap authorized research
or original observed learning-pair research chronology, even if that research
authorization was later removed.

Persist the original future-period seal at E3. Carry its key in the candidate
identity; check observation readiness before AI capture/fill. E4 must use that
exact seal, valid after-freeze observation times and unchanged parameters,
discipline and the existing paper gate. Changing/re-hashing the outcome does
not authorize a different period. Completed E4 remains evidence after its
period closes, but new orders stop. These are prospective live-paper periods,
not retroactive frozen-CSV windows or live trading permission.

Seal confirmation routes before research construction: historical causal
research remains distinct from independent instrument/activation/Academy
authority. Missing original untouched-window provenance is an explicit
`BLOCKED_DEPENDENCY`. Existing instrument proofs attest exact pair/run/delta,
but do not establish candidate-wide training/selection provenance; therefore
post-paper-trained candidates are not admitted from a new unchecked flag.

The existing window owner also supplies authenticated execution transport for
a completed server-authorized post-paper full replay. The protected internal
API key signs a versioned, stable persisted release/window/actual-CSV contract;
Python validates actual whole-file chronology and consumed source rows before
execution or cache reuse. This is not an independent/untouched-data certificate.
A caller flag or cutoff cannot admit literal 2026. HMAC delivery metadata is
distinct from the scientific cache identity: key rotation requires fresh
authentication, not fresh market evidence. Default registries remain empty.

## Consequences

The bounded original-provenance consumer supports one explicit exception to
the generic post-paper training block: an unchanged modern archive baseline
plus a single canonically confirmed, runtime-seal-preserving trait. Original
archive seals must precede the 2027 research era; every post-paper source
window must finish before candidate freeze. The exact total parameter delta,
common original runtime/instrument treatment, current evaluator, original
compressed request/model/response bytes and all four CSV histories are
revalidated. A parent E3 is not required. Arbitrary future training or a trait
that changes component seals still needs separately verified provenance;
caller flags and later historical replay cannot create it.

- No future interval, research dataset, causal credit or market edge is created.
- Fully incubated still-pre-2026-trained candidates frozen later can use an
  explicitly approved later prospective paper period.
- Post-paper-trained candidates wait for original verified training/selection
  provenance. Ordinary discovery may continue without independent authority.
- The 2026 contract and historical receipts are not rewritten; existing risk,
  paper/promotion guards remain required.
