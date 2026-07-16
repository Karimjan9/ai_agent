@extends('layouts.app', [
    'heading' => 'Agent Mind',
    'subtitle' => "Agentlarning confidence, stress, trust, adaptation pressure, memory va reputation holati.",
])

@section('content')
    <section class="grid metrics">
        <article class="card tone-blue">
            <div class="metric-label">Agents</div>
            <div class="metric-value">{{ $metrics['agents'] }}</div>
        </article>
        <article class="card tone-red">
            <div class="metric-label">Stressed</div>
            <div class="metric-value">{{ $metrics['stressed'] }}</div>
        </article>
        <article class="card tone-green">
            <div class="metric-label">Avg Confidence</div>
            <div class="metric-value">{{ $metrics['avg_confidence'] }}%</div>
        </article>
        <article class="card tone-yellow">
            <div class="metric-label">Avg Stress</div>
            <div class="metric-value">{{ $metrics['avg_stress'] }}%</div>
        </article>
        <article class="card tone-green">
            <div class="metric-label">Avg Trust</div>
            <div class="metric-value">{{ $metrics['avg_trust'] }}%</div>
        </article>
        <article class="card tone-red">
            <div class="metric-label">Evolution Triggers</div>
            <div class="metric-value">{{ $metrics['triggers'] }}</div>
        </article>
    </section>

    <article class="card" style="margin-top: 14px;">
        <h2 class="section-title">Psychology</h2>
        <table class="table">
            <thead>
            <tr>
                <th>Agent</th>
                <th>State</th>
                <th>Confidence</th>
                <th>Stress</th>
                <th>Trust</th>
                <th>Adaptation</th>
                <th>Stability</th>
                <th>Learning</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($latestByStrategy as $snapshot)
                <tr>
                    <td>{{ strtoupper($snapshot->strategy) }}</td>
                    <td>{{ strtoupper($snapshot->state) }}</td>
                    <td>{{ $snapshot->confidence }}%</td>
                    <td>{{ $snapshot->stress }}%</td>
                    <td>{{ $snapshot->trust }}%</td>
                    <td>{{ $snapshot->adaptation_pressure }}%</td>
                    <td>{{ $snapshot->stability }}%</td>
                    <td>{{ $snapshot->learning_rate }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">Hali psychology snapshot yo'q.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </article>

    <section class="split">
        <article class="card">
            <h2 class="section-title">Reputation</h2>
            <table class="table">
                <thead>
                <tr>
                    <th>Agent</th>
                    <th>Reputation</th>
                    <th>Trust</th>
                    <th>Calibration</th>
                    <th>Sessions</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($reputations as $reputation)
                    <tr>
                        <td>{{ strtoupper($reputation->strategy) }}</td>
                        <td>{{ $reputation->reputation_score }}</td>
                        <td>{{ $reputation->trust_score }}</td>
                        <td>{{ $reputation->calibration_score }}</td>
                        <td>{{ $reputation->sessions_count }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5">Hali reputation yo'q.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </article>

        <article class="card">
            <h2 class="section-title">Evolution Triggers</h2>
            <table class="table">
                <thead>
                <tr>
                    <th>Agent</th>
                    <th>Type</th>
                    <th>Value</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($triggers as $trigger)
                    <tr>
                        <td>{{ strtoupper($trigger->strategy) }}</td>
                        <td>{{ $trigger->trigger_type }}</td>
                        <td>{{ $trigger->trigger_value }}%</td>
                        <td>{{ strtoupper($trigger->status) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">Hali trigger yo'q.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </article>
    </section>

    <article class="card" style="margin-top: 14px;">
        <h2 class="section-title">Self Reflections</h2>
        <table class="table">
            <thead>
            <tr>
                <th>Agent</th>
                <th>Reflection</th>
                <th>Suggested Action</th>
                <th>Stress</th>
                <th>Adaptation</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($reflections as $reflection)
                <tr>
                    <td>{{ strtoupper($reflection->strategy) }}</td>
                    <td>{{ $reflection->reflection }}</td>
                    <td>{{ $reflection->suggested_action }}</td>
                    <td>{{ $reflection->stress }}%</td>
                    <td>{{ $reflection->adaptation_pressure }}%</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5">Hali self reflection yo'q.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </article>

    <section class="split">
        <article class="card">
            <h2 class="section-title">Memory</h2>
            <table class="table">
                <thead>
                <tr>
                    <th>Agent</th>
                    <th>Type</th>
                    <th>Regime</th>
                    <th>Lesson</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($memories as $memory)
                    <tr>
                        <td>{{ strtoupper($memory->strategy) }}</td>
                        <td>{{ $memory->memory_type }}</td>
                        <td>{{ $memory->market_regime ?? '-' }}</td>
                        <td>{{ $memory->lesson }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">Hali memory yo'q.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </article>

        <article class="card">
            <h2 class="section-title">Internal Debate</h2>
            @forelse ($debates as $debate)
                <div style="border-top: 1px solid var(--line); padding-top: 12px; margin-top: 12px;">
                    <div class="metric-label">Session #{{ $debate->training_session_id }} · Final {{ $debate->final_decision }} · Consensus {{ $debate->consensus_score }}</div>
                    <table class="table">
                        <tbody>
                        @foreach ($debate->arguments as $argument)
                            <tr>
                                <td>{{ strtoupper($argument->strategy) }}</td>
                                <td>{{ $argument->stance }}</td>
                                <td>{{ $argument->argument }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @empty
                <p class="muted">Hali internal debate yo'q.</p>
            @endforelse
        </article>
    </section>
@endsection
