<?php

namespace Tests\Feature;

use App\Models\MarketSymbol;
use App\Models\MarketTrainingArchive;
use App\Models\MarketTrainingCandle;
use App\Services\MarketData\DukascopyMarketDataProvider;
use App\Services\MarketData\MarketTrainingDataService;
use App\Services\MarketData\TwelveDataMarketDataProvider;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TrainingBackfillProviderIdentityTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('mismatchedProviders')]
    public function test_native_backfill_rejects_mismatched_labels_before_any_source_or_archive_side_effect(
        string $command,
        string $requestedProvider,
    ): void {
        // A pre-existing scientific archive must remain byte-for-byte unchanged.
        $training = app(MarketTrainingDataService::class);
        $archive = $training->ensureArchive('preserved_native_source', 'dukascopy', 'XAUUSD', 'H1',
            CarbonImmutable::parse('2025-01-06 00:00:00', 'UTC'),
            CarbonImmutable::parse('2025-01-06 01:00:00', 'UTC'));
        $training->upsertCandles('preserved_native_source', 'dukascopy', 'XAUUSD', 'H1', [$this->candle('2025-01-06 00:00:00')]);
        $archiveBefore = $archive->fresh()->toArray();
        $candleBefore = MarketTrainingCandle::query()->first()->toArray();

        $trainingGuard = Mockery::mock(MarketTrainingDataService::class);
        $trainingGuard->shouldNotReceive('trainingCutoff');
        $trainingGuard->shouldNotReceive('ensureArchive');
        $trainingGuard->shouldNotReceive('upsertCandles');
        $trainingGuard->shouldNotReceive('refreshCoverage');
        $native = Mockery::mock(DukascopyMarketDataProvider::class);
        $native->shouldNotReceive('fetchCandles');
        $alternate = Mockery::mock(TwelveDataMarketDataProvider::class);
        $alternate->shouldNotReceive('fetchCandles');
        $this->app->instance(MarketTrainingDataService::class, $trainingGuard);
        $this->app->instance(DukascopyMarketDataProvider::class, $native);
        $this->app->instance(TwelveDataMarketDataProvider::class, $alternate);
        $transportBefore = config('services.dukascopy.transport');

        $this->artisan($command, ['--provider' => $requestedProvider, '--dataset' => 'must_not_be_created'])
            ->assertExitCode(Command::INVALID);

        $this->assertSame($archiveBefore, $archive->fresh()->toArray());
        $this->assertSame($candleBefore, MarketTrainingCandle::query()->first()->toArray());
        $this->assertDatabaseCount('market_training_archives', 1);
        $this->assertDatabaseCount('market_training_candles', 1);
        $this->assertDatabaseCount('candles', 0);
        $this->assertSame($transportBefore, config('services.dukascopy.transport'));
    }

    public static function mismatchedProviders(): array
    {
        $cases = [];
        foreach (['market-data:backfill-training', 'market-data:backfill-intraday-training'] as $command) {
            foreach (['twelve', 'csv', 'unsupported', '', 'Dukascopy', ' dukascopy'] as $index => $provider) {
                $cases[$command.'-'.$index] = [$command, $provider];
            }
        }

        return $cases;
    }

    #[DataProvider('nativeFoundationRequests')]
    public function test_genuine_native_foundation_request_fetches_and_persists_only_dukascopy(
        string $timeframe,
        bool $explicitProvider,
    ): void {
        $this->activeSymbol();
        $from = CarbonImmutable::parse('2025-01-06 00:00:00', 'UTC');
        $to = $from->addMinutes($timeframe === 'H1' ? 60 : 15);
        $native = Mockery::mock(DukascopyMarketDataProvider::class);
        $native->shouldReceive('fetchCandles')->once()->withArgs(
            fn ($symbol, $providerSymbol, $requestedTimeframe, $limit, $requestedFrom, $requestedTo) =>
                $symbol === 'XAUUSD' && $providerSymbol === 'XAU_USD' && $requestedTimeframe === $timeframe
                && $limit === 10000 && $from->equalTo($requestedFrom) && $to->equalTo($requestedTo),
        )->andReturn([$this->candle($from->format('Y-m-d H:i:s'))]);
        $alternate = Mockery::mock(TwelveDataMarketDataProvider::class);
        $alternate->shouldNotReceive('fetchCandles');
        $this->app->instance(DukascopyMarketDataProvider::class, $native);
        $this->app->instance(TwelveDataMarketDataProvider::class, $alternate);
        $dataset = 'native_identity_'.strtolower($timeframe);
        $options = ['--symbol' => 'XAUUSD', '--timeframe' => $timeframe, '--dataset' => $dataset,
            '--from' => $from->format('Y-m-d H:i:s'), '--to' => $to->format('Y-m-d H:i:s'), '--max-chunks' => 1];
        if ($explicitProvider) $options['--provider'] = 'dukascopy';

        $this->artisan('market-data:backfill-training', $options)->assertSuccessful();

        $archive = MarketTrainingArchive::query()->sole();
        $candle = MarketTrainingCandle::query()->sole();
        $this->assertSame($dataset, $archive->dataset_key);
        $this->assertSame('dukascopy', $archive->provider);
        $this->assertSame('dukascopy', $candle->provider);
        $this->assertSame($dataset, $candle->dataset_key);
        $this->assertSame($timeframe, $candle->timeframe);
        $this->assertSame('complete', $archive->status);
        $this->assertSame(1, (int) $archive->row_count);
        $this->assertSame('BID', data_get($archive->metrics, 'price_side'));
        $this->assertSame(100.5, (float) $candle->close);
        $this->assertDatabaseCount('candles', 0);
    }

    public static function nativeFoundationRequests(): array
    {
        return [['H1', false], ['H1', true], ['M15', false], ['M15', true]];
    }

    #[DataProvider('nativeIntradayRequests')]
    public function test_genuine_native_intraday_request_preserves_source_and_derived_dataset_identity(bool $explicitProvider): void
    {
        $this->activeSymbol();
        $from = CarbonImmutable::parse('2025-01-06 00:00:00', 'UTC'); $to = $from->addMinutes(30);
        $minutes = [];
        for ($offset = 0; $offset < 30; $offset++) $minutes[] = $this->candle($from->addMinutes($offset)->format('Y-m-d H:i:s'));
        $native = Mockery::mock(DukascopyMarketDataProvider::class);
        $native->shouldReceive('fetchCandles')->once()->withArgs(
            fn ($symbol, $providerSymbol, $timeframe, $limit, $requestedFrom, $requestedTo) =>
                $symbol === 'XAUUSD' && $providerSymbol === 'XAU_USD' && $timeframe === 'M1'
                && $limit === 1000000 && $from->equalTo($requestedFrom) && $to->equalTo($requestedTo),
        )->andReturn($minutes);
        $alternate = Mockery::mock(TwelveDataMarketDataProvider::class);
        $alternate->shouldNotReceive('fetchCandles');
        $this->app->instance(DukascopyMarketDataProvider::class, $native);
        $this->app->instance(TwelveDataMarketDataProvider::class, $alternate);
        $options = ['--dataset' => 'native_intraday_identity', '--from' => $from->format('Y-m-d H:i:s'),
            '--to' => $to->format('Y-m-d H:i:s'), '--max-chunks' => 1];
        if ($explicitProvider) $options['--provider'] = 'dukascopy';

        $this->artisan('market-data:backfill-intraday-training', $options)->assertSuccessful();

        $this->assertDatabaseCount('market_training_archives', 3);
        $this->assertSame(['dukascopy'], MarketTrainingArchive::query()->distinct()->pluck('provider')->all());
        $this->assertSame(['dukascopy'], MarketTrainingCandle::query()->distinct()->pluck('provider')->all());
        $this->assertSame(['native_intraday_identity'], MarketTrainingCandle::query()->distinct()->pluck('dataset_key')->all());
        foreach (['M1' => 30, 'M5' => 6, 'M30' => 1] as $timeframe => $count) {
            $archive = MarketTrainingArchive::query()->where('timeframe', $timeframe)->sole();
            $this->assertSame($count, (int) $archive->row_count);
            $this->assertSame('complete', $archive->status);
            $this->assertSame('BID', data_get($archive->metrics, 'price_side'));
            $this->assertSame($count, MarketTrainingCandle::query()->where('timeframe', $timeframe)->count());
        }
        $this->assertSame(60.0, (float) MarketTrainingCandle::query()->where('timeframe', 'M30')->sole()->volume);
        $this->assertDatabaseCount('candles', 0);
    }

    public static function nativeIntradayRequests(): array
    {
        return [[false], [true]];
    }

    private function activeSymbol(): void
    {
        MarketSymbol::create(['symbol' => 'XAUUSD', 'provider_symbol' => 'XAU_USD', 'name' => 'Gold / US Dollar',
            'market_type' => 'forex', 'is_active' => true]);
    }

    private function candle(string $time): array
    {
        return ['time' => $time, 'open' => 100.0, 'high' => 101.0, 'low' => 99.0, 'close' => 100.5, 'volume' => 2.0];
    }
}
