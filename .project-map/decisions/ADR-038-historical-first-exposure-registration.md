# ADR-038: Separate historical event time from first research exposure

Date: 2026-10-12
Status: Accepted registration contract; historical independence remains an evidence dependency

ADR-037 permanently reserves every event from 2026 onward for paper. The legacy
ADR-036 registration rule (wall-clock registration before market events) therefore
cannot admit a new historical question today. Moving the clock or using a future
paper year cannot fix that contradiction.

New independent-policy questions may explicitly declare
`registration_time_policy: historical_first_exposure_v2`. Their historical windows
must still finish before 2026. Their design, source models and selection policy
are frozen before the first original model/request/fold exposure. Registration
uses the real wall clock. V1 records, hashes, temporal checks and original outcomes
remain unchanged; an existing question cannot be retrospectively converted.

This permits registration, not authority. A new capture cannot attest old
training, selection, MTF, warmup or holding history. Historical issuance is blocked
with `HISTORICAL_FIRST_EXPOSURE_PROVENANCE_NOT_ATTESTED` while the original
historical owner cannot demonstrate non-exposure. Reference inventory, absence
of records, caller completeness flags and a newly downloaded archive are not
complete original exposure proof. There is no override or future-data fallback.

The current-date admission test uses synthetic source models to verify the time
contract and the refusal of unsupported authority. It is not market evidence.
Original physical data, source acquisition/custody, complete historical exposure,
power and the separate named issuers are still required for a scientific result.

The request-reference audit reads a fixed run watermark in 32-row chunks and
decodes each request once. It remains read-only exclusion evidence. Output caps
and missing histories remain explicit; faster diagnostics do not establish
unused data or grant paper, economic or inheritance authority.
