<?php

namespace App\Services;

use App\Models\TradePathLaboratoryRun;
use Illuminate\Support\Facades\Schema;

/** Separates entry alpha from stop, target and management counterfactuals. */
class TradePathLaboratoryService
{
    public const PROTOCOL = 'trade_path_laboratory_v1';

    /** @return array<string,mixed> */
    public function plan(array $entry): array
    {
        $paths = ['fixed_target', 'early_break_even', 'structure_break_even', 'partial_plus_runner', 'm1_trailing', 'm5_trailing', 'm15_trailing', 'time_stop', 'session_close', 'winner_only_pyramiding_shadow'];
        return ['protocol' => self::PROTOCOL, 'entry_hash' => hash('sha256', json_encode($entry, JSON_UNESCAPED_SLASHES)), 'sealed_entry' => $entry, 'paths' => array_map(fn (string $path): array => ['path' => $path, 'same_entry_required' => true, 'same_cost_model_required' => true, 'research_only' => true], $paths), 'attribution_axes' => ['entry_alpha', 'stop_model_value', 'target_model_value', 'management_value', 'position_sizing_value', 'execution_cost', 'market_drift'], 'promotion_evidence' => false];
    }

    /** @return array<string,mixed> */
    public function settle(array $entry, array $pathResults, array $scope = []): array
    {
        $plan = $this->plan($entry); $baseline = (float) data_get($pathResults, 'fixed_target.net_r', 0);
        $best = collect($pathResults)->sortByDesc(fn ($result): float => (float) data_get($result, 'net_r', -INF))->keys()->first() ?? 'fixed_target';
        $attribution = ['entry_alpha' => $baseline, 'management_value' => (float) data_get($pathResults, $best.'.net_r', 0) - $baseline, 'best_path' => $best, 'requires_independent_replication' => true, 'promotion_evidence' => false];
        if (Schema::hasTable('trade_path_laboratory_runs')) {
            $key = hash('sha256', implode('|', [$scope['composition_id'] ?? '', $plan['entry_hash']]));
            TradePathLaboratoryRun::query()->updateOrCreate(['run_key' => $key], ['model_version_id' => $scope['model_version_id'] ?? null, 'symbol' => strtoupper((string) ($scope['symbol'] ?? 'XAUUSD')), 'timeframe' => strtoupper((string) ($scope['timeframe'] ?? 'H1')), 'composition_id' => $scope['composition_id'] ?? null, 'entry_hash' => $plan['entry_hash'], 'status' => 'settled_research_only', 'paths' => $pathResults, 'attribution' => $attribution, 'evidence' => ['sealed_entry' => $entry], 'settled_at' => now()]);
        }
        $learningReceipt = app(LearningCompilerService::class)->compileTradePath([...$plan, 'attribution' => $attribution], $scope);
        return [...$plan, 'results' => $pathResults, 'attribution' => $attribution, 'learning_receipt' => $learningReceipt];
    }
}
