@extends('layouts.app', [
    'heading' => 'Reality Center',
    'subtitle' => 'Knowledge, law va theorylarni model realitydan real/paper market evidence bilan ajratib tekshiradi.',
])

@section('content')
    <section class="grid metrics">
        <article class="card tone-blue"><div class="metric-label">Runs</div><div class="metric-value">{{ $metrics['runs'] }}</div></article>
        <article class="card tone-green"><div class="metric-label">Reality Scores</div><div class="metric-value">{{ $metrics['scores'] }}</div></article>
        <article class="card tone-green"><div class="metric-label">Certified</div><div class="metric-value">{{ $metrics['certified'] }}</div></article>
        <article class="card tone-red"><div class="metric-label">Failed</div><div class="metric-value">{{ $metrics['failed'] }}</div></article>
        <article class="card tone-yellow"><div class="metric-label">Skeptic Reports</div><div class="metric-value">{{ $metrics['skeptic_reports'] }}</div></article>
        <article class="card tone-blue"><div class="metric-label">Avg Reality</div><div class="metric-value">{{ $metrics['avg_reality_score'] }}%</div></article>
    </section>

    <article class="card" style="margin-top: 14px;">
        <h2 class="section-title">Reality Verification</h2>
        <form class="form-grid" method="POST" action="{{ route('reality-center.verify') }}">
            @csrf
            <button type="submit">Verify Reality</button>
        </form>
        <p class="muted" style="margin-top: 12px;">
            {{ $latestRun?->summary ?? "Hali reality verification run yo'q." }}
        </p>
    </article>

    <section class="split">
        <article class="card">
            <h2 class="section-title">Reality Score</h2>
            <table class="table">
                <thead><tr><th>Knowledge</th><th>Layer</th><th>Reality</th><th>Status</th></tr></thead>
                <tbody>
                @forelse ($scores as $score)
                    <tr>
                        <td>{{ $score->source_title }}</td>
                        <td>{{ $score->source_layer }}</td>
                        <td>{{ round((float) $score->reality_score, 2) }}%</td>
                        <td>{{ $score->validation_status }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4">Hali reality score yo'q.</td></tr>
                @endforelse
                </tbody>
            </table>
        </article>

        <article class="card">
            <h2 class="section-title">Certified Knowledge</h2>
            <table class="table">
                <thead><tr><th>Knowledge</th><th>Grade</th><th>Reality</th></tr></thead>
                <tbody>
                @forelse ($certified as $item)
                    <tr>
                        <td>{{ $item->title }}</td>
                        <td>{{ $item->grade }}</td>
                        <td>{{ round((float) $item->reality_score, 2) }}%</td>
                    </tr>
                @empty
                    <tr><td colspan="3">Hali certified knowledge yo'q.</td></tr>
                @endforelse
                </tbody>
            </table>
        </article>
    </section>

    <section class="split">
        <article class="card">
            <h2 class="section-title">Knowledge Cemetery</h2>
            <table class="table">
                <thead><tr><th>Knowledge</th><th>Reason</th><th>Final Reality</th></tr></thead>
                <tbody>
                @forelse ($cemetery as $entry)
                    <tr>
                        <td>{{ $entry->title }}</td>
                        <td>{{ $entry->failure_reason }}</td>
                        <td>{{ round((float) $entry->final_reality_score, 2) }}%</td>
                    </tr>
                @empty
                    <tr><td colspan="3">Hali cemetery entry yo'q.</td></tr>
                @endforelse
                </tbody>
            </table>
        </article>

        <article class="card">
            <h2 class="section-title">Reality Validation</h2>
            <table class="table">
                <thead><tr><th>Experiment</th><th>Status</th><th>Observed</th><th>Success</th></tr></thead>
                <tbody>
                @forelse ($experiments as $experiment)
                    <tr>
                        <td>{{ $experiment->title }}</td>
                        <td>{{ $experiment->status }}</td>
                        <td>{{ $experiment->observed_samples }}</td>
                        <td>{{ round((float) $experiment->success_rate, 2) }}%</td>
                    </tr>
                @empty
                    <tr><td colspan="4">Hali reality experiment yo'q.</td></tr>
                @endforelse
                </tbody>
            </table>
        </article>
    </section>

    <section class="split">
        <article class="card">
            <h2 class="section-title">Skeptic Reports</h2>
            <table class="table">
                <thead><tr><th>Verdict</th><th>Risk</th><th>Objection</th></tr></thead>
                <tbody>
                @forelse ($skepticReports as $report)
                    <tr>
                        <td>{{ $report->verdict }}</td>
                        <td>{{ round((float) $report->false_discovery_risk, 2) }}%</td>
                        <td>{{ $report->objections }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3">Hali skeptic report yo'q.</td></tr>
                @endforelse
                </tbody>
            </table>
        </article>

        <article class="card">
            <h2 class="section-title">Validation Timeline</h2>
            <table class="table">
                <thead><tr><th>Knowledge</th><th>Event</th><th>Reality</th></tr></thead>
                <tbody>
                @forelse ($events as $event)
                    <tr>
                        <td>{{ $event->score?->source_title }}</td>
                        <td>{{ $event->event_type }}</td>
                        <td>{{ round((float) $event->new_reality_score, 2) }}%</td>
                    </tr>
                @empty
                    <tr><td colspan="3">Hali validation event yo'q.</td></tr>
                @endforelse
                </tbody>
            </table>
        </article>
    </section>
@endsection
