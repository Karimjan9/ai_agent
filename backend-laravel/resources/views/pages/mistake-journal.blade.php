@extends('layouts.app', [
    'heading' => 'Mistake Journal',
    'subtitle' => "Yo'qotilgan tradelar sababi va strategy xatolari.",
])

@section('content')
    <article class="card">
        <h2 class="section-title">Xatolar</h2>
        <table class="table">
            <thead>
            <tr>
                <th>Type</th>
                <th>Description</th>
                <th>Suggestion</th>
                <th>Run</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($mistakes as $mistake)
                <tr>
                    <td>{{ $mistake->mistake_type }}</td>
                    <td>{{ $mistake->description }}</td>
                    <td>{{ $mistake->suggestion }}</td>
                    <td>#{{ $mistake->backtest_run_id }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4">Hali mistake yozuvlari yo'q.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </article>
@endsection
