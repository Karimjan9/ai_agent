"""Research-only instrument recipes selected by an independently sealed policy.

The runtime observes only reached, previous-closed decision inputs. A recipe
may veto an existing entry, reduce its existing risk, or request an owned exit;
it cannot invent a signal or bypass the execution kernel's external gates.
"""

from __future__ import annotations

import hashlib
import json
import math
import re
from collections import Counter

import pandas as pd

from app.services.execution_contract import execution_contract_metadata
from app.services.research_program_tasks import (
    BoundedDecisionProgram, canonical_hash, canonical_json, _same_ast_copy,
)

PROTOCOL = "instrument_method_learning_v1"
POLICY_PROTOCOL = "agent_method_policy_v1"
EVENT_PROTOCOL = "instrument_method_decision_v1"
RECEIPT_PROTOCOL = "instrument_method_learning_receipt_v1"
CONTEXT_FIELDS = {
    "symbol", "timeframe", "direction", "regime", "market_regime",
    "volatility", "volatility_regime", "session", "market_session",
    "venue_phase", "market_venue_phase", "spread", "spread_context",
    "spread_liquidity_state", "transition_state",
    "session_instance_id", "calendar_version",
}
PRE_2026_END = pd.Timestamp("2026-01-01T00:00:00Z")


def actual_meta_evaluation_binding(payload, *, project_numeric: bool = False) -> dict:
    """Seven actual physical ports, not copied training/evaluation assertions.

    Training source/data/policy identity belongs to the immutable original
    exporter. The prospective method intervention may use a different library
    and later data, but cannot silently alter its physical strategy/account.
    """
    if payload is None or not hasattr(payload, 'model_dump'):
        raise ValueError('METHOD_META_ACTUAL_REQUEST_REQUIRED')
    request = payload.model_dump(mode='python')
    context = request.get('policy_context') or {}
    ports = {key: request[key] for key in ('risk_governor', 'risk_policy', 'capital_policy', 'capital_allocation') if key in request}
    policy_ports = {key: context[key] for key in ('risk_policy', 'risk_governor', 'capital_policy', 'capital_allocation', 'ood_assessment') if key in context}
    # Empty PHP associative arrays retain their [] wire/canonical spelling.
    def php_arrays(value):
        if isinstance(value, dict):
            return {key: php_arrays(item) for key, item in value.items()} if value else []
        if isinstance(value, list):
            return [php_arrays(item) for item in value]
        return value
    account = {'initial_balance': request['initial_balance'], 'risk_per_trade': request['risk_per_trade'],
        'hard_limits': request.get('hard_limits', context.get('hard_limits')),
        'risk_capital_ports': ports, 'policy_risk_capital_ports': policy_ports}
    seconds = {'M1': 60, 'M5': 300, 'M15': 900, 'M30': 1800, 'H1': 3600, 'H4': 14400, 'D1': 86400}.get(payload.timeframe)
    if seconds is None:
        raise ValueError('METHOD_META_PHYSICAL_CLOCK_REQUIRED')
    parameters = request['parameters']
    if project_numeric:
        from app.services.instrument_method_meta_model import project_physical_parameters
        parameters = project_physical_parameters(payload.strategy, parameters, request.get('base_strategy'))
    return {'symbol': payload.symbol, 'timeframe': payload.timeframe, 'strategy': payload.strategy,
        'parameters_hash': canonical_hash(parameters), 'account_risk_hash': canonical_hash(php_arrays(account)),
        'execution_hash': execution_contract_metadata(payload)['execution_hash'], 'clock_seconds': seconds}


def _sealed_body(value: object, hash_key: str, json_key: str, prefix: str) -> dict:
    if not isinstance(value, dict):
        raise ValueError(prefix + "_OBJECT_REQUIRED")
    body = {key: item for key, item in value.items() if key not in {hash_key, json_key}}
    encoded = value.get(json_key)
    if encoded is not None:
        if not isinstance(encoded, str) or len(encoded.encode("utf-8")) > 1048576:
            raise ValueError(prefix + "_JSON_COPY_INVALID")
        try:
            preserved = json.loads(encoded)
        except (ValueError, RecursionError) as error:
            raise ValueError(prefix + "_JSON_COPY_INVALID") from error
        if not isinstance(preserved, dict) or not _same_ast_copy(body, preserved):
            raise ValueError(prefix + "_JSON_COPY_MISMATCH")
        body = preserved
    if value.get(hash_key) != canonical_hash(body):
        raise ValueError(prefix + "_HASH_MISMATCH")
    return body


