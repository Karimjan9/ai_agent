@extends('layouts.app', [
    'heading' => 'Meta Intelligence',
    'subtitle' => 'Knowledge Base o‘zini tekshiradi: audit, belief decay, contradictions, unknown zones, blind spots va self critique.',
])

@section('content')
    <section class="grid metrics">
        <article class="card tone-blue">
            <div class="metric-label">Audit Runs</div>
            <div class="metric-value">{{ $metrics['runs'] }}</div>
        </article>
        <article class="card tone-green">
            <div class="metric-label">Knowledge Health</div>
            <div class="metric-value">{{ round((float) $metrics['health'], 2) }}%</div>
        </article>
        <article class="card tone-yellow">
            <div class="metric-label">Knowledge Audits</div>
            <div class="metric-value">{{ $metrics['audits'] }}</div>
        </article>
        <article class="card tone-red">
            <div class="metric-label">Open Conflicts</div>
            <div class="metric-value">{{ $metrics['contradictions'] }}</div>
        </article>
        <article class="card tone-yellow">
            <div class="metric-label">Unknown Zones</div>
            <div class="metric-value">{{ $metrics['unknown_zones'] }}</div>
        </article>
        <article class="card tone-red">
            <div class="metric-label">Blind Spots</div>
            <div class="metric-value">{{ $metrics['blind_spots'] }}</div>
        </article>
    </section>

    <article class="card" style="margin-top: 14px;">
        <h2 class="section-title">Knowledge Health Score</h2>
        <form class="form-grid" method="POST" action="{{ route('meta-intelligence.audit') }}">
            @csrf
            <button type="submit">Run Meta Audit</button>
        </form>
        @if ($latestRun)
            <p class="muted" style="margin-top: 12px;">
                Latest audit #{{ $latestRun->id }}: {{ $latestRun->summary }}
            </p>
        @else
            <p class="muted" style="margin-top: 12px;">Hali Meta Intelligence audit yo'q.</p>
        @endif
    </article>

    <section class="split">
        <article class="card">
            <h2 class="section-title">Knowledge Audit</h2>
            <table class="table">
                <thead>
                <tr>
                    <th>Claim</th>
                    <th>Original</th>
                    <th>Audited</th>
                    <th>Verdict</th>
                    <th>Action</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($knowledgeAudits as $audit)
                    <tr>
                        <td>{{ $audit->knowledgeClaim?->title ?? 'Claim #'.$audit->knowledge_claim_id }}</td>
                        <td>{{ round((float) $audit->original_confidence, 2) }}%</td>
                        <td>{{ round((float) $audit->audited_confidence, 2) }}%</td>
                        <td>{{ $audit->verdict }}</td>
                        <td>{{ $audit->recommended_action }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5">Hali knowledge audit yo'q.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </article>

        <article class="card">
            <h2 class="section-title">Belief Health</h2>
            <table class="table">
                <thead>
                <tr>
                    <th>Belief</th>
                    <th>Score</th>
                    <th>Audited</th>
                    <th>Reason</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($beliefDecays as $event)
                    <tr>
                        <td>{{ strtoupper($event->strategy) }} / {{ $event->belief_key }}</td>
                        <td>{{ round((float) $event->original_score, 2) }}%</td>
                        <td>{{ round((float) $event->decayed_score, 2) }}%</td>
                        <td>{{ $event->reason_code }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">Hali belief decay yo'q.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </article>
    </section>

    <section class="split">
        <article class="card">
            <h2 class="section-title">Contradictions</h2>
            <table class="table">
                <thead>
                <tr>
                    <th>Conflict</th>
                    <th>Severity</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($contradictions as $conflict)
                    <tr>
                        <td>{{ $conflict->summary }}</td>
                        <td>{{ round((float) $conflict->severity_score, 2) }}%</td>
                        <td>{{ $conflict->status }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3">Hali contradiction topilmadi.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </article>

        <article class="card">
            <h2 class="section-title">Self Critiques</h2>
            <table class="table">
                <thead>
                <tr>
                    <th>Critique</th>
                    <th>Severity</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($selfCritiques as $critique)
                    <tr>
                        <td>
                            <strong>{{ $critique->title }}</strong><br>
                            <span class="muted">{{ $critique->recommended_action }}</span>
                        </td>
                        <td>{{ round((float) $critique->severity_score, 2) }}%</td>
                        <td>{{ $critique->status }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3">Hali self critique yo'q.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </article>
    </section>

    <section class="split">
        <article class="card">
            <h2 class="section-title">Unknown Zones</h2>
            <table class="table">
                <thead>
                <tr>
                    <th>Market</th>
                    <th>Similarity</th>
                    <th>Uncertainty</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($unknownZones as $zone)
                    <tr>
                        <td>{{ $zone->symbol }} {{ $zone->timeframe }} / {{ $zone->market_species ?? $zone->market_state }}</td>
                        <td>{{ round((float) $zone->similarity_score, 2) }}%</td>
                        <td>{{ round((float) $zone->uncertainty_score, 2) }}%</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3">Hali unknown zone yo'q.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </article>

        <article class="card">
            <h2 class="section-title">Blind Spots</h2>
            <table class="table">
                <thead>
                <tr>
                    <th>Spot</th>
                    <th>Priority</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($blindSpots as $spot)
                    <tr>
                        <td>{{ $spot->label }}</td>
                        <td>{{ round((float) $spot->priority_score, 2) }}%</td>
                        <td>{{ $spot->status }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3">Hali blind spot yo'q.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </article>
    </section>

    <article class="card" style="margin-top: 14px;">
        <h2 class="section-title">Health Timeline</h2>
        <table class="table">
            <thead>
            <tr>
                <th>Audit</th>
                <th>Overall</th>
                <th>Fresh</th>
                <th>Aging</th>
                <th>Contradiction</th>
                <th>Unknown</th>
                <th>Blind Spot</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($healthScores as $score)
                <tr>
                    <td>#{{ $score->meta_audit_run_id }}</td>
                    <td>{{ round((float) $score->overall_score, 2) }}%</td>
                    <td>{{ round((float) $score->fresh_discoveries_score, 2) }}%</td>
                    <td>{{ round((float) $score->aging_discoveries_score, 2) }}%</td>
                    <td>{{ round((float) $score->contradiction_score, 2) }}%</td>
                    <td>{{ round((float) $score->unknown_zone_score, 2) }}%</td>
                    <td>{{ round((float) $score->blind_spot_score, 2) }}%</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">Hali health timeline yo'q.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </article>
@endsection
