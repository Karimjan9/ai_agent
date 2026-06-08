from collections import Counter
from datetime import datetime
from pathlib import Path

import pandas as pd

from app.schemas import (
    BacktestRequest,
    BacktestResponse,
    DailyReport,
    DailyReportDay,
    Metrics,
    MistakeJournalEntry,
    SimpleBacktestRequest,
    SimpleBacktestResponse,
    SimpleTrade,
    Trade,
)
from app.services.data_loader import load_candles
from app.services.indicators import add_indicators
from app.services.market_regime import apply_market_regime
from app.strategies.registry import get_strategy, strategy_label


def run_backtest(payload: BacktestRequest) -> BacktestResponse:
    candles = load_candles(payload.dataset_path, payload.candles, payload.from_date, payload.to_date)
    strategy = payload.strategy
    prepared = add_indicators(
        candles,
        ema_fast=strategy.ema_fast,
        ema_slow=strategy.ema_slow,
        rsi_period=strategy.rsi_period,
        atr_period=strategy.atr_period,
    )

    trades = _simulate(prepared, payload)
    mistakes = _mistakes(trades)
    metrics = _metrics(trades, payload.strategy.initial_balance)
    daily_report = _daily_report(trades, mistakes)

    return BacktestResponse(
        symbol=payload.symbol,
        timeframe=payload.timeframe,
        metrics=metrics,
        trades=trades,
        mistake_journal=mistakes,
        daily_report=daily_report,
    )


