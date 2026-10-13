"""Private-issuer deadline for one bounded original simple method study.

This is NOT full/window authorization. It can honor at most 600 seconds for
one signed <=4096-row incremental original, never raise ordinary global caps.
"""

import csv
import hashlib
import hmac
import json
import math
import re
from pathlib import Path

import pandas as pd

from app.services.execution_contract import execution_contract_metadata
from app.services.research_program_tasks import canonical_hash, _same_ast_copy

PROTOCOL = 'instrument_method_original_deadline_v2'
MARKER = 'instrument_method_original_deadline'
ARMS = {'L1A1', 'L2A1', 'L1A2', 'L2A2'}
AUTHORITY = {'research_only': True, 'independent_validation': False, 'economic_authority': False,
    'promotion_evidence': False, 'paper_authority': False, 'live_authority': False}
FIELDS = {'protocol', 'operation', 'study_hash', 'arm', 'resource_caps', 'symbol', 'timeframe', 'strategy',
    'base_strategy', 'parameters_hash', 'data_hash', 'execution_hash', 'method_contract_hash',
    'initial_balance', 'risk_per_trade', 'scope', 'source_identity', 'policy_context_hash', 'research_release_hash', *AUTHORITY}
FIELDS.add('runtime_controls_hash')


def _need(condition, reason):
    if not condition:
        raise ValueError('METHOD_ORIGINAL_DEADLINE_' + reason)


def _sha(value):
    return isinstance(value, str) and re.fullmatch(r'[a-f0-9]{64}', value) is not None


def _number(value):
    return type(value) in {int, float} and math.isfinite(value)


def _utc(value):
    _need(isinstance(value, str) and value.endswith(('Z', '+00:00')), 'EXPLICIT_UTC_REQUIRED')
    try:
        stamp = pd.Timestamp(value)
    except (TypeError, ValueError, OverflowError) as error:
        raise ValueError('METHOD_ORIGINAL_DEADLINE_CLOCK_INVALID') from error
    _need(not pd.isna(stamp) and stamp.tzinfo is not None, 'CLOCK_INVALID')
    return stamp


def _original_source_utc(value):
    # The signed original CSV owner has always interpreted this one finite
    # legacy wire vocabulary as UTC. API scope/receipt clocks remain explicit.
    # Do not accept arbitrary local dates, offsets or timezone inference here.
    if isinstance(value, str) and re.fullmatch(r'\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}', value):
        try:
            stamp = pd.Timestamp(value).tz_localize('UTC')
        except (TypeError, ValueError, OverflowError) as error:
            raise ValueError('METHOD_ORIGINAL_DEADLINE_CLOCK_INVALID') from error
        _need(not pd.isna(stamp), 'CLOCK_INVALID')
        return stamp
    return _utc(value)


def _data_roots():
    project = Path(__file__).resolve().parents[3]
    return (project / 'backend-laravel/storage/app/lab-datasets',
        project / 'backend-laravel/storage/app/private/lab-datasets')


