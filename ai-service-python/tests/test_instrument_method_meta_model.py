import copy
import hashlib
import json

import pytest

from app.services.instrument_method_meta_model import (
    BINDING_FIELDS, CEILING, CONTEXT_FIELDS, PHYSICAL_FIELDS, PROTOCOL, FiniteInstrumentMetaModel,
)
from app.services.research_program_tasks import canonical_hash, canonical_json


def original_export():
    context = {"regime": "trend_up", "volatility": "normal", "session": "london", "direction": "buy"}
    binding = {"symbol": "XAUUSD", "timeframe": "M5", "strategy": "differential_router",
               "clock_seconds": 300}
    for field in BINDING_FIELDS - set(binding):
        binding[field] = hashlib.sha256(field.encode()).hexdigest()
    body = {"protocol": PROTOCOL, "binding": binding, "context_fields": CONTEXT_FIELDS,
            "confidence_edges": [0.0, 0.5, 0.75, 1.0], "probability_edges": [0.0, 0.25, 0.5, 0.75, 1.0],
            "smoothing": 1.0, "minimum_cell_training": 5, "minimum_cell_calibration": 3,
            "training_samples": 20, "calibration_samples": 10,
            "training_cutoff": "2025-01-01T04:55:00+00:00",
            "calibration_cutoff": "2025-01-01T07:25:00+00:00",
            "last_label_available_at": "2025-01-01T07:25:00+00:00",
            "training_range": {"start": "2025-01-01T00:00:00+00:00", "end": "2025-01-01T04:55:00+00:00"},
            "calibration_range": {"start": "2025-01-01T05:00:00+00:00", "end": "2025-01-01T07:25:00+00:00"},
            "bins": [{"key": canonical_hash({"context": context, "confidence_bin": 2}), "context": context,
                      "confidence_bin": 2, "count": 20, "wins": 15, "score": 16 / 22}],
            "calibration_bins": [{"probability_bin": 2, "count": 10, "wins": 6, "score": 7 / 12}], **CEILING}
    for field in ("registration_hash", "records_hash", "originals_hash", "training_records_hash", "calibration_records_hash"):
        body[field] = hashlib.sha256(field.encode()).hexdigest()
    return reseal(body)


def reseal(body):
    encoded = canonical_json(body)
    # Test-only server reference. Real expected ref comes from PHP export, not callers.
    artifact = {**body, "artifact_json": encoded, "artifact_hash": hashlib.sha256(encoded.encode()).hexdigest(),
                "issuer_signature": "a" * 64}
    reference = {**body["binding"], "artifact_hash": artifact["artifact_hash"],
                 "issuer_signature": artifact["issuer_signature"]}
    return artifact, reference


def predict(model, **changes):
    value = {"context": {"regime": "trend_up", "volatility": "normal_volatility", "session": "london", "direction": "BUY"},
             "signal_confidence": 0.8, "observed_at": "2025-01-01T07:30:00+00:00", "decision_at": "2025-01-01T07:30:00+00:00"}
    value.update(changes)
    return model.predict(**value)


def test_finite_classifier_and_separate_calibrator_not_empirical_pf_filter():
    artifact, reference = original_export()
    model = FiniteInstrumentMetaModel(artifact, reference)
    assert predict(model) == pytest.approx(7 / 12)
    assert model.body["bins"][0]["score"] == pytest.approx(16 / 22)
    assert model.body["research_only"] and not model.body["economic_authority"]


@pytest.mark.parametrize("stamp", ["2024-12-31T23:00:00+00:00", "2025-01-01T04:55:00+00:00", "2025-01-01T07:25:00+00:00"])
def test_weights_unavailable_until_every_training_and_calibration_label_is_past(stamp):
    artifact, reference = original_export()
    assert predict(FiniteInstrumentMetaModel(artifact, reference), observed_at=stamp) is None


def test_later_evaluation_can_use_different_dataset_and_method_intervention_but_same_physical_baseline():
    artifact, reference = original_export()
    prospective_physical = {key: artifact["binding"][key] for key in PHYSICAL_FIELDS}
    model = FiniteInstrumentMetaModel(artifact, reference, prospective_physical)
    assert predict(model, observed_at="2025-06-01T07:30:00+00:00", decision_at="2025-06-01T07:30:00+00:00") is not None
    prospective_physical["execution_hash"] = "b" * 64
    with pytest.raises(ValueError, match="EVALUATION_PHYSICAL_BASELINE_MISMATCH"):
        FiniteInstrumentMetaModel(artifact, reference, prospective_physical)


