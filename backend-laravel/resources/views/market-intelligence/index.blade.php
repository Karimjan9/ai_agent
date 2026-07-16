@extends('layouts.app', [
    'heading' => 'Market Intelligence',
    'subtitle' => "OHLCV state va CFTC pozitsiyalanish ma'lumotlari asosidagi read-only market intelligence. Bu sahifa hali trade yoki scoring qarorini o'zgartirmaydi.",
])

@section('content')
    <section class="split" style="margin-top: 0;">
        <article class="card tone-blue">
            <h2 class="section-title">Institutional Positioning — COT</h2>
            @if ($cotSnapshot)
                <table class="table">
                    <tbody>
                    <tr><th>Weekly Bias</th><td>{{ strtoupper($cotSnapshot->weekly_bias) }} <span class="muted">({{ str_replace('_', ' ', $cotSnapshot->positioning_state) }})</span></td></tr>
                    <tr><th>Managed Money Net</th><td>{{ number_format($cotSnapshot->managed_money_net) }}</td></tr>
                    <tr><th>1 Week Delta</th><td>{{ $cotSnapshot->managed_money_delta_1w === null ? '-' : number_format($cotSnapshot->managed_money_delta_1w) }}</td></tr>
                    <tr><th>3 Year Percentile</th><td>{{ number_format((float) $cotSnapshot->managed_money_percentile_3y, 2) }}%</td></tr>
                    <tr><th>Crowding</th><td>{{ number_format((float) $cotSnapshot->crowding_index, 2) }}/100</td></tr>
                    <tr><th>Open Interest</th><td>{{ number_format((int) $cotSnapshot->report?->open_interest) }}</td></tr>
                    </tbody>
                </table>
                <p class="muted" style="margin-top: 12px;">Report date: {{ $cotSnapshot->report_date->format('Y-m-d') }}. Available from: {{ $cotSnapshot->available_at->timezone('America/New_York')->format('Y-m-d H:i T') }}@if($cotSnapshot->report?->release_time_estimated) (estimated standard release time)@endif.</p>
            @else
                <p class="muted">COT ma'lumoti hali import qilinmagan. `php artisan market-intelligence:sync-cot` buyrug'ini ishga tushiring.</p>
            @endif
        </article>

        <article class="card tone-yellow">
            <h2 class="section-title">COT Safety Rule</h2>
            <p class="muted">Bu fazada COT faqat kuzatuv va AI izohi uchun. U signal, paper order, Strategy Lab score yoki model promotion qoidalariga ulanmagan.</p>
            <p class="muted">Xom CFTC satri, report date va available-at saqlanadi; keyingi walk-forward test faqat o'sha vaqtda mavjud bo'lgan feature’dan foydalanadi.</p>
        </article>
    </section>

    <article class="card" style="margin-top: 14px;">
        <h2 class="section-title">Recent COT Feature History</h2>
        <table class="table">
            <thead><tr><th>Report Date</th><th>Bias</th><th>Managed Money Net</th><th>1W Delta</th><th>Percentile</th><th>Commercial Net</th></tr></thead>
            <tbody>
            @forelse ($cotHistory as $snapshot)
                <tr>
                    <td>{{ $snapshot->report_date->format('Y-m-d') }}</td>
                    <td>{{ strtoupper($snapshot->weekly_bias) }}</td>
                    <td>{{ number_format($snapshot->managed_money_net) }}</td>
                    <td>{{ $snapshot->managed_money_delta_1w === null ? '-' : number_format($snapshot->managed_money_delta_1w) }}</td>
                    <td>{{ number_format((float) $snapshot->managed_money_percentile_3y, 2) }}%</td>
                    <td>{{ number_format((int) data_get($snapshot->features, 'commercial_net')) }}</td>
                </tr>
            @empty
                <tr><td colspan="6">Hali COT feature history yo'q.</td></tr>
            @endforelse
            </tbody>
        </table>
    </article>

    <section class="grid metrics">
        <article class="card tone-blue">
            <div class="metric-label">State Snapshots</div>
            <div class="metric-value">{{ $metrics['snapshots'] }}</div>
        </article>
        <article class="card tone-green">
            <div class="metric-label">Species</div>
            <div class="metric-value">{{ $metrics['species'] }}</div>
        </article>
        <article class="card tone-yellow">
            <div class="metric-label">Memories</div>
            <div class="metric-value">{{ $metrics['memories'] }}</div>
        </article>
        <article class="card tone-green">
            <div class="metric-label">Discoveries</div>
            <div class="metric-value">{{ $metrics['discoveries'] }}</div>
        </article>
        <article class="card tone-blue">
            <div class="metric-label">Similarity Matches</div>
            <div class="metric-value">{{ $metrics['similarities'] }}</div>
        </article>
        <article class="card tone-red">
            <div class="metric-label">Strategy Species Links</div>
            <div class="metric-value">{{ $metrics['strategy_species'] }}</div>
        </article>
    </section>

    <section class="split">
        <article class="card">
            <h2 class="section-title">Current Market Genome</h2>
            @if ($latestSnapshot)
                <table class="table">
                    <tbody>
                    <tr>
                        <th>Species</th>
                        <td>{{ $latestSnapshot->marketSpecies?->name ?? '-' }}</td>
                    </tr>
                    <tr>
                        <th>State</th>
                        <td>{{ $latestSnapshot->market_state }}</td>
                    </tr>
                    <tr>
                        <th>Structure</th>
                        <td>{{ $latestSnapshot->structure_state }}</td>
                    </tr>
                    <tr>
                        <th>Momentum</th>
                        <td>{{ $latestSnapshot->momentum_state }}</td>
                    </tr>
                    <tr>
                        <th>Liquidity Proxy</th>
                        <td>{{ $latestSnapshot->liquidity_state }} / {{ $latestSnapshot->liquidity_proxy_score }}</td>
                    </tr>
                    <tr>
                        <th>Confidence</th>
                        <td>{{ $latestSnapshot->confidence_score }}%</td>
                    </tr>
                    </tbody>
                </table>
                <p class="muted" style="margin-top: 12px;">{{ $latestSnapshot->explanation }}</p>
            @else
                <p class="muted">Hali market reality snapshot yo'q.</p>
            @endif
        </article>

        <article class="card">
            <h2 class="section-title">Recent Market Genomes</h2>
            <table class="table">
                <thead>
                <tr>
                    <th>Time</th>
                    <th>Species</th>
                    <th>Trend</th>
                    <th>Panic</th>
                    <th>Compression</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($genomes as $genome)
                    <tr>
                        <td>{{ $genome->time->format('Y-m-d H:i') }}</td>
                        <td>{{ $genome->marketSpecies?->name ?? '-' }}</td>
                        <td>{{ $genome->trend }}</td>
                        <td>{{ $genome->panic }}</td>
                        <td>{{ $genome->compression }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5">Hali genome yo'q.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </article>
    </section>

    <article class="card" style="margin-top: 14px;">
        <h2 class="section-title">Species Library</h2>
        <table class="table">
            <thead>
            <tr>
                <th>Code</th>
                <th>Name</th>
                <th>State</th>
                <th>Danger</th>
                <th>Opportunity</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($species as $item)
                <tr>
                    <td>{{ $item->code }}</td>
                    <td>{{ $item->name }}</td>
                    <td>{{ $item->dominant_state }}</td>
                    <td>{{ $item->danger_score }}</td>
                    <td>{{ $item->opportunity_score }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5">Hali species yo'q.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </article>

    <section class="split">
        <article class="card">
            <h2 class="section-title">Market Memories</h2>
            <table class="table">
                <thead>
                <tr>
                    <th>State</th>
                    <th>Species</th>
                    <th>Lesson</th>
                    <th>Strength</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($memories as $memory)
                    <tr>
                        <td>{{ $memory->market_state }}</td>
                        <td>{{ $memory->marketSpecies?->name ?? '-' }}</td>
                        <td>{{ $memory->lesson }}</td>
                        <td>{{ $memory->strength }}</td>
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
            <h2 class="section-title">Similarity Scanner</h2>
            <table class="table">
                <thead>
                <tr>
                    <th>Current</th>
                    <th>Matched</th>
                    <th>Similarity</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($similarities as $match)
                    <tr>
                        <td>{{ $match->currentGenome?->snapshot?->marketSpecies?->name ?? '-' }}</td>
                        <td>{{ $match->matchedGenome?->time?->format('Y-m-d H:i') }} / {{ $match->matchedGenome?->snapshot?->marketSpecies?->name ?? '-' }}</td>
                        <td>{{ $match->similarity_score }}%</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3">Hali similarity match yo'q.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </article>
    </section>

    <section class="split">
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
                        <td colspan="3">Hali discovery yo'q.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </article>

        <article class="card">
            <h2 class="section-title">Strategy x Species</h2>
            <table class="table">
                <thead>
                <tr>
                    <th>Strategy</th>
                    <th>Species</th>
                    <th>Trades</th>
                    <th>Winrate</th>
                    <th>Profit</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($strategySpecies as $performance)
                    <tr>
                        <td>{{ strtoupper($performance->strategy) }}</td>
                        <td>{{ $performance->species_name ?? $performance->marketSpecies?->name ?? '-' }}</td>
                        <td>{{ $performance->trades }}</td>
                        <td>{{ $performance->winrate }}%</td>
                        <td>{{ $performance->profit_percent }}%</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5">Hali strategy-species performance yo'q.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </article>
    </section>
@endsection
