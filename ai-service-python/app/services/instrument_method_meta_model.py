"""Finite issuer-bound contextual classifier, never pickle/code execution.

Training identity and prospective evaluation identity are separate. Only a
trusted PHP export/compiler may supply expected_training_binding: a caller's
self-rehashed model and copied signature are not an authenticated model ref.
Earlier observations return unavailable rather than seeing future labels.
"""

from __future__ import annotations

import hashlib
import json
import math
import re
from datetime import datetime, timezone

from app.services.research_program_tasks import canonical_hash

PROTOCOL = "instrument_method_meta_model_v1"
PARAMETER_PROJECTION_PROTOCOL = "instrument_method_schema_numeric_projection_v1"
BINDING_FIELDS = {"symbol", "timeframe", "strategy", "parameters_hash", "account_risk_hash", "effective_policy_hash",
                  "data_hash", "execution_hash", "source_hash", "python_source_hash", "clock_seconds"}
PHYSICAL_FIELDS = {"symbol", "timeframe", "strategy", "parameters_hash", "account_risk_hash", "execution_hash", "clock_seconds"}
CONTEXT_FIELDS = ["regime", "volatility", "session", "direction"]
CEILING = {"research_only": True, "out_of_sample": False, "independent_validation": False,
           "confirmation_proven": False, "economic_authority": False,
           "promotion_evidence": False, "live_authority": False, "paper_authority": False,
           "skill_credit": False, "mastery_proven": False}
ARTIFACT_FIELDS = {"protocol", "registration_hash", "binding", "context_fields", "confidence_edges",
                   "probability_edges", "smoothing", "minimum_cell_training", "minimum_cell_calibration",
                   "training_samples", "calibration_samples", "training_cutoff", "calibration_cutoff",
                   "last_label_available_at", "training_range", "calibration_range", "records_hash",
                   "originals_hash", "training_records_hash", "calibration_records_hash", "bins",
                   "calibration_bins", *CEILING}
PAPER_START = datetime(2026, 1, 1, tzinfo=timezone.utc)


def _need(condition: bool, message: str) -> None:
    if not condition:
        raise ValueError(message)


def _sha(value) -> bool:
    return isinstance(value, str) and re.fullmatch(r"[a-f0-9]{64}", value) is not None


def _number(value) -> bool:
    return type(value) in {int, float} and math.isfinite(value)


def project_physical_parameters(strategy: str, parameters: dict, base_strategy: str | None = None) -> dict:
    """Only float-schema spelling changes; integer/flag/string values stay exact."""
    from app.services.parameter_schema import PARAMETER_SCHEMAS, EXECUTION_PARAMETER_SCHEMA, strategy_family
    _need(isinstance(strategy, str) and (base_strategy is None or isinstance(base_strategy, str)),
          "META_MODEL_PARAMETER_PROJECTION_SCHEMA_REQUIRED")
    _need(base_strategy is None or (0 < len(base_strategy) <= 128 and strategy_family(base_strategy) in PARAMETER_SCHEMAS),
          "META_MODEL_PARAMETER_PROJECTION_SCHEMA_REQUIRED")
    schema = PARAMETER_SCHEMAS.get(strategy_family(strategy, base_strategy))
    _need(schema is not None and isinstance(parameters, dict) and len(parameters) <= 256,
          "META_MODEL_PARAMETER_PROJECTION_SCHEMA_REQUIRED")
    schema = {**schema, **EXECUTION_PARAMETER_SCHEMA}
    _need(not set(parameters) - set(schema), "META_MODEL_PARAMETER_PROJECTION_SCHEMA_REQUIRED")
    projected = {}
    for key, value in parameters.items():
        rule = schema[key]
        kind = rule['type']
        valid = (_number(value) if kind is float else type(value) is kind)
        if kind is str:
            valid = valid and (not rule.get('choices') or value in rule['choices'])
        if kind in {int, float}:
            valid = valid and rule['min'] <= value <= rule['max']
        _need(valid, "META_MODEL_PARAMETER_PROJECTION_TYPE_REQUIRED")
        projected[key] = float(value) if kind is float else value
    return projected


