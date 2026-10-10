"""Synthetic authenticated-file mechanisms; these fixtures grant no research authority."""
import copy
import hashlib
import hmac
import json

import pandas as pd
import pytest

from app.schemas import SimpleBacktestRequest, StrategyRuntimeConfig
from app.services import research_release as transport
from app.services.backtester import _load_verified_dataset_csv, prepare_replay_feature_context
from app.services.execution_contract import execution_contract_metadata
from app.services.scoped_research_runtime import (MANIFEST_PROTOCOL, bind_scoped_research_runtime,
    derive_scoped_partition_payload, scoped_research_runtime_current, _wire_json, _hash)

KEY = "scoped-runtime-fixture-internal-key-32-characters"


def sign(payload, identity):
    canonical = transport._release_json(identity)
    payload.policy_context["authorized_research_transport"] = {**identity,
        "contract_hash": hashlib.sha256(canonical.encode()).hexdigest(),
        "hmac_sha256": hmac.new(KEY.encode(), (transport.RESEARCH_TRANSPORT_PROTOCOL + "\n" + canonical).encode(), hashlib.sha256).hexdigest()}


@pytest.fixture
def scoped(tmp_path, monkeypatch, request):
    # Only the test-owned storage root and clock differ; all admission/file/MTF guards run.
    monkeypatch.setattr(transport, "_research_data_roots", lambda: (tmp_path,))
    monkeypatch.setattr(transport, "_research_transport_now", lambda: pd.Timestamp("2028-01-01", tz="UTC"))
    records = {}; files = {}
    for timeframe, minutes, rows in [("M5", 5, 240), ("M15", 15, 80), ("H1", 60, 30), ("H4", 240, 10)]:
        path = tmp_path / (timeframe + ".csv")
        times = pd.date_range("2027-02-01", periods=rows, freq=f"{minutes}min", tz="UTC")
        frame = pd.DataFrame({"time": times, "open": 100.0, "high": 101.0, "low": 99.0, "close": 100.0, "volume": 10})
        if getattr(request, "param", False) and timeframe == "M5":
            frame["spread_available"] = 1
            frame["spread"] = .02
            frame["bid_close"] = 100.0
            frame["ask_close"] = 100.02
            frame["quote_time_utc"] = times + pd.Timedelta(minutes=4, seconds=30)
            frame["quote_available_after_utc"] = times + pd.Timedelta(minutes=5)
            frame["quote_age_ms"] = 30000
        frame.to_csv(path, index=False)
        digest = hashlib.sha256(path.read_bytes()).hexdigest()
        records[timeframe] = {"path": str(path), "sha256": digest, "row_count": rows}
        files[timeframe] = {"path": str(path), "sha256": digest, "rows": rows,
            "start_inclusive": times[0].isoformat(), "last_candle_at": times[-1].isoformat()}
    dataset_hash = hashlib.sha256(_wire_json(records).encode()).hexdigest()
    raw_arms = [{"lab_agent_id": index, "strategy": "ema_rsi_v1", "base_strategy": "ema_rsi_v1", "version": "fixture",
        "parameters": {"ema_fast": 12, "ema_slow": 26, "time_stop_candles": 24},
        "mtf_pilot": {"enabled": True}, "symbol": "XAUUSD"} for index in [1, 2, 3]]
    payload = SimpleBacktestRequest(symbol="XAUUSD", timeframe="M5", strategy="all", evaluation_mode="full",
        dataset_path=records["M5"]["path"], replay_dataset_hash=dataset_hash,
        mtf_dataset_paths={key: value["path"] for key, value in records.items()},
        mtf_snapshot_manifest={"protocol": "closed_h4_h1_m15_m5_snapshot_v1", "validation_bundle_protocol": MANIFEST_PROTOCOL,
            "bundle_hash": dataset_hash, "streams": records},
        mtf_pilot={"enabled": True, "activation_status": "execution_stream_bound", "execution_timeframe": "M5"},
        strategies=[StrategyRuntimeConfig.model_validate(arm) for arm in raw_arms])
    payload.execution_contract = execution_contract_metadata(payload)
    if getattr(request, "param", False):
        payload.mtf_snapshot_manifest["quote_spread_provenance"] = {
            "protocol": "historical_quote_spread_snapshot_v1", "provider": "dukascopy_historical_synchronized_tick_v1",
            "maximum_quote_age_ms": 60000, "paper_2026_included": False, "promotion_evidence": False,
            "sources": [{"sha256": records["M5"]["sha256"]}], "source_m5_csv_sha256": records["M5"]["sha256"]}
    release = {"protocol": "prospective_research_release_v1", "python_source_hash": transport.source_hash(),
        "source_hash": "b" * 64, "dataset_hash": dataset_hash,
        "agent_execution_hashes": {str(index): payload.execution_contract["execution_hash"] for index in [1, 2, 3]}}
    release["release_hash"] = hashlib.sha256(transport._release_json(release).encode()).hexdigest()
    payload.research_release = release
    window = {"protocol": "instrument_research_window_v1", "authorization_id": "test-owned-window",
        "research_epoch_id": "test-owned-research-2027", "start_inclusive": "2027-02-01T00:00:00+00:00",
        "end_exclusive": "2027-03-01T00:00:00+00:00", "dataset_sha256": dataset_hash}
    window["window_key"] = hashlib.sha256(json.dumps(window, separators=(",", ":")).encode()).hexdigest()
    declaration = {"protocol": "independent_original_scope_authority_v1", "purpose": "independent_scoped_selector_research",
        "certificate_id": 1, "design_hash": "a" * 64, "window_key": window["window_key"], "promotion_evidence": False}
    fence = {"protocol": "scoped_original_maturity_fence_v1", "entry_end_exclusive": "2027-02-28T23:55:00+00:00",
        "end_exclusive": window["end_exclusive"], "holding_fence_seconds": 300}
    payload.policy_context = {"scoped_research_certificate": declaration, "scoped_position_maturity_fence": fence}
    shared = {"initial_balance": 10000, "risk_per_trade": 1,
        "execution": payload.execution.model_dump(mode="json", exclude_unset=True), "execution_contract": payload.execution_contract,
        "mtf_pilot": payload.mtf_pilot, "volume_context": payload.volume_context}
    descriptor = {"protocol": "scoped_original_window_v1", "purpose": declaration["purpose"], "certificate_id": 1,
        "design_hash": declaration["design_hash"], "declaration_hash": _hash(declaration), "manifest_hash": _hash(payload.mtf_snapshot_manifest),
        "execution_hash": payload.execution_contract["execution_hash"], "maturity_fence_hash": _hash(fence),
        "strategies_json": _wire_json(raw_arms), "strategies_hash": _hash(raw_arms),
        "shared_runtime_json": _wire_json(shared), "shared_runtime_hash": _hash(shared), "confirmation_contracts_hash": _hash([]),
        "full_replay_runtime_policy_hash": _hash([])}
    identity = {"protocol": transport.RESEARCH_TRANSPORT_PROTOCOL, "purpose": "server_authorized_research_execution",
        "generation_id": 1, "release_hash": release["release_hash"], "dataset_hash": dataset_hash, "symbol": "XAUUSD", "timeframe": "M5",
        "evaluation_mode": "full", "window": window, "files": files,
        "paper_exclusions": [{"start_inclusive": "2026-01-01T00:00:00+00:00", "end_exclusive": "2027-01-01T00:00:00+00:00"}],
        "scoped_original_window": descriptor, "independent_evidence": False, "promotion_evidence": False}
    sign(payload, identity)
    return payload, identity