def _source(source, payload, caps, scope):
    _need(isinstance(source, dict), 'ORIGINAL_SOURCE_REQUIRED')
    raw = source.get('dataset_path')
    _need(isinstance(raw, str) and raw and '..' not in Path(raw).parts, 'ORIGINAL_SOURCE_PATH_INVALID')
    try:
        path = Path(raw).resolve(strict=True)
    except (OSError, RuntimeError) as error:
        raise ValueError('METHOD_ORIGINAL_DEADLINE_ORIGINAL_SOURCE_REQUIRED') from error
    _need(path.is_file() and path.suffix.lower() == '.csv' and not Path(raw).is_symlink()
        and path.stat().st_size <= 16 * 1024 * 1024
        and any(path.is_relative_to(root.resolve()) for root in _data_roots()), 'ORIGINAL_SOURCE_ROOT_REQUIRED')
    _need(payload.dataset_path == raw and source.get('dataset_sha256') == payload.replay_dataset_hash,
        'ORIGINAL_SOURCE_IDENTITY_MISMATCH')
    digest = hashlib.sha256()
    with path.open('rb') as stream:
        for block in iter(lambda: stream.read(65536), b''):
            digest.update(block)
    _need(digest.hexdigest() == source.get('dataset_sha256'), 'ORIGINAL_SOURCE_BYTES_CHANGED')
    with path.open('r', encoding='utf-8', newline='') as stream:
        reader = csv.DictReader(stream)
        _need(reader.fieldnames is not None and {'time', 'open', 'high', 'low', 'close'} <= set(reader.fieldnames),
            'ORIGINAL_SOURCE_COLUMNS_REQUIRED')
        stamps = []
        for row in reader:
            _need(len(stamps) < min(4096, caps['max_rows']), 'ORIGINAL_SOURCE_ROW_CEILING_EXCEEDED')
            stamp = _original_source_utc(row.get('time'))
            duration = {'M1': 60, 'M5': 300, 'M15': 900, 'M30': 1800, 'H1': 3600, 'H4': 14400, 'D1': 86400}.get(payload.timeframe)
            _need(duration is not None and stamp + pd.Timedelta(seconds=duration) < pd.Timestamp('2026-01-01T00:00:00Z')
                and (not stamps or stamps[-1] + pd.Timedelta(seconds=duration) <= stamp), 'ORIGINAL_PRE2026_CLOSED_CLOCK_REQUIRED')
            stamps.append(stamp)
    _need(200 < len(stamps) <= 4096 and source.get('loaded_rows') == len(stamps)
        and source.get('warmup_rows') == 200 and source.get('evaluated_rows') == len(stamps) - 200
        and source.get('selection') == 'entire_exact_source' and source.get('independent_validation') is False,
        'ORIGINAL_ROW_RECEIPT_MISMATCH')
    _need(_utc(source.get('loaded_start')) == stamps[0] and _utc(source.get('loaded_end')) == stamps[-1]
        and _utc(scope.get('evaluated_start')) == stamps[200] and _utc(scope.get('evaluated_end')) == stamps[-1],
        'ORIGINAL_SCOPE_CLOCK_MISMATCH')


