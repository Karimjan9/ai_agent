@extends('layouts.app', [
    'heading' => 'Quant Laws',
    'subtitle' => 'Universal Laws Discovery Engine: law candidates, validated laws, evidence, conflicts, law graph va universal driver ranking.',
])

@section('content')
    <section class="grid metrics">
        <article class="card tone-blue">
            <div class="metric-label">Discovery Runs</div>
            <div class="metric-value">{{ $metrics['runs'] }}</div>
        </article>
        <article class="card tone-green">
            <div class="metric-label">Universal Laws</div>
            <div class="metric-value">{{ $metrics['laws'] }}</div>
        </article>
        <article class="card tone-green">
            <div class="metric-label">Active Laws</div>
            <div class="metric-value">{{ $metrics['active_laws'] }}</div>
        </article>
        <article class="card tone-yellow">
            <div class="metric-label">Law Candidates</div>
            <div class="metric-value">{{ $metrics['candidates'] }}</div>
        </article>
        <article class="card tone-red">
            <div class="metric-label">Conflicts</div>
            <div class="metric-value">{{ $metrics['conflicts'] }}</div>
        </article>
        <article class="card tone-blue">
            <div class="metric-label">Top Drivers</div>
            <div class="metric-value">{{ $metrics['drivers'] }}</div>
        </article>
    </section>

    <article class="card" style="margin-top: 14px;">
        <h2 class="section-title">Law Candidate Engine</h2>
        <form class="form-grid" method="POST" action="{{ route('quant-laws.discover') }}">
            @csrf
            <button type="submit">Discover Laws</button>
        </form>
        @if ($latestRun)
            <p class="muted" style="margin-top: 12px;">Latest run #{{ $latestRun->id }}: {{ $latestRun->summary }}</p>
        @else
            <p class="muted" style="margin-top: 12px;">Hali Quant Laws discovery yo'q.</p>
        @endif
    </article>

    <section class="split">
        <article class="card">
            <h2 class="section-title">Universal Laws Library</h2>
            <table class="table">
                <thead>
                <tr>
                    <th>Law</th>
                    <th>Confidence</th>
                    <th>Universality</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($laws as $law)
                    <tr>
                        <td>
                            {{ $law->title }}<br>
                            <span class="muted">{{ $law->statement }}</span>
                        </td>
                        <td>{{ round((float) $law->confidence_score, 2) }}%</td>
                        <td>{{ round((float) $law->universality_score, 2) }}%</td>
                        <td>{{ $law->status }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">Hali law yo'q.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </article>

        <article class="card">
            <h2 class="section-title">Top Drivers</h2>
            <table class="table">
                <thead>
                <tr>
                    <th>#</th>
                    <th>Driver</th>
                    <th>Impact</th>
                    <th>Confidence</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($drivers as $driver)
                    <tr>
                        <td>{{ $driver->rank }}</td>
                        <td>{{ $driver->driver_label }}</td>
                        <td>{{ round((float) $driver->impact_score, 2) }}%</td>
                        <td>{{ round((float) $driver->confidence_score, 2) }}%</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">Hali universal driver yo'q.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </article>
    </section>

    <section class="split">
        <article class="card">
            <h2 class="section-title">Emerging Law Candidates</h2>
            <table class="table">
                <thead>
                <tr>
                    <th>Candidate</th>
                    <th>Evidence</th>
                    <th>Strategies</th>
                    <th>Confidence</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($candidates as $candidate)
                    <tr>
                        <td>{{ $candidate->title }}</td>
                        <td>{{ $candidate->evidence_count }}</td>
                        <td>{{ $candidate->strategy_count }}</td>
                        <td>{{ round((float) $candidate->confidence_score, 2) }}%</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">Hali candidate yo'q.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </article>

        <article class="card">
            <h2 class="section-title">Law Conflicts</h2>
            <table class="table">
                <thead>
                <tr>
                    <th>Conflict</th>
                    <th>Severity</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($conflicts as $conflict)
                    <tr>
                        <td>{{ $conflict->summary }}</td>
                        <td>{{ round((float) $conflict->severity_score, 2) }}%</td>
                        <td>{{ $conflict->status }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3">Hali law conflict yo'q.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </article>
    </section>

    <section class="split">
        <article class="card">
            <h2 class="section-title">Law Graph</h2>
            <table class="table">
                <thead>
                <tr>
                    <th>Source</th>
                    <th>Relation</th>
                    <th>Target</th>
                    <th>Confidence</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($graphEdges as $edge)
                    <tr>
                        <td>{{ $edge->source_label }}</td>
                        <td>{{ $edge->relation_type }}</td>
                        <td>{{ $edge->target_label }}</td>
                        <td>{{ round((float) $edge->confidence_score, 2) }}%</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">Hali law graph edge yo'q.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </article>

        <article class="card">
            <h2 class="section-title">Evidence</h2>
            <table class="table">
                <thead>
                <tr>
                    <th>Evidence</th>
                    <th>Strategy</th>
                    <th>Confidence</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($evidences as $evidence)
                    <tr>
                        <td>{{ $evidence->summary }}</td>
                        <td>{{ $evidence->strategy ?? '-' }}</td>
                        <td>{{ round((float) $evidence->confidence_score, 2) }}%</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3">Hali evidence yo'q.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </article>
    </section>
@endsection
