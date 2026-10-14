"""Fixed, past-closed method inputs; price topology is not observed order flow.

Every value belongs to the source candle on which it became observable. Swing
pivots need two completed right-hand bars; no pivot is backdated to its origin.
Unavailable prices, quotes, volume and trained-model scores are not synthesized.
The library may use these observations only to veto/reduce/manage an existing
signal. This owner never creates a direction, probability or trading authority.
"""

from __future__ import annotations

import math
from bisect import bisect_right
from collections import deque

import pandas as pd

PROTOCOL = "closed_instrument_method_features_v1"
BOOL_FIELDS = {
    "method_history_available", "method_session_available", "method_swing_available",
    "method_bos", "method_choch", "method_sweep_reclaim", "method_volume_available",
    "method_loss_history_available", "method_signal_available", "method_meta_model_available",
    "method_compression_expansion", "method_dynamic_cooldown_ready", "method_regime_aligned",
    "method_regime_available", "method_transition_available", "method_quote_available",
    "method_band_available", "method_band_reentry",
    "method_pullback_available", "method_pullback_confirmed",
    "method_retest_available", "method_retest_confirmed",
}
NUMBER_FIELDS = {
    "method_atr_ratio", "method_range_atr", "method_session_range_atr",
    "method_session_distance_atr", "method_swing_range_atr", "method_fib_retracement",
    "method_sr_distance_atr", "method_sr_touch_count", "method_sr_age", "method_equal_level_count",
    "method_volume_ratio", "method_transition_age", "method_loss_streak", "method_bars_since_loss",
    "method_signal_age", "method_signal_survival", "method_regime_code", "method_meta_score",
    "method_spread_atr",
}
FIELDS = BOOL_FIELDS | NUMBER_FIELDS


def _number(value) -> float | None:
    if isinstance(value, bool):
        return None
    try:
        value = float(value)
        return value if math.isfinite(value) else None
    except (ValueError, TypeError, OverflowError):
        return None


def _flag(value) -> bool:
    return value is True or isinstance(value, (int, float)) and value == 1


def _utc(value) -> pd.Timestamp:
    try:
        stamp = pd.Timestamp(value)
    except (ValueError, TypeError, OverflowError) as error:
        raise ValueError("METHOD_FEATURE_TIMESTAMP_INVALID") from error
    if pd.isna(stamp) or stamp.tzinfo is None:
        raise ValueError("METHOD_FEATURE_EXPLICIT_UTC_REQUIRED")
    return stamp.tz_convert("UTC")


