@extends('layouts.app', [
    'heading' => 'Theory Lab',
    'subtitle' => 'Pattern, law va cause evidence qatlamlarini yuqori darajadagi quant nazariyalarga birlashtiradi.',
])

@section('content')
    <section class="grid metrics">
        <article class="card tone-blue"><div class="metric-label">Runs</div><div class="metric-value">{{ $metrics['runs'] }}</div></article>
        <article class="card tone-green"><div class="metric-label">Theories</div><div class="metric-value">{{ $metrics['theories'] }}</div></article>
        <article class="card tone-yellow"><div class="metric-label">Dominant</div><div class="metric-value">{{ $metrics['dominant'] }}</div></article>
        <article class="card tone-green"><div class="metric-label">Accepted</div><div class="metric-value">{{ $metrics['accepted'] }}</div></article>
        <article class="card tone-blue"><div class="metric-label">Battles</div><div class="metric-value">{{ $metrics['battles'] }}</div></article>
        <article class="card tone-red"><div class="metric-label">Predictions</div><div class="metric-value">{{ $metrics['predictions'] }}</div></article>
    </section>

    <article class="card" style="margin-top: 14px;">
        <h2 class="section-title">Autonomous Theory Generation</h2>
        <form class="form-grid" method="POST" action="{{ route('theory-lab.generate') }}">
            @csrf
            <button type="submit">Generate Theories</button>
        </form>
        <p class="muted" style="margin-top: 12px;">
            {{ $latestRun?->summary ?? "Hali theory generation run yo'q." }}
        </p>
    </article>

    <section class="split">
        <article class="card">
            <h2 class="section-title">Emerging & Dominant Theories</h2>
            <table class="table">
                <thead><tr><th>Theory</th><th>Status</th><th>Confidence</th><th>Evidence</th></tr></thead>
                <tbody>
                @forelse ($theories as $theory)
                    <tr>
                        <td>{{ $theory->title }}</td>
                        <td>{{ $theory->status }}</td>
                        <td>{{ round((float) $theory->confidence_score, 2) }}%</td>
                        <td>{{ $theory->components_count }} components</td>
                    </tr>
                @empty
                    <tr><td colspan="4">Hali theory yo'q.</td></tr>
                @endforelse
                </tbody>
            </table>
        </article>

        <article class="card">
            <h2 class="section-title">Theory Battles</h2>
            <table class="table">
                <thead><tr><th>Battle</th><th>Status</th><th>Winner</th></tr></thead>
                <tbody>
                @forelse ($battles as $battle)
                    <tr>
                        <td>{{ $battle->theoryA?->title }} vs {{ $battle->theoryB?->title }}</td>
                        <td>{{ $battle->status }}</td>
                        <td>{{ $battle->winner?->title ?? 'contested' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3">Hali theory battle yo'q.</td></tr>
                @endforelse
                </tbody>
            </table>
        </article>
    </section>

    <section class="split">
        <article class="card">
            <h2 class="section-title">Theory Predictions</h2>
            <table class="table">
                <thead><tr><th>Theory</th><th>Target</th><th>Delta</th><th>Status</th></tr></thead>
                <tbody>
                @forelse ($predictions as $prediction)
                    <tr>
                        <td>{{ $prediction->theory?->title }}</td>
                        <td>{{ $prediction->target_metric }}</td>
                        <td>{{ round((float) $prediction->predicted_delta, 2) }}</td>
                        <td>{{ $prediction->status }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4">Hali theory prediction yo'q.</td></tr>
                @endforelse
                </tbody>
            </table>
        </article>

        <article class="card">
            <h2 class="section-title">Unified Models</h2>
            <table class="table">
                <thead><tr><th>Model</th><th>Status</th><th>Confidence</th><th>Theories</th></tr></thead>
                <tbody>
                @forelse ($models as $model)
                    <tr>
                        <td>{{ $model->title }}</td>
                        <td>{{ $model->status }}</td>
                        <td>{{ round((float) $model->confidence_score, 2) }}%</td>
                        <td>{{ $model->theory_count }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4">Hali unified model yo'q.</td></tr>
                @endforelse
                </tbody>
            </table>
        </article>
    </section>

    <section class="split">
        <article class="card">
            <h2 class="section-title">Theory Components</h2>
            <table class="table">
                <thead><tr><th>Theory</th><th>Component</th><th>Contribution</th></tr></thead>
                <tbody>
                @forelse ($components as $component)
                    <tr>
                        <td>{{ $component->theory?->title }}</td>
                        <td>{{ $component->component_type }}</td>
                        <td>{{ round((float) $component->contribution_score, 2) }}%</td>
                    </tr>
                @empty
                    <tr><td colspan="3">Hali component yo'q.</td></tr>
                @endforelse
                </tbody>
            </table>
        </article>

        <article class="card">
            <h2 class="section-title">Theory Evolution</h2>
            <table class="table">
                <thead><tr><th>Theory</th><th>Event</th><th>Confidence</th></tr></thead>
                <tbody>
                @forelse ($events as $event)
                    <tr>
                        <td>{{ $event->theory?->title }}</td>
                        <td>{{ $event->event_type }}</td>
                        <td>{{ round((float) $event->new_confidence, 2) }}%</td>
                    </tr>
                @empty
                    <tr><td colspan="3">Hali evolution event yo'q.</td></tr>
                @endforelse
                </tbody>
            </table>
        </article>
    </section>
@endsection
