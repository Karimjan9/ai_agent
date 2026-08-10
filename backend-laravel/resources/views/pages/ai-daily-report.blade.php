@extends('layouts.app', [
    'heading' => 'AI Daily Report',
    'subtitle' => 'Canonical lab evidence asosidagi kunlik xulosa va keyingi trening rejasi.',
])

@section('content')
    <article class="card">
        <h2 class="section-title">Bugungi report</h2>
        @if ($dailyReport)
            <table class="table">
                <tbody>
                <tr>
                    <th>Sana</th>
                    <td>{{ $dailyReport->report_date->toDateString() }}</td>
                </tr>
                <tr>
                    <th>Strategiya</th>
                    <td>{{ $dailyReport->strategy ?? '-' }}</td>
                </tr>
                <tr>
                    <th>Symbol</th>
                    <td>{{ $dailyReport->symbol ?? '-' }}</td>
                </tr>
                <tr>
                    <th>Timeframe</th>
                    <td>{{ $dailyReport->timeframe ?? '-' }}</td>
                </tr>
                <tr>
                    <th>Backtests</th>
                    <td>{{ $dailyReport->total_backtests }}</td>
                </tr>
                <tr>
                    <th>Trades</th>
                    <td>{{ $dailyReport->total_trades }}</td>
                </tr>
                <tr>
                    <th>Wins / Losses</th>
                    <td>{{ $dailyReport->total_wins }} / {{ $dailyReport->total_losses }}</td>
                </tr>
                <tr>
                    <th>Average winrate</th>
                    <td>{{ $dailyReport->average_winrate }}%</td>
                </tr>
                <tr>
                    <th>Average profit</th>
                    <td>{{ $dailyReport->average_profit }}%</td>
                </tr>
                </tbody>
            </table>
        @else
            <p class="muted">Hali daily report yaratilmagan.</p>
        @endif
    </article>

    @if ($dailyReport)
        <section class="split">
            <article class="card">
                <h2 class="section-title">Eng ko'p xatolar</h2>
                @if (!empty($dailyReport->top_mistakes))
                    <table class="table">
                        <tbody>
                        @foreach ($dailyReport->top_mistakes as $mistake)
                            <tr>
                                <td>{{ $mistake['type'] }}</td>
                                <td>{{ $mistake['count'] }} ta</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @else
                    <p class="muted">Xato klassifikatsiyasi topilmadi.</p>
                @endif
            </article>
            <article class="card">
                <h2 class="section-title">Keyingi trening rejasi</h2>
                <p class="muted">{{ $dailyReport->next_training_plan }}</p>
            </article>
        </section>

        <article class="card" style="margin-top: 14px;">
            <h2 class="section-title">AI xulosasi</h2>
            <p class="muted">{{ $dailyReport->ai_conclusion }}</p>
        </article>
    @endif
@endsection
