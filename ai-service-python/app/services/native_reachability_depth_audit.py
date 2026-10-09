"""Signed prefix diagnostics emitted by the unchanged native account programme.

The physical discovery inventory and its original probe are still validated in
full. Only a separate server declaration can bound the diagnostic execution
clock. Reachability is an observation, never an economic qualification.
"""

from __future__ import annotations

import hashlib
import hmac
import json
import math
from collections import Counter
from pathlib import Path

import pandas as pd

from app.services.research_program_tasks import canonical_hash, canonical_json, preserved_contract_body, _same_ast_copy

PROTOCOL = 'native_reachability_depth_audit_v1'
RECEIPT_PROTOCOL = 'native_reachability_depth_audit_receipt_v1'
SEAL_PROTOCOL = 'native_reachability_depth_audit_server_seal_v1'
DECLARATION_PROTOCOL = 'native_reachability_depth_declaration_v1'
VIEW_PROTOCOL = 'native_reachability_execution_view_v1'
SELECTION_PROTOCOL = 'native_reachability_reject_sample_v1'
ROLES = {'scalp', 'hour', 'day', 'swing'}
CONTEXT_KEYS = {'regime', 'volatility', 'session', 'venue_phase', 'direction'}
AUTHORITY_KEYS = ('economic_authority', 'skill_authority', 'independent_evidence', 'promotion_evidence')
IDENTITY_KEYS = {'native_contract_hash', 'dataset_hash', 'execution_hash', 'account_policy_hash',
    'initial_capital', 'evaluation_policy_hash', 'context_hash', 'declaration_hash', 'members_hash', 'quote_provenance_hash'}
BODY_KEYS = {'protocol', 'audit_id', 'arm', 'identity', 'identity_hash', 'declaration', 'execution_view',
    'selection', 'authority', *AUTHORITY_KEYS}
DECLARATION_KEYS = {'protocol', 'criterion', 'minimum_observed_opportunities', 'contexts',
    'cheap_evaluated_rows', 'deeper_evaluated_rows', 'sample_cap', 'seed', 'initial_capital'}
SELECTION_KEYS = {'protocol', 'cheap_run_id', 'cheap_response_hash', 'cheap_receipt_hash', 'pool_hash',
    'selected_specialist_ids', 'selected_member_hashes', 'sample_hash'}
COUNTS = ('raw_opportunities', 'matching_context_opportunities', 'observed_quote_opportunities',
    'missing_quote_opportunities', 'gate_reached_opportunities')
MAX_EVENTS = 4 * 14999


def raw_source_signal(prior):
    """Select the actual closed upstream port; never choose by its result."""
    source = next((key for key in ('composition_strategy_signal', 'pre_specialist_signal', 'pre_volume_signal', 'signal') if key in prior), 'signal')
    signal = str(prior.get(source, 'WAIT'))
    return source, signal if signal in {'BUY', 'SELL'} else 'WAIT'


def _hash(value):
    return isinstance(value, str) and len(value) == 64 and all(char in '0123456789abcdef' for char in value)


def _integer(value, minimum, maximum):
    return type(value) is int and minimum <= value <= maximum


def _verify_seal(seal, protocol, digest, error):
    from app.main import _internal_api_token
    key = _internal_api_token()
    expected = hmac.new(key.encode(), (PROTOCOL + '\n' + digest).encode(), 'sha256').hexdigest()
    if len(key) < 32 or not isinstance(seal, dict) or set(seal) != {'protocol', 'hmac_sha256'} \
            or seal.get('protocol') != protocol or not isinstance(seal.get('hmac_sha256'), str) \
            or not hmac.compare_digest(seal['hmac_sha256'], expected):
        raise ValueError(error)