def run_simple_ema_rsi_backtest(payload: SimpleBacktestRequest) -> SimpleBacktestResponse:
    if payload.candles:
        df = pd.DataFrame([candle.model_dump() for candle in payload.candles])
    else:
        csv_path = _resolve_dataset_path(payload.dataset_path or "../datasets/XAUUSD_H1.csv")
        df = pd.read_csv(csv_path)

    if "volume" not in df.columns:
        df["volume"] = 0

    required_columns = {"time", "open", "high", "low", "close", "volume"}
    missing_columns = required_columns - set(df.columns)
    if missing_columns:
        missing = ", ".join(sorted(missing_columns))
        raise ValueError(f"Dataset is missing required columns: {missing}")

    df = df.copy()
    df["time"] = pd.to_datetime(df["time"])
    for column in ["open", "high", "low", "close", "volume"]:
        df[column] = pd.to_numeric(df[column], errors="coerce")

    df = df.dropna(subset=["time", "open", "high", "low", "close"])
    df = df.sort_values("time").reset_index(drop=True)

    if payload.from_date:
        df = df[df["time"].dt.date >= payload.from_date]
    if payload.to_date:
        df = df[df["time"].dt.date <= payload.to_date]

    df = df.reset_index(drop=True)
    if len(df) <= 200:
        raise ValueError("At least 201 candles are required for EMA 200 backtest.")

    df = apply_market_regime(df)
    strategy_function = get_strategy(payload.strategy, payload.base_strategy)
    df = strategy_function(df, payload.parameters)

    balance = payload.initial_balance
    peak_balance = balance
    max_drawdown = 0.0
    trades: list[SimpleTrade] = []
    position: dict[str, object] | None = None
    gross_profit = 0.0
    gross_loss = 0.0

    for index in range(200, len(df) - 1):
        row = df.iloc[index]
        next_row = df.iloc[index + 1]

        if position is None:
            signal = str(row["signal"])
            if signal not in {"BUY", "SELL"}:
                continue

            entry_price = float(row["close"])
            if signal == "BUY":
                stop_loss = entry_price * 0.995
                take_profit = entry_price * 1.010
            else:
                stop_loss = entry_price * 1.005
                take_profit = entry_price * 0.990

            position = {
                "direction": signal,
                "entry_time": row["time"],
                "entry_price": entry_price,
                "stop_loss": stop_loss,
                "take_profit": take_profit,
                "market_regime": row.get("market_regime", "unknown"),
                "volatility_regime": row.get("volatility_regime", "normal_volatility"),
            }
            continue

        direction = str(position["direction"])
        exit_price = None
        result = None

        if direction == "BUY":
            if float(next_row["low"]) <= float(position["stop_loss"]):
                exit_price = float(position["stop_loss"])
                result = "LOSS"
            elif float(next_row["high"]) >= float(position["take_profit"]):
                exit_price = float(position["take_profit"])
                result = "WIN"
        else:
            if float(next_row["high"]) >= float(position["stop_loss"]):
                exit_price = float(position["stop_loss"])
                result = "LOSS"
            elif float(next_row["low"]) <= float(position["take_profit"]):
                exit_price = float(position["take_profit"])
                result = "WIN"

        if not result or exit_price is None:
            continue

        entry_price = float(position["entry_price"])
        if direction == "BUY":
            profit_percent = ((exit_price - entry_price) / entry_price) * 100
        else:
            profit_percent = ((entry_price - exit_price) / entry_price) * 100

        scaled_profit_percent = profit_percent * payload.risk_per_trade
        balance += balance * (scaled_profit_percent / 100)
        peak_balance = max(peak_balance, balance)
        drawdown = ((peak_balance - balance) / peak_balance) * 100 if peak_balance else 0
        max_drawdown = max(max_drawdown, drawdown)

        if profit_percent > 0:
            gross_profit += profit_percent
        else:
            gross_loss += abs(profit_percent)

        mistake = classify_mistake(direction, row, next_row, position) if result == "LOSS" else None

        trades.append(
            SimpleTrade(
                direction=direction,
                entry_time=str(position["entry_time"]),
                exit_time=str(next_row["time"]),
                entry_price=round(entry_price, 2),
                exit_price=round(exit_price, 2),
                stop_loss=round(float(position["stop_loss"]), 2),
                take_profit=round(float(position["take_profit"]), 2),
                result=result,
                profit_percent=round(profit_percent, 3),
                balance=round(balance, 2),
                market_regime=str(position.get("market_regime", "unknown")),
                volatility_regime=str(position.get("volatility_regime", "normal_volatility")),
                mistake_type=mistake["type"] if mistake else None,
                reason=mistake["reason"] if mistake else None,
                suggestion=mistake["suggestion"] if mistake else None,
            )
        )
        position = None

    total_trades = len(trades)
    wins = len([trade for trade in trades if trade.result == "WIN"])
    losses = len([trade for trade in trades if trade.result == "LOSS"])
    winrate = round((wins / total_trades) * 100, 2) if total_trades else 0.0
    net_profit = round(((balance - payload.initial_balance) / payload.initial_balance) * 100, 2)
    equity_curve = [round(payload.initial_balance, 2), *[trade.balance for trade in trades]]
    max_drawdown = calculate_max_drawdown(equity_curve)
    profit_factor = calculate_profit_factor(trades)
    average_win = calculate_average_win(trades)
    average_loss = calculate_average_loss(trades)
    risk_reward_ratio = calculate_risk_reward_ratio(average_win, average_loss)
    max_consecutive_losses = calculate_max_consecutive_losses(trades)
    stability_score = calculate_stability_score(
        max_drawdown=max_drawdown,
        max_consecutive_losses=max_consecutive_losses,
        profit_factor=profit_factor,
        total_trades=total_trades,
    )
    period_start = payload.from_date.isoformat() if payload.from_date else df["time"].min().date().isoformat()
    period_end = payload.to_date.isoformat() if payload.to_date else df["time"].max().date().isoformat()
    top_mistakes = _top_simple_mistakes(trades)
    regime_performance = calculate_regime_performance(trades)
    volatility_performance = calculate_volatility_performance(trades)

    return SimpleBacktestResponse(
        strategy=strategy_label(payload.strategy),
        parameters=payload.parameters,
        instrument="XAU/USD",
        timeframe=payload.timeframe,
        period=f"{period_start} - {period_end}",
        initial_balance=payload.initial_balance,
        final_balance=round(balance, 2),
        net_profit_percent=net_profit,
        total_trades=total_trades,
        wins=wins,
        losses=losses,
        winrate=winrate,
        profit_factor=profit_factor,
        max_drawdown=max_drawdown,
        max_drawdown_percent=max_drawdown,
        average_win_percent=average_win,
        average_loss_percent=average_loss,
        risk_reward_ratio=risk_reward_ratio,
        max_consecutive_losses=max_consecutive_losses,
        stability_score=stability_score,
        equity_curve=equity_curve,
        regime_performance=regime_performance,
        volatility_performance=volatility_performance,
        top_mistakes=top_mistakes,
        trades=trades[-20:],
        conclusion=_simple_conclusion(
            winrate,
            net_profit,
            total_trades,
            top_mistakes,
            max_drawdown=max_drawdown,
            profit_factor=profit_factor,
            stability_score=stability_score,
        ),
    )


