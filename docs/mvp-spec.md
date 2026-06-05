# NeuroTrader Lab MVP Spec

## Goal

Build the smallest working version of NeuroTrader Lab:

```text
Historical data -> Strategy engine -> Backtest -> Result -> Mistake journal -> Daily report
```

The MVP is a research and learning environment only. It must not place real orders.

## First Instrument

- Symbol: `XAU/USD`
- Timeframes: `M15`, `H1`

## Strategy V1

The first strategy is deterministic and rule-based.

### Indicators

- EMA 50
- EMA 200
- RSI 14
- ATR 14
- Fibonacci retracement based on recent swing high/low

### Long Setup

- EMA 50 is above EMA 200
- RSI is between 45 and 70
- Price is near the 38.2% to 61.8% Fibonacci retracement zone
- Stop-loss uses ATR distance below entry
- Take-profit uses risk/reward ratio

### Short Setup

- EMA 50 is below EMA 200
- RSI is between 30 and 55
- Price is near the 38.2% to 61.8% Fibonacci retracement zone
- Stop-loss uses ATR distance above entry
- Take-profit uses risk/reward ratio

## Backtest Rules

- One position at a time
- Entry at candle close after signal confirmation
- Stop-loss and take-profit are checked on future candle high/low
- If both SL and TP are touched in one candle, assume SL first for conservative testing
- No spread/slippage in V1 unless configured later

## Mistake Journal

Each losing trade creates a mistake journal item with:

- Symbol
- Timeframe
- Direction
- Entry time
- Exit time
- Entry price
- Exit price
- PnL
- Reason
- Indicator snapshot

## Daily Report

Daily reports summarize:

- Number of trades
- Wins
- Losses
- Win rate
- Net PnL
- Most common mistake reason
- Short conclusion

## Next Iterations

1. Add Laravel dashboard pages.
2. Persist backtests, trades, mistake journal, and daily reports.
3. Add Redis Queue jobs for long-running backtests.
4. Connect market data provider.
5. Add AI learning layer after enough backtest history exists.