def arm(payload):
    config = payload.strategies[0].model_dump(mode="json")
    return payload.model_copy(update={key: config[key] for key in ["strategy", "base_strategy", "version", "parameters",
        "instrument_research_assignment", "composition_runtime_contract", "specialist_context_contract", "specialist_council_contract"]})


def test_real_authenticated_four_csv_request_compiles_closed_context_and_private_copy_survives(scoped):
    payload, _ = scoped
    verified = transport.verify_research_transport(payload, KEY)
    assert transport.authenticated_research_transport_current(verified)
    assert scoped_research_runtime_current(payload)
    candidate = arm(payload)
    assert scoped_research_runtime_current(candidate)
    assert scoped_research_runtime_current(copy.deepcopy(candidate))
    context = prepare_replay_feature_context(candidate)
    assert context.mtf_context.status == "ready"
    assert "_verified_scoped_research_runtime" not in candidate.model_dump(mode="json")
    candidate.model_dump_json()  # No private issuer object is serialized into a request.


def test_manifest_flag_or_reconstructed_json_cannot_supply_private_authority(scoped):
    payload, _ = scoped
    with pytest.raises(ValueError, match="PRIVATE_WITNESS_REQUIRED"):
        prepare_replay_feature_context(arm(payload))
    raw = payload.model_dump(mode="json")
    raw["_verified_scoped_research_runtime"] = {"authorized": True}
    raw["policy_context"]["verified_scoped_original"] = True
    reconstructed = SimpleBacktestRequest.model_validate(raw)
    with pytest.raises(ValueError, match="AUTHENTICATED_TRANSPORT_REQUIRED"):
        bind_scoped_research_runtime(reconstructed, dict(payload.policy_context["authorized_research_transport"]))