def classify_mistake(
    direction: str,
    row: pd.Series,
    next_row: pd.Series,
    position: dict[str, object],
) -> dict[str, str]:
    ema_50 = _series_float(row, "ema_50")
    ema_200 = _series_float(row, "ema_200")
    rsi = _series_float(row, "rsi")
    close = _series_float(row, "close")
    stop_loss = float(position["stop_loss"])
    entry_price = float(position["entry_price"])

    if direction == "BUY" and ema_50 is not None and ema_200 is not None and ema_50 < ema_200:
        return {
            "type": "trend_against_entry",
            "reason": "BUY signal umumiy trendga qarshi berilgan.",
            "suggestion": "BUY signal uchun EMA 50 EMA 200 dan yuqori bo'lishi shart.",
        }

    if direction == "SELL" and ema_50 is not None and ema_200 is not None and ema_50 > ema_200:
        return {
            "type": "trend_against_entry",
            "reason": "SELL signal umumiy trendga qarshi berilgan.",
            "suggestion": "SELL signal uchun EMA 50 EMA 200 dan past bo'lishi shart.",
        }

    if rsi is not None and rsi > 68:
        return {
            "type": "late_entry",
            "reason": "RSI juda yuqori bo'lgan, kirish kechikkan bo'lishi mumkin.",
            "suggestion": "RSI 65 dan yuqori bo'lsa, BUY signalni kamaytirish kerak.",
        }

    if rsi is not None and rsi < 32:
        return {
            "type": "late_entry",
            "reason": "RSI juda past bo'lgan, SELL kirish kechikkan bo'lishi mumkin.",
            "suggestion": "RSI 35 dan past bo'lsa, SELL signalni kamaytirish kerak.",
        }

    if rsi is not None and 45 <= rsi <= 55:
        return {
            "type": "rsi_false_signal",
            "reason": "RSI neytral zonada bo'lgan, signal yetarlicha kuchli emas.",
            "suggestion": "RSI signal zonasini trend kuchi bilan birga tekshirish kerak.",
        }

    if close and ema_50 is not None and ema_200 is not None and abs(ema_50 - ema_200) / close < 0.001:
        return {
            "type": "sideways_market",
            "reason": "EMA 50 va EMA 200 juda yaqin, bozor sideways bo'lishi mumkin.",
            "suggestion": "Sideways market uchun ATR volatility yoki trend strength filter qo'shish kerak.",
        }

    if entry_price and abs(entry_price - stop_loss) / entry_price < 0.004:
        return {
            "type": "stop_loss_too_close",
            "reason": "Stop-loss entry narxiga juda yaqin joylashgan.",
            "suggestion": "Stop-loss masofasini ATR asosida moslashtirish kerak.",
        }

    return {
        "type": "unknown_loss",
        "reason": "Loss sababi aniq klassifikatsiya qilinmadi.",
        "suggestion": "Qo'shimcha indikatorlar: ATR, trend strength, news filter qo'shish kerak.",
    }


def _top_simple_mistakes(trades: list[SimpleTrade]) -> list[dict[str, int | str]]:
    mistake_counter = Counter([trade.mistake_type for trade in trades if trade.mistake_type])
    return [
        {"type": mistake_type, "count": count}
        for mistake_type, count in mistake_counter.most_common(5)
    ]