def _utc(value: object) -> pd.Timestamp:
    if not isinstance(value, str) or not value.endswith(("Z", "+00:00")):
        raise ValueError("METHOD_LEARNING_EXPLICIT_UTC_REQUIRED")
    try:
        stamp = pd.Timestamp(value)
    except (ValueError, TypeError, OverflowError) as error:
        raise ValueError("METHOD_LEARNING_TIMESTAMP_INVALID") from error
    if pd.isna(stamp) or stamp.tzinfo is None or stamp.utcoffset().total_seconds() != 0:
        raise ValueError("METHOD_LEARNING_EXPLICIT_UTC_REQUIRED")
    return stamp.tz_convert("UTC")


def _predicate(value: object) -> dict:
    # PHP encodes an empty associative map as []; it denotes the same wildcard.
    if value == []:
        return {}
    if not isinstance(value, dict) or len(value) > len(CONTEXT_FIELDS):
        raise ValueError("METHOD_LEARNING_CONTEXT_PREDICATE_INVALID")
    for key, allowed in value.items():
        if key not in CONTEXT_FIELDS:
            raise ValueError("METHOD_LEARNING_CONTEXT_AXIS_UNSUPPORTED")
        options = allowed if isinstance(allowed, list) else [allowed]
        if not 1 <= len(options) <= 32 or any(not isinstance(item, str) or not item for item in options):
            raise ValueError("METHOD_LEARNING_CONTEXT_VALUE_INVALID")
    return value


def _matches(predicate: dict, context: dict) -> bool:
    return all(_canonical(key, context.get(key)) in [_canonical(key, item)
                   for item in (value if isinstance(value, list) else [value])]
               for key, value in predicate.items())


def _canonical(axis: str, value: object) -> str:
    from app.services.instrument_research import _canonical_context_value
    if axis in {"symbol", "timeframe"}:
        return str(value or "").strip().upper()
    if axis in {'session_instance_id', 'calendar_version'}:
        return str(value or '')
    axis = {"market_regime": "regime", "volatility_regime": "volatility",
        "market_session": "session", "market_venue_phase": "venue_phase",
        "spread": "spread_liquidity_state", "spread_context": "spread_liquidity_state"}.get(axis, axis)
    normalized = _canonical_context_value(axis, value)
    extra = {"regime": {"uptrend": "trend_up", "bull": "trend_up", "bullish": "trend_up",
        "downtrend": "trend_down", "bear": "trend_down", "bearish": "trend_down",
        "ranging": "range", "mean_reversion": "range", "transitional": "transition"},
        "volatility": {"compression": "low", "medium": "normal", "expansion": "high"},
        "session": {"newyork": "new_york", "ny": "new_york", "london_ny_overlap": "overlap"}}
    return extra.get(axis, {}).get(normalized, normalized)


def _context(row: dict, symbol: str, timeframe: str, direction: str) -> dict:
    from app.services.backtester import _instrument_runtime_context
    result = {"symbol": symbol, "timeframe": timeframe, "direction": _canonical("direction", direction)}
    derived = _instrument_runtime_context(row, direction)
    for field in ('regime', 'session', 'venue_phase', 'volatility', 'spread_liquidity_state', 'transition_state'):
        value = derived.get(field)
        if field == 'volatility' and not isinstance(row.get('volatility_regime'), str):
            continue
        if isinstance(value, str) and value and value != 'unknown':
            result[field] = _canonical(field, value)
    aliases = {"regime": "market_regime", "volatility": "volatility_regime",
               "session": "market_session", "venue_phase": "market_venue_phase",
               "spread": "spread_context", 'session_instance_id': 'market_session_instance_id',
               'calendar_version': 'market_session_calendar_version'}
    for field in CONTEXT_FIELDS - {"symbol", "timeframe", "direction"}:
        value = row.get(aliases.get(field, field))
        if isinstance(value, str) and value:
            result[field] = _canonical(field, value)
    return result


