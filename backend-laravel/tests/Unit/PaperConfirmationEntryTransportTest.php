<?php

namespace Tests\Unit;

use App\Models\ModelVersion;
use App\Services\ConfirmationEntryContractService;
use App\Services\MarketData\CandlePayloadService;
use App\Services\PaperTradingExecutionService;
use ReflectionMethod;
use Tests\TestCase;

class PaperConfirmationEntryTransportTest extends TestCase
{
    public function test_paper_transport_requires_exact_top_level_and_sealed_entry_contract_parity(): void
    {
        $service = app(PaperTradingExecutionService::class);
        $method = new ReflectionMethod($service, 'confirmationEntryTransport');
        $method->setAccessible(true);
        $model = new ModelVersion(['strategy' => 'confirmation_entry_mtf_v1']);
        $body = [
            'protocol' => ConfirmationEntryContractService::PROTOCOL,
            'model' => 'trend_continuation', 'mode' => 'balanced',
            'direction' => 'BUY', 'status' => 'entry_ready',
        ];
        $contract = [...$body, 'contract_hash' => $this->canonicalHash($body)];
        $fillAdmission = ['allowed' => true, 'status' => 'admitted', 'reason' => null];
        $this->assertSame(
            '06765b1a9f4c3a362cdb4698a3ca2a65e77cb58069c08bc6392f2e1d1c7252af',
            $contract['contract_hash'],
        );

        $valid = $method->invoke($service, $model, [
            'entry_contract' => $contract,
            'entry_fill_admission' => $fillAdmission,
            'execution_contract_preview' => [
                'entry_contract' => $contract,
                'entry_fill_admission' => $fillAdmission,
            ],
        ]);
        $tampered = $method->invoke($service, $model, [
            'entry_contract' => $contract,
            'entry_fill_admission' => $fillAdmission,
            'execution_contract_preview' => [
                'entry_contract' => [...$contract, 'direction' => 'SELL'],
                'entry_fill_admission' => $fillAdmission,
            ],
        ]);
        $missing = $method->invoke($service, $model, []);
        $legacy = $method->invoke($service, new ModelVersion(['strategy' => 'ema_rsi_v1']), []);
        $sameTamper = [...$contract, 'direction' => 'SELL'];
        $tamperedBothCopies = $method->invoke($service, $model, [
            'entry_contract' => $sameTamper,
            'entry_fill_admission' => $fillAdmission,
            'execution_contract_preview' => [
                'entry_contract' => $sameTamper,
                'entry_fill_admission' => $fillAdmission,
            ],
        ]);
        $tamperedFill = $method->invoke($service, $model, [
            'entry_contract' => $contract,
            'entry_fill_admission' => [...$fillAdmission, 'allowed' => false],
            'execution_contract_preview' => [
                'entry_contract' => $contract,
                'entry_fill_admission' => $fillAdmission,
            ],
        ]);

        $this->assertTrue($valid['required']);
        $this->assertTrue($valid['attested']);
        $this->assertFalse($tampered['attested']);
        $this->assertFalse($tamperedBothCopies['attested']);
        $this->assertFalse($tamperedFill['attested']);
        $this->assertFalse($missing['attested']);
        $this->assertFalse($legacy['required']);
        $this->assertTrue($legacy['attested']);
        $this->assertTrue($legacy['fill_admission']['allowed']);
    }

    public function test_confirmation_entry_paper_request_receives_all_closed_context_streams(): void
    {
        $candles = $this->mock(CandlePayloadService::class);
        $candles->shouldReceive('candlesForBacktest')->once()->with('EURUSD', 'H4', 500)->andReturn([['stream' => 'H4']]);
        $candles->shouldReceive('candlesForBacktest')->once()->with('EURUSD', 'H1', 500)->andReturn([['stream' => 'H1']]);
        $candles->shouldReceive('candlesForBacktest')->once()->with('EURUSD', 'M15', 1000)->andReturn([['stream' => 'M15']]);

        $service = app(PaperTradingExecutionService::class);
        $method = new ReflectionMethod($service, 'confirmationEntryMtfStreams');
        $method->setAccessible(true);

        $streams = $method->invoke(
            $service,
            new ModelVersion(['strategy' => 'confirmation_entry_mtf_v1']),
            'EURUSD',
        );

        $this->assertSame(['H4', 'H1', 'M15'], array_keys($streams));
        $this->assertSame('H4', $streams['H4'][0]['stream']);
        $this->assertSame('H1', $streams['H1'][0]['stream']);
        $this->assertSame('M15', $streams['M15'][0]['stream']);
    }

    /** @param array<string,mixed> $value */
    private function canonicalHash(array $value): string
    {
        $normalize = function (array $item) use (&$normalize): array {
            if (! array_is_list($item)) {
                ksort($item);
            }
            foreach ($item as $key => $nested) {
                if (is_array($nested)) {
                    $item[$key] = $normalize($nested);
                }
            }

            return $item;
        };

        return hash('sha256', json_encode($normalize($value), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
