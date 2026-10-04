# Cross-runtime contract states

| From | Trigger | To | Guard / owner | Failure behavior |
| --- | --- | --- | --- | --- |
| request_drafted | Laravel freezes data and canonicalizes payload | request_sealed | Laravel owns request identity/version; XAUUSD autonomous work requires one M5/H4/H1/M15 manifest; a declared volume lane additionally requires snapshot-scoped provenance | Invalid payload, incomplete bundle or unattested historical volume is rejected before dispatch. |
| request_sealed | Python compiler validates frozen DAG, ports, activation-scope overlap and aggregate execution authority | program_compiled | Python owns versioned program compilation; MTF hashes, scope evidence and instrument source components must equal the passport | Missing/empty scope, invalid edge or drifted authority is `compile_failed` before replay. |
| program_compiled | deterministic replay executes | computed | Python emits typed gate receipts at admission/veto and runtime receipts through actual position close | Missing or modified gate-chain hashes fail evidence assembly; idle candles are not rejection receipts; totals from different decisions cannot manufacture a consumed witness. |
| runtime_bound | cost/stress diagnostic changes execution assumptions | diagnostic_runtime_bound | Python derives a hash-valid child from the canonical composition authority | Diagnostic results have no promotion authority and cannot mutate the canonical passport. |
| computed | response returns to Laravel | response_attested | Laravel verifies required protocol/hash/projection copies | Missing or mismatched evidence fails closed. |
| response_attested | downstream gate accepts the result | persisted_or_dispatched | Relevant Laravel owner records immutable evidence | No downstream owner may reinterpret a sealed identity. |
| any | protocol mismatch/staleness | rejected_or_quarantined | Boundary owner preserves reason | The value cannot become promotion evidence. |

An original completed server-authorized post-paper window has a narrow
`request_sealed -> authorized_research_execution` branch for full mode. The
original persisted release, canonical frozen stream records, actual CSV
hashes and complete UTC chronology are required. Missing/tampered signature,
source drift, inline replacement, paper overlap or any literal 2026 candle
rejects before cache or execution. The seven-field scientific window receipt
is unchanged; execution admission alone grants neither independent evidence
nor authority. Stable HMAC contract identity survives ordinary retries; key
rotation reauthenticates transport without creating a new scientific cache
identity. Every cache hit still rechecks authentication and actual sources.

New nonterminal requests additionally receive one original model/runtime seal
in a separate immutable artifact. The persisted run is locked before any write;
terminal or stale callbacks do not change existing request/data hashes or add
modern seals. Original raw and compressed request identities remain distinct.
Runtime/typed-program drift or a stamp later than completion invalidates the
read-only original-source proof, not the original historical record.

This is a boundary lifecycle, not a replacement for the state machines of
laboratory, entry or paper execution modules.

For composition/instrument research, `computed -> response_attested` also
requires an explicit v3 runtime trace backed by an execution receipt, compiled
program hash, full decision-receipt ledger and a single completed decision
witness for `consumed`. `assigned`, `available` or
parameter-bound metadata alone is not equivalent to `activated`; a valid but
unactivated/rejected composition receives no whole-organism credit.
For ordinary XAUUSD generations, the same boundary also separates the H1
storage identity from the M5 execution state: no ready, hash-attested closed-MTF
context means no replay evidence.

Volume has a distinct optional branch at `request_drafted`: `volume_lane=none`
transitions directly as `not_requested`, while a volume-dependent lane must
reach `historical_volume_ready` for the exact frozen snapshot. A live audit
cannot cause that transition.

`request_sealed` rejects inline replacements before cache lookup. A file's SHA
is computed from the same bytes passed to the parser; feature construction and
completed cache return attest actual consumed source rows. Missing or changed
rows are rejected, not replaced by the declared dataset hash. Invalid, duplicate
or non-finite HTF rows fail their stream guard instead of being silently dropped.
`computed` may emit `replay_decision_identity_v2`; only a complete, source-bound
ordered candle domain with observed stage event hashes can carry stage mastery.
Legacy count-only telemetry remains non-controlling.