class InstrumentMethodLearning:
    def __init__(self, contract: dict, payload=None, *, expected_agent_key: str | None = None,
                 dataset_hash: str | None = None, execution_hash: str | None = None,
                 execution_owner: str = 'ordinary_single_position_v1', native_context: tuple | None = None):
        body = _sealed_body(contract, "contract_hash", "contract_json", "METHOD_LEARNING_CONTRACT")
        if body.get("protocol") != PROTOCOL:
            raise ValueError("METHOD_LEARNING_PROTOCOL_REQUIRED")
        self.phase_target_selection = 'selection_protocol' in body
        if self.phase_target_selection and body['selection_protocol'] != 'phase_target_applicable_v2':
            raise ValueError('METHOD_LEARNING_SELECTION_PROTOCOL_INVALID')
        data = payload.replay_dataset_hash if payload is not None else dataset_hash
        execution = execution_contract_metadata(payload)["execution_hash"] if payload is not None else execution_hash
        if (not data or body.get("data_hash") != data or not execution or body.get("execution_hash") != execution
                or dataset_hash is not None and data != dataset_hash
                or execution_hash is not None and execution != execution_hash):
            raise ValueError("METHOD_LEARNING_DATA_OR_EXECUTION_MISMATCH")
        for key in ("economic_authority", "promotion_evidence", "paper_authority", "live_authority"):
            if body.get(key, False) is not False:
                raise ValueError("METHOD_LEARNING_AUTHORITY_FORBIDDEN")
        scope = body.get("scope")
        if (not isinstance(scope, dict) or not isinstance(scope.get("symbol"), str) or not scope['symbol']
                or not isinstance(scope.get("timeframe"), str) or not scope['timeframe']
                or payload is not None and (scope.get("symbol") != payload.symbol or scope.get("timeframe") != payload.timeframe)):
            raise ValueError("METHOD_LEARNING_SCOPE_MISMATCH")
        self.start, self.end = _utc(scope.get("evaluated_start")), _utc(scope.get("evaluated_end"))
        if self.start > self.end or self.end >= PRE_2026_END:
            raise ValueError("METHOD_LEARNING_PRE_2026_SCOPE_REQUIRED")
        self.scope_predicate = _predicate(scope.get("context", scope.get("context_predicate", {})))
        if 'runtime_parameters' in body:
            from app.services.parameter_schema import strategy_family, validate_strategy_parameters
            parameters = body['runtime_parameters']
            family = body.get('strategy_family')
            if (not isinstance(parameters, dict) or not isinstance(family, str)
                    or body.get('runtime_parameters_hash') != canonical_hash(parameters)):
                raise ValueError('METHOD_LEARNING_RUNTIME_PARAMETERS_IDENTITY_MISMATCH')
            if payload is not None:
                expected_parameters = validate_strategy_parameters(family, parameters)
                actual_parameters = validate_strategy_parameters(payload.strategy, payload.parameters, payload.base_strategy)
                if (family != strategy_family(payload.strategy, payload.base_strategy)
                        or not _same_ast_copy(expected_parameters, actual_parameters)):
                    raise ValueError('METHOD_LEARNING_RUNTIME_PARAMETERS_DRIFT')
        if not isinstance(body.get("library_contract"), dict):
            raise ValueError("METHOD_LEARNING_LIBRARY_OBJECT_REQUIRED")
        library = _sealed_body({**body["library_contract"],
            "library_hash": body.get("library_hash"), "library_contract_json": body.get("library_contract_json")},
            "library_hash", "library_contract_json", "METHOD_LEARNING_LIBRARY")
        if (library.get('protocol') != 'instrument_method_library_v1'
                or not isinstance(library.get("recipe_hashes"), list) or not isinstance(library.get("method_hashes"), list)
                or not library.get("instrument_inventory_hash")):
            raise ValueError("METHOD_LEARNING_LIBRARY_IDENTITY_REQUIRED")
        policy_raw = body.get("policy")
        policy = _sealed_body(policy_raw, "policy_hash", "policy_json", "METHOD_LEARNING_POLICY")
        if policy.get("protocol") != POLICY_PROTOCOL or policy.get("library_hash") != body["library_hash"]:
            raise ValueError("METHOD_LEARNING_POLICY_LIBRARY_MISMATCH")
        policy_library = policy.get("library_contract")
        if not _same_ast_copy(library, policy_library):
            raise ValueError("METHOD_LEARNING_POLICY_LIBRARY_MISMATCH")
        expected = expected_agent_key or body.get("expected_agent_key")
        if (not isinstance(policy.get("agent_key"), str) or not policy["agent_key"]
                or expected is not None and policy["agent_key"] != str(expected)
                or not isinstance(policy.get("revision"), int) or isinstance(policy["revision"], bool)
                or policy["revision"] < 1):
            raise ValueError("METHOD_LEARNING_POLICY_AGENT_OR_REVISION_MISMATCH")
        if policy.get("default_action") not in {"BASELINE", "WAIT"}:
            raise ValueError("METHOD_LEARNING_DEFAULT_ACTION_INVALID")
        recipes = body.get("recipes")
        if not isinstance(recipes, list) or not 1 <= len(recipes) <= 32:
            raise ValueError("METHOD_LEARNING_RECIPE_BUDGET_INVALID")
        self.recipes, self.predicates, self.programs = {}, {}, {}
        for recipe in recipes:
            runtime = _sealed_body(recipe, "runtime_hash", "runtime_json", "METHOD_LEARNING_RECIPE_RUNTIME")
            if runtime.get('protocol') != 'instrument_method_recipe_runtime_v1':
                raise ValueError('METHOD_LEARNING_RECIPE_RUNTIME_PROTOCOL_REQUIRED')
            identity = runtime.get("recipe_hash")
            if not isinstance(identity, str) or identity not in library["recipe_hashes"] or identity in self.recipes:
                raise ValueError("METHOD_LEARNING_RECIPE_LIBRARY_MISMATCH")
            revision = runtime.get("recipe_revision")
            declared = _sealed_body({**(revision if isinstance(revision, dict) else {}),
                "recipe_hash": identity, "recipe_revision_json": runtime.get("recipe_revision_json")},
                "recipe_hash", "recipe_revision_json", "METHOD_LEARNING_RECIPE_REVISION")
            if declared.get('protocol') != 'instrument_method_recipe_v1':
                raise ValueError('METHOD_LEARNING_RECIPE_REVISION_PROTOCOL_REQUIRED')
            if declared.get("recipe_key") != runtime.get("recipe_key"):
                raise ValueError("METHOD_LEARNING_RECIPE_KEY_MISMATCH")
            methods = declared.get("method_contracts", runtime.get("method_contracts", {}))
            if not isinstance(methods, dict) or not 1 <= len(methods) <= 8:
                raise ValueError("METHOD_LEARNING_PINNED_METHODS_REQUIRED")
            for method_hash, method in methods.items():
                if not isinstance(method, dict) or not isinstance(method.get('implementation'), dict):
                    raise ValueError('METHOD_LEARNING_METHOD_IMPLEMENTATION_REQUIRED')
                if method['implementation'].get('kind') not in {'typed_ast', 'parameter_adapter'}:
                    raise ValueError('METHOD_LEARNING_METHOD_IMPLEMENTATION_UNSUPPORTED')
                if method_hash not in library["method_hashes"]:
                    raise ValueError("METHOD_LEARNING_METHOD_LIBRARY_MISMATCH")
                if canonical_hash(method) != method_hash:
                    raise ValueError("METHOD_LEARNING_METHOD_REVISION_HASH_MISMATCH")
                if not _same_ast_copy(method.get('context_predicate', {}), declared.get('context_predicate', {})):
                    raise ValueError('METHOD_LEARNING_METHOD_CONTEXT_BROADENING_FORBIDDEN')
            if declared.get('method_hashes') != sorted(methods):
                raise ValueError('METHOD_LEARNING_PINNED_METHOD_LIST_MISMATCH')
            bindings = runtime.get("scope_bindings", {})
            if (not isinstance(bindings, dict) or any(bindings.get(key) != expected_value for key, expected_value in
                    (("symbol", scope['symbol']), ("timeframe", scope['timeframe']), ("data_hash", body["data_hash"]),
                     ("execution_hash", body["execution_hash"]))) or bindings.get("pre_2026_only") is not True):
                raise ValueError("METHOD_LEARNING_RECIPE_SCOPE_BINDING_MISMATCH")
            predicate = _predicate(runtime.get("context_predicate", {}))
            if bindings.get('context_hash') != canonical_hash(runtime.get('context_predicate', {})):
                raise ValueError('METHOD_LEARNING_RECIPE_CONTEXT_HASH_MISMATCH')
            if not _same_ast_copy(runtime.get("context_predicate", {}), declared.get("context_predicate", {})):
                raise ValueError("METHOD_LEARNING_RECIPE_CONTEXT_MISMATCH")
            operators = runtime.get("operators")
            if not isinstance(operators, list) or len(operators) > 3:
                raise ValueError("METHOD_LEARNING_OPERATOR_BUDGET_INVALID")
            programs = [BoundedDecisionProgram(operator, allow_method_features=True) for operator in operators]
            if len({program.target for program in programs}) != len(programs):
                raise ValueError("METHOD_LEARNING_OPERATOR_TARGET_DUPLICATE")
            typed_methods = [method['implementation'] for method in methods.values()
                             if method.get('implementation', {}).get('kind') == 'typed_ast']
            if len(programs) != len(typed_methods):
                raise ValueError('METHOD_LEARNING_OPERATOR_SOURCE_COUNT_MISMATCH')
            for program in programs:
                source = next((method for method in typed_methods if method.get('target') == program.target), None)
                if (source is None or not _same_ast_copy(program.ast, source.get('ast'))
                        or not _same_ast_copy(program.bindings, source.get('input_bindings') or {})
                        or not _same_ast_copy(program.contract['budget'], source.get('budget'))
                        or not _same_ast_copy(program.contract.get('source_context'), bindings)):
                    raise ValueError('METHOD_LEARNING_OPERATOR_PINNED_SOURCE_MISMATCH')
            adapters = runtime.get('parameter_adapters', [])
            expected_adapters = [{'method_hash': method_hash, 'instrument_key': method.get('instrument_key'),
                'strategy_family': method['implementation'].get('strategy_family'),
                'parameters': method['implementation'].get('parameters')}
                for method_hash, method in methods.items() if method.get('implementation', {}).get('kind') == 'parameter_adapter']
            if not _same_ast_copy(adapters, expected_adapters):
                raise ValueError('METHOD_LEARNING_ADAPTER_PINNED_SOURCE_MISMATCH')
            self.recipes[identity], self.predicates[identity], self.programs[identity] = recipe, predicate, programs
        if self.phase_target_selection and any(recipe.get('parameter_adapters') for recipe in self.recipes.values()):
            # A parameter adapter is a sealed construction treatment, not an
            # inapplicable runtime recipe that may silently be skipped.
            raise ValueError('METHOD_LEARNING_PHASE_SELECTION_TYPED_RECIPES_REQUIRED')
        pool = {"recipes": sorted([{"recipe_hash": key, "runtime_hash": value["runtime_hash"]}
            for key, value in self.recipes.items()], key=lambda item: item["recipe_hash"])}
        if body.get("compiled_pool_hash") != canonical_hash(pool):
            raise ValueError("METHOD_LEARNING_COMPILED_POOL_HASH_MISMATCH")
        rules = policy.get("rules")
        if not isinstance(rules, list) or len(rules) > 32:
            raise ValueError("METHOD_LEARNING_POLICY_RULE_BUDGET_INVALID")
        for rule in rules:
            if not isinstance(rule, dict):
                raise ValueError("METHOD_LEARNING_POLICY_RULE_INVALID")
            _predicate(rule.get("context_predicate", {}))
            preferred = rule.get("preferred_recipes")
            if (not isinstance(preferred, list) or not 1 <= len(preferred) <= 32
                    or len(set(preferred)) != len(preferred) or any(key not in self.recipes for key in preferred)):
                raise ValueError("METHOD_LEARNING_POLICY_UNKNOWN_RECIPE")
        self.body, self.policy, self.contract_hash = body, policy, contract["contract_hash"]
        self.policy_hash = policy_raw["policy_hash"]
        self.events = self.used = self.in_scope = self.changed = 0
        self.reason_counts, self.samples = Counter(), []
        self.event_hasher = hashlib.sha256()
        from app.services.instrument_method_features import FIELDS
        self.needs_features = any(field in FIELDS for programs in self.programs.values()
            for program in programs for field in program.bindings.values())
        self.features = None
        self.meta_model = None
        self.native_meta_binding = None
        if 'meta_model' in body:
            meta = body['meta_model']
            legacy_fields = {'artifact', 'expected_training_binding', 'evaluation_binding'}
            if not isinstance(meta, dict) or set(meta) not in (legacy_fields, legacy_fields | {'parameter_projection'}):
                raise ValueError('METHOD_META_MODEL_REFERENCE_FIELDS_REQUIRED')
            from app.services.instrument_method_native_meta_model import PROTOCOL as native_protocol
            if isinstance(meta.get('artifact'), dict) and meta['artifact'].get('protocol') == native_protocol:
                if (execution_owner != 'native_specialist_council_v1' or set(meta) != legacy_fields
                        or not isinstance(native_context, tuple) or len(native_context) != 3):
                    raise ValueError('METHOD_NATIVE_META_ACTUAL_PARENT_REQUEST_REQUIRED')
                from app.services.instrument_method_native_meta_model import (
                    FiniteNativeInstrumentMetaModel, PHYSICAL_FIELDS, actual_native_meta_evaluation_binding,
                )
                parent, council, member = native_context
                actual = actual_native_meta_evaluation_binding(parent, council, member)
                from app.services.parameter_schema import validate_strategy_parameters
                if (payload is None or payload.symbol != parent.symbol or payload.timeframe != parent.timeframe
                        or payload.strategy != member.get('strategy') or payload.base_strategy != member.get('base_strategy')
                        or not _same_ast_copy(member.get('method_learning'), contract)
                        or not _same_ast_copy(validate_strategy_parameters(payload.strategy, payload.parameters, payload.base_strategy),
                            validate_strategy_parameters(member['strategy'], member.get('parameters', {}), member.get('base_strategy')))):
                    raise ValueError('METHOD_NATIVE_META_ORIGINAL_MEMBER_RUNTIME_REQUIRED')
                if meta.get('evaluation_binding') != actual:
                    raise ValueError('METHOD_NATIVE_META_ACTUAL_EVALUATION_BASELINE_MISMATCH')
                self.native_meta_binding = actual
                self.meta_model = FiniteNativeInstrumentMetaModel(meta['artifact'], meta['expected_training_binding'],
                    {key: actual[key] for key in PHYSICAL_FIELDS})
                return
            if (execution_owner != 'ordinary_single_position_v1'
                    or payload is not None and bool(payload.specialist_council_contract)):
                raise ValueError('METHOD_META_ORDINARY_ORIGINAL_ACCOUNT_SCOPE_REQUIRED')
            if payload is None or not hasattr(payload, 'model_dump'):
                raise ValueError('METHOD_META_ACTUAL_REQUEST_REQUIRED')
            if 'parameter_projection' in meta:
                from app.services.parameter_schema import strategy_family
                projection = meta['parameter_projection']
                if (not isinstance(projection, dict) or not isinstance(projection.get('strategy'), str)
                        or projection.get('base_strategy') is not None and not isinstance(projection['base_strategy'], str)
                        or strategy_family(projection['strategy'], projection.get('base_strategy'))
                            != strategy_family(payload.strategy, payload.base_strategy)):
                    raise ValueError('METHOD_META_PARAMETER_PROJECTION_FAMILY_MISMATCH')
            actual = actual_meta_evaluation_binding(payload, project_numeric='parameter_projection' in meta)
            if meta.get('evaluation_binding') != actual:
                raise ValueError('METHOD_META_ACTUAL_EVALUATION_BASELINE_MISMATCH')
            from app.services.instrument_method_meta_model import FiniteInstrumentMetaModel
            self.meta_model = FiniteInstrumentMetaModel(meta['artifact'], meta['expected_training_binding'], actual, meta.get('parameter_projection'))

    def validate_frame(self, frame: pd.DataFrame, *, bind_features: bool = True) -> None:
        stamps = pd.to_datetime(frame["time"], errors="coerce", utc=True)
        if stamps.isna().any() or (stamps >= PRE_2026_END).any():
            raise ValueError("METHOD_LEARNING_POST_2026_INPUT_FORBIDDEN")
        if bind_features:
            self.bind_feature_frame(frame)

    def bind_feature_frame(self, frame: pd.DataFrame) -> None:
        if self.needs_features:
            from app.services.instrument_method_features import ClosedInstrumentMethodFeatures
            from app.services.backtester import _timeframe_duration_minutes
            feature_frame = frame
            session_fields = {'method_session_available', 'method_session_range_atr', 'method_session_distance_atr'}
            if (not {'market_session', 'session'}.intersection(frame.columns)
                    and any(field in session_fields for programs in self.programs.values()
                        for program in programs for field in program.bindings.values())):
                from app.services.market_sessions import session_membership
                # Selection already resolves missing session labels through this
                # calendar owner. Bind the same point-in-time groups before
                # compiling their closed extrema; explicit unknowns stay missing.
                sessions = session_membership(frame['time'])
                feature_frame = frame.copy()
                feature_frame['market_session'] = sessions['session']
                feature_frame['market_session_instance_id'] = sessions['session_instance_id']
                feature_frame['market_session_calendar_version'] = sessions['calendar_version']
            self.features = ClosedInstrumentMethodFeatures(feature_frame,
                bar_duration_seconds=int(_timeframe_duration_minutes(self.body['scope']['timeframe']) * 60))

    def notify_mature_close(self, *, position_id: str, net_pnl: float,
                            available_at: str, mature: bool = True) -> None:
        if self.needs_features:
            if self.features is None:
                raise ValueError("METHOD_LEARNING_FEATURE_FRAME_REQUIRED")
            self.features.notify_mature_close(position_id=position_id, net_pnl=net_pnl,
                available_at=available_at, mature=mature)

    def evaluate(self, row: dict, *, decision_id: str, phase: str, decision_at: str,
                 observed_at: str, direction: str) -> dict:
        if phase not in {"entry", "exit"}:
            raise ValueError("METHOD_LEARNING_PHASE_INVALID")
        decision, observed = _utc(decision_at), _utc(observed_at)
        if observed > decision:
            raise ValueError("METHOD_LEARNING_FUTURE_OBSERVATION_FORBIDDEN")
        if observed >= PRE_2026_END or decision >= PRE_2026_END:
            raise ValueError("METHOD_LEARNING_POST_2026_INPUT_FORBIDDEN")
        feature_inputs = None
        if self.needs_features:
            if self.features is None:
                raise ValueError("METHOD_LEARNING_FEATURE_FRAME_REQUIRED")
            feature_inputs = self.features.inputs(row, direction=direction, observed_at=observed_at)
            # A caller/CSV cannot inject method inputs, availability or a model
            # score; only the original closed-frame/state owner publishes them.
            row = {key: value for key, value in row.items() if not key.startswith("method_")}
            row.update(feature_inputs)
        context = _context(row, self.body["scope"]["symbol"], self.body["scope"]["timeframe"], direction)
        meta_status = None
        if self.meta_model is not None and feature_inputs is not None:
            meta_status = 'entry_target_unreached'
            if phase == 'entry':
                axes = ('regime', 'volatility', 'session', 'direction')
                original_context = {key: context.get(key) for key in axes}
                known_context = all(isinstance(value, str) and value.lower() not in {'', 'unknown', 'any', 'none'}
                    and re.fullmatch(r'[A-Za-z0-9_:-]{1,64}', value) is not None for value in original_context.values())
                confidence = row.get('signal_confidence')
                known_confidence = type(confidence) in {int, float} and math.isfinite(confidence) and 0 <= confidence <= 1
                if not known_context or original_context['direction'].lower() not in {'buy', 'sell'}:
                    meta_status = 'closed_context_unavailable'
                elif not known_confidence:
                    meta_status = 'signal_confidence_unavailable'
                elif observed <= pd.Timestamp(self.meta_model.calibration_cutoff) or observed <= pd.Timestamp(self.meta_model.last_label_available_at):
                    meta_status = 'calibration_labels_not_yet_available'
                else:
                    score = self.meta_model.predict(original_context, confidence,
                        observed_at=observed_at, decision_at=decision_at)
                    meta_status = 'context_calibration_cell_unavailable' if score is None else 'diagnostic_calibrated_score_available'
                    if score is not None:
                        row['method_meta_score'] = feature_inputs['method_meta_score'] = score
                        row['method_meta_model_available'] = feature_inputs['method_meta_model_available'] = True
        in_scope = self.start <= observed <= self.end and _matches(self.scope_predicate, context)
        chosen, outputs, used = None, {}, False
        reason = "outside_scope"
        if in_scope:
            reason = "policy_default_" + self.policy["default_action"].lower()
            targets = {"confirmation", "risk_multiplier"} if phase == "entry" else {"exit"}
            for rule in self.policy["rules"]:
                if not _matches(_predicate(rule.get("context_predicate", {})), context):
                    continue
                chosen = next((key for key in rule["preferred_recipes"]
                    if _matches(self.predicates[key], context)
                    and (not self.phase_target_selection or any(program.target in targets for program in self.programs[key]))), None)
                if chosen is not None:
                    break
            if chosen is not None:
                for program in self.programs[chosen]:
                    if program.target in targets:
                        try:
                            output = program.evaluate(row, decision_at=decision_at, observed_at=observed_at)
                        except ValueError as error:
                            if (str(error) != 'DECISION_OPERATOR_INPUT_TYPE_REQUIRED'
                                    or not any(field.startswith('method_') and field not in row
                                        for field in program.bindings.values())):
                                raise
                            # Missing genuine observation/model is a typed
                            # dependency and conservative decision, not an
                            # invented feature or unrelated technical failure.
                            output = {'confirmation': False, 'risk_multiplier': 0.0, 'exit': False}[program.target]
                            reason = 'method_input_unavailable'
                        if program.target == 'confirmation' and output is False and feature_inputs is not None:
                            missing = [field for field in program.bindings.values()
                                if field.endswith('_available') and row.get(field) is False]
                            if 'method_meta_model_available' in missing:
                                reason = 'method_meta_score_unavailable' if self.meta_model is not None else 'method_model_unavailable'
                            elif 'method_volume_available' in missing:
                                reason = 'method_observed_volume_unavailable'
                            elif 'method_quote_available' in missing:
                                reason = 'method_observed_quote_unavailable'
                            elif missing:
                                reason = 'method_context_underpowered'
                        outputs[program.target] = output
                        changed = not output if program.target == "confirmation" else output != 1 if program.target == "risk_multiplier" else bool(output)
                        program.changed_decisions += int(changed)
                        used = True
                if reason not in {'method_input_unavailable', 'method_model_unavailable',
                        'method_meta_score_unavailable',
                        'method_observed_volume_unavailable', 'method_observed_quote_unavailable',
                        'method_context_underpowered'}:
                    reason = "recipe_evaluated" if used else "recipe_target_unreached"
            elif self.policy["default_action"] == "WAIT" and phase == "entry":
                outputs["confirmation"] = False
        changed = (phase == "entry" and (outputs.get("confirmation") is False or outputs.get("risk_multiplier", 1) < 1)
                   or phase == "exit" and outputs.get("exit") is True)
        event = {"protocol": EVENT_PROTOCOL, "decision_id": decision_id, "phase": phase,
            "decision_at": decision_at, "observed_at": observed_at, "context": context,
            "context_hash": canonical_hash(context), "contract_hash": self.contract_hash,
            "library_hash": self.body["library_hash"], "compiled_pool_hash": self.body["compiled_pool_hash"],
            "policy_hash": self.policy_hash, "agent_key": self.policy["agent_key"],
            "recipe_hash": chosen, "runtime_hash": self.recipes[chosen]["runtime_hash"] if chosen else None,
            "in_scope": bool(in_scope), "used": used, "reason": reason, "outputs": outputs or [],
            "behavior_changed": bool(changed), "research_only": True,
            "promotion_evidence": False, "economic_authority": False}
        if feature_inputs is not None:
            from app.services.instrument_method_features import PROTOCOL as feature_protocol
            event.update({"feature_protocol": feature_protocol,
                "feature_inputs_hash": canonical_hash(feature_inputs),
                "feature_availability": {key: value for key, value in feature_inputs.items() if key.endswith('_available')}})
        if self.meta_model is not None:
            event.update({'meta_model_hash': self.meta_model.artifact_hash,
                'meta_training_binding_hash': canonical_hash(self.meta_model.body['binding']),
                'meta_score_status': meta_status or 'method_feature_target_unreached'})
            if self.native_meta_binding is not None:
                event['native_meta_evaluation_binding_hash'] = canonical_hash(self.native_meta_binding)
        event["receipt_hash"] = canonical_hash(event)
        encoded = canonical_json(event).encode("utf-8")
        self.event_hasher.update(len(encoded).to_bytes(8, "big"))
        self.event_hasher.update(encoded)
        self.events += 1
        self.used += int(used)
        self.in_scope += int(in_scope)
        self.changed += int(changed)
        self.reason_counts[reason] += 1
        if len(self.samples) < 32:
            self.samples.append(event)
        return event

    def receipt(self) -> dict:
        body = {"protocol": RECEIPT_PROTOCOL, "contract_hash": self.contract_hash,
            "library_hash": self.body["library_hash"], "compiled_pool_hash": self.body["compiled_pool_hash"],
            "policy_hash": self.policy_hash, "agent_key": self.policy["agent_key"], "revision": self.policy["revision"],
            "data_hash": self.body["data_hash"], "execution_hash": self.body["execution_hash"], "scope": self.body["scope"],
            "event_count": self.events, "used_count": self.used, "in_scope_count": self.in_scope,
            "behavior_changed_count": self.changed, "reason_counts": dict(self.reason_counts) or [],
            "events_hash": self.event_hasher.hexdigest(), "samples": self.samples,
            "operator_receipts": [{"recipe_hash": key, **program.receipt()}
                for key, programs in self.programs.items() for program in programs],
            "research_only": True, "promotion_evidence": False, "economic_authority": False}
        def transport(value):
            if isinstance(value, dict):
                return {key: transport(item) for key, item in value.items()} if value else []
            if isinstance(value, list):
                return [transport(item) for item in value]
            return value
        body = transport(body)
        return {**body, "receipt_hash": canonical_hash(body), "receipt_json": canonical_json(body)}