def validate_depth_audit(payload, native):
    declared = payload.native_reachability_depth_audit_contract
    if not declared:
        return None
    from app.services import backtester as kernel
    from app.services.prospective_probe_window import assert_clean_discovery_boundary
    body = preserved_contract_body({key: value for key, value in declared.items() if key != 'server_seal'}, 'NATIVE_REACHABILITY_DEPTH_AUDIT')
    if set(body) != BODY_KEYS or body.get('protocol') != PROTOCOL or body.get('arm') not in {'cheap', 'deeper'} \
            or not isinstance(body.get('audit_id'), str) or not 1 <= len(body['audit_id']) <= 255 \
            or body.get('authority') != 'research_only' or any(body.get(key) is not False for key in AUTHORITY_KEYS) \
            or payload.evaluation_mode != 'incremental' or payload.timeframe != 'M5' or payload.dataset_tail_rows is not None \
            or native.get('upgrades') or payload.native_spread_context_study_contract or payload.native_standalone_qualification:
        raise ValueError('NATIVE_REACHABILITY_DEPTH_AUDIT_SCOPE_INVALID')
    # This remains the original strict discovery boundary, not a short-probe exception.
    assert_clean_discovery_boundary(payload)
    manifest = payload.mtf_snapshot_manifest or {}
    streams = manifest.get('streams')
    if manifest.get('protocol') != 'closed_h4_h1_m15_m5_snapshot_v1' \
            or manifest.get('validation_bundle_protocol') != 'prospective_clean_discovery_bundle_v1' \
            or not isinstance(streams, dict) or set(streams) != {'M5', 'M15', 'H1', 'H4'} \
            or any(not isinstance(stream, dict) or not _hash(stream.get('sha256')) or not stream.get('path') for stream in streams.values()) \
            or payload.dataset_path != streams['M5']['path'] \
            or any(payload.mtf_dataset_paths.get(role) != streams[role]['path'] for role in ('M15', 'H1', 'H4')) \
            or not (payload.mtf_pilot or {}).get('enabled'):
        raise ValueError('NATIVE_REACHABILITY_DEPTH_AUDIT_PHYSICAL_SOURCE_INVALID')
    declaration = body.get('declaration')
    if not isinstance(declaration, dict) or set(declaration) != DECLARATION_KEYS \
            or declaration.get('protocol') != DECLARATION_PROTOCOL or declaration.get('criterion') != 'instrument_gate_reached' \
            or not _integer(declaration.get('minimum_observed_opportunities'), 1, 15000) \
            or not _integer(declaration.get('cheap_evaluated_rows'), 2, 1024) \
            or not _integer(declaration.get('deeper_evaluated_rows'), declaration['cheap_evaluated_rows'] + 1, 15000) \
            or not _integer(declaration.get('sample_cap'), 1, 2) \
            or not isinstance(declaration.get('seed'), str) or not 1 <= len(declaration['seed']) <= 128 \
            or declaration['seed'] != declaration['seed'].strip() \
            or declaration.get('initial_capital') != payload.initial_balance:
        raise ValueError('NATIVE_REACHABILITY_DEPTH_AUDIT_DECLARATION_INVALID')
    contexts = declaration.get('contexts')
    if not isinstance(contexts, dict) or set(contexts) != ROLES:
        raise ValueError('NATIVE_REACHABILITY_DEPTH_AUDIT_CONTEXT_INVALID')
    for context in contexts.values():
        if not isinstance(context, dict) or set(context) != CONTEXT_KEYS or context.get('direction') not in {'BUY', 'SELL'} \
                or any(not isinstance(value, str) or not 1 <= len(value) <= 128 for value in context.values()) \
                or any(not kernel._canonical_instrument_context_value(axis, value)
                    or (axis != 'direction' and kernel._canonical_instrument_context_value(axis, value) != value)
                    for axis, value in context.items()):
            raise ValueError('NATIVE_REACHABILITY_DEPTH_AUDIT_CONTEXT_INVALID')
    members = native['members']
    if len(members) != 4 or {member['role'] for member in members} != ROLES:
        raise ValueError('NATIVE_REACHABILITY_DEPTH_AUDIT_WHOLE_PROGRAMME_REQUIRED')
    probe = (payload.policy_context or {}).get('prospective_probe_window')
    if not isinstance(probe, dict) or probe.get('evaluated_rows') != 15000 or probe.get('warmup_rows') != 512 \
            or probe.get('loaded_rows') != 15512:
        raise ValueError('NATIVE_REACHABILITY_DEPTH_AUDIT_ORIGINAL_PROBE_INVALID')
    expected_identity = {'native_contract_hash': native['contract_hash'], 'dataset_hash': payload.replay_dataset_hash,
        'execution_hash': native['execution_hash'], 'account_policy_hash': canonical_hash(native['policy']),
        'initial_capital': payload.initial_balance, 'evaluation_policy_hash': canonical_hash(probe),
        'context_hash': canonical_hash(contexts), 'declaration_hash': canonical_hash(declaration), 'members_hash': canonical_hash(members),
        'quote_provenance_hash': canonical_hash(manifest.get('quote_spread_provenance') or {})}
    identity = body.get('identity')
    if not isinstance(identity, dict) or set(identity) != IDENTITY_KEYS or identity != expected_identity \
            or body.get('identity_hash') != canonical_hash(identity):
        raise ValueError('NATIVE_REACHABILITY_DEPTH_AUDIT_ORIGINAL_REQUEST_MISMATCH')
    view_rows = declaration[body['arm'] + '_evaluated_rows']
    expected_view = {'protocol': VIEW_PROTOCOL, 'selection': 'prefix', 'evaluated_rows': view_rows,
        'decision_rows': view_rows - 1, 'warmup_rows': 512, 'source_evaluated_rows': 15000, 'source_loaded_rows': 15512}
    if body.get('execution_view') != expected_view:
        raise ValueError('NATIVE_REACHABILITY_DEPTH_AUDIT_EXECUTION_VIEW_INVALID')
    selection = body.get('selection')
    if body['arm'] == 'cheap':
        if selection is not None:
            raise ValueError('NATIVE_REACHABILITY_DEPTH_AUDIT_PREMATURE_SELECTION')
    else:
        if not isinstance(selection, dict) or set(selection) != SELECTION_KEYS \
                or selection.get('protocol') != SELECTION_PROTOCOL or not _integer(selection.get('cheap_run_id'), 1, 2**63 - 1) \
                or any(not _hash(selection.get(key)) for key in ('cheap_response_hash', 'cheap_receipt_hash', 'pool_hash')):
            raise ValueError('NATIVE_REACHABILITY_DEPTH_AUDIT_SELECTION_INVALID')
        selected = selection.get('selected_specialist_ids')
        hashes = selection.get('selected_member_hashes')
        member_hashes = {member['specialist_id']: canonical_hash({'council_version': native['council_version'], 'member': member}) for member in members}
        if not isinstance(selected, list) or not 1 <= len(selected) <= declaration['sample_cap'] \
                or any(not isinstance(item, str) or item not in member_hashes for item in selected) \
                or len(set(selected)) != len(selected) or hashes != [member_hashes[item] for item in selected] \
                or selection.get('sample_hash') != canonical_hash({key: value for key, value in selection.items() if key != 'sample_hash'}):
            raise ValueError('NATIVE_REACHABILITY_DEPTH_AUDIT_SELECTION_INVALID')
    digest = canonical_hash(body)
    if declared.get('contract_hash') != digest:
        raise ValueError('NATIVE_REACHABILITY_DEPTH_AUDIT_CONTRACT_HASH_INVALID')
    _verify_seal(declared.get('server_seal'), SEAL_PROTOCOL, digest, 'NATIVE_REACHABILITY_DEPTH_AUDIT_SERVER_SEAL_INVALID')
    return {**body, 'contract_hash': digest}


