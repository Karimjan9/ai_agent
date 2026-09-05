<?php

namespace App\Services;

/**
 * Fail-closed dispatcher for independently researched practitioner playbooks.
 *
 * A catalogue playbook is one hypothesis, not one voting member.  The regime
 * router must nominate one model before it runs.  If diagnostic execution
 * reveals multiple actionable models, that observation is retained but it
 * cannot become a blended order.  A blend is a new hypothesis and must enter
 * the frozen-control research lane as its own candidate.
 */
class ResearchPlaybookConflictResolutionService
{
    public const PROTOCOL = 'individual_playbook_conflict_resolution_v1';

    private const ACTIONS = ['BUY', 'SELL', 'WAIT'];

    public function __construct(private StrategyResearchCatalogueService $catalogue) {}

    /**
     * @param array<int,array<string,mixed>> $proposals
     * @param array<string,mixed> $routing
     * @return array<string,mixed>
     */
    public function resolve(array $proposals, array $routing): array
    {
        $selectedModel = trim((string) ($routing['selected_model_id'] ?? ''));
        $normalized = array_map(fn (array $proposal): array => $this->normalize($proposal), $proposals);
        $invalid = array_values(array_filter($normalized, fn (array $proposal): bool => $proposal['valid'] !== true));
        $dataHashes = array_values(array_unique(array_filter(array_column($normalized, 'data_hash'))));
        $executionHashes = array_values(array_unique(array_filter(array_column($normalized, 'execution_hash'))));
        $actionable = array_values(array_filter($normalized, fn (array $proposal): bool => in_array($proposal['decision'], ['BUY', 'SELL'], true)));
        $directions = array_values(array_unique(array_column($actionable, 'decision')));
        $models = array_values(array_unique(array_column($actionable, 'model_id')));

        $base = [
            'protocol' => self::PROTOCOL,
            'routing_model_id' => $selectedModel ?: null,
            'observed_proposals' => array_map(fn (array $proposal): array => [
                'model_id' => $proposal['model_id'],
                'decision' => $proposal['decision'],
                'valid' => $proposal['valid'],
            ], $normalized),
            'data_hashes' => $dataHashes,
            'execution_hashes' => $executionHashes,
            'execution_authority' => false,
            'promotion_evidence' => false,
        ];
        if ($selectedModel === '') return $this->wait($base, 'MODEL_ROUTER_MISSING');
        if ($invalid !== []) return $this->wait($base, 'INVALID_PLAYBOOK_PROPOSAL');
        if ($normalized === []) return $this->wait($base, 'NO_PLAYBOOK_OUTPUT');
        if (count($dataHashes) !== 1 || count($executionHashes) !== 1) {
            return $this->wait($base, 'NON_COMPARABLE_MODEL_OUTPUTS');
        }
        if (! $this->known($selectedModel)) return $this->wait($base, 'UNKNOWN_ROUTED_PLAYBOOK');
        if (count($directions) > 1) return $this->wait($base, 'OPPOSITE_PLAYBOOK_DIRECTIONS');
        if (count($models) > 1) return $this->wait($base, 'UNDECLARED_MODEL_BLEND');
        if ($actionable === []) return $this->wait($base, 'ROUTED_PLAYBOOK_WAIT');

        $proposal = $actionable[0];
        if ($proposal['model_id'] !== $selectedModel) {
            return $this->wait($base, 'ROUTER_SCOPE_VIOLATION');
        }
        if (count($actionable) !== 1) return $this->wait($base, 'DUPLICATE_MODEL_OUTPUT');

        return [
            ...$base,
            'status' => 'candidate_setup',
            'selected_model_id' => $selectedModel,
            'selected_decision' => $proposal['decision'],
            'reason_code' => 'ONE_ROUTED_PLAYBOOK_ACTIONABLE',
            'next_gate' => 'risk_and_execution_authorization_required',
        ];
    }

    /** @return array<string,mixed> */
    public function contract(): array
    {
        return [
            'protocol' => self::PROTOCOL,
            'dispatch' => 'regime_router_selects_exactly_one_model_before_execution',
            'opposite_actions' => 'WAIT_OPPOSITE_PLAYBOOK_DIRECTIONS',
            'same_direction_multiple_models' => 'WAIT_UNDECLARED_MODEL_BLEND',
            'explicit_composite' => 'new_research_candidate_requires_own_frozen_control',
            'same_data_and_execution_hash_required' => true,
            'execution_authority' => false,
            'promotion_evidence' => false,
        ];
    }

    /** @param array<string,mixed> $proposal @return array<string,mixed> */
    private function normalize(array $proposal): array
    {
        $modelId = trim((string) ($proposal['model_id'] ?? $proposal['research_model_id'] ?? ''));
        $decision = strtoupper(trim((string) ($proposal['decision'] ?? $proposal['signal'] ?? 'WAIT')));
        return [
            'model_id' => $modelId,
            'decision' => in_array($decision, self::ACTIONS, true) ? $decision : 'WAIT',
            'data_hash' => trim((string) ($proposal['data_hash'] ?? '')),
            'execution_hash' => trim((string) ($proposal['execution_hash'] ?? '')),
            'valid' => $modelId !== '' && $this->known($modelId) && in_array($decision, self::ACTIONS, true),
        ];
    }

    private function known(string $modelId): bool
    {
        try {
            $this->catalogue->model($modelId);
            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /** @param array<string,mixed> $base @return array<string,mixed> */
    private function wait(array $base, string $reason): array
    {
        return [
            ...$base,
            'status' => 'wait',
            'selected_model_id' => null,
            'selected_decision' => 'WAIT',
            'reason_code' => $reason,
            'next_gate' => 'record_conflict_or_wait_reason',
        ];
    }
}
