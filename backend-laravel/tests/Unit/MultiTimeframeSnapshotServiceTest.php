<?php

namespace Tests\Unit;

use App\Services\MultiTimeframeSnapshotService;
use ReflectionMethod;
use Tests\TestCase;

class MultiTimeframeSnapshotServiceTest extends TestCase
{
    public function test_h4_is_formed_only_from_four_complete_utc_h1_candles(): void
    {
        $rows = [
            ['time' => '2026-01-01 00:00:00', 'open' => 10, 'high' => 12, 'low' => 9, 'close' => 11, 'volume' => 2],
            ['time' => '2026-01-01 01:00:00', 'open' => 11, 'high' => 14, 'low' => 10, 'close' => 13, 'volume' => 3],
            ['time' => '2026-01-01 02:00:00', 'open' => 13, 'high' => 15, 'low' => 12, 'close' => 14, 'volume' => 4],
            ['time' => '2026-01-01 03:00:00', 'open' => 14, 'high' => 16, 'low' => 13, 'close' => 15, 'volume' => 5],
            ['time' => '2026-01-01 04:00:00', 'open' => 15, 'high' => 17, 'low' => 14, 'close' => 16, 'volume' => 6],
        ];
        $method = new ReflectionMethod(MultiTimeframeSnapshotService::class, 'aggregateH4');
        $result = $method->invoke(app(MultiTimeframeSnapshotService::class), $rows);

        $this->assertCount(1, $result);
        $this->assertSame('2026-01-01 00:00:00', $result[0]['time']);
        $this->assertSame(10.0, $result[0]['open']);
        $this->assertSame(16.0, $result[0]['high']);
        $this->assertSame(9.0, $result[0]['low']);
        $this->assertSame(15.0, $result[0]['close']);
        $this->assertSame(14.0, $result[0]['volume']);
    }

    public function test_d1_is_formed_only_from_twenty_four_complete_utc_h1_candles(): void
    {
        $rows = [];
        for ($hour = 0; $hour < 25; $hour++) {
            $rows[] = [
                'time' => sprintf('2026-01-%02d %02d:00:00', $hour < 24 ? 1 : 2, $hour % 24),
                'open' => 10 + $hour, 'high' => 11 + $hour, 'low' => 9 + $hour,
                'close' => 10.5 + $hour, 'volume' => 1,
            ];
        }
        $method = new ReflectionMethod(MultiTimeframeSnapshotService::class, 'aggregateD1');
        $result = $method->invoke(app(MultiTimeframeSnapshotService::class), $rows);

        $this->assertCount(1, $result);
        $this->assertSame('2026-01-01 00:00:00', $result[0]['time']);
        $this->assertSame(10.0, $result[0]['open']);
        $this->assertSame(34.0, $result[0]['high']);
        $this->assertSame(33.5, $result[0]['close']);
    }
}