def test_changed_actual_account_risk_or_hard_limit_identity_refuses_model_transfer():
    artifact, reference = original_export()
    physical = {key: artifact["binding"][key] for key in PHYSICAL_FIELDS}
    physical["account_risk_hash"] = "f" * 64
    with pytest.raises(ValueError, match="EVALUATION_PHYSICAL_BASELINE_MISMATCH"):
        FiniteInstrumentMetaModel(artifact, reference, physical)


def test_copied_signature_and_self_rehash_cannot_replace_original_server_model_reference():
    artifact, reference = original_export()
    forged_body = json.loads(artifact["artifact_json"])
    forged_body["bins"][0]["wins"] = 19
    forged_body["bins"][0]["score"] = 20 / 22
    forged, _ = reseal(forged_body)
    with pytest.raises(ValueError, match="SERVER_REFERENCE_MISMATCH"):
        FiniteInstrumentMetaModel(forged, reference)


@pytest.mark.parametrize("field,value", [("pickle", "gASVA"), ("python", "import os"), ("weights", [0.0]), ("Master", True)])
def test_extra_code_authority_or_unbounded_model_types_are_rejected(field, value):
    artifact, reference = original_export()
    artifact[field] = value
    with pytest.raises(ValueError, match="FINITE_ARTIFACT_FIELDS_REQUIRED"):
        FiniteInstrumentMetaModel(artifact, reference)


def test_unknown_context_or_confidence_bin_is_unavailable_not_global_fallback():
    artifact, reference = original_export()
    model = FiniteInstrumentMetaModel(artifact, reference)
    assert predict(model, signal_confidence=0.4) is None
    assert predict(model, context={"regime": "range", "volatility": "normal", "session": "london", "direction": "BUY"}) is None


@pytest.mark.parametrize("change", ["context", "count", "score", "duplicate", "nan", "paper", "overlap", "flag"])
def test_finite_numeric_type_count_score_context_and_chronology_guards(change):
    artifact, _ = original_export()
    body = json.loads(artifact["artifact_json"])
    if change == "context":
        body["bins"][0]["context"]["evil"] = "unbounded"
    elif change == "count":
        body["bins"][0]["count"] = True
    elif change == "score":
        body["bins"][0]["score"] = 0.99
    elif change == "duplicate":
        body["bins"].append(copy.deepcopy(body["bins"][0]))
    elif change == "nan":
        body["bins"][0]["score"] = "NaN"
    elif change == "paper":
        body["calibration_range"]["end"] = "2026-01-01T00:00:00+00:00"
        body["calibration_cutoff"] = body["calibration_range"]["end"]
    elif change == "overlap":
        body["calibration_range"]["start"] = body["training_cutoff"]
    else:
        body["economic_authority"] = True
    forged, forged_reference = reseal(body)
    with pytest.raises(ValueError):
        FiniteInstrumentMetaModel(forged, forged_reference)


@pytest.mark.parametrize("value", [True, -0.01, 1.01, float("nan"), "0.8"])
def test_invalid_signal_scores_never_become_calibrated_probability(value):
    artifact, reference = original_export()
    with pytest.raises(ValueError, match="SIGNAL_CONFIDENCE_INVALID"):
        predict(FiniteInstrumentMetaModel(artifact, reference), signal_confidence=value)


def test_future_decision_and_paper_input_rejected_even_with_valid_artifact():
    artifact, reference = original_export()
    model = FiniteInstrumentMetaModel(artifact, reference)
    with pytest.raises(ValueError, match="FUTURE_OR_PAPER_INPUT_FORBIDDEN"):
        predict(model, observed_at="2025-01-01T07:35:00+00:00")
    with pytest.raises(ValueError, match="FUTURE_OR_PAPER_INPUT_FORBIDDEN"):
        predict(model, observed_at="2026-01-01T00:00:00+00:00", decision_at="2026-01-01T00:00:00+00:00")


def test_wire_hash_missing_reference_and_wrong_training_baseline_are_rejected():
    artifact, reference = original_export()
    with pytest.raises(ValueError, match="SERVER_EXPORTED_REFERENCE_REQUIRED"):
        FiniteInstrumentMetaModel(artifact, {})
    wrong = {**reference, "effective_policy_hash": "f" * 64}
    with pytest.raises(ValueError, match="TRAINING_BASELINE_MISMATCH"):
        FiniteInstrumentMetaModel(artifact, wrong)
    artifact["artifact_json"] += " "
    with pytest.raises(ValueError, match="ARTIFACT_HASH_INVALID"):
        FiniteInstrumentMetaModel(artifact, reference)
