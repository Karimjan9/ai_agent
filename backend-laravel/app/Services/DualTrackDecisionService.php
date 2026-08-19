<?php

namespace App\Services;

/**
 * Deterministic, fail-closed adjudicator for the Champion and Council lanes.
 * It never grants promotion evidence; it only projects a runtime observation.
 */
class DualTrackDecisionService
{
    public const PROTOCOL = 'dual_track_constitutional_intelligence_v1';

    private const ACTIONS = ['BUY', 'SELL', 'WAIT'];

    /** @return array<string, mixed> */
    public function evaluate(
        array $context,
        array $champion,
        array $council,
        array $evidence = [],
    ): array {
        $championOutputPresent = array_key_exists('decision', $champion) || array_key_exists('signal', $champion);
        $councilOutputPresent = array_key_exists('decision', $council) || array_key_exists('signal', $council);
        $champion = $this->normalize($champion);
        $council = $this->normalize($council);
        $championDecision = $champion['decision'];
        $councilDecision = $council['decision'];
        $hardGate = $this->hardGate($champion, $council, $evidence, $championOutputPresent, $councilOutputPresent);
        $disagreement = $this->disagreement($championDecision, $councilDecision);
        $sameAction = in_array($championDecision, ['BUY', 'SELL'], true)
            && $championDecision === $councilDecision;

        $route = 'incumbent';
        $selected = (string) ($evidence['incumbent_decision'] ?? 'WAIT');
        $status = 'observed';
        $reason = 'SHADOW_OBSERVATION_ONLY';

        if (! $hardGate['passed']) {
            $status = 'blocked';
            $reason = 'HARD_GATE_FAILURE';
        } elseif ($disagreement !== null) {
            $status = 'wait';
            $reason = $disagreement;
            $selected = 'WAIT';
        } elseif ($sameAction) {
            $route = 'hybrid';
            $selected = $councilDecision;
            $reason = 'LANES_AGREE';
        } elseif ($championDecision === 'WAIT' && $councilDecision === 'WAIT') {
            $selected = 'WAIT';
            $reason = 'BOTH_LANES_WAIT';
        } else {
            $status = 'wait';
            $reason = 'NON_ACTIONABLE_LANE_CONFLICT';
            $selected = 'WAIT';
        }

        return [
            'protocol' => self::PROTOCOL,
            'cell_key' => self::cellKey($context),
            'status' => $status,
            'route' => $route,
            'selected_lane' => $route === 'hybrid' ? 'hybrid' : 'incumbent',
            'selected_decision' => $this->decision($selected),
            'champion' => $champion,
            'council' => $council,
            'disagreement_code' => $disagreement,
            'hard_gate' => $hardGate,
            'scores' => [
                'champion' => $champion['score'],
                'council' => $council['score'],
                'complementarity' => $sameAction ? 1.0 : 0.0,
            ],
            'reason' => $reason,
            'promotion_evidence' => false,
        ];
    }

    /** @param array<string, mixed> $context */
    public static function cellKey(array $context): string
    {
        return implode('|', [
            strtoupper((string) ($context['symbol'] ?? 'UNKNOWN')),
            strtoupper((string) ($context['timeframe'] ?? 'UNKNOWN')),
            strtolower((string) ($context['market_regime'] ?? 'unknown')),
            strtolower((string) ($context['volatility_regime'] ?? 'unknown')),
            strtolower((string) ($context['task_type'] ?? 'signal')),
        ]);
    }

    /** @return array<string, mixed> */
    private function normalize(array $output): array
    {
        $decision = $this->decision($output['decision'] ?? $output['signal'] ?? 'WAIT');
        $score = is_numeric($output['score'] ?? null)
            ? max(0.0, min(100.0, (float) $output['score']))
            : $this->derivedScore($output, $decision);

        return [
            ...$output,
            'decision' => $decision,
            'score' => round($score, 4),
            'confidence' => is_numeric($output['confidence'] ?? null)
                ? max(0.0, min(1.0, (float) $output['confidence'])) : null,
            'hard_gate_passed' => ($output['hard_gate_passed'] ?? true) === true,
        ];
    }

    private function decision(mixed $value): string
    {
        $decision = strtoupper(trim((string) $value));
        return in_array($decision, self::ACTIONS, true) ? $decision : 'WAIT';
    }

    private function derivedScore(array $output, string $decision): float
    {
        if ($decision === 'WAIT') return 0.0;
        $confidence = is_numeric($output['confidence'] ?? null) ? (float) $output['confidence'] : .5;
        return max(0.0, min(100.0, $confidence * 100));
    }

    /** @return array{passed:bool, failed:array<int, string>} */
    private function hardGate(
        array $champion,
        array $council,
        array $evidence,
        bool $championOutputPresent,
        bool $councilOutputPresent,
    ): array
    {
        $checks = [
            'champion_output_present' => $championOutputPresent,
            'council_output_present' => $councilOutputPresent,
            'champion_lane_gate' => $champion['hard_gate_passed'] === true,
            'council_lane_gate' => $council['hard_gate_passed'] === true,
            'constitution_integrity' => ($evidence['constitution_integrity'] ?? true) === true,
            'snapshot_integrity' => ($evidence['snapshot_integrity'] ?? true) === true,
            'catastrophic_regression_absent' => ($evidence['catastrophic_regression'] ?? false) !== true,
        ];

        $failed = array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed));
        return ['passed' => $failed === [], 'checks' => $checks, 'failed' => $failed];
    }

    private function disagreement(string $champion, string $council): ?string
    {
        if ($champion === $council) return null;
        if (in_array($champion, ['BUY', 'SELL'], true) && in_array($council, ['BUY', 'SELL'], true)) {
            return 'OPPOSITE_ACTIONS';
        }
        if ($champion !== 'WAIT' || $council !== 'WAIT') return 'ACTIONABLE_VS_WAIT';
        return null;
    }
}
