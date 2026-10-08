"""Real PHP/Python fixture transport; preview closed context before any account replay."""

import json
import sys

from app.schemas import SimpleBacktestRequest
from app.services import backtester as kernel
from app.services.specialist_council import validate_contract, _compile_member


def main():
    incoming = json.load(sys.stdin)
    payload = SimpleBacktestRequest.model_validate(incoming['request'])
    if incoming['mode'] == 'replay':
        print(kernel.run_simple_ema_rsi_backtest(payload).model_dump_json())
        return
    if incoming['mode'] != 'preview_context':
        raise ValueError('FIXTURE_MODE_UNSUPPORTED')
    contract = validate_contract(payload)
    member = next(item for item in contract['members'] if item['specialist_id'] == incoming['specialist_id'])
    frame = kernel._prepare_simple_dataframe(payload, kernel._load_simple_candles(payload))
    runtime = _compile_member(payload, frame, member, contract['council_version'])
    warmup = payload.policy_context['prospective_probe_window']['warmup_rows']
    for index in range(warmup, len(runtime.rows) - 1):
        row = runtime.rows[index]
        if row.get('signal') not in {'BUY', 'SELL'}:
            continue
        context = kernel._instrument_runtime_context(row, row['signal'])
        axes = {key: kernel._canonical_instrument_context_value(key, context[key])
            for key in ('regime', 'volatility', 'session', 'venue_phase')}
        if any(value in {'', 'unknown', 'none'} for value in axes.values()):
            continue
        print(json.dumps({'exact_context': {**axes, 'direction': row['signal']},
            'preview_policy': 'closed_signal_context_before_account_replay_not_outcome',
            'signal_time': str(row['time']), 'no_account_or_pnl_computed': True}))
        return
    raise ValueError('FIXTURE_SOURCE_HAS_NO_RAW_OPPORTUNITY')


if __name__ == '__main__':
    main()
