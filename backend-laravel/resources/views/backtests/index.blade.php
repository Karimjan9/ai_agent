@extends('layouts.app', [
    'heading' => 'Run Canonical Lab Replay',
    'subtitle' => 'Replay LabEvaluationRun sifatida immutable evidence plane\'ga yoziladi.',
])

@section('content')
    @if (session('error'))
        <article class="card tone-red" style="margin-bottom: 14px;">
            <h2 class="section-title">Xatolik</h2>
            <p class="muted">{{ session('error') }}</p>
        </article>
    @endif

    <article class="card">
        <h2 class="section-title">Canonical replay sozlamalari</h2>
        <form class="form-grid" method="post" action="{{ route('backtests.run') }}">
            @csrf
            <label>Symbol
                <select name="symbol">
                    <option value="XAUUSD">XAUUSD</option>
                    <option value="EURUSD">EURUSD</option>
                    <option value="GBPUSD">GBPUSD</option>
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
                    <option value="liquidity_trap_mtf_v1">liquidity_trap_mtf_v1 (shadow: H4/H1/M15 → M5)</option>
                </select>
            </label>
            <label>Initial balance
                <input name="initial_balance" type="number" min="1" step="100" value="10000">
            </label>
            <label>Risk
                <input name="risk_per_trade" type="number" min="0.1" step="0.1" value="1">
            </label>
            <label>From
                <input name="from_date" type="date" value="2023-01-01">
            </label>
            <label>To
                <input name="to_date" type="date" value="2025-12-31">
            </label>
            <button type="submit">Run</button>
        </form>
        <p class="muted" style="margin: 12px 0 0;">Liquidity Trap MTF tanlansa, yuqoridagi timeframe faqat so'rov metama'lumoti bo'ladi: replay H4/H1/M15 ni bitta immutable snapshotda muzlatib, M5 yopilishidan keyingi signalni sinaydi. Bu paper/live ruxsati emas.</p>
    </article>
@endsection
