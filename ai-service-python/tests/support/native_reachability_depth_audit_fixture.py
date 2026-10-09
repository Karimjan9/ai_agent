"""Native transport fixture; synthetic sources never assert economic evidence."""

import json
import sys

from app.schemas import SimpleBacktestRequest
from app.services import backtester as kernel
from app.services.specialist_council import validate_contract, _compile_member
from app.services.native_reachability_depth_audit import raw_source_signal


def main():
    incoming = json.load(sys.stdin)
    payload = SimpleBacktestRequest.model_validate(incoming['request'])
    if incoming['mode'] == 'replay':
        print(kernel.run_simple_ema_rsi_backtest(payload).model_dump_json())
        return
    if incoming['mode'] != 'preview_contexts':
        raise ValueError('FIXTURE_MODE_UNSUPPORTED')
    native = validate_contract(payload)
    frame = kernel._prepare_simple_dataframe(payload, kernel._load_simple_candles(payload))
    warmup = payload.policy_context['prospective_probe_window']['warmup_rows']
    rows = incoming.get('closed_context_preview_rows', 1024)
    if type(rows) is not int or not 2 <= rows <= 1024:
        raise ValueError('FIXTURE_CONTEXT_PREVIEW_BUDGET_INVALID')
    contexts, previews = {}, []
    for member in native['members']:
        runtime = _compile_member(payload, frame, member, native['council_version'])
        for index in range(warmup, min(len(runtime.rows) - 1, warmup + rows - 1)):
            row = runtime.rows[index]
            raw_source, raw_signal = raw_source_signal(row)
            if raw_signal not in {'BUY', 'SELL'}:
                continue
            context = kernel._instrument_runtime_context(row, raw_signal)
            axes = {key: kernel._canonical_instrument_context_value(key, context[key])
                for key in ('regime', 'volatility', 'session', 'venue_phase')}
            if any(not value for value in axes.values()):
                continue
            contexts[member['role']] = {**axes, 'direction': raw_signal}
            previews.append({'specialist_id': member['specialist_id'], 'signal_time': str(row['time']), 'raw_signal_source': raw_source})
            break
    if set(contexts) != {'scalp', 'hour', 'day', 'swing'}:
        raise ValueError('FIXTURE_SOURCE_CONTEXT_DEPENDENCY:' + ','.join(sorted({'scalp', 'hour', 'day', 'swing'} - set(contexts))))
    print(json.dumps({'contexts': contexts, 'previews': previews,
        'preview_policy': 'bounded_closed_signal_context_before_account_replay_not_outcome',
        'closed_context_preview_rows': rows, 'no_account_or_pnl_computed': True,
        'fixture_economic_evidence': False}))


if __name__ == '__main__':
    main()