def calculate_max_drawdown(equity_curve: list[float]) -> float:
    if not equity_curve:
        return 0.0

    peak = equity_curve[0]
    max_drawdown = 0.0

    for equity in equity_curve:
        peak = max(peak, equity)
        drawdown = ((peak - equity) / peak) * 100 if peak > 0 else 0
        max_drawdown = max(max_drawdown, drawdown)

    return round(max_drawdown, 2)


def calculate_profit_factor(trades: list[SimpleTrade]) -> float:
    gross_profit = sum(trade.profit_percent for trade in trades if trade.profit_percent > 0)
    gross_loss = abs(sum(trade.profit_percent for trade in trades if trade.profit_percent < 0))

    if gross_loss == 0:
        return round(gross_profit, 2) if gross_profit > 0 else 0.0

    return round(gross_profit / gross_loss, 2)


def calculate_average_win(trades: list[SimpleTrade]) -> float:
    wins = [trade.profit_percent for trade in trades if trade.profit_percent > 0]
    if not wins:
        return 0.0

    return round(sum(wins) / len(wins), 3)


def calculate_average_loss(trades: list[SimpleTrade]) -> float:
    losses = [abs(trade.profit_percent) for trade in trades if trade.profit_percent < 0]
    if not losses:
        return 0.0

    return round(sum(losses) / len(losses), 3)


def calculate_risk_reward_ratio(avg_win: float, avg_loss: float) -> float:
    if avg_loss == 0:
        return 0.0

    return round(avg_win / avg_loss, 2)


def calculate_max_consecutive_losses(trades: list[SimpleTrade]) -> int:
    max_streak = 0
    current_streak = 0

    for trade in trades:
        if trade.result == "LOSS":
            current_streak += 1
            max_streak = max(max_streak, current_streak)
        else:
            current_streak = 0

    return max_streak


def calculate_stability_score(
    max_drawdown: float,
    max_consecutive_losses: int,
    profit_factor: float,
    total_trades: int,
) -> int:
    score = 100

    if max_drawdown > 20:
        score -= 40
    elif max_drawdown > 15:
        score -= 30
    elif max_drawdown > 10:
        score -= 20
    elif max_drawdown > 5:
        score -= 10

    if max_consecutive_losses >= 10:
        score -= 30
    elif max_consecutive_losses >= 7:
        score -= 20
    elif max_consecutive_losses >= 5:
        score -= 10

    if profit_factor >= 1.7:
        score += 10
    elif profit_factor < 1:
        score -= 25

    if total_trades < 20:
        score -= 20
    elif total_trades >= 100:
        score += 5

    return max(min(score, 100), 0)


def calculate_regime_performance(trades: list[SimpleTrade]) -> dict[str, dict[str, float | int]]:
    regimes: dict[str, dict[str, float | int]] = {}

    for trade in trades:
        regime = trade.market_regime or "unknown"
        regimes.setdefault(regime, {
            "trades": 0,
            "wins": 0,
            "losses": 0,
            "profit_percent": 0.0,
        })

        regimes[regime]["trades"] += 1
        if trade.result == "WIN":
            regimes[regime]["wins"] += 1
        if trade.result == "LOSS":
            regimes[regime]["losses"] += 1

        regimes[regime]["profit_percent"] += trade.profit_percent

    return _finalize_regime_performance(regimes)


def calculate_volatility_performance(trades: list[SimpleTrade]) -> dict[str, dict[str, float | int]]:
    regimes: dict[str, dict[str, float | int]] = {}

    for trade in trades:
        regime = trade.volatility_regime or "normal_volatility"
        regimes.setdefault(regime, {
            "trades": 0,
            "wins": 0,
            "losses": 0,
            "profit_percent": 0.0,
        })

        regimes[regime]["trades"] += 1
        if trade.result == "WIN":
            regimes[regime]["wins"] += 1
        if trade.result == "LOSS":
            regimes[regime]["losses"] += 1

        regimes[regime]["profit_percent"] += trade.profit_percent

    return _finalize_regime_performance(regimes)


