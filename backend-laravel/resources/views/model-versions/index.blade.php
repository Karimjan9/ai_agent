@extends('layouts.app', [
    'heading' => 'Model Versions',
    'subtitle' => 'Strategiya agentlarining versiya tarixi va eng yaxshi natijalari.',
])

@section('content')
    <article class="card" style="margin-bottom: 14px;">
        <h2 class="section-title">Model Status Distribution</h2>
        <canvas id="modelStatusChart" height="100"></canvas>
    </article>

    <article class="card">
        <h2 class="section-title">Version History</h2>
        <table class="table">
            <thead>
            <tr>
                <th>Strategy</th>
                <th>Version</th>
                <th>Generation</th>
                <th>Status</th>
                <th>Best Score</th>
                <th>Best Winrate</th>
                <th>Best Profit</th>
                <th>Best Drawdown</th>
                <th>Promoted</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($versions as $version)
                <tr>
                    <td>{{ strtoupper($version->strategy ?? $version->name) }}</td>
                    <td>{{ $version->version ?? '-' }}</td>
                    <td>{{ $version->generation ?? '-' }}</td>
                    <td>
                        @php
                            $statusTone = match ($version->status) {
                                'active' => 'tone-green',
                                'rejected' => 'tone-red',
                                'testing' => 'tone-yellow',
                                default => 'tone-blue',
                            };
                        @endphp
                        <span class="{{ $statusTone }}" style="display:inline-block; border-radius:8px; padding:4px 8px;">
                            {{ $version->status }}
                        </span>
                    </td>
                    <td>{{ $version->best_score }}</td>
                    <td>{{ $version->best_winrate }}%</td>
                    <td>{{ $version->best_profit }}%</td>
                    <td>{{ $version->best_drawdown }}%</td>
                    <td>{{ $version->promoted_at?->format('Y-m-d H:i') ?? '-' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="9">Hali model version yozuvlari yo'q.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </article>

    @if ($versions->hasPages())
        <article class="card" style="margin-top: 14px;">
            {{ $versions->links() }}
        </article>
    @endif

    @push('scripts')
        <script>
            const statusLabels = @json($statusCounts->keys()->values());
            const statusData = @json($statusCounts->values());

            const modelStatusCanvas = document.getElementById('modelStatusChart');
            if (modelStatusCanvas) {
                new Chart(modelStatusCanvas, {
                    type: 'doughnut',
                    data: {
                        labels: statusLabels,
                        datasets: [{
                            label: 'Models',
                            data: statusData
                        }]
                    },
                    options: {
                        responsive: true
                    }
                });
            }
        </script>
    @endpush
@endsection
