<?php

namespace Tests\Unit;

use App\Services\OverfitDetectorService;
use PHPUnit\Framework\TestCase;

class OverfitDetectionTest extends TestCase
{
    public function test_train_forward_gap_over_25_is_overfit(): void
    {
        $detector = new OverfitDetectorService();

        $this->assertTrue($detector->isOverfit(95, 40));
    }
}