class NativeReachabilityDepthAudit:
    def __init__(self, contract, payload, native, source):
        self.contract, self.payload, self.native, self.source = contract, payload, native, source
        self.declaration = contract['declaration']
        self.selected = set(contract['selection']['selected_specialist_ids']) if contract['selection'] is not None else {member['specialist_id'] for member in native['members']}
        self.counts = {member['specialist_id']: Counter({key: 0 for key in COUNTS}) for member in native['members']}
        self.events = []
        self.current = None
        self.provenance = (payload.mtf_snapshot_manifest or {}).get('quote_spread_provenance') or {}
        self.provenance_valid = isinstance(self.provenance, dict) and self.provenance.get('protocol') == 'historical_quote_spread_snapshot_v1' \
            and self.provenance.get('provider') == 'dukascopy_historical_synchronized_tick_v1' \
            and self.provenance.get('maximum_quote_age_ms') == 60000 and self.provenance.get('paper_2026_included') is False \
            and self.provenance.get('promotion_evidence') is False and bool(self.provenance.get('sources'))

    def quote(self, prior):
        values = {}
        for name, field in [('bid', 'bid_close'), ('ask', 'ask_close'), ('spread', 'spread'), ('age_ms', 'quote_age_ms')]:
            try:
                value = float(prior.get(field))
                values[name] = value if math.isfinite(value) else None
            except (TypeError, ValueError, OverflowError):
                values[name] = None
        def stamp(key):
            try:
                value = pd.Timestamp(prior.get(key))
                return value.isoformat() if not pd.isna(value) and value.tzinfo is not None else None
            except (TypeError, ValueError, OverflowError):
                return None
        quote_time, available_at = stamp('quote_time_utc'), stamp('quote_available_after_utc')
        valid = prior.get('spread_available') in (True, 1, '1') and self.provenance_valid \
            and self.source.get('status') == 'verified' and _hash(self.source.get('actual_source_sha256')) \
            and all(value is not None for value in values.values()) and quote_time is not None and available_at is not None
        if valid:
            close = pd.Timestamp(prior['time']) + pd.Timedelta(minutes=5)
            quote = pd.Timestamp(quote_time)
            valid = pd.Timestamp(prior['time']) <= quote < close and pd.Timestamp(available_at) == close \
                and close < pd.Timestamp('2026-01-01', tz='UTC') and 0 < values['age_ms'] <= 60000 \
                and abs((close - quote).total_seconds() * 1000 - values['age_ms']) <= .001 \
                and values['bid'] > 0 and values['ask'] >= values['bid'] \
                and abs(values['bid'] - float(prior['close'])) <= .000001 \
                and abs(values['spread'] - (values['ask'] - values['bid'])) <= .000001
        return {'available': bool(valid), **values, 'quote_time': quote_time, 'available_at': available_at,
            'provenance_hash': canonical_hash(self.provenance), 'source_sha256': self.source.get('actual_source_sha256')}

    def begin(self, runtime, prior, evaluation_index, execution_index, execution_time):
        self.current = None
        raw_source, raw_signal = raw_source_signal(prior)
        if runtime.identity not in self.selected or raw_signal not in {'BUY', 'SELL'}:
            return
        from app.services import backtester as kernel
        counts = self.counts[runtime.identity]
        counts['raw_opportunities'] += 1
        raw = kernel._instrument_runtime_context(prior, raw_signal)
        context = {axis: raw_signal if axis == 'direction' else kernel._canonical_instrument_context_value(axis, raw[axis]) for axis in CONTEXT_KEYS}
        if context != self.declaration['contexts'][runtime.declaration['role']]:
            return
        if len(self.events) >= MAX_EVENTS:
            raise ValueError('NATIVE_REACHABILITY_DEPTH_AUDIT_EVENT_BUDGET_EXCEEDED')
        counts['matching_context_opportunities'] += 1
        quote = self.quote(prior)
        counts['observed_quote_opportunities' if quote['available'] else 'missing_quote_opportunities'] += 1
        closed = {str(key): kernel._semantic_event_value(value) for key, value in prior.items()}
        identity = {'member_version_hash': runtime.version_hash, 'evaluation_index': evaluation_index,
            'execution_index': execution_index, 'signal_time': pd.Timestamp(prior['time']).isoformat(),
            'execution_time': pd.Timestamp(execution_time).isoformat(), 'direction': raw_signal}
        self.current = {**identity, 'event_id': canonical_hash(identity), 'specialist_id': runtime.identity,
            'role': runtime.declaration['role'], 'source_context': context, 'source_context_hash': canonical_hash(context),
            'raw_signal_source': raw_source, 'raw_signal_hash': canonical_hash({'source_key': raw_source, 'value': raw_signal}),
            'source_quote': quote, 'source_quote_hash': canonical_hash(quote), 'closed_input_hash': canonical_hash(closed),
            'gate_reached': False}
        self.events.append(self.current)

    def gate(self, runtime, signal):
        if self.current is not None and self.current['specialist_id'] == runtime.identity and self.current['direction'] == signal:
            self.current['gate_reached'] = True
            self.counts[runtime.identity]['gate_reached_opportunities'] += 1

    def finish(self, clock, scope, dependencies, effort):
        complete = not dependencies and clock.get('complete') is True and clock.get('decision_rows') == self.contract['execution_view']['decision_rows']
        pool = []
        for member in self.native['members']:
            observed = member['specialist_id'] in self.selected
            counts = dict(self.counts[member['specialist_id']])
            missing = list(dependencies)
            if observed:
                if not complete: missing.append('execution_view_incomplete')
                if self.source.get('status') != 'verified' or not self.provenance_valid: missing.append('quote_source_unverified')
                if not counts['raw_opportunities']: missing.append('no_raw_opportunities')
                if not counts['matching_context_opportunities']: missing.append('no_matching_context_opportunities')
                if counts['missing_quote_opportunities']: missing.append('matching_quote_missing')
                if counts['observed_quote_opportunities'] < self.declaration['minimum_observed_opportunities']: missing.append('matching_quote_opportunities_underpowered')
            status = 'not_selected' if not observed else 'dependency' if missing else 'reached' if counts['gate_reached_opportunities'] else 'rejected'
            pool.append({'specialist_id': member['specialist_id'], 'role': member['role'],
                'member_version_hash': canonical_hash({'council_version': self.native['council_version'], 'member': member}),
                'observed': observed, 'status': status, 'counts': counts, 'dependency_reasons': sorted(set(missing)) if observed else []})
        artifacts = {'observer_sha256': hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),
            'native_producer_sha256': hashlib.sha256(Path(__file__).with_name('specialist_council.py').read_bytes()).hexdigest()}
        body = {'protocol': RECEIPT_PROTOCOL, 'audit_id': self.contract['audit_id'], 'arm': self.contract['arm'],
            'contract_hash': self.contract['contract_hash'], 'identity_hash': self.contract['identity_hash'],
            'declaration': self.declaration, 'execution_view': self.contract['execution_view'], 'selection': self.contract['selection'],
            'source_attestation': self.source, 'physical_source_rows': 15512,
            'execution_input_rows': 512 + self.contract['execution_view']['evaluated_rows'], 'evaluated_scope': scope,
            'replay_executed_clock': clock, 'source_artifacts': artifacts, 'pool': pool, 'pool_hash': canonical_hash(pool),
            'effort': effort,
            'events': self.events, 'events_hash': canonical_hash(self.events), 'status': 'computed' if complete else 'dependency',
            'authority': 'research_only', **{key: False for key in AUTHORITY_KEYS}}
        return {**body, 'receipt_hash': canonical_hash(body), 'receipt_json': canonical_json(body)}