def validate_contract(contract: dict, *, dataset_hash: str, execution_hash: str,
                      expected_agent_key: str | None = None, payload=None,
                      execution_owner: str = 'ordinary_single_position_v1', native_context: tuple | None = None) -> dict:
    """Pure pre-cache validation; it never evaluates or consumes a decision."""
    return InstrumentMethodLearning(contract, payload, dataset_hash=dataset_hash,
        execution_hash=execution_hash, expected_agent_key=expected_agent_key,
        execution_owner=execution_owner, native_context=native_context).body


def receipt_current(result: dict, contract: dict) -> bool:
    """Require the matching original loop receipt before reusing a cache product."""
    if not isinstance(result, dict) or not isinstance(contract, dict):
        return False
    candidates = [(result.get('data_quality') or {}).get('instrument_method_learning_receipt')]
    council = result.get('specialist_council_receipt') or {}
    candidates.extend(member.get('method_learning_receipt') for member in council.get('members', []) if isinstance(member, dict))
    for candidate in candidates:
        if not isinstance(candidate, dict) or candidate.get('contract_hash') != contract.get('contract_hash'):
            continue
        try:
            body = _sealed_body(candidate, 'receipt_hash', 'receipt_json', 'METHOD_LEARNING_RECEIPT')
            if (body.get('protocol') != RECEIPT_PROTOCOL or body.get('policy_hash') != (contract.get('policy') or {}).get('policy_hash')
                    or any(body.get(key) != contract.get(key) for key in ('library_hash', 'compiled_pool_hash', 'data_hash', 'execution_hash'))
                    or body.get('research_only') is not True or body.get('promotion_evidence') is not False
                    or body.get('economic_authority') is not False):
                continue
            counts = [body.get(key) for key in ('event_count', 'used_count', 'in_scope_count', 'behavior_changed_count')]
            if (any(not isinstance(value, int) or isinstance(value, bool) or value < 0 for value in counts)
                    or any(value > counts[0] for value in counts[1:]) or not isinstance(body.get('events_hash'), str)):
                continue
            for sample in body.get('samples', []):
                _sealed_body(sample, 'receipt_hash', 'receipt_json', 'METHOD_LEARNING_EVENT')
                if sample.get('contract_hash') != contract.get('contract_hash'):
                    raise ValueError('METHOD_LEARNING_EVENT_CONTRACT_MISMATCH')
            return True
        except (ValueError, TypeError, KeyError, RecursionError):
            continue
    return False
