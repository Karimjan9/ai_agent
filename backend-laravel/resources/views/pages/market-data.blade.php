@extends('layouts.app', [
    'heading' => 'Market Data',
    'subtitle' => 'XAU/USD historical candle data import va dataset holati.',
])

@section('content')
    <article class="card">
        <h2 class="section-title">Datasetlar</h2>
        <table class="table">
            <thead>
            <tr>
                <th>Symbol</th>
                <th>Timeframe</th>
                <th>Source</th>
                <th>Status</th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td>XAU/USD</td>
                <td>M15</td>
                <td>datasets/xauusd_sample_m15.csv</td>
                <td>Smoke test</td>
            </tr>
            <tr>
                <td>XAU/USD</td>
                <td>H1</td>
                <td>datasets/XAUUSD_H1.csv</td>
                <td>Ready for MVP backtest</td>
            </tr>
            </tbody>
        </table>
    </article>
@endsection