def _finalize_regime_performance(
    regimes: dict[str, dict[str, float | int]],
) -> dict[str, dict[str, float | int]]:
    for data in regimes.values():
        trades_count = int(data["trades"])
        wins = int(data["wins"])
        data["winrate"] = round((wins / trades_count) * 100, 2) if trades_count else 0.0
        data["profit_percent"] = round(float(data["profit_percent"]), 3)

    return regimes


def _resolve_dataset_path(dataset_path: str) -> Path:
    path = Path(dataset_path)
    if path.exists():
        return path

    repo_root = Path(__file__).resolve().parents[3]
    repo_relative = repo_root / dataset_path
    if repo_relative.exists():
        return repo_relative

    dataset_relative = repo_root / "datasets" / path.name
    if dataset_relative.exists():
        return dataset_relative

    raise FileNotFoundError(f"Dataset not found: {dataset_path}")


def _simple_conclusion(
    winrate: float,
    net_profit: float,
    total_trades: int,
    top_mistakes: list[dict[str, int | str]],
    max_drawdown: float = 0,
    profit_factor: float = 0,
    stability_score: int = 0,
) -> str:
    if total_trades == 0:
        return "Strategiya bu periodda trade ochmadi."

    main_mistake = top_mistakes[0]["type"] if top_mistakes else None
    if net_profit > 0 and profit_factor >= 1.3 and max_drawdown <= 10:
        conclusion = "Strategiya risk va profit bo'yicha yaxshi natija berdi. "
    elif net_profit > 0 and max_drawdown > 15:
        conclusion = "Strategiya profit berdi, lekin drawdown yuqori. Riskni kamaytirish kerak. "
    elif net_profit < 0:
        conclusion = "Strategiya zarar bilan yakunlandi. Parametrlarni qayta optimizatsiya qilish kerak. "
    else:
        conclusion = "Strategiya o'rtacha natija berdi. Qo'shimcha filterlar kerak. "

    if profit_factor < 1:
        conclusion += "Profit Factor 1 dan past, zararli trade hajmi foydali tradelardan ko'proq. "
    elif profit_factor >= 1.7:
        conclusion += "Profit Factor kuchli, strategiyada yaxshi risk/reward bor. "

    if max_drawdown > 15:
        conclusion += "Max drawdown yuqori, position size yoki stop-loss logikasini yaxshilash kerak. "

    if stability_score < 50:
        conclusion += "Stability score past, strategiya barqaror emas. "
    elif stability_score >= 75:
        conclusion += "Stability score yaxshi, agent barqarorroq ishlayapti. "

    if main_mistake == "trend_against_entry":
        conclusion += "Eng ko'p xato trendga qarshi kirish bilan bog'liq. Trend filterni kuchaytirish kerak."
    elif main_mistake == "late_entry":
        conclusion += "Eng ko'p xato kech kirish bilan bog'liq. RSI chegaralarini qayta sozlash kerak."
    elif main_mistake == "rsi_false_signal":
        conclusion += "Eng ko'p xato RSI false signal bilan bog'liq. RSI signalini trend kuchi bilan tasdiqlash kerak."
    elif main_mistake == "sideways_market":
        conclusion += "Eng ko'p xato sideways market bilan bog'liq. ATR volatility filter qo'shish kerak."
    elif main_mistake == "stop_loss_too_close":
        conclusion += "Eng ko'p xato stop-loss juda yaqin bo'lgani bilan bog'liq. Stop-lossni ATR asosida sozlash kerak."
    elif main_mistake == "unknown_loss":
        conclusion += "Ko'p loss sababi aniq emas. ATR, volatility va market regime filter qo'shish kerak."
    else:
        conclusion += "Keyingi bosqichda drawdown va market regime tahlil qilish kerak."

    return conclusion


