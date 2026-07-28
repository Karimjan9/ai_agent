<?php

namespace App\Services;

use App\Models\AgentMemory;
use App\Models\ModelMarketPerformance;

/**
 * Turns a completed replay into durable, falsifiable decision lessons.
 * It stores aggregate evidence, never a claim that a single lucky trade is an
 * edge. LabPopulationService consumes only the high-confidence lessons.
 */
class DecisionLearningService
{
    public function __construct(private PhaseTwoFoundationService $foundation, private StrategyParameterSchemaService $schemas) {}

    public function learn(ModelMarketPerformance $performance, array $result): void
    {
        $trades = (int) ($result['total_trades'] ?? 0);
        $confidence = min(95, 35 + min(45, $trades) + min(15, (int) $performance->rolling_windows_count * 3));
        $family = $performance->strategy_family;
        $scope = $this->worstScope($result);
        $mistakes = collect($result['top_mistakes'] ?? [])->pluck('type')->all();
        $entry = $this->entryActions($family, $result);
        $exit = $this->exitActions($family, $result, $mistakes);
        $architecture = $this->architectureActions($family, $result);

        $this->writeOnce($performance, 'entry_quality', $scope, $confidence, $entry, [
            'summary' => "Entry decision evidence: PF ".round((float) ($result['profit_factor'] ?? 0), 2)." across {$trades} trades.",
            'lesson' => $entry['lesson'],
        ]);
        $this->writeOnce($performance, 'exit_quality', $scope, $confidence, $exit, [
            'summary' => 'Exit decision evidence: '.implode(', ', array_keys(data_get($result, 'pf_attribution.by_exit_reason', [])) ?: ['no closed exits']).'.',
            'lesson' => $exit['lesson'],
        ]);
        $this->writeOnce($performance, 'architecture_quality', $scope, $confidence, $architecture, [
            'summary' => 'Architecture evidence was evaluated across rolling checkpoints.',
            'lesson' => $architecture['lesson'],
        ]);
    }

    /** @return array{prioritize: array<int,string>, avoid: array<int,string>} */
    public function advice(string $symbol, string $timeframe, string $family, ?string $scope): array
    {
        $regime = $scope ? str_replace(['market:', 'volatility:'], '', $scope) : null;
        $prefix = strtolower($symbol);
        $memories = AgentMemory::query()->where(function ($query) use ($prefix, $family, $timeframe): void {
                $query->where('strategy', 'like', $prefix.'_'.$family.'_%')
                    ->orWhere('strategy', 'like', $prefix.'_'.strtolower($timeframe).'_'.$family.'_%');
            })
            ->whereIn('memory_type', ['entry_quality', 'exit_quality', 'architecture_quality', 'screen_entry_quality', 'screen_exit_quality', 'screen_architecture_quality'])
            ->where('confidence_score', '>=', 55)
            ->when($regime, fn ($query) => $query->where(function ($inner) use ($regime): void {
                $inner->where('market_regime', $regime)->orWhereNull('market_regime');
            }))
            ->latest()->take(30)->get();
        $prioritize = [];
        $avoid = [];
        foreach ($memories as $memory) {
            $prioritize = [...$prioritize, ...((array) data_get($memory->metadata, 'parameter_actions.prioritize', []))];
            $avoid = [...$avoid, ...((array) data_get($memory->metadata, 'parameter_actions.avoid', []))];
        }
        return ['prioritize' => array_values(array_unique($prioritize)), 'avoid' => array_values(array_unique($avoid))];
    }

    private function writeOnce(ModelMarketPerformance $performance, string $type, ?string $scope, int $confidence, array $actions, array $copy): void
    {
        if (AgentMemory::query()->where('source_type', ModelMarketPerformance::class)->where('source_id', $performance->id)->where('memory_type', $type)->exists()) return;
        $outcome = (float) data_get($performance->metrics, 'profit_factor', 0) >= 1.3 ? 'validated' : 'falsified';
        $this->foundation->writeExperienceMemory([
            'strategy' => $performance->modelVersion?->strategy ?? strtolower($performance->symbol).'_'.$performance->strategy_family,
            'memory_type' => $type, 'market_regime' => $scope ? str_replace(['market:', 'volatility:'], '', $scope) : null,
            'outcome' => $outcome, 'summary' => $copy['summary'], 'lesson' => $copy['lesson'],
            'strength' => $confidence, 'confidence_score' => $confidence,
            'source_type' => ModelMarketPerformance::class, 'source_id' => $performance->id,
            'metadata' => ['symbol' => $performance->symbol, 'timeframe' => $performance->timeframe, 'strategy_family' => $performance->strategy_family, 'scope' => $scope, 'parameter_actions' => $actions],
        ]);
    }

    private function entryActions(string $family, array $result): array
    {
        $schema = array_keys($this->schemas->schema($family));
        $byDirection = data_get($result, 'pf_attribution.by_direction', []);
        $weakDirection = collect($byDirection)->filter(fn ($item) => (int) data_get($item, 'trades', 0) >= 3 && (float) data_get($item, 'net_pf', 0) < 1)->isNotEmpty();
        $weakPf = (float) ($result['profit_factor'] ?? 0) < 1.3;
        $keys = $weakPf || $weakDirection
            ? ['trend_strength_min', 'pullback_atr_fraction', 'confirmation_candles', 'minimum_signal_confidence', 'lookback', 'adx_max', 'deviation'] : [];
        $keys = array_values(array_intersect($schema, $keys));
        return ['prioritize' => $keys, 'avoid' => [], 'lesson' => $keys ? 'Entry edge was weak; mutate regime/confirmation filters before increasing trade frequency.' : 'No entry-specific weakness reached the minimum evidence threshold.'];
    }

    private function exitActions(string $family, array $result, array $mistakes): array
    {
        $schema = array_keys($this->schemas->schema($family));
        $stops = (int) data_get($result, 'pf_attribution.by_exit_reason.intrabar_stop.trades', 0)
            + (int) data_get($result, 'pf_attribution.by_exit_reason.gap_stop.trades', 0);
        $redTeam = (array) data_get($result, 'red_team.recommendations', []);
        $keys = ($stops > 0 || in_array('stop_loss_too_close', $mistakes, true))
            ? ['atr_stop_multiplier', 'atr_target_multiplier', 'trailing_atr_multiplier', 'time_stop_candles', 'partial_take_profit_fraction', 'partial_target_atr_multiplier'] : [];
        $keys = [...$keys, ...$redTeam];
        $keys = array_values(array_intersect($schema, $keys));
        return ['prioritize' => $keys, 'avoid' => [], 'lesson' => $keys ? 'Observed stop/exit or red-team stress failure requires a bounded ATR/risk experiment, not a wider blind stop.' : 'No exit-specific weakness reached the minimum evidence threshold.'];
    }

    private function architectureActions(string $family, array $result): array
    {
        $weak = (float) ($result['profit_factor'] ?? 0) < 1 || (int) ($result['total_trades'] ?? 0) === 0;
        return ['prioritize' => [], 'avoid' => [], 'lesson' => $weak ? "{$family} architecture failed its economic edge claim in this regime; de-prioritize it until a materially different topology is tested." : 'Architecture remains a candidate, not a proven edge.'];
    }

    private function worstScope(array $result): ?string
    {
        $regime = collect($result['regime_performance'] ?? [])->filter(fn ($value) => (int) data_get($value, 'trades', 0) >= 3)->sortBy('profit_percent')->keys()->first();
        return $regime ? 'market:'.$regime : null;
    }
}
