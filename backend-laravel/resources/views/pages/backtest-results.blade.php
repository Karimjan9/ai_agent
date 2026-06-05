@extends('layouts.app', [
    'heading' => 'Backtest Results',
    'subtitle' => 'Backtest run metrikalari va trade statistikasi.',
])

@section('content')
    <article class="card">
        <h2 class="section-title">Natijalar</h2>
        <table class="table">
            <thead>
            <tr>
                <th>Strategiya</th>
                <th>Instrument</th>
                <th>Timeframe</th>
                <th>Period</th>
                <th>Trades</th>
                <th>Winrate</th>
                <th>Profit factor</th>
                <th>Max drawdown</th>
                <th>Net profit</th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td>{{ $backtestSummary['strategy'] }}</td>
                <td>{{ $backtestSummary['instrument'] }}</td>
                <td>{{ $backtestSummary['timeframe'] }}</td>
                <td>{{ $backtestSummary['period'] }}</td>
                <td>{{ $backtestSummary['trades'] }}</td>
                <td>{{ $backtestSummary['winrate'] }}</td>
                <td>{{ $backtestSummary['profit_factor'] }}</td>
                <td>{{ $backtestSummary['max_drawdown'] }}</td>
                <td>{{ $backtestSummary['net_profit'] }}</td>
            </tr>
            </tbody>
        </table>
    </article>

    <article class="card" style="margin-top: 14px;">
        <h2 class="section-title">Xulosa</h2>
        <p class="muted">{{ $backtestSummary['conclusion'] }}</p>
    </article>
@endsection
