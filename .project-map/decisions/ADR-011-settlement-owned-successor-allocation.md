# ADR-011: Settlement-owned successor allocation

- Status: accepted
- Date: 2026-09-26

## Context

The cold-start council allocated 16 cooperative seats alongside three protected
causal-proof arms and one uncertainty-abstain seat. Its fixed block-type list
used prior evidence to rank context and phase, but did not show which exact
settlement changed the next generation's research mix.

## Decision

Only the immediate terminal predecessor may supply allocation feedback. The
constructor supplies the generation number it already reserved; the council
must not infer a new number from a table that includes that draft row. Seal
the predecessor's cooperative settlement IDs, statuses, arm evidence and content hashes into
the successor allocation digest. Change at most one cold-start pair with an
explicit source settlement ID and before/after block type: negative repair
diversifies to novelty, negative novelty refocuses repair, underpowered
activation reserves coverage, and technical-invalid evidence reserves a
diagnostic guard. Preserve the ordinary adversarial guard, protected causal
arms and uncertainty abstention. Every seat receives one owner and the final
block/ownership mapping is hashed into the allocation manifest. Protected
causal owner keys use stable experiment/source IDs rather than JSON-serialized
floating intervention metadata.
Queue admission recomputes that manifest and the source-settlement digest;
drift after construction stops dispatch rather than silently changing the
experiment's owner.

Screening-positive settlement is not confirmed causal credit. The existing
credit/authority gate alone can open replication, factorial, transfer and
descendant blocks. A malformed, incomplete or nonterminal predecessor receipt
does not reallocate a pair.

## Consequences

- The manifest can answer which receipt changed which seat without treating
  a failed or underpowered result as an economic win.
- Historical generations and their allocation contracts are not rewritten.
- Deterministic successor tests establish the code handoff. A newly executed
  autonomous generation is still needed to establish runtime feedback proof.