def _simulate(candles: pd.DataFrame, payload: BacktestRequest) -> list[Trade]:
    strategy = payload.strategy
    trades: list[Trade] = []
    open_trade: Trade | None = None

    start_index = max(
        strategy.ema_slow,
        strategy.rsi_period,
        strategy.atr_period,
        strategy.swing_lookback,
    )
    if len(candles) <= start_index:
        return trades

    for index in range(start_index, len(candles)):
        row = candles.iloc[index]

        if open_trade:
            closed = _try_close_trade(open_trade, row)
            if closed:
                trades.append(closed)
                open_trade = None
            continue

        signal = _signal(candles, index, payload)
        if signal is None:
            continue

        direction = signal["direction"]
        entry_price = float(row["close"])
        atr_value = float(row["atr"])
        risk = atr_value * strategy.atr_stop_multiplier

        if direction == "long":
            stop_loss = entry_price - risk
            take_profit = entry_price + (risk * strategy.risk_reward)
        else:
            stop_loss = entry_price + risk
            take_profit = entry_price - (risk * strategy.risk_reward)

        open_trade = Trade(
            direction=direction,
            entry_time=_to_datetime(row["time"]),
            exit_time=None,
            entry_price=round(entry_price, 5),
            exit_price=None,
            stop_loss=round(stop_loss, 5),
            take_profit=round(take_profit, 5),
            pnl=None,
            result="open",
            indicator_snapshot={
                "ema_fast": _rounded(row["ema_fast"]),
                "ema_slow": _rounded(row["ema_slow"]),
                "rsi": _rounded(row["rsi"]),
                "atr": _rounded(row["atr"]),
                "fib_zone": signal["fib_zone"],
            },
        )

    if open_trade:
        last = candles.iloc[-1]
        exit_price = float(last["close"])
        trades.append(_close_trade(open_trade, last["time"], exit_price))

    return trades


def _signal(candles: pd.DataFrame, index: int, payload: BacktestRequest) -> dict[str, str] | None:
    strategy = payload.strategy
    row = candles.iloc[index]

    if pd.isna(row["rsi"]) or pd.isna(row["atr"]):
        return None

    window = candles.iloc[index - strategy.swing_lookback : index + 1]
    swing_high = float(window["high"].max())
    swing_low = float(window["low"].min())
    swing_range = swing_high - swing_low
    if swing_range <= 0:
        return None

    close = float(row["close"])
    ema_fast = float(row["ema_fast"])
    ema_slow = float(row["ema_slow"])
    rsi_value = float(row["rsi"])

    long_zone_low = swing_high - (swing_range * strategy.fibonacci_max)
    long_zone_high = swing_high - (swing_range * strategy.fibonacci_min)
    short_zone_low = swing_low + (swing_range * strategy.fibonacci_min)
    short_zone_high = swing_low + (swing_range * strategy.fibonacci_max)

    if ema_fast > ema_slow and 45 <= rsi_value <= 70 and long_zone_low <= close <= long_zone_high:
        return {"direction": "long", "fib_zone": "38.2-61.8 pullback"}

    if ema_fast < ema_slow and 30 <= rsi_value <= 55 and short_zone_low <= close <= short_zone_high:
        return {"direction": "short", "fib_zone": "38.2-61.8 pullback"}

    return None


def _try_close_trade(trade: Trade, row: pd.Series) -> Trade | None:
    high = float(row["high"])
    low = float(row["low"])

    if trade.direction == "long":
        if low <= trade.stop_loss:
            return _close_trade(trade, row["time"], trade.stop_loss)
        if high >= trade.take_profit:
            return _close_trade(trade, row["time"], trade.take_profit)
    else:
        if high >= trade.stop_loss:
            return _close_trade(trade, row["time"], trade.stop_loss)
        if low <= trade.take_profit:
            return _close_trade(trade, row["time"], trade.take_profit)

    return None


def _close_trade(trade: Trade, exit_time: datetime, exit_price: float) -> Trade:
    pnl = exit_price - trade.entry_price
    if trade.direction == "short":
        pnl = trade.entry_price - exit_price

    return trade.model_copy(
        update={
            "exit_time": _to_datetime(exit_time),
            "exit_price": round(float(exit_price), 5),
            "pnl": round(float(pnl), 5),
            "result": "win" if pnl > 0 else "loss",
        }
    )


