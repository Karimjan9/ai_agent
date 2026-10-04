# ADR-016: Pin prospective research to the actually loaded release

- Status: accepted
- Date: 2026-09-28

## Context

Historical generations contain multiple source hashes. A hash of current disk
files cannot attest imports already loaded in a long-lived PHP/Python worker.
Replacing historical hashes after deployment would fabricate reproducibility.

## Decision

The canonical generation dispatcher seals one source/config/dependency hash,
Python source hash, dataset/MTF identity and per-agent economic parameter hash
before queue admission. Admission, immutable-run creation and request binding
recheck this release. PHP queue workers capture their source hash at boot;
the Python API and replay worker compare their boot and current hashes before
cache lookup or computation. Both boundaries recheck the release hash and the
request's dataset and execution-cost identity. Results return an actual worker
receipt, which Laravel requires for prospective evidence completeness and
learning eligibility.

JSON object order and integer/float projection do not change numerical cost
identity. Changed costs, datasets or source do. Existing attempted generations
remain explicitly legacy-unsealed; recovery never pins a newer release to them.

Deploy by pausing admission, draining bounded work, gracefully recycling queue
workers and restarting the idle Python owner, then resuming the same lineage.
The release audit distinguishes loaded-worker evidence from a clean Git artifact.
Source health alone is not a replay receipt, market proof or promotion authority.

## Consequences

- A source change after sealing is terminal release drift, not scientific loss.
- Fresh evidence can identify the computation that actually ran.
- A clean reproducible Git artifact still needs a separate commit/deployment;
  an attested dirty source snapshot does not claim that artifact exists.
- Historical technical/mixed-source evidence is preserved unchanged.

## 2026-10-03: Explicit source artifact, distinct from Git and workers

ResearchReleaseSealService owns an opt-in, bounded content-addressed ZIP of the
actual allowlisted runtime source, config, canonical scripts, dependency
manifests and focused tests. It reconstructs the existing full-runtime and
Python fingerprint domains from archived byte hashes, records Git HEAD/dirty
provenance and selected non-secret settings/tool versions, rejects collection
drift, and verifies archive entries without extraction. Secret/environment,
data/storage and installed-dependency paths are excluded. An explicitly built
matching archive may be referenced only when a fresh prospective release is
sealed. Old seals are never backfilled and no archive is built automatically
on every queue admission. Absence remains diagnostic incomplete for compatible
legacy/generic workflows. The audit keeps archive reproducibility, clean Git
commit, loaded-worker evidence and scientific proof separate; a dirty archive
can be byte-reproducible without claiming a clean commit or trading authority.
