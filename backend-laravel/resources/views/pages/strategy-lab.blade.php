@extends('layouts.app', [
    'heading' => 'Strategy Lab',
    'subtitle' => 'Ko\'p strategiyalarni bir xil datasetda test qilish va agentlarni solishtirish.',
])

@section('content')
    <article class="card">
        <h2 class="section-title">Run backtest</h2>
        <form class="form-grid" method="post" action="/api/backtest/run" onsubmit="event.preventDefault(); runBacktest(this);">
            <label>Symbol
                <select name="symbol">
                    <option value="XAU_USD">XAU_USD</option>
                </select>
            </label>
            <label>Timeframe
                <select name="timeframe">
                    <option value="H1">H1</option>
                    <option value="M15">M15</option>
                </select>
            </label>
            <label>Strategy
                <select name="strategy">
                    <option value="ema_rsi_v1">ema_rsi_v1</option>
                    <option value="macd_trend_v1">macd_trend_v1</option>
                    <option value="fibonacci_v1">fibonacci_v1</option>
                    <option value="breakout_v1">breakout_v1</option>
                </select>
            </label>
            <label>From
                <input name="from" type="date" value="2023-01-01">
            </label>
            <label>To
                <input name="to" type="date" value="2025-12-31">
            </label>
            <button type="submit">Run</button>
        </form>
    </article>

    <section class="split">
        <article class="card">
            <h2 class="section-title">API response</h2>
            <pre class="code" id="backtest-output">POST /api/backtest/run</pre>
        </article>
        <article class="card">
            <h2 class="section-title">Preset</h2>
            <p class="muted">EMA RSI Agent, MACD Trend Agent, Fibonacci Pullback Agent va Breakout Agent bir xil XAU/USD H1 datasetda test qilinadi.</p>
        </article>
    </section>

    <article class="card" style="margin-top: 14px;">
        <h2 class="section-title">Agent Leaderboard</h2>
        <table class="table">
            <thead>
            <tr>
                <th>Agent / Strategy</th>
                <th>Trades</th>
                <th>Winrate</th>
                <th>Profit</th>
                <th>Drawdown</th>
                <th>Score</th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td>MACD_TREND_V1</td>
                <td>185</td>
                <td>61.2%</td>
                <td>+22.4%</td>
                <td>6.2%</td>
                <td>84</td>
            </tr>
            <tr>
                <td>EMA_RSI_V1</td>
                <td>248</td>
                <td>56.4%</td>
                <td>+18.5%</td>
                <td>8.7%</td>
                <td>76</td>
            </tr>
            <tr>
                <td>FIBONACCI_V1</td>
                <td>132</td>
                <td>53.1%</td>
                <td>+11.2%</td>
                <td>9.4%</td>
                <td>64</td>
            </tr>
            <tr>
                <td>BREAKOUT_V1</td>
                <td>201</td>
                <td>48.7%</td>
                <td>-3.5%</td>
                <td>14.8%</td>
                <td>38</td>
            </tr>
            </tbody>
        </table>
    </article>

    <script>
        async function runBacktest(form) {
            const output = document.getElementById('backtest-output');
            const payload = Object.fromEntries(new FormData(form).entries());
            output.textContent = 'Running...';

            const response = await fetch(form.action, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(payload),
            });

            output.textContent = JSON.stringify(await response.json(), null, 2);
        }
    </script>
@endsection
