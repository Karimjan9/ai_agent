"""Signed, matched-context ENTRY/WAIT research; masks no prices or strategy features."""

from __future__ import annotations

import hmac
import math
from collections import Counter

import pandas as pd

from app.services.research_program_tasks import canonical_hash, canonical_json, preserved_contract_body

PROTOCOL = 'native_spread_context_study_v1'
RECEIPT_PROTOCOL = 'native_spread_context_study_receipt_v1'
CRITERION = 'entry_wait_decision_change'
INTERVENTION = 'decision_context.spread_liquidity_state_only'
MAX_EVENTS = 20000
LIQUIDITY_ATR_BINDINGS = {
    'closed_strategy_atr_v1': 'atr',
    'closed_structure_atr_v1': 'structure_atr',
    'closed_m5_management_atr_v1': '_management_atr',
}
IDENTITY_KEYS = {'native_contract_hash', 'target_member_hash', 'specialist_id', 'dataset_hash', 'execution_hash',
    'account_policy_hash', 'initial_capital', 'evaluation_policy_hash', 'quote_provenance_hash',
    'exact_context', 'minimum_paired_opportunities', 'liquidity_atr_binding'}
BODY_KEYS = {'protocol', 'study_id', 'arm', 'identity', 'identity_hash', 'criterion', 'intervention',
    'authority', 'economic_authority', 'skill_authority', 'independent_evidence', 'promotion_evidence'}
CONTEXT_KEYS = {'regime', 'volatility', 'session', 'venue_phase', 'direction'}


def _state_tokens(value):
    """Hash complete causal state without language-specific floats or timestamps."""
    from app.services.backtester import _semantic_event_value
    if isinstance(value, dict):
        return {str(key): _state_tokens(item) for key, item in value.items()}
    if isinstance(value, (list, tuple)):
        return [_state_tokens(item) for item in value]
    return _semantic_event_value(value)


def validate_study(payload, native: dict) -> dict | None:
    declared = payload.native_spread_context_study_contract
    if not declared:
        return None
    body = preserved_contract_body({key: value for key, value in declared.items() if key != 'server_seal'}, 'NATIVE_SPREAD_CONTEXT_STUDY')
    if set(body) != BODY_KEYS or body.get('protocol') != PROTOCOL or body.get('criterion') != CRITERION \
            or body.get('intervention') != INTERVENTION or body.get('authority') != 'research_only' \
            or body.get('arm') not in {'masked', 'unmasked'} or payload.evaluation_mode != 'incremental' or payload.timeframe != 'M5' or native.get('upgrades') \
            or not isinstance(body.get('study_id'), str) or not 1 <= len(body['study_id']) <= 255 \
            or any(body.get(key) is not False for key in ('economic_authority', 'skill_authority', 'independent_evidence', 'promotion_evidence')):
        raise ValueError('NATIVE_SPREAD_CONTEXT_STUDY_SCOPE_INVALID')
    identity = body.get('identity')
    if not isinstance(identity, dict) or set(identity) != IDENTITY_KEYS or body.get('identity_hash') != canonical_hash(identity):
        raise ValueError('NATIVE_SPREAD_CONTEXT_STUDY_IDENTITY_INVALID')
    context = identity.get('exact_context')
    minimum = identity.get('minimum_paired_opportunities')
    if not isinstance(context, dict) or set(context) != CONTEXT_KEYS \
            or any(not isinstance(value, str) or not 1 <= len(value) <= 128 for value in context.values()) \
            or context['direction'] not in {'BUY', 'SELL'} or not isinstance(minimum, int) or isinstance(minimum, bool) \
            or not 1 <= minimum <= MAX_EVENTS:
        raise ValueError('NATIVE_SPREAD_CONTEXT_STUDY_CONTEXT_OR_POWER_INVALID')
    if identity.get('liquidity_atr_binding') not in LIQUIDITY_ATR_BINDINGS:
        raise ValueError('NATIVE_SPREAD_CONTEXT_STUDY_ATR_BINDING_INVALID')
    members = [member for member in native['members'] if member['specialist_id'] == identity['specialist_id']]
    if len(members) != 1 or canonical_hash({'council_version': native['council_version'], 'member': members[0]}) != identity['target_member_hash'] \
            or members[0].get('liquidity_atr_binding') != identity['liquidity_atr_binding']:
        raise ValueError('NATIVE_SPREAD_CONTEXT_STUDY_MEMBER_INVALID')
    policy = (payload.policy_context or {}).get('prospective_probe_window')
    provenance = (payload.mtf_snapshot_manifest or {}).get('quote_spread_provenance') or []
    expected = {'native_contract_hash': native['contract_hash'], 'dataset_hash': payload.replay_dataset_hash,
        'execution_hash': native['execution_hash'], 'account_policy_hash': canonical_hash(native['policy']),
        'initial_capital': payload.initial_balance, 'evaluation_policy_hash': canonical_hash(policy),
        'quote_provenance_hash': canonical_hash(provenance)}
    evaluated_rows = policy.get('evaluated_rows') if isinstance(policy, dict) else None
    if not isinstance(evaluated_rows, int) or isinstance(evaluated_rows, bool) or not 2 <= evaluated_rows <= MAX_EVENTS \
            or any(identity.get(key) != value for key, value in expected.items()):
        raise ValueError('NATIVE_SPREAD_CONTEXT_STUDY_ORIGINAL_REQUEST_MISMATCH')
    contract_hash = canonical_hash(body)
    if declared.get('contract_hash') != contract_hash:
        raise ValueError('NATIVE_SPREAD_CONTEXT_STUDY_CONTRACT_HASH_INVALID')
    from app.main import _internal_api_token
    key = _internal_api_token()
    seal = declared.get('server_seal')
    expected_seal = hmac.new(key.encode(), (PROTOCOL + '\n' + contract_hash).encode(), 'sha256').hexdigest()
    if len(key) < 32 or not isinstance(seal, dict) or set(seal) != {'protocol', 'hmac_sha256'} \
            or seal.get('protocol') != 'native_spread_context_study_server_seal_v1' \
            or not isinstance(seal.get('hmac_sha256'), str) or not hmac.compare_digest(seal['hmac_sha256'], expected_seal):
        raise ValueError('NATIVE_SPREAD_CONTEXT_STUDY_SERVER_SEAL_INVALID')
    return {**body, 'contract_hash': contract_hash}