def _metrics(trades: list[Trade], initial_balance: float) -> Metrics:
    wins = [trade for trade in trades if trade.result == "win"]
    losses = [trade for trade in trades if trade.result == "loss"]
    net_pnl = sum(trade.pnl or 0 for trade in trades)
    gross_profit = sum(trade.pnl or 0 for trade in wins)
    gross_loss = abs(sum(trade.pnl or 0 for trade in losses))
    profit_factor = gross_profit / gross_loss if gross_loss else 0.0

    return Metrics(
        total_trades=len(trades),
        wins=len(wins),
        losses=len(losses),
        win_rate=round((len(wins) / len(trades)) * 100, 2) if trades else 0.0,
        net_pnl=round(float(net_pnl), 5),
        profit_factor=round(float(profit_factor), 2),
        max_drawdown=_max_drawdown(trades, initial_balance),
    )


def _max_drawdown(trades: list[Trade], initial_balance: float) -> float:
    equity = initial_balance
    peak = initial_balance
    max_drawdown = 0.0

    for trade in trades:
        equity += trade.pnl or 0.0
        peak = max(peak, equity)
        if peak <= 0:
            continue
        drawdown = ((peak - equity) / peak) * 100
        max_drawdown = max(max_drawdown, drawdown)

    return round(float(max_drawdown), 2)


def _mistakes(trades: list[Trade]) -> list[MistakeJournalEntry]:
    entries: list[MistakeJournalEntry] = []
    for trade in trades:
        if trade.result != "loss":
            continue

        reason = "ATR stop-loss was hit before take-profit."
        entries.append(
            MistakeJournalEntry(
                reason=reason,
                trade=trade,
                context={
                    "direction": trade.direction,
                    "entry_price": trade.entry_price,
                    "exit_price": trade.exit_price,
                    "pnl": trade.pnl,
                    "rsi": trade.indicator_snapshot.get("rsi"),
                    "atr": trade.indicator_snapshot.get("atr"),
                    "fib_zone": trade.indicator_snapshot.get("fib_zone"),
                },
            )
        )
    return entries


def _daily_report(trades: list[Trade], mistakes: list[MistakeJournalEntry]) -> DailyReport:
    if not trades:
        return DailyReport(summary="No trades were generated.", days=[])

    grouped: dict[str, list[Trade]] = {}
    for trade in trades:
        key = trade.entry_time.date().isoformat()
        grouped.setdefault(key, []).append(trade)

    mistake_reasons = Counter(entry.reason for entry in mistakes)
    most_common_mistake = mistake_reasons.most_common(1)[0][0] if mistake_reasons else None

    days: list[DailyReportDay] = []
    for date, day_trades in sorted(grouped.items()):
        wins = [trade for trade in day_trades if trade.result == "win"]
        losses = [trade for trade in day_trades if trade.result == "loss"]
        net_pnl = sum(trade.pnl or 0 for trade in day_trades)
        win_rate = (len(wins) / len(day_trades)) * 100 if day_trades else 0

        if net_pnl > 0:
            conclusion = "Positive day. Strategy conditions produced net profit."
        elif net_pnl < 0:
            conclusion = "Negative day. Review mistake journal before next test."
        else:
            conclusion = "Flat day. More data is needed for a useful conclusion."

        days.append(
            DailyReportDay(
                date=date,
                total_trades=len(day_trades),
                wins=len(wins),
                losses=len(losses),
                win_rate=round(win_rate, 2),
                net_pnl=round(float(net_pnl), 5),
                most_common_mistake=most_common_mistake,
                conclusion=conclusion,
            )
        )

    summary = f"Generated {len(trades)} trades across {len(days)} day(s)."
    return DailyReport(summary=summary, days=days)


def _to_datetime(value: object) -> datetime:
    return pd.Timestamp(value).to_pydatetime()


def _rounded(value: object) -> float | None:
    if pd.isna(value):
        return None
    return round(float(value), 5)


def _series_float(row: pd.Series, key: str) -> float | None:
    value = row.get(key)
    if value is None or pd.isna(value):
        return None
    return float(value)