STANDALONE_PROTOCOL = 'native_standalone_qualification_v1'
STANDALONE_SEAL_PROTOCOL = 'native_standalone_qualification_server_seal_v1'
STANDALONE_STATISTICS_PROTOCOL = 'native_standalone_qualification_statistics_v1'
STANDALONE_CRITERIA = {'minimum_windows': 3, 'minimum_positive_windows': 2, 'aggregate_net_positive': True,
    'no_censored_outcomes': True, 'bootstrap_method': 'bootstrap_profit_factor', 'bootstrap_simulations': 500,
    'bootstrap_seed': 42, 'bootstrap_pf_5_percentile_minimum': 1.10}
FORCED_REASONS = {'replay_end', 'end_of_replay', 'hard_drawdown', 'hard_daily_loss', 'hard_gross_exposure', 'hard_account_risk'}


def validate_standalone_qualification(payload, native):
    declared = payload.native_standalone_qualification
    if not declared:
        return None
    if payload.evaluation_mode != 'full' or len(native['members']) != 1 or native['members'][0]['capital_weight'] != 1.0 \
            or native.get('upgrades') or payload.native_reachability_depth_audit_contract or payload.native_spread_context_study_contract \
            or payload.dataset_tail_rows is not None or (payload.policy_context or {}).get('prospective_probe_window'):
        raise ValueError('NATIVE_STANDALONE_QUALIFICATION_FULL_SINGLETON_REQUIRED')
    body = {key: value for key, value in declared.items() if key not in {'declaration_hash', 'declaration_json', 'server_seal'}}
    encoded = declared.get('declaration_json')
    if encoded is not None:
        try:
            preserved = json.loads(encoded) if isinstance(encoded, str) and len(encoded.encode()) <= 131072 else None
        except (ValueError, RecursionError) as error:
            raise ValueError('NATIVE_STANDALONE_QUALIFICATION_JSON_COPY_INVALID') from error
        if not isinstance(preserved, dict) or not _same_ast_copy(body, preserved):
            raise ValueError('NATIVE_STANDALONE_QUALIFICATION_JSON_COPY_MISMATCH')
        body = preserved
    if set(body) != {'protocol', 'panel_plan_hash', 'source_projection_hash', 'criteria_hash', 'criteria'} \
            or body.get('protocol') != STANDALONE_PROTOCOL \
            or any(not _hash(body.get(key)) for key in ('panel_plan_hash', 'source_projection_hash', 'criteria_hash')):
        raise ValueError('NATIVE_STANDALONE_QUALIFICATION_DECLARATION_INVALID')
    criteria = body.get('criteria')
    if not isinstance(criteria, dict) or set(criteria) != {*STANDALONE_CRITERIA, 'minimum_paired_trades'} \
            or any(type(criteria.get(key)) is not type(value) or criteria.get(key) != value for key, value in STANDALONE_CRITERIA.items()) \
            or not _integer(criteria.get('minimum_paired_trades'), 20, 15000) or body['criteria_hash'] != canonical_hash(criteria):
        raise ValueError('NATIVE_STANDALONE_QUALIFICATION_CRITERIA_INVALID')
    digest = canonical_hash(body)
    if declared.get('declaration_hash') != digest:
        raise ValueError('NATIVE_STANDALONE_QUALIFICATION_DECLARATION_HASH_INVALID')
    # Existing PHP declaration transports this one seal as a hex string.
    from app.main import _internal_api_token
    key = _internal_api_token()
    expected = hmac.new(key.encode(), (STANDALONE_SEAL_PROTOCOL + '\n' + digest).encode(), 'sha256').hexdigest()
    seal = declared.get('server_seal')
    if len(key) < 32 or not isinstance(seal, str) or not hmac.compare_digest(seal, expected):
        raise ValueError('NATIVE_STANDALONE_QUALIFICATION_SERVER_SEAL_INVALID')
    return {**body, 'declaration_hash': digest}