@pytest.mark.parametrize("mutation", ["params", "caps", "risk", "fence", "declaration", "runtime"])
def test_post_authentication_runtime_scope_drift_is_refused(scoped, mutation):
    payload, _ = scoped
    transport.verify_research_transport(payload, KEY)
    candidate = arm(payload).model_copy(deep=True)
    if mutation == "params": candidate.parameters["time_stop_candles"] = 1
    if mutation == "caps": candidate.policy_context["learning_confirmation_contracts"] = {"1": {"maximum_holding_bars": 1}}
    if mutation == "risk": candidate.risk_per_trade = 2
    if mutation == "fence": candidate.policy_context["scoped_position_maturity_fence"]["holding_fence_seconds"] = 600
    if mutation == "declaration": candidate.policy_context["scoped_research_certificate"]["certificate_id"] = 2
    if mutation == "runtime": candidate.composition_runtime_contract["additional_veto"] = True
    with pytest.raises(ValueError, match="DRIFT"):
        scoped_research_runtime_current(candidate)


def test_actual_source_change_after_authentication_is_refused(scoped):
    payload, _ = scoped
    transport.verify_research_transport(payload, KEY)
    with open(payload.mtf_dataset_paths["H4"], "a", encoding="utf-8") as handle:
        handle.write("2027-02-03T00:00:00+00:00,100,101,99,100,10\n")
    with pytest.raises(ValueError, match="SOURCE_HASH_DRIFT"):
        scoped_research_runtime_current(arm(payload))


def test_only_actual_sealed_slice_can_issue_narrower_partition_fence(scoped):
    payload, _ = scoped
    transport.verify_research_transport(payload, KEY)
    candidate = arm(payload)
    frame = _load_verified_dataset_csv(candidate, candidate.dataset_path, "M5").iloc[:220].reset_index(drop=True)
    derived = derive_scoped_partition_payload(candidate, frame)
    assert scoped_research_runtime_current(derived, frame)
    assert derived.parameters == candidate.parameters
    fence = derived.policy_context["scoped_position_maturity_fence"]
    assert pd.Timestamp(fence["end_exclusive"]) == pd.Timestamp(frame.time.iloc[-1]) + pd.Timedelta(minutes=5)
    assert fence["holding_fence_seconds"] == 300
    assert candidate.policy_context["scoped_position_maturity_fence"]["end_exclusive"] == "2027-03-01T00:00:00+00:00"
    repeated = transport.verify_research_transport(derived, KEY)
    assert transport.authenticated_research_transport_current(repeated)
    assert scoped_research_runtime_current(derived, frame)
    with pytest.raises(ValueError, match="PARTITION_DRIFT"):
        scoped_research_runtime_current(derived, frame.iloc[:-1])
    copied_wire = SimpleBacktestRequest.model_validate(derived.model_dump(mode="json"))
    with pytest.raises(ValueError, match="PRIVATE_WITNESS_REQUIRED"):
        scoped_research_runtime_current(copied_wire)


def test_signature_and_closed_row_source_checks_are_not_substituted_by_descriptor_labels(scoped):
    payload, identity = scoped
    payload.policy_context["authorized_research_transport"]["hmac_sha256"] = "f" * 64
    with pytest.raises(ValueError, match="SIGNATURE_INVALID"):
        transport.verify_research_transport(payload, KEY)
    sign(payload, identity)
    forged = copy.deepcopy(identity)
    forged["files"]["H4"]["rows"] = 11
    sign(payload, forged)
    with pytest.raises(ValueError, match="SOURCE_CHRONOLOGY_MISMATCH"):
        transport.verify_research_transport(payload, KEY)