def _projected_parameter_hash(projection: dict, binding: dict) -> str:
    _need(isinstance(projection, dict) and set(projection) == {
        'protocol', 'strategy', 'base_strategy', 'original_parameters', 'projected_parameters_hash'}
        and projection.get('protocol') == PARAMETER_PROJECTION_PROTOCOL
        and projection.get('strategy') == binding['strategy'], "META_MODEL_PARAMETER_PROJECTION_FIELDS_REQUIRED")
    original = projection['original_parameters']
    _need(isinstance(original, dict) and canonical_hash(original) == binding['parameters_hash'],
          "META_MODEL_ORIGINAL_PARAMETER_PROJECTION_REQUIRED")
    projected = project_physical_parameters(projection['strategy'], original, projection['base_strategy'])
    result = canonical_hash(projected)
    _need(result == projection['projected_parameters_hash'], "META_MODEL_PARAMETER_PROJECTION_HASH_INVALID")
    return result


def _utc(value) -> datetime:
    _need(isinstance(value, str) and re.search(r"(?:Z|[+-]\d{2}:\d{2})$", value) is not None,
          "META_MODEL_EXPLICIT_UTC_REQUIRED")
    try:
        stamp = datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError as error:
        raise ValueError("META_MODEL_UTC_INVALID") from error
    return stamp.astimezone(timezone.utc)


def _edges(value) -> list[float]:
    _need(isinstance(value, list) and 2 <= len(value) <= 9 and all(_number(item) for item in value)
          and value[0] == 0 and value[-1] == 1
          and all(0 <= item <= 1 for item in value)
          and all(left < right for left, right in zip(value, value[1:])), "META_MODEL_BIN_EDGES_INVALID")
    return value


def _bin(value: float, edges: list[float]) -> int:
    return next(index for index in range(len(edges) - 1)
                if value < edges[index + 1] or index == len(edges) - 2)


def _context(value: dict) -> dict:
    _need(isinstance(value, dict) and all(field in value for field in CONTEXT_FIELDS),
          "META_MODEL_ORIGINAL_CONTEXT_REQUIRED")
    result = {}
    for field in CONTEXT_FIELDS:
        item = value[field]
        _need(isinstance(item, str) and re.fullmatch(r"[A-Za-z0-9_:-]{1,64}", item) is not None,
              "META_MODEL_CONTEXT_TOKEN_INVALID")
        result[field] = item.lower()
    result["volatility"] = {"normal_volatility": "normal", "high_volatility": "high",
                            "low_volatility": "low"}.get(result["volatility"], result["volatility"])
    result["session"] = {"london_new_york_overlap": "overlap", "london_comex_overlap": "overlap",
                         "asian": "asia"}.get(result["session"], result["session"])
    _need(result["direction"] in {"buy", "sell"}, "META_MODEL_TRADE_DIRECTION_REQUIRED")
    return result


