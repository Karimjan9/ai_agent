@extends('layouts.app', [
    'heading' => 'AI Daily Reports',
    'subtitle' => 'Kunlik AI training reportlar tarixi.',
])

@section('content')
    <article class="card">
        <h2 class="section-title">Reportlar</h2>
        <table class="table">
            <thead>
            <tr>
                <th>Date</th>
                <th>Backtests</th>
                <th>Trades</th>
                <th>Winrate</th>
                <th>Profit</th>
                <th>Action</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($reports as $report)
                <tr>
                    <td>{{ $report->report_date->format('Y-m-d') }}</td>
                    <td>{{ $report->total_backtests }}</td>
                    <td>{{ $report->total_trades }}</td>
                    <td>{{ $report->average_winrate }}%</td>
                    <td>{{ $report->average_profit }}%</td>
                    <td>
                        <a class="muted" href="{{ route('daily-reports.show', $report) }}">
                            Ko'rish
                        </a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">Hali daily report yaratilmagan.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </article>

    @if ($reports->hasPages())
        <article class="card" style="margin-top: 14px;">
            {{ $reports->links() }}
        </article>
    @endif
@endsection