def verify_original_method_deadline(payload, key: str, *, operation: str) -> int | None:
    policy = payload.policy_context or {}
    if MARKER not in policy:
        return None
    declared = policy[MARKER]
    _need(isinstance(declared, dict) and set(declared) == FIELDS | {'contract_json', 'contract_hash', 'issuer_signature'},
        'FINITE_DECLARATION_REQUIRED')
    encoded = declared.get('contract_json')
    _need(isinstance(encoded, str) and 0 < len(encoded.encode('utf-8')) <= 1048576, 'WIRE_BYTE_CAP_EXCEEDED')
    try:
        body = json.loads(encoded)
    except (ValueError, RecursionError) as error:
        raise ValueError('METHOD_ORIGINAL_DEADLINE_JSON_INVALID') from error
    supplied = {name: value for name, value in declared.items() if name not in {'contract_json', 'contract_hash', 'issuer_signature'}}
    _need(isinstance(body, dict) and set(body) == FIELDS and _same_ast_copy(body, supplied), 'WIRE_COPY_MISMATCH')
    digest = hashlib.sha256(encoded.encode('utf-8')).hexdigest()
    _need(_sha(declared.get('contract_hash')) and digest == declared['contract_hash'], 'HASH_MISMATCH')
    _need(isinstance(key, str) and len(key) >= 32 and _sha(declared.get('issuer_signature')), 'PRIVATE_ISSUER_REQUIRED')
    signature = hmac.new(key.encode('utf-8'), (PROTOCOL + '\n' + digest).encode('utf-8'), hashlib.sha256).hexdigest()
    _need(hmac.compare_digest(signature, declared['issuer_signature']), 'ISSUER_SIGNATURE_MISMATCH')
    _need(body['protocol'] == PROTOCOL and body['operation'] == operation == 'simple'
        and payload.evaluation_mode == 'incremental' and not payload.specialist_council_contract
        and not payload.strategies and not payload.portfolio_members and payload.dataset_tail_rows is None
        and payload.from_date is None and payload.to_date is None and payload.version is None and payload.twin_lane is None
        and not payload.candles and not payload.foundation_dataset_path and not payload.regime_dataset_path
        and not payload.regime_candles and not payload.mtf_streams and not payload.mtf_dataset_paths
        and not payload.related_mtf_streams and not payload.related_mtf_dataset_paths
        and payload.regime_dataset_tail_rows is None and not payload.mtf_dataset_tail_rows
        and not payload.related_mtf_dataset_tail_rows and not payload.mtf_snapshot_manifest
        and not payload.composition_runtime_contract and not payload.specialist_context_contract
        and not payload.instrument_research_assignment and not payload.runtime_ensemble_policy
        and not payload.mtf_pilot and not payload.volume_context
        and not payload.native_spread_context_study_contract and not payload.native_reachability_depth_audit_contract
        and not payload.native_standalone_qualification,
        'SIMPLE_ORIGINAL_SCOPE_REQUIRED')
    _need(all(body.get(name) is value for name, value in AUTHORITY.items()), 'AUTHORITY_FORBIDDEN')
    caps = body['resource_caps']
    _need(isinstance(caps, dict) and set(caps) == {'max_rows', 'max_decision_events', 'max_runtime_seconds'}
        and type(caps['max_rows']) is int and 201 <= caps['max_rows'] <= 4096
        and type(caps['max_decision_events']) is int and 1 <= caps['max_decision_events'] <= 1000000
        and type(caps['max_runtime_seconds']) is int and 300 < caps['max_runtime_seconds'] <= 600,
        'BOUNDED_CAPS_REQUIRED')
    study = policy.get('instrument_method_study')
    _need(isinstance(study, dict) and _sha(body['study_hash']) and body['arm'] in ARMS
        and study == {'study_hash': body['study_hash'], 'arm': body['arm'], 'resource_caps': caps}
        and policy.get('instrument_method_study_resource_cap') == caps, 'EXACT_ORIGINAL_ARM_REQUIRED')
    method = policy.get('instrument_method_learning')
    _need(isinstance(method, dict) and body['method_contract_hash'] == method.get('contract_hash')
        and body['scope'] == method.get('scope'), 'EXACT_METHOD_SCOPE_REQUIRED')
    _need(body['symbol'] == payload.symbol and body['timeframe'] == payload.timeframe
        and body['strategy'] == payload.strategy and body['base_strategy'] == payload.base_strategy
        and body['parameters_hash'] == canonical_hash(payload.parameters)
        and body['data_hash'] == payload.replay_dataset_hash
        and body['execution_hash'] == execution_contract_metadata(payload)['execution_hash']
        and _number(body['initial_balance']) and body['initial_balance'] == payload.initial_balance
        and _number(body['risk_per_trade']) and body['risk_per_trade'] == payload.risk_per_trade,
        'ACTUAL_PHYSICAL_BASELINE_MISMATCH')
    original_policy = {name: value for name, value in policy.items() if name != MARKER}
    _need(body['policy_context_hash'] == canonical_hash(original_policy)
        and body['research_release_hash'] == canonical_hash(payload.research_release or []), 'EXACT_REQUEST_POLICY_RELEASE_MISMATCH')
    controls = {'random_seed': payload.random_seed, 'signal_delay_candles': payload.signal_delay_candles,
        'emit_decision_trace': payload.emit_decision_trace, 'emit_trade_ledger': payload.emit_trade_ledger}
    _need(body['runtime_controls_hash'] == canonical_hash(controls), 'EXACT_RUNTIME_CONTROLS_MISMATCH')
    execution = policy.get('instrument_method_execution')
    _need(isinstance(execution, dict) and execution.get('protocol') == 'instrument_method_original_execution_v1'
        and execution.get('original_source') == body['source_identity'] and execution.get('research_only') is True
        and execution.get('uses_existing_simple_replay') is True and execution.get('independent_validation') is False
        and execution.get('promotion_evidence') is False, 'ORIGINAL_EXECUTION_OWNER_REQUIRED')
    _source(body['source_identity'], payload, caps, body['scope'])
    return caps['max_runtime_seconds']
