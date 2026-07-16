@extends('layouts.app', [
    'heading' => 'Evolution Lab',
    'subtitle' => "Strategy genome, mutation, lineage, crossover, extinction va discovery laboratoriyasi.",
])

@section('content')
    <section class="grid metrics">
        <article class="card tone-blue">
            <div class="metric-label">Genomes</div>
            <div class="metric-value">{{ $metrics['genomes'] }}</div>
        </article>
        <article class="card tone-green">
            <div class="metric-label">Alive</div>
            <div class="metric-value">{{ $metrics['alive'] }}</div>
        </article>
        <article class="card tone-red">
            <div class="metric-label">Archived</div>
            <div class="metric-value">{{ $metrics['archived'] }}</div>
        </article>
        <article class="card tone-yellow">
            <div class="metric-label">Mutations</div>
            <div class="metric-value">{{ $metrics['mutations'] }}</div>
        </article>
        <article class="card tone-green">
            <div class="metric-label">Crossovers</div>
            <div class="metric-value">{{ $metrics['crossovers'] }}</div>
        </article>
        <article class="card tone-blue">
            <div class="metric-label">Discoveries</div>
            <div class="metric-value">{{ $metrics['discoveries'] }}</div>
        </article>
    </section>

    <article class="card" style="margin-top: 14px;">
        <h2 class="section-title">Genome Tree</h2>
        <table class="table">
            <thead>
            <tr>
                <th>Genome</th>
                <th>Family</th>
                <th>Generation</th>
                <th>Fitness</th>
                <th>Efficiency</th>
                <th>Status</th>
                <th>Parents</th>
                <th>Children</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($genomes as $genome)
                <tr>
                    <td>{{ strtoupper($genome->strategy) }}</td>
                    <td>{{ $genome->family }}</td>
                    <td>{{ $genome->generation }}</td>
                    <td>{{ $genome->fitness_score }}</td>
                    <td>{{ $genome->evolution_efficiency }}%</td>
                    <td>{{ strtoupper($genome->status) }}</td>
                    <td>{{ $genome->parentLineages->pluck('parentGenome.strategy')->filter()->map(fn ($item) => strtoupper($item))->implode(', ') ?: '-' }}</td>
                    <td>{{ $genome->childLineages->pluck('childGenome.strategy')->filter()->map(fn ($item) => strtoupper($item))->implode(', ') ?: '-' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">Hali genome yozilmagan.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </article>

    <section class="split">
        <article class="card">
            <h2 class="section-title">Mutations</h2>
            <table class="table">
                <thead>
                <tr>
                    <th>Parent</th>
                    <th>Child</th>
                    <th>Type</th>
                    <th>Diff</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($mutations as $mutation)
                    <tr>
                        <td>{{ strtoupper($mutation->parentGenome?->strategy ?? '-') }}</td>
                        <td>{{ strtoupper($mutation->childGenome?->strategy ?? '-') }}</td>
                        <td>{{ $mutation->mutation_type }}</td>
                        <td><pre class="code" style="min-height:auto;">{{ json_encode($mutation->mutation_diff, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">Hali mutation yo'q.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </article>

        <article class="card">
            <h2 class="section-title">Cross Breeding</h2>
            <table class="table">
                <thead>
                <tr>
                    <th>Parent A</th>
                    <th>Parent B</th>
                    <th>Child</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($crossovers as $crossover)
                    <tr>
                        <td>{{ strtoupper($crossover->parentA?->strategy ?? '-') }}</td>
                        <td>{{ strtoupper($crossover->parentB?->strategy ?? '-') }}</td>
                        <td>{{ strtoupper($crossover->child_strategy) }}</td>
                        <td>{{ strtoupper($crossover->status) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">Hali crossover candidate yo'q.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </article>
    </section>

    <section class="split">
        <article class="card">
            <h2 class="section-title">Evolution Efficiency</h2>
            <table class="table">
                <thead>
                <tr>
                    <th>Genome</th>
                    <th>Fitness</th>
                    <th>Summary</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($fitnessEvaluations as $evaluation)
                    <tr>
                        <td>{{ strtoupper($evaluation->strategyGenome?->strategy ?? '-') }}</td>
                        <td>{{ $evaluation->fitness_score }}</td>
                        <td>{{ $evaluation->evaluation_summary }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3">Hali fitness evaluation yo'q.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </article>

        <article class="card">
            <h2 class="section-title">Genome Heatmap</h2>
            <table class="table">
                <thead>
                <tr>
                    <th>Gene</th>
                    <th>Range</th>
                    <th>Count</th>
                    <th>Avg Fitness</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($geneHeatmap as $gene => $stats)
                    <tr>
                        <td>{{ $gene }}</td>
                        <td>{{ $stats['min'] }} - {{ $stats['max'] }}</td>
                        <td>{{ $stats['count'] }}</td>
                        <td>{{ $stats['avg_fitness'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">Numeric gene heatmap hali yo'q.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </article>
    </section>

    <section class="split">
        <article class="card">
            <h2 class="section-title">Extinct Agents</h2>
            <table class="table">
                <thead>
                <tr>
                    <th>Genome</th>
                    <th>Reason</th>
                    <th>Extinct At</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($extinctions as $event)
                    <tr>
                        <td>{{ strtoupper($event->strategyGenome?->strategy ?? '-') }}</td>
                        <td>{{ $event->reason }}</td>
                        <td>{{ optional($event->extinct_at)->format('Y-m-d H:i') ?? '-' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3">Hali archived/extinct genome yo'q.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </article>

        <article class="card">
            <h2 class="section-title">Discoveries</h2>
            <table class="table">
                <thead>
                <tr>
                    <th>Discovery</th>
                    <th>Confidence</th>
                    <th>Evidence</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($discoveries as $discovery)
                    <tr>
                        <td>{{ $discovery->discovery }}</td>
                        <td>{{ $discovery->confidence_score }}%</td>
                        <td>{{ $discovery->evidence_count }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3">Hali genome discovery yo'q.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </article>
    </section>
@endsection