class FiniteInstrumentMetaModel:
    """A bounded count-table classifier and separate chronological calibrator."""

    protocol = PROTOCOL
    binding_fields = BINDING_FIELDS
    physical_fields = PHYSICAL_FIELDS
    token_fields = {"symbol", "timeframe", "strategy"}

    def __init__(self, artifact: dict, expected_training_binding: dict,
                 evaluation_binding: dict | None = None, parameter_projection: dict | None = None):
        _need(isinstance(artifact, dict) and set(artifact) == ARTIFACT_FIELDS | {
            "artifact_json", "artifact_hash", "issuer_signature"}, "META_MODEL_FINITE_ARTIFACT_FIELDS_REQUIRED")
        encoded = artifact.get("artifact_json")
        _need(isinstance(encoded, str) and 0 < len(encoded.encode("utf-8")) <= 262144,
              "META_MODEL_ARTIFACT_BYTE_CAP_EXCEEDED")
        try:
            body = json.loads(encoded)
        except (ValueError, RecursionError) as error:
            raise ValueError("META_MODEL_JSON_INVALID") from error
        supplied = {key: value for key, value in artifact.items()
                    if key not in {"artifact_json", "artifact_hash", "issuer_signature"}}
        _need(isinstance(body, dict) and set(body) == ARTIFACT_FIELDS and body == supplied,
              "META_MODEL_WIRE_BODY_MISMATCH")
        _need(_sha(artifact.get("artifact_hash"))
              and hashlib.sha256(encoded.encode("utf-8")).hexdigest() == artifact["artifact_hash"],
              "META_MODEL_ARTIFACT_HASH_INVALID")
        _need(_sha(artifact.get("issuer_signature")) and isinstance(expected_training_binding, dict)
              and set(expected_training_binding) == self.binding_fields | {"artifact_hash", "issuer_signature"},
              "META_MODEL_SERVER_EXPORTED_REFERENCE_REQUIRED")
        _need(expected_training_binding["artifact_hash"] == artifact["artifact_hash"]
              and expected_training_binding["issuer_signature"] == artifact["issuer_signature"],
              "META_MODEL_SERVER_REFERENCE_MISMATCH")
        _need(body.get("protocol") == self.protocol and all(body.get(key) is value for key, value in CEILING.items()),
              "META_MODEL_RESEARCH_ONLY_REQUIRED")
        binding = body.get("binding")
        _need(isinstance(binding, dict) and set(binding) == self.binding_fields
              and all(expected_training_binding[field] == binding[field] for field in self.binding_fields),
              "META_MODEL_TRAINING_BASELINE_MISMATCH")
        for field in self.binding_fields - self.token_fields - {"clock_seconds"}:
            _need(_sha(binding[field]), "META_MODEL_ORIGINAL_BINDING_HASH_REQUIRED")
        for field in self.token_fields:
            _need(isinstance(binding[field], str) and 0 < len(binding[field]) <= 128,
                  "META_MODEL_BINDING_TOKEN_REQUIRED")
        _need(type(binding["clock_seconds"]) is int and 1 <= binding["clock_seconds"] <= 86400,
              "META_MODEL_CLOCK_INVALID")
        _need(parameter_projection is None or self.protocol == PROTOCOL,
              "META_MODEL_NATIVE_PARAMETER_PROJECTION_FORBIDDEN")
        projected_hash = None if parameter_projection is None else _projected_parameter_hash(parameter_projection, binding)
        if evaluation_binding is not None:
            _need(isinstance(evaluation_binding, dict) and set(evaluation_binding) == self.physical_fields
                  and all(evaluation_binding[field] == (projected_hash if field == 'parameters_hash' and projected_hash is not None
                                                        else binding[field]) for field in self.physical_fields),
                  "META_MODEL_EVALUATION_PHYSICAL_BASELINE_MISMATCH")
        for field in ("registration_hash", "records_hash", "originals_hash", "training_records_hash", "calibration_records_hash"):
            _need(_sha(body[field]), "META_MODEL_ORIGINAL_PROVENANCE_HASH_REQUIRED")
        _need(body["context_fields"] == CONTEXT_FIELDS, "META_MODEL_FIXED_FEATURE_VOCABULARY_REQUIRED")
        self.confidence_edges = _edges(body["confidence_edges"])
        self.probability_edges = _edges(body["probability_edges"])
        _need(_number(body["smoothing"]) and 0.5 <= body["smoothing"] <= 10,
              "META_MODEL_SMOOTHING_INVALID")
        self.smoothing = float(body["smoothing"])
        for field, minimum in (("minimum_cell_training", 2), ("minimum_cell_calibration", 2),
                               ("training_samples", 20), ("calibration_samples", 10)):
            _need(type(body[field]) is int and minimum <= body[field] <= 50000,
                  "META_MODEL_BOUNDED_SAMPLE_REQUIREMENTS_REQUIRED")
        self.minimum_training = body["minimum_cell_training"]
        self.minimum_calibration = body["minimum_cell_calibration"]
        training, calibration = body["training_range"], body["calibration_range"]
        _need(isinstance(training, dict) and isinstance(calibration, dict)
              and set(training) == set(calibration) == {"start", "end"}, "META_MODEL_RANGE_FIELDS_INVALID")
        train_start, train_end = _utc(training["start"]), _utc(training["end"])
        cal_start, cal_end = _utc(calibration["start"]), _utc(calibration["end"])
        self.last_label_available_at = _utc(body["last_label_available_at"])
        self.calibration_cutoff = _utc(body["calibration_cutoff"])
        _need(train_start <= train_end < cal_start <= cal_end < PAPER_START
              and _utc(body["training_cutoff"]) == train_end
              and self.calibration_cutoff == cal_end
              and cal_start <= self.last_label_available_at <= cal_end,
              "META_MODEL_CHRONOLOGICAL_CUTOFF_INVALID")
        bins, calibrator = body["bins"], body["calibration_bins"]
        _need(isinstance(bins, list) and 1 <= len(bins) <= 4096
              and isinstance(calibrator, list) and 1 <= len(calibrator) < len(self.probability_edges),
              "META_MODEL_FINITE_CELL_CAP_REQUIRED")
        self.bins = {}
        for cell in bins:
            _need(isinstance(cell, dict) and set(cell) == {"key", "context", "confidence_bin", "count", "wins", "score"},
                  "META_MODEL_CELL_FIELDS_INVALID")
            context = _context(cell["context"])
            index = cell["confidence_bin"]
            _need(type(index) is int and 0 <= index < len(self.confidence_edges) - 1,
                  "META_MODEL_CONFIDENCE_BIN_INVALID")
            key = canonical_hash({"context": context, "confidence_bin": index})
            _need(cell["context"] == context and cell["key"] == key and key not in self.bins,
                  "META_MODEL_CELL_IDENTITY_INVALID")
            self._count_cell(cell)
            self.bins[key] = cell
        _need(sum(cell["count"] for cell in bins) == body["training_samples"]
              and 0 < sum(cell["wins"] for cell in bins) < body["training_samples"],
              "META_MODEL_TRAINING_BOTH_CLASSES_REQUIRED")
        self.calibrator = {}
        for cell in calibrator:
            _need(isinstance(cell, dict) and set(cell) == {"probability_bin", "count", "wins", "score"},
                  "META_MODEL_CALIBRATION_CELL_FIELDS_INVALID")
            index = cell["probability_bin"]
            _need(type(index) is int and 0 <= index < len(self.probability_edges) - 1 and index not in self.calibrator,
                  "META_MODEL_CALIBRATION_BIN_INVALID")
            self._count_cell(cell)
            self.calibrator[index] = cell
        count, wins = sum(cell["count"] for cell in calibrator), sum(cell["wins"] for cell in calibrator)
        _need(10 <= count <= body["calibration_samples"] and 0 < wins < count,
              "META_MODEL_CALIBRATION_BOTH_CLASSES_REQUIRED")
        self.body, self.artifact_hash = body, artifact["artifact_hash"]

    def _count_cell(self, cell: dict) -> None:
        _need(type(cell["count"]) is int and type(cell["wins"]) is int
              and 1 <= cell["count"] <= 50000 and 0 <= cell["wins"] <= cell["count"]
              and _number(cell["score"]) and 0 <= cell["score"] <= 1,
              "META_MODEL_CELL_COUNTS_INVALID")
        exact = (cell["wins"] + self.smoothing) / (cell["count"] + 2 * self.smoothing)
        _need(abs(cell["score"] - exact) <= 1e-12, "META_MODEL_CELL_SCORE_INVALID")

    def predict(self, context: dict, signal_confidence: float, *, observed_at: str, decision_at: str) -> float | None:
        observed, decision = _utc(observed_at), _utc(decision_at)
        _need(observed <= decision < PAPER_START, "META_MODEL_FUTURE_OR_PAPER_INPUT_FORBIDDEN")
        _need(_number(signal_confidence) and 0 <= signal_confidence <= 1, "META_MODEL_SIGNAL_CONFIDENCE_INVALID")
        if observed <= self.calibration_cutoff or observed <= self.last_label_available_at:
            return None
        key = canonical_hash({"context": _context(context),
                              "confidence_bin": _bin(float(signal_confidence), self.confidence_edges)})
        cell = self.bins.get(key)
        if cell is None or cell["count"] < self.minimum_training:
            return None
        calibrated = self.calibrator.get(_bin(cell["score"], self.probability_edges))
        if calibrated is None or calibrated["count"] < self.minimum_calibration:
            return None
        return float(calibrated["score"])
