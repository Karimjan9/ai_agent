@extends('layouts.app', [
    'heading' => 'DNA Laboratory',
    'subtitle' => 'Strategiya agentlarining xarakteri, moslashuvchanligi va survival profilini solishtirish.',
])

@section('content')
    <section class="grid metrics">
        <article class="card tone-red">
            <div class="metric-label">Most Aggressive Agent</div>
            <div class="metric-value">{{ strtoupper($mostAggressive?->strategyScore?->strategy ?? '-') }}</div>
            <p class="muted">{{ $mostAggressive?->aggression_score ?? 0 }}</p>
        </article>
        <article class="card tone-blue">
            <div class="metric-label">Most Adaptive Agent</div>
            <div class="metric-value">{{ strtoupper($mostAdaptive?->strategyScore?->strategy ?? '-') }}</div>
            <p class="muted">{{ $mostAdaptive?->adaptability_score ?? 0 }}</p>
        </article>
        <article class="card tone-green">
            <div class="metric-label">Highest Survival Agent</div>
            <div class="metric-value">{{ strtoupper($highestSurvival?->strategyScore?->strategy ?? '-') }}</div>
            <p class="muted">{{ $highestSurvival?->survival_score ?? 0 }}</p>
        </article>
        <article class="card tone-yellow">
            <div class="metric-label">Best Recovery Agent</div>
            <div class="metric-value">{{ strtoupper($bestRecovery?->strategyScore?->strategy ?? '-') }}</div>
            <p class="muted">{{ $bestRecovery?->recovery_score ?? 0 }}</p>
        </article>
    </section>

    <article class="card" style="margin-top: 14px;">
        <h2 class="section-title">DNA History</h2>
        <table class="table">
            <thead>
            <tr>
                <th>Strategy</th>
                <th>Aggression</th>
                <th>Trend</th>
                <th>Range</th>
                <th>Volatility</th>
                <th>Adaptability</th>
                <th>Recovery</th>
                <th>Survival</th>
                <th>Summary</th>
                <th>Created</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($profiles as $profile)
                <tr>
                    <td>{{ strtoupper($profile->strategyScore?->strategy ?? '-') }}</td>
                    <td>{{ $profile->aggression_score ?? '-' }}</td>
                    <td>{{ $profile->trend_dependency ?? '-' }}</td>
                    <td>{{ $profile->range_dependency ?? '-' }}</td>
                    <td>{{ $profile->volatility_sensitivity ?? '-' }}</td>
                    <td>{{ $profile->adaptability_score ?? '-' }}</td>
                    <td>{{ $profile->recovery_score ?? '-' }}</td>
                    <td>{{ $profile->survival_score ?? '-' }}</td>
                    <td class="muted">{{ $profile->dna_summary ?? '-' }}</td>
                    <td>{{ $profile->created_at?->format('Y-m-d H:i') ?? '-' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="10">Hali DNA profile yaratilmagan.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </article>

    @if ($profiles->hasPages())
        <article class="card" style="margin-top: 14px;">
            {{ $profiles->links() }}
        </article>
    @endif
@endsection
