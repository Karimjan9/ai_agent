"""Test-only real PHP request -> original native Python account products.

No supplied scores, signal overrides, synthetic learner issuance or risk changes
are accepted here. Inline synthetic candle fixtures are software evidence only.
"""

import json
import sys

from app import main
from app.schemas import SimpleBacktestRequest
from app.services import backtester as kernel
from app.services.instrument_method_native_meta_model import actual_native_meta_evaluation_binding
from app.services.specialist_council import validate_contract


def run():
    incoming = json.load(sys.stdin)
    if not isinstance(incoming, dict) or set(incoming) - {'mode', 'request', 'specialist_id'}:
        raise ValueError('NATIVE_META_FIXTURE_TYPED_INPUT_REQUIRED')
    payload = SimpleBacktestRequest.model_validate(incoming['request'])
    native = validate_contract(payload)
    if incoming['mode'] == 'binding':
        member = next(item for item in native['members'] if item['specialist_id'] == incoming['specialist_id'])
        result = actual_native_meta_evaluation_binding(payload, native, member)
    elif incoming['mode'] == 'replay':
        main._validate_method_learning_before_cache(payload)
        result = kernel.run_simple_ema_rsi_backtest(payload).model_dump(mode='json')
    else:
        raise ValueError('NATIVE_META_FIXTURE_MODE_UNSUPPORTED')
    print(json.dumps(result, allow_nan=False, separators=(',', ':')))


if __name__ == '__main__':
    run()
