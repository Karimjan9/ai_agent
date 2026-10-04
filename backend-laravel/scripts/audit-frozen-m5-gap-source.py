"""Offline actual calendar audit, no replay/features/cache or data writes."""
from pathlib import Path
import hashlib
import json
import sys
import pandas as pd

backend = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(backend.parent / 'ai-service-python'))
from app.services.backtester import _is_scheduled_market_closure, _is_expected_market_candle

source = Path(sys.argv[1]).resolve()
source_hash = hashlib.sha256(source.read_bytes()).hexdigest()
times = pd.to_datetime(pd.read_csv(source, usecols=['time'])['time'], utc=True)
if not 2 <= len(times) <= 350000 or not times.is_monotonic_increasing or times.duplicated().any():
    raise ValueError('RECOVERY_SOURCE_CHRONOLOGY_INVALID')
target = pd.Timestamp('2025-12-17T23:00:00Z')
inventory_mode = '--inventory' in sys.argv
recovered_arg = next((arg[12:] for arg in sys.argv[2:] if arg.startswith('--recovered=')), None)
if ((times == target).any() and not inventory_mode and recovered_arg is None) or (times >= pd.Timestamp('2026-01-01T00:00:00Z')).any():
    raise ValueError('RECOVERY_SOURCE_TARGET_OR_EPOCH_INVALID')
def missing_times(scope):
    unexpected = []
    expected = pd.Timedelta(minutes=5)
    values = scope.array
    for previous, current in zip(values[:-1], values[1:]):
        previous, current = pd.Timestamp(previous), pd.Timestamp(current)
        if current - previous <= expected or _is_scheduled_market_closure(previous, current, 'XAUUSD'):
            continue
        missing = previous + expected
        while missing < current:
            if _is_expected_market_candle(missing, 'XAUUSD'): unexpected.append(missing.isoformat())
            missing += expected
    return unexpected
def gaps(scope): return len(missing_times(scope))
missing = missing_times(times)
before = len(missing)
if recovered_arg is not None:
    recovered = recovered_arg.split(',')
    if not isinstance(recovered, list) or not 1 <= len(recovered) <= 300 or len(set(recovered)) != len(recovered) or any(value not in missing for value in recovered):
        raise ValueError('RECOVERY_BATCH_TARGETS_NOT_CANONICAL_MISSING')
    new_times = pd.Series(sorted([*times.array, *(pd.Timestamp(value) for value in recovered)]))
elif inventory_mode:
    recovered = []
    new_times = times
else:
    recovered = [target.isoformat()]
    new_times = pd.Series(sorted([*times.array, target]))
after = gaps(new_times)
tail = new_times.tail(5000)
folds = []
if recovered_arg is not None:
    width = min(4096, len(new_times))
    for index in range(9):
        start = ((len(new_times)-width)*index)//8
        scope = new_times.iloc[start:start+width]
        folds.append({'label':f'representative_4096_{index+1}', 'actual_confirmation_fold':False,
                      'rows':len(scope),'start_utc':scope.iloc[0].isoformat(),'end_utc':scope.iloc[-1].isoformat(),
                      'unexpected_gaps':gaps(scope)})
print(json.dumps({'protocol':'frozen_source_calendar_scope_audit_v2' if recovered_arg is not None else 'frozen_source_calendar_scope_audit_v1','source_csv_sha256':source_hash,
    'source_rows':len(times),'new_rows':len(new_times),'full_source_unexpected_before':before,
    'full_source_unexpected_after':after,'whole_archive_continuity_proven':after==0,
    'screening_rows':len(tail),'screening_start':tail.iloc[0].isoformat(),'screening_end':tail.iloc[-1].isoformat(),
    'screening_unexpected_after':gaps(tail),'canonical_missing_utc':missing if inventory_mode or recovered_arg is not None else [],
    'recovered_targets_utc':recovered,'remaining_missing_utc':missing_times(new_times), 'representative_fold_scopes':folds,
    'independent_evidence':False}))
