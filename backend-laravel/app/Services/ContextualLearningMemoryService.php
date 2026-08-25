<?php

namespace App\Services;

use App\Models\EvolutionLearningReceipt;
use Illuminate\Support\Facades\Schema;

/** Retrieves only non-expired knowledge whose declared scope matches context. */
class ContextualLearningMemoryService
{
    /** @return array<int,array<string,mixed>> */
    public function retrieve(string $symbol, string $timeframe, array $context = [], array $statuses = ['confirmed', 'replicated', 'provisional']): array
    {
        if (! Schema::hasTable('evolution_learning_receipts')) return [];
        return EvolutionLearningReceipt::query()->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))
            ->whereIn('status', $statuses)->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get()->filter(fn (EvolutionLearningReceipt $receipt): bool => $this->matches((array) $receipt->scope, $context))
            ->sortByDesc(fn (EvolutionLearningReceipt $receipt): array => [$this->rank((string) $receipt->status), (float) $receipt->confidence, (int) $receipt->support])
            ->map(fn (EvolutionLearningReceipt $receipt): array => ['receipt_id' => $receipt->id, 'receipt_key' => $receipt->receipt_key, 'claim' => $receipt->claim, 'component' => $receipt->component, 'action' => $receipt->action, 'status' => $receipt->status, 'confidence' => (float) $receipt->confidence, 'causal_uplift_r' => $receipt->causal_uplift_r, 'scope' => $receipt->scope, 'evidence' => $receipt->evidence])->values()->all();
    }

    private function matches(array $scope, array $context): bool
    {
        foreach ($scope as $key => $value) {
            if ($value === null || $value === '') continue;
            $actual = $context[$key] ?? ($key === 'strategy_family' ? ($context['family'] ?? null) : null);
            // A scoped receipt is never allowed to leak into an unknown or
            // different context; silence is exploration, not confirmation.
            if ($actual === null || (string) $actual !== (string) $value) return false;
        }
        return true;
    }

    private function rank(string $status): int { return match ($status) { 'confirmed' => 3, 'replicated' => 2, 'provisional' => 1, default => 0 }; }
}
