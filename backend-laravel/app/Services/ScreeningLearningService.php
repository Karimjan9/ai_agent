<?php

namespace App\Services;

use App\Models\AgentMemory;
use App\Models\LabAgent;
use App\Models\ModelVersion;
use App\Models\MutationMemory;

/**
 * Stores a cautious lesson from a fast screen. It influences the next search
 * direction, but only a full replay can produce a high-confidence hard ban.
 */
class ScreeningLearningService
{
    public function record(LabAgent $agent, ModelVersion $model, array $result, float $forwardScore): void
    {
        $trades = (int) ($result['total_trades'] ?? 0);
        $pf = (float) ($result['profit_factor'] ?? 0);
        $failure = $trades === 0 ? 'no_trade' : ($pf < 1.0 ? 'negative_edge' : ($pf < 1.3 ? 'weak_profit_factor' : 'screen_pass'));
        $confidence = min(65, 45 + min(20, $trades));
        $funnel = (array) ($result['entry_funnel'] ?? []);
        $architecture = data_get($model->metadata, 'strategy_architecture');

        $actions = $this->actions($agent->strategy_family, $failure, $funnel);
        foreach (['entry_quality', 'exit_quality', 'architecture_quality'] as $type) {
            AgentMemory::updateOrCreate(
                ['source_type' => LabAgent::class, 'source_id' => $agent->id, 'memory_type' => 'screen_'.$type],
                [
                    'strategy' => $model->strategy,
                    'outcome' => $failure === 'screen_pass' ? 'candidate' : 'falsified',
                    'summary' => "Screen {$failure}: PF ".round($pf, 2).", {$trades} trades, forward ".round($forwardScore, 2).'.',
                    'lesson' => $actions[$type]['lesson'],
                    'strength' => $confidence,
                    'confidence_score' => $confidence,
                    'metadata' => [
                        'symbol' => $agent->symbol, 'timeframe' => $agent->timeframe,
                        'strategy_family' => $agent->strategy_family, 'screen_failure' => $failure,
                        'entry_funnel' => $funnel, 'parameter_actions' => $actions[$type],
                    ],
                ],
            );
        }

        if ($failure === 'screen_pass') {
            return;
        }

        foreach ($agent->parameter_diff ?? [] as $key => $change) {
            MutationMemory::updateOrCreate(
                ['lab_agent_id' => $agent->id, 'parameter_key' => $key],
                [
                    'symbol' => $agent->symbol, 'timeframe' => $agent->timeframe,
                    'strategy_family' => $agent->strategy_family,
                    'old_value' => ['value' => $change['old'] ?? null],
                    'new_value' => ['value' => $change['new'] ?? null],
                    'forward_delta' => min(0, $forwardScore), 'outcome' => 'harmful',
                    'confidence' => $confidence, 'decision' => "screen_{$failure}",
                ],
            );
        }

        if ($architecture) {
            MutationMemory::updateOrCreate(
                ['lab_agent_id' => $agent->id, 'parameter_key' => '__architecture'],
                [
                    'symbol' => $agent->symbol, 'timeframe' => $agent->timeframe,
                    'strategy_family' => $agent->strategy_family,
                    'old_value' => ['value' => null], 'new_value' => ['value' => $architecture],
                    'forward_delta' => min(0, $forwardScore), 'outcome' => 'harmful',
                    'confidence' => $confidence, 'decision' => "screen_{$failure}_soft",
                ],
            );
        }
    }

    private function actions(string $family, string $failure, array $funnel): array
    {
        $overFiltered = (int) ($funnel['flat_signal_opportunities'] ?? 0) >= 30
            && (int) ($funnel['accepted_entries'] ?? 0) < ((int) ($funnel['flat_signal_opportunities'] ?? 0) / 2);
        $entry = $failure === 'no_trade' || $overFiltered
            ? ['prioritize' => ['lookback', 'confirmation_candles', 'minimum_signal_confidence'], 'avoid' => [], 'lesson' => 'Screen lacked executable entries; relax only the diagnosed entry filter in G2.']
            : ['prioritize' => ['trend_strength_min', 'pullback_atr_fraction', 'minimum_signal_confidence'], 'avoid' => [], 'lesson' => 'Screen PF was weak; alter entry quality before adding trade frequency.'];
        $exit = ['prioritize' => ['atr_stop_multiplier', 'atr_target_multiplier', 'trailing_atr_multiplier', 'time_stop_candles', 'partial_take_profit_fraction'], 'avoid' => [], 'lesson' => 'Weak screen economics requires adaptive ATR exit experiments, not a larger fixed stop.'];
        $architecture = ['prioritize' => [], 'avoid' => [], 'lesson' => "{$family} failed a short economic-edge screen; G2 must test a materially different topology before reuse."];

        return [
            'entry_quality' => $entry,
            'exit_quality' => $exit,
            'architecture_quality' => $architecture,
        ];
    }
}
