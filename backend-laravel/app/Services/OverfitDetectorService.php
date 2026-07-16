<?php

namespace App\Services;

class OverfitDetectorService
{
    public function isOverfit(float|int|null $trainScore, float|int|null $forwardScore): bool
    {
        return abs((float) $trainScore - (float) $forwardScore) > 25;
    }
}
