# Laravel and Python execution contract

## Idea

Laravel owns admission, persistence and evidence policy; Python owns
deterministic strategy/replay calculation. Their handoff must be versioned,
canonicalized and attested so the persisted decision describes the computation
that was actually requested and returned.

## Goals

- Define explicit endpoint, payload and response responsibilities.
- Seal relevant data, strategy, execution and management identities.
- Reject missing, stale or mismatched contract copies at the boundary.
- Keep the same semantic compiler in replay and paper paths.

## Non-goals

- The contract does not grant a candidate authority that Laravel gates denied.
- It does not turn transport success into valid evidence.
- It does not permit either runtime to silently alter the other runtime's hash.

The authoritative detailed API reference is `docs/ai-service-contract.md`.