@pytest.mark.parametrize("scoped", [True], indirect=True)
def test_authenticated_actual_future_bid_ask_keeps_the_existing_quote_checks(scoped):
    from app.services.historical_quotes import validate_historical_quotes
    payload, _ = scoped
    transport.verify_research_transport(payload, KEY)
    candidate = arm(payload)
    frame = _load_verified_dataset_csv(candidate, candidate.dataset_path, "M5")
    frame["time"] = pd.to_datetime(frame["time"], utc=True)
    checked = validate_historical_quotes(frame.copy(), candidate)
    assert checked["spread_available"].eq(1).all()
    # A changed observed row cannot borrow the authenticated original source.
    forged = frame.copy()
    forged.loc[0, "ask_close"] = 90
    with pytest.raises(ValueError, match="SEALED_DATASET_CONSUMED_ROWS_MISMATCH"):
        validate_historical_quotes(forged, candidate)


@pytest.mark.parametrize("purpose", ["independent_scoped_component_research", "independent_scoped_descendant_research"])
def test_original_full_window_runs_actual_main_and_cache_without_historical_split(scoped, purpose, monkeypatch, tmp_path, capsys):
    from app import main
    payload, identity = scoped
    payload.strategies = payload.strategies[:1]
    declaration = payload.policy_context["scoped_research_certificate"]
    declaration["purpose"] = purpose
    policy = {"protocol": "scoped_original_full_source_v1", "evaluation_mode": "full",
        "selection": "entire_authorized_source", "maximum_source_rows": 1000, "maximum_runtime_seconds": 60,
        "warmup_rows": 0, "no_walk_forward_selection": True, "promotion_evidence": False}
    payload.policy_context["full_replay_runtime_policy"] = policy
    descriptor = identity["scoped_original_window"]
    descriptor.update(purpose=purpose, declaration_hash=_hash(declaration), full_replay_runtime_policy_hash=_hash(policy))
    raw = json.loads(descriptor["strategies_json"])[:1]
    descriptor.update(strategies_json=_wire_json(raw), strategies_hash=_hash(raw))
    sign(payload, identity)
    monkeypatch.setenv("INTERNAL_API_TOKEN", KEY)
    monkeypatch.setenv("INTERNAL_API_TOKEN_FILE", "")
    monkeypatch.setenv("AI_REPLAY_CHECKPOINT_DIR", str(tmp_path / "checkpoints"))
    monkeypatch.setenv("AI_REPLAY_IMMUTABLE_CACHE_DIR", str(tmp_path / "cache"))
    original_parameters = copy.deepcopy(payload.strategies[0].parameters)
    outcome = main._run_all_backtests_sync(payload)
    result = outcome["leaderboard"][0]["result"]
    clock = result["data_quality"]["replay_executed_clock"]
    assert clock["complete"] and clock["input_rows"] == 240 and clock["decision_rows"] == 40
    assert clock["first_evaluation_index"] == 200 and clock["last_evaluation_index"] == 239
    assert result["data_quality"]["scoped_original_window"]["selection"] == "entire_authorized_source"
    assert result["statistical_evidence"]["original_position_maturity"]["forced_terminal_close_applied"] is False
    assert "monte_carlo" in result and result["monte_carlo"].get("deferred") is not True
    assert payload.strategies[0].parameters == original_parameters
    assert outcome["leaderboard"][0]["rolling_windows_count"] == 0
    assert list((tmp_path / "cache").glob("*.json.gz"))
    capsys.readouterr()
    cached = main._run_all_backtests_sync(payload.model_copy(deep=True))
    assert cached["leaderboard"] == outcome["leaderboard"]
    timing = next(line.removeprefix("REPLAY_TIMING ") for line in capsys.readouterr().err.splitlines()
        if line.startswith("REPLAY_TIMING "))
    assert "stateful_replay_ms" not in json.loads(timing)["stages_ms"]


def test_canonical_native_parameters_cannot_admit_unsigned_gene_additions(scoped):
    from app.services.parameter_schema import validate_strategy_parameters
    payload, _ = scoped
    transport.verify_research_transport(payload, KEY)
    candidate = arm(payload)
    candidate.parameters = validate_strategy_parameters(candidate.strategy, candidate.parameters, candidate.base_strategy)
    assert scoped_research_runtime_current(candidate)
    candidate.parameters["atr_stop"] = 1.5
    with pytest.raises(ValueError, match="ARM_DRIFT"):
        scoped_research_runtime_current(candidate)


