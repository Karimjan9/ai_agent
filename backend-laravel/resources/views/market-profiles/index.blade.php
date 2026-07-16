@extends('layouts.app', [
    'heading' => 'Market Profiles',
    'subtitle' => 'Instrument Intelligence: XAUUSD va EURUSD brain, session, strategy va regime farqlarini yigadi.',
])

@section('content')
    <section class="grid metrics">
        <article class="card tone-blue"><div class="metric-label">Active Symbols</div><div class="metric-value">{{ $metrics['symbols'] }}</div></article>
        <article class="card tone-green"><div class="metric-label">Profiles</div><div class="metric-value">{{ $metrics['profiles'] }}</div></article>
        <article class="card tone-yellow"><div class="metric-label">XAUUSD</div><div class="metric-value">{{ $metrics['xauusd_profiles'] }}</div></article>
        <article class="card tone-green"><div class="metric-label">EURUSD</div><div class="metric-value">{{ $metrics['eurusd_profiles'] }}</div></article>
        <article class="card tone-blue"><div class="metric-label">Avg Confidence</div><div class="metric-value">{{ $metrics['avg_confidence'] }}%</div></article>
    </section>

    <article class="card" style="margin-top: 14px;">
        <h2 class="section-title">Instrument Intelligence</h2>
        <form class="form-grid" method="POST" action="{{ route('market-profiles.refresh') }}">
            @csrf
            <button type="submit">Refresh Profiles</button>
        </form>
    </article>

    <section class="split">
        <article class="card">
            <h2 class="section-title">Active Instruments</h2>
            <table class="table">
                <thead><tr><th>Symbol</th><th>Category</th><th>Priority</th><th>Provider</th></tr></thead>
                <tbody>
                @forelse ($symbols as $symbol)
                    <tr>
                        <td>{{ $symbol->symbol }}</td>
                        <td>{{ $symbol->category ?? $symbol->market_type }}</td>
                        <td>{{ $symbol->priority }}</td>
                        <td>{{ $symbol->provider_symbol }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4">Hali active instrument yo'q.</td></tr>
                @endforelse
                </tbody>
            </table>
        </article>

        <article class="card">
            <h2 class="section-title">Market Brains</h2>
            <table class="table">
                <thead><tr><th>Profile</th><th>Best Session</th><th>Best Strategy</th><th>Confidence</th></tr></thead>
                <tbody>
                @forelse ($profiles as $profile)
                    <tr>
                        <td>{{ $profile->symbol }} {{ $profile->timeframe }}</td>
                        <td>{{ $profile->best_session ?? 'unknown' }}</td>
                        <td>{{ $profile->best_strategy ?? 'unknown' }}</td>
                        <td>{{ round((float) $profile->confidence_score, 2) }}%</td>
                    </tr>
                @empty
                    <tr><td colspan="4">Hali market profile yo'q.</td></tr>
                @endforelse
                </tbody>
            </table>
        </article>
    </section>

    <article class="card" style="margin-top: 14px;">
        <h2 class="section-title">Instrument Comparison</h2>
        <table class="table">
            <thead><tr><th>Symbol</th><th>Timeframe</th><th>Regime</th><th>News Sensitivity</th><th>Volatility</th><th>Trend Cleanliness</th></tr></thead>
            <tbody>
            @forelse ($profiles as $profile)
                <tr>
                    <td>{{ $profile->symbol }}</td>
                    <td>{{ $profile->timeframe }}</td>
                    <td>{{ $profile->current_regime ?? 'unknown' }}</td>
                    <td>{{ round((float) $profile->news_sensitivity_score, 2) }}%</td>
                    <td>{{ round((float) $profile->volatility_profile_score, 2) }}%</td>
                    <td>{{ round((float) $profile->trend_cleanliness_score, 2) }}%</td>
                </tr>
            @empty
                <tr><td colspan="6">Hali comparison yo'q.</td></tr>
            @endforelse
            </tbody>
        </table>
    </article>
@endsection