class ClosedInstrumentMethodFeatures:
    """A bounded original frame plus an agent-owned mature-close journal."""

    def __init__(self, frame: pd.DataFrame, *, bar_duration_seconds: int = 300):
        if not {"time", "open", "high", "low", "close"}.issubset(frame.columns):
            raise ValueError("METHOD_FEATURE_CLOSED_OHLC_REQUIRED")
        stamps = pd.to_datetime(frame["time"], errors="coerce", utc=True)
        if stamps.isna().any() or stamps.duplicated().any() or not stamps.is_monotonic_increasing:
            raise ValueError("METHOD_FEATURE_ORDERED_UNIQUE_CLOCK_REQUIRED")
        if len(frame) > 200000:
            raise ValueError("METHOD_FEATURE_ROW_BUDGET_EXCEEDED")
        if (not isinstance(bar_duration_seconds, int) or isinstance(bar_duration_seconds, bool)
                or not 1 <= bar_duration_seconds <= 604800):
            raise ValueError("METHOD_FEATURE_BAR_DURATION_REQUIRED")
        self.duration = pd.Timedelta(seconds=bar_duration_seconds)
        self.rows = frame.to_dict("records")
        self.index = {stamp.value: index for index, stamp in enumerate(stamps)}
        self.stamps = list(stamps)
        self.features = self._compile()
        self.close_events, self.close_ids = [], set()
        self.last_decision = None

    def _compile(self) -> list[dict]:
        rows, count = self.rows, len(self.rows)
        high = pd.Series([_number(row.get("high")) for row in rows], dtype=float)
        low = pd.Series([_number(row.get("low")) for row in rows], dtype=float)
        close = pd.Series([_number(row.get("close")) for row in rows], dtype=float)
        valid = high.notna() & low.notna() & close.notna() & high.ge(low) & high.ge(close) & low.le(close)
        if not valid.all():
            raise ValueError("METHOD_FEATURE_OHLC_INVALID")
        tr = pd.concat([high - low, (high - close.shift(1)).abs(), (low - close.shift(1)).abs()], axis=1).max(axis=1)
        atr = tr.rolling(14, min_periods=14).mean()
        slow = atr.shift(1).rolling(50, min_periods=50).mean()
        # Disjoint windows: prior five compressed TR bars vs their previous
        # twenty-bar baseline, followed by the current completed expansion bar.
        compressed = tr.shift(1).rolling(5, min_periods=5).mean()
        compression_base = tr.shift(6).rolling(20, min_periods=20).mean()
        # A band belongs to its own completed twenty-close window. Population
        # variance (ddof=0) and the two-standard-deviation width are fixed lab
        # conventions, not a claim that prices have a normal distribution.
        band_mean = close.rolling(20, min_periods=20).mean()
        band_deviation = close.rolling(20, min_periods=20).std(ddof=0)
        band_lower = band_mean - 2.0 * band_deviation
        band_upper = band_mean + 2.0 * band_deviation
        # cTrader's EMA recursion uses alpha=2/(n+1); dynamic EMA support and
        # prior-channel breakouts are reference ideas, not profitability proof.
        # These periods, maturity floor and three-bar retest cap are fixed lab
        # hypotheses. Both EMAs need fifty observed closes, including their seed.
        ema20 = close.ewm(span=20, adjust=False, min_periods=50).mean()
        ema50 = close.ewm(span=50, adjust=False, min_periods=50).mean()
        donchian_high = high.shift(1).rolling(20, min_periods=20).max()
        donchian_low = low.shift(1).rolling(20, min_periods=20).min()
        volume = pd.Series([_number(row.get("volume")) for row in rows], dtype=float)
        observed_volume = pd.Series([_flag(row.get("volume_available")) for row in rows]) & volume.gt(0)
        previous_volume = volume.where(observed_volume).shift(1).rolling(20, min_periods=20).mean()
        result, pivots = [], deque(maxlen=64)
        last_high = last_low = None
        last_break, last_regime, transition_age = None, None, 0
        last_signal, signal_age = None, 0
        session_key, session_high, session_low, session_count = None, None, None, 0
        # At most one (direction, frozen prior-channel level, breakout index).
        # It is consumed on one confirmation candle, never backdated or held
        # beyond three subsequent contiguous source observations.
        pending_retest = None
        for index in range(count):
            row, stamp = rows[index], self.stamps[index]
            a = _number(atr.iloc[index])
            output = {key: False for key in BOOL_FIELDS}
            output["method_history_available"] = a is not None and a > 0
            lower, upper = _number(band_lower.iloc[index]), _number(band_upper.iloc[index])
            previous_lower = _number(band_lower.iloc[index - 1]) if index else None
            previous_upper = _number(band_upper.iloc[index - 1]) if index else None
            band_ready = bool(lower is not None and upper is not None and lower < upper
                and previous_lower is not None and previous_upper is not None and previous_lower < previous_upper)
            reentry_direction = None
            if band_ready and lower < close.iloc[index] < upper:
                # Contact or a continuing outside close is not reentry. A
                # price move inward is required as well as the band crossing,
                # so widening bands alone cannot turn a stationary close into
                # this new confirmation. No later bar confirms it retroactively.
                if close.iloc[index - 1] < previous_lower and close.iloc[index] > close.iloc[index - 1]:
                    reentry_direction = "BUY"
                elif close.iloc[index - 1] > previous_upper and close.iloc[index] < close.iloc[index - 1]:
                    reentry_direction = "SELL"
            output.update({"method_band_available": band_ready, "_band_lower": lower,
                "_band_upper": upper, "_band_reentry_direction": reentry_direction})
            fast, trend = _number(ema20.iloc[index]), _number(ema50.iloc[index])
            previous_fast = _number(ema20.iloc[index - 1]) if index else None
            previous_trend = _number(ema50.iloc[index - 1]) if index else None
            contiguous = bool(index and stamp - self.stamps[index - 1] == self.duration)
            # The immediate pullback/reclaim pair cannot bridge a missing bar
            # or weekend; the fifty-close EMA history need not be gap-free.
            pullback_ready = contiguous and all(value is not None for value in (fast, trend, previous_fast, previous_trend))
            pullback_direction = None
            if pullback_ready:
                if (low.iloc[index - 1] <= previous_fast and previous_trend < close.iloc[index - 1] <= previous_fast
                        and previous_fast > previous_trend and close.iloc[index] > fast
                        and close.iloc[index] > close.iloc[index - 1] and fast > trend):
                    pullback_direction = "BUY"
                elif (high.iloc[index - 1] >= previous_fast and previous_trend > close.iloc[index - 1] >= previous_fast
                        and previous_fast < previous_trend and close.iloc[index] < fast
                        and close.iloc[index] < close.iloc[index - 1] and fast < trend):
                    pullback_direction = "SELL"
            output.update({"method_pullback_available": pullback_ready, "_ema20": fast, "_ema50": trend,
                "_pullback_direction": pullback_direction})
            channel_high, channel_low = _number(donchian_high.iloc[index]), _number(donchian_low.iloc[index])
            retest_ready = bool(contiguous and channel_high is not None and channel_low is not None and channel_high >= channel_low)
            retest_direction, retest_level = None, None
            if pending_retest is not None:
                side, level, breakout_index = pending_retest
                if not contiguous or index - breakout_index > 3:
                    pending_retest = None
                else:
                    retest_level = level
                    opposing = close.iloc[index] < level if side == "BUY" else close.iloc[index] > level
                    touched = low.iloc[index] <= level if side == "BUY" else high.iloc[index] >= level
                    back_on_side = close.iloc[index] > level if side == "BUY" else close.iloc[index] < level
                    if opposing:
                        pending_retest = None
                    elif touched and back_on_side:
                        retest_direction, pending_retest = side, None
            # Only a preceding-bar channel can arm a crossing. Already being
            # beyond a level is not a new breakout; a breakout candle cannot
            # also be its own retest. A consumed confirmation does not rearm.
            if pending_retest is None and retest_direction is None and retest_ready:
                if close.iloc[index - 1] <= channel_high < close.iloc[index]:
                    pending_retest = ("BUY", channel_high, index)
                    retest_level = channel_high
                elif close.iloc[index - 1] >= channel_low > close.iloc[index]:
                    pending_retest = ("SELL", channel_low, index)
                    retest_level = channel_low
            output.update({"method_retest_available": retest_ready, "_retest_direction": retest_direction,
                "_retest_level": retest_level, "_donchian_high": channel_high, "_donchian_low": channel_low})
            output["method_compression_expansion"] = bool(
                _number(compression_base.iloc[index]) is not None and compression_base.iloc[index] > 0
                and compressed.iloc[index] < .7 * compression_base.iloc[index]
                and tr.iloc[index] > 1.5 * compressed.iloc[index])
            if a is not None and a > 0:
                output["method_range_atr"] = float(tr.iloc[index] / a)
                if _number(slow.iloc[index]) is not None and slow.iloc[index] > 0:
                    output["method_atr_ratio"] = float(a / slow.iloc[index])
            # Only actually declared closed session context identifies a window.
            session = row.get("market_session", row.get("session"))
            known_session = isinstance(session, str) and session not in {"", "unknown", "any"}
            key = row.get("market_session_instance_id") or (stamp.date().isoformat(), session)
            if not known_session or key != session_key:
                session_key, session_high, session_low, session_count = key, None, None, 0
            if known_session:
                output["_previous_session_high"] = session_high
                output["_previous_session_low"] = session_low
                session_high = max(session_high, high.iloc[index]) if session_high is not None else high.iloc[index]
                session_low = min(session_low, low.iloc[index]) if session_low is not None else low.iloc[index]
                session_count += 1
                if session_count >= 20 and a is not None and a > 0:
                    output["method_session_available"] = True
                    output["method_session_range_atr"] = float((session_high - session_low) / a)
            # The candidate at index-2 becomes known NOW, after two right bars.
            if index >= 4:
                pivot = index - 2
                highs, lows = high.iloc[pivot - 2:index + 1], low.iloc[pivot - 2:index + 1]
                if high.iloc[pivot] == highs.max() and int(highs.eq(high.iloc[pivot]).sum()) == 1:
                    last_high = (float(high.iloc[pivot]), index)
                    pivots.append(("high", *last_high))
                if low.iloc[pivot] == lows.min() and int(lows.eq(low.iloc[pivot]).sum()) == 1:
                    last_low = (float(low.iloc[pivot]), index)
                    pivots.append(("low", *last_low))
            if last_high is not None and last_low is not None and last_high[0] > last_low[0] and a is not None and a > 0:
                output["method_swing_available"] = True
                output["method_swing_range_atr"] = float((last_high[0] - last_low[0]) / a)
            output.update({"_atr": a, "_high_pivot": last_high, "_low_pivot": last_low,
                "_pivots": list(pivots), "_close": float(close.iloc[index]),
                "_high": float(high.iloc[index]), "_low": float(low.iloc[index])})
            # Crossing uses the level already confirmed on the preceding bar.
            prev = result[-1] if result else {}
            prior_high, prior_low = prev.get("_high_pivot"), prev.get("_low_pivot")
            break_direction = None
            if index and prior_high and close.iloc[index - 1] <= prior_high[0] < close.iloc[index]:
                break_direction = "BUY"
            elif index and prior_low and close.iloc[index - 1] >= prior_low[0] > close.iloc[index]:
                break_direction = "SELL"
            output["_break_direction"] = break_direction
            output["_change_direction"] = break_direction if last_break is not None and break_direction not in {None, last_break} else None
            if break_direction:
                last_break = break_direction
            regime = str(row.get("market_regime", row.get("regime", "unknown"))).lower()
            regime_code = {"trend_up": 1.0, "uptrend": 1.0, "trend_down": -1.0,
                "downtrend": -1.0, "range": 0.0, "ranging": 0.0, "transition": 2.0}.get(regime)
            if regime_code is not None:
                transition_age = transition_age + 1 if regime == last_regime else 0
                output.update({"method_regime_available": True, "method_transition_available": True,
                    "method_regime_code": regime_code, "method_transition_age": float(transition_age)})
                last_regime = regime
            else:
                last_regime, transition_age = None, 0
            signal = str(row.get("signal", "WAIT"))
            if signal in {"BUY", "SELL"}:
                signal_age = signal_age + 1 if signal == last_signal else 1
                output.update({"method_signal_available": True, "method_signal_age": float(signal_age),
                    "method_signal_survival": 1.0 / (1.0 + signal_age / 4.0)})
                last_signal = signal
            else:
                last_signal, signal_age = None, 0
            if observed_volume.iloc[index] and _number(previous_volume.iloc[index]) is not None and previous_volume.iloc[index] > 0:
                output.update({"method_volume_available": True, "method_volume_ratio": float(volume.iloc[index] / previous_volume.iloc[index])})
            # Explicit provenance-bearing observed quotes only; modeled spread
            # and bare OHLC never become quote evidence.
            # The canonical quote freezer exports `spread`; older observed
            # inputs may use `bid_ask_spread`. Present malformed/conflicting
            # aliases cannot be rescued by selecting the other column.
            canonical_present = "spread" in row
            legacy_present = "bid_ask_spread" in row
            canonical_spread = _number(row.get("spread"))
            legacy_spread = _number(row.get("bid_ask_spread"))
            spread = canonical_spread if canonical_present else legacy_spread
            aliases_agree = not (canonical_present and legacy_present) or (
                canonical_spread is not None and legacy_spread is not None
                and canonical_spread == legacy_spread)
            bid, ask = _number(row.get("bid_close")), _number(row.get("ask_close"))
            quote_valid = False
            if (aliases_agree and _flag(row.get("spread_available")) and spread is not None and spread >= 0
                    and bid is not None and ask is not None and ask >= bid
                    and abs((ask - bid) - spread) <= .000001
                    and abs(bid - close.iloc[index]) <= .000001):
                try:
                    quote, available = _utc(row.get("quote_time_utc")), _utc(row.get("quote_available_after_utc"))
                    quote_valid = stamp <= quote < stamp + self.duration and available == stamp + self.duration
                except ValueError:
                    quote_valid = False
            if quote_valid and a is not None and a > 0:
                output.update({"method_quote_available": True, "method_spread_atr": spread / a})
            result.append(output)
        return result

    def notify_mature_close(self, *, position_id: str, net_pnl: float, available_at: str, mature: bool = True) -> None:
        if not mature:
            return
        if not isinstance(position_id, str) or not position_id or _number(net_pnl) is None:
            raise ValueError("METHOD_FEATURE_MATURE_OWN_CLOSE_REQUIRED")
        stamp = _utc(available_at)
        event = (stamp, position_id, float(net_pnl))
        if position_id in self.close_ids:
            original = next(item for item in self.close_events if item[1] == position_id)
            if original != event:
                raise ValueError("METHOD_FEATURE_MATURE_CLOSE_ID_COLLISION")
            return
        self.close_ids.add(position_id)
        self.close_events.append(event)
        # Stable owner publication order resolves simultaneous own closes;
        # arbitrary position-ID spelling cannot change the mature streak.
        self.close_events.sort(key=lambda item: item[0])

    def inputs(self, row: dict, *, direction: str, observed_at: str) -> dict:
        observed = _utc(observed_at)
        index = self.index.get(_utc(row.get("time")).value)
        if index is None or self.stamps[index] + self.duration > observed:
            raise ValueError("METHOD_FEATURE_ORIGINAL_CLOSED_ROW_REQUIRED")
        if self.last_decision is not None and observed < self.last_decision:
            raise ValueError("METHOD_FEATURE_NON_MONOTONIC_DECISION_FORBIDDEN")
        self.last_decision = observed
        original, source = self.rows[index], self.features[index]
        # Reject altered OHLC even when caller supplies the right timestamp.
        if any(_number(row.get(key)) != _number(original.get(key)) for key in ("open", "high", "low", "close")):
            raise ValueError("METHOD_FEATURE_ORIGINAL_ROW_DRIFT")
        output = {key: item for key, item in source.items() if key in FIELDS}
        a, close = source.get("_atr"), source["_close"]
        high, low = source.get("_high_pivot"), source.get("_low_pivot")
        buy = direction == "BUY"
        if direction in {"BUY", "SELL"}:
            output["method_band_reentry"] = source["_band_reentry_direction"] == direction
            output["method_pullback_confirmed"] = source["_pullback_direction"] == direction
            output["method_retest_confirmed"] = source["_retest_direction"] == direction
            output["method_bos"] = source["_break_direction"] == direction
            output["method_choch"] = source["_change_direction"] == direction
            code = source.get("method_regime_code")
            output["method_regime_aligned"] = code == (1.0 if buy else -1.0)
            if output["method_session_available"]:
                level = source.get("_previous_session_high" if buy else "_previous_session_low")
                if level is not None:
                    output["method_session_distance_atr"] = float((close - level if buy else level - close) / a)
            if output["method_swing_available"]:
                width = high[0] - low[0]
                output["method_fib_retracement"] = float((high[0] - close if buy else close - low[0]) / width)
                support = low if buy else high
                relevant = [item for item in source["_pivots"] if item[0] == ("low" if buy else "high")]
                output["method_sr_distance_atr"] = abs(close - support[0]) / a
                output["method_sr_age"] = float(index - support[1])
                output["method_sr_touch_count"] = float(sum(abs(item[1] - support[0]) <= .15 * a for item in relevant))
                output["method_equal_level_count"] = output["method_sr_touch_count"]
                output["method_sweep_reclaim"] = bool(source["_low"] < support[0] < close if buy else source["_high"] > support[0] > close)
        mature = [event for event in self.close_events if event[0] <= observed]
        streak, last_loss = 0, None
        for stamp, _identity, pnl in mature:
            if pnl < 0:
                streak += 1
                last_loss = stamp
            else:
                streak = 0
        # The fresh replay account has a known empty own journal. That is
        # genuinely zero losses, not absent historical/parent performance.
        output["method_loss_history_available"] = True
        output["method_loss_streak"] = float(streak)
        # Count actual closed observations, not calendar minutes/session gaps.
        if last_loss is not None:
            bars = max(0, index + 1 - bisect_right(self.stamps, last_loss - self.duration))
            output["method_bars_since_loss"] = float(bars)
        output["method_dynamic_cooldown_ready"] = streak == 0 or output.get("method_bars_since_loss", 0) >= min(24, 4 * streak)
        return output