@pytest.mark.parametrize("kind,expected_universe,expected_rows", [("authenticated_selector", 1, 512), ("legacy_replay", 2, 2048)])
def test_actual_main_admission_preserves_only_authenticated_selector_small_signed_caps(
        scoped, kind, expected_universe, expected_rows, monkeypatch, tmp_path):
    """Admission-only observation: intentionally stop at producer dispatch, not a replay receipt."""
    from app import main
    payload, identity = scoped
    cap = {"protocol": "bounded_cold_start_learning_confirmation_v1", "execution_mode": "durable_single_fold_job",
        "fold_count": 1, "fold_offset": 0, "fold_universe_count": 1, "max_rows_per_fold": 512,
        "maximum_holding_bars": 1, "purge_bars": 1, "embargo_bars": 1,
        "per_fold_budget_seconds": 45, "audit_trace_rows": 512, "minimum_trades_per_window": 3,
        "admitted": True, "promotion_evidence": False}
    if kind == "authenticated_selector":
        contracts = {str(config.lab_agent_id): copy.deepcopy(cap) for config in payload.strategies}
        payload.policy_context["learning_confirmation_contracts"] = contracts
        identity["scoped_original_window"]["confirmation_contracts_hash"] = _hash(contracts)
        sign(payload, identity)
    else:
        # A real pre-paper CSV exercises the ordinary replay branch and its
        # original minima; it carries no scoped declaration or private proof.
        legacy = pd.read_csv(payload.dataset_path)
        legacy["time"] = pd.to_datetime(legacy["time"], utc=True) - pd.DateOffset(years=2)
        legacy_path = tmp_path / "legacy_pre_paper.csv"
        legacy.to_csv(legacy_path, index=False)
        payload = SimpleBacktestRequest(symbol="XAUUSD", timeframe="M5", strategy="all", evaluation_mode="replay",
            dataset_path=str(legacy_path), strategies=payload.strategies[:1],
            policy_context={"learning_confirmation_contracts": {"1": copy.deepcopy(cap)}})
        payload.execution_contract = execution_contract_metadata(payload)
    monkeypatch.setenv("INTERNAL_API_TOKEN", KEY)
    monkeypatch.setenv("INTERNAL_API_TOKEN_FILE", "")
    monkeypatch.setenv("AI_REPLAY_CHECKPOINT_DIR", str(tmp_path / "admission_checkpoints"))
    monkeypatch.setenv("AI_REPLAY_IMMUTABLE_CACHE_DIR", str(tmp_path / "admission_cache"))
    observed = []

    class AdmissionOnlyStop(RuntimeError):
        pass

    def record_dispatch(_owner, candidate, actual_frame, _score, **arguments):
        if kind == "authenticated_selector":
            assert scoped_research_runtime_current(candidate, actual_frame)
        else:
            assert not scoped_research_runtime_current(candidate, actual_frame)
        observed.append({"fold_count": arguments["fold_count"], "fold_universe_count": arguments["fold_universe_count"],
            "max_rows_per_fold": arguments["max_rows_per_fold"], "source_rows": len(actual_frame),
            "source_start": pd.Timestamp(actual_frame.time.iloc[0])})
        raise AdmissionOnlyStop("admission observed; no producer result issued")

    # All main/authentication/source/cap guards run. Only execution is stopped
    # at its boundary so this regression does not spend a large replay budget.
    monkeypatch.setattr(main.WalkForwardService, "run_causal_confirmation", record_dispatch)
    with pytest.raises(AdmissionOnlyStop, match="no producer result issued"):
        main._run_all_backtests_sync(payload)
    assert len(observed) == 1
    assert observed[0]["fold_count"] == 1
    assert observed[0]["fold_universe_count"] == expected_universe
    assert observed[0]["max_rows_per_fold"] == expected_rows
    assert observed[0]["source_rows"] == 240
    assert observed[0]["source_start"].year == (2027 if kind == "authenticated_selector" else 2025)
    assert all(contract == cap for contract in payload.policy_context["learning_confirmation_contracts"].values())
    assert not list((tmp_path / "admission_cache").glob("*.json.gz"))