class NativeSpreadContextStudy:
    def __init__(self, contract: dict, payload, source: dict):
        self.contract, self.payload, self.source = contract, payload, source
        self.identity = contract['identity']
        self.atr_binding = self.identity['liquidity_atr_binding']
        self.atr_source_key = LIQUIDITY_ATR_BINDINGS[self.atr_binding]
        self.masked = contract['arm'] == 'masked'
        self.provenance = (payload.mtf_snapshot_manifest or {}).get('quote_spread_provenance') or {}
        self.provenance_valid = self.provenance.get('protocol') == 'historical_quote_spread_snapshot_v1' \
            and self.provenance.get('provider') == 'dukascopy_historical_synchronized_tick_v1' \
            and self.provenance.get('maximum_quote_age_ms') == 60000 \
            and self.provenance.get('paper_2026_included') is False and self.provenance.get('promotion_evidence') is False \
            and bool(self.provenance.get('sources'))
        self.events: list[dict] = []
        self.counts = Counter({key: 0 for key in ('raw_opportunities', 'matching_context_opportunities', 'observed_quote_opportunities',
            'gate_reached_opportunities', 'feature_gate_reached_opportunities', 'entry_actions', 'wait_actions',
            'missing_quote_opportunities', 'missing_context_feature_opportunities', 'outside_context_opportunities')})
        self.current = None

    def begin(self, runtime, prior: dict, evaluation_index: int, execution_time, account: dict):
        self.current = None
        if runtime.identity != self.identity['specialist_id'] or prior.get('signal') not in {'BUY', 'SELL'}:
            return
        from app.services import backtester as kernel
        self.counts['raw_opportunities'] += 1
        direction = str(prior['signal'])
        raw_context = kernel._instrument_runtime_context(prior, direction, liquidity_atr_field=self.atr_source_key)
        source_context = {key: value for key, value in raw_context.items() if key != 'spread_liquidity_state'}
        if any(kernel._canonical_instrument_context_value(key, source_context[key]) != kernel._canonical_instrument_context_value(key, value)
                for key, value in self.identity['exact_context'].items()):
            self.counts['outside_context_opportunities'] += 1
            return
        if len(self.events) >= MAX_EVENTS:
            raise ValueError('NATIVE_SPREAD_CONTEXT_STUDY_EVENT_BUDGET_EXCEEDED')
        self.counts['matching_context_opportunities'] += 1
        quote = self.quote(prior, raw_context['spread_liquidity_state'])
        observed = quote['available']
        self.counts['observed_quote_opportunities' if observed else 'missing_quote_opportunities'] += 1
        if observed and not quote['feature_available']:
            self.counts['missing_context_feature_opportunities'] += 1
        closed = {str(key): kernel._semantic_event_value(value) for key, value in prior.items()}
        closed_hash = canonical_hash(closed)
        signal_time = pd.Timestamp(prior['time']).isoformat()
        execution_time = pd.Timestamp(execution_time).isoformat()
        event_id = canonical_hash({'member': self.identity['target_member_hash'], 'evaluation_index': evaluation_index,
            'signal_time': signal_time, 'execution_time': execution_time, 'direction': direction})
        self.current = {'event_id': event_id, 'evaluation_index': evaluation_index, 'signal_time': signal_time,
            'execution_time': execution_time, 'direction': direction,
            'raw_signal_hash': canonical_hash({'signal': direction, 'confidence': closed.get('signal_confidence')}),
            'source_context': source_context, 'source_context_hash': canonical_hash(source_context),
            'source_quote': quote, 'source_quote_hash': canonical_hash(quote), 'closed_input_hash': closed_hash,
            'mask_applied': self.masked, 'gate_reached': False, 'feature_gate_reached': False,
            'gate_context_hash': None, 'gate_context': None, 'gate_allowed': None,
            'account_before_gate_hash': None, 'action': 'WAIT', 'rejection_code': 'instrument_gate_unreached'}
        self.account = account
        self.events.append(self.current)

    def quote(self, prior: dict, observed_state: str) -> dict:
        values = {}
        for name, field in [('bid', 'bid_close'), ('ask', 'ask_close'), ('spread', 'spread'), ('age_ms', 'quote_age_ms'), ('atr', self.atr_source_key)]:
            try:
                value = float(prior.get(field))
                values[name] = value if math.isfinite(value) else None
            except (TypeError, ValueError, OverflowError):
                values[name] = None
        def stamp(key):
            try:
                value = pd.Timestamp(prior.get(key))
                return value.isoformat() if not pd.isna(value) and value.tzinfo is not None else None
            except (TypeError, ValueError):
                return None
        quote_time, available_at = stamp('quote_time_utc'), stamp('quote_available_after_utc')
        source_sha = self.source.get('actual_source_sha256')
        valid = prior.get('spread_available') in (True, 1, '1') and self.provenance_valid \
            and self.source.get('status') == 'verified' and isinstance(source_sha, str) and len(source_sha) == 64 \
            and all(values[name] is not None for name in ('bid', 'ask', 'spread', 'age_ms')) and quote_time is not None and available_at is not None
        if valid:
            close = pd.Timestamp(prior['time']) + pd.Timedelta(minutes=5)
            quote = pd.Timestamp(quote_time)
            valid = pd.Timestamp(prior['time']) <= quote < close and pd.Timestamp(available_at) == close \
                and close < pd.Timestamp('2026-01-01', tz='UTC') and 0 < values['age_ms'] <= 60000 \
                and abs((close - quote).total_seconds() * 1000 - values['age_ms']) <= .001 \
                and values['bid'] > 0 and values['ask'] >= values['bid'] \
                and abs(values['bid'] - float(prior['close'])) <= .000001 \
                and abs(values['spread'] - (values['ask'] - values['bid'])) <= .000001
        feature_available = valid and values['atr'] is not None and values['atr'] > 0 and observed_state in {'liquid', 'illiquid'}
        return {'available': bool(valid), 'feature_available': bool(feature_available), **values, 'quote_time': quote_time, 'available_at': available_at,
            'atr_binding': self.atr_binding, 'atr_source_key': self.atr_source_key,
            'atr_input_hash': canonical_hash({'source_key': self.atr_source_key, 'value': values['atr']}),
            'observed_state': observed_state, 'provenance_hash': self.identity['quote_provenance_hash'], 'source_sha256': source_sha}

    def gate(self, context: dict, allowed: bool, feature_reached: bool):
        if self.current is None:
            return
        self.current.update({'gate_reached': True, 'feature_gate_reached': feature_reached, 'gate_context': dict(context),
            'gate_context_hash': canonical_hash(context), 'gate_allowed': allowed,
            'account_before_gate_hash': canonical_hash(_state_tokens(self.account))})
        self.counts['gate_reached_opportunities'] += 1
        self.counts['feature_gate_reached_opportunities'] += int(feature_reached)

    def action(self, runtime, stage: str, reason: str):
        if self.current is None or runtime is None or runtime.identity != self.identity['specialist_id']:
            return
        if stage == 'execution' and reason == 'filled':
            self.current.update(action='ENTRY', rejection_code=None)
        elif stage in {'risk', 'operator'} or reason in {'decision_cadence_wait', 'expired', 'duplicate_intent'}:
            self.current.update(action='WAIT', rejection_code=reason)

    def finish(self, clock: dict) -> dict:
        self.counts['entry_actions'] = sum(event['action'] == 'ENTRY' for event in self.events)
        self.counts['wait_actions'] = len(self.events) - self.counts['entry_actions']
        status = 'data_missing' if self.counts['missing_quote_opportunities'] or self.counts['missing_context_feature_opportunities'] \
            or self.source.get('status') != 'verified' or not self.provenance_valid else \
            'underpowered' if min(self.counts['matching_context_opportunities'], self.counts['feature_gate_reached_opportunities']) < self.identity['minimum_paired_opportunities'] else 'computed'
        body = {'protocol': RECEIPT_PROTOCOL, 'study_id': self.contract['study_id'], 'arm': self.contract['arm'],
            'contract_hash': self.contract['contract_hash'], 'identity_hash': self.contract['identity_hash'],
            'criterion': CRITERION, 'intervention': INTERVENTION, 'status': status,
            'native_contract_hash': self.identity['native_contract_hash'], 'source_attestation': self.source,
            'quote_provenance_hash': self.identity['quote_provenance_hash'], 'replay_executed_clock': clock,
            'events': self.events, 'counts': dict(self.counts), 'pricing_model_unchanged': True, 'risk_policy_unchanged': True,
            'promotion_evidence': False, 'economic_authority': False, 'skill_authority': False, 'independent_evidence': False}
        return {**body, 'receipt_hash': canonical_hash(body), 'receipt_json': canonical_json(body)}