def standalone_qualification_statistics(declaration, native, payload, ledger):
    from app.services.statistical_validation import bootstrap_profit_factor_lower_bound
    values, censored, unknown = [], 0, 0
    for position in ledger:
        reasons = [str(position.get(key, '')).strip().lower() for key in ('exit_reason', 'closure_reason', 'close_reason')]
        forced = any('force' in reason or 'end_of_data' in reason or reason in FORCED_REASONS for reason in reasons) \
            or position.get('force_closed') is True or position.get('is_forced_close') is True
        mature = position.get('outcome_matured')
        censor = forced or mature is False
        censored += int(censor)
        known = type(mature) is bool
        pnl = position.get('net_pnl')
        if not known or isinstance(pnl, bool) or not isinstance(pnl, (int, float)) or not math.isfinite(pnl):
            unknown += 1
        elif mature and not censor:
            values.append(float(pnl))
    bootstrap = {**bootstrap_profit_factor_lower_bound(values, simulations=500, seed=42), 'seed': 42}
    return {'protocol': STANDALONE_STATISTICS_PROTOCOL, 'declaration_hash': declaration['declaration_hash'],
        'panel_plan_hash': declaration['panel_plan_hash'], 'source_projection_hash': declaration['source_projection_hash'],
        'criteria_hash': declaration['criteria_hash'], 'criteria': declaration['criteria'],
        'native_contract_hash': native['contract_hash'], 'dataset_hash': payload.replay_dataset_hash,
        'execution_hash': native['execution_hash'], 'input_hash': canonical_hash(values), 'trade_count': len(ledger),
        'mature_trade_count': len(values), 'censored_trade_count': censored, 'unknown_maturity_count': unknown,
        'bootstrap': bootstrap, 'status': 'unknown' if unknown else 'incomplete' if censored else 'complete',
        **{key: False for key in AUTHORITY_KEYS}}
