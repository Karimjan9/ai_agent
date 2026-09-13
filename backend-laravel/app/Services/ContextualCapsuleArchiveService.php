<?php

namespace App\Services;

use App\Models\ContextualSpecialistCapsule;
use App\Models\LabAgent;
use App\Models\LabEvolutionArchiveEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Context-local MAP-Elites archive with Pareto-safe monotonic replacement. */
class ContextualCapsuleArchiveService
{
    public const PROTOCOL = 'contextual_capsule_map_elites_v1';

    public function __construct(
        private CooperativeModuleSpeciesService $species,
        private ContextualSpecialistAuthorityService $authority,
    ) {}

    /** @return array<string,mixed> */
    public function recordScreening(LabAgent $agent, array $result): array
    {
        $identityContract = (array) data_get($agent->modelVersion?->metadata, 'contextual_specialist_identity', []);
        $identity = (array) data_get($identityContract, 'identity', []);
        $capsule = (array) data_get($agent->modelVersion?->metadata, 'cooperative_evolution_capsule', []);
        $cellKey = (string) (data_get($capsule, 'context_cell_hash') ?: data_get($identityContract, 'identity_hash', ''));
        if ($identity === [] || $cellKey === '' || $capsule === []) {
            return ['protocol' => self::PROTOCOL, 'status' => 'not_applicable_non_cooperative_candidate', 'promotion_evidence' => false];
        }
        $outsideActivations = max(0, (int) data_get($result, 'outside_scope_activation_count', data_get($result, 'context_scope.outside_scope_activation_count', 0)));
        $pareto = $this->paretoVector($result, $capsule, $outsideActivations);
        $authorityEvidence = [
            ...(array) data_get($result, 'contextual_specialist_evidence', []),
            'identity' => [...$identity, 'identity_hash' => $cellKey],
            'screening_status' => data_get($result, 'contextual_specialist_evidence.screening_status', data_get($result, 'decision') === 'passed' ? 'passed' : null),
            'outside_scope_activation_count' => $outsideActivations,
        ];
        $assessment = $this->authority->assess($authorityEvidence);
        $confirmed = data_get($assessment, 'status') === 'contextually_confirmed_specialist';
        $harmful = (float) $pareto['after_cost_expectancy'] < 0 || $outsideActivations > 0;
        $status = $harmful ? 'anti_skill' : 'challenger';
        $authorityLevel = $confirmed ? 'contextually_confirmed' : 'research_only';
        $replaces = null;

        if (! Schema::hasTable('contextual_specialist_capsules')) {
            return ['protocol' => self::PROTOCOL, 'status' => 'migration_pending', 'pareto_vector' => $pareto,
                'authority_assessment' => $assessment, 'promotion_evidence' => false];
        }

        $capsuleKey = hash('sha256', implode('|', [self::PROTOCOL, $cellKey, (int) $agent->model_version_id]));
        DB::transaction(function () use ($agent, $result, $identity, $cellKey, $capsule, $capsuleKey, $pareto, $assessment, $authorityEvidence, $outsideActivations, $confirmed, $harmful, &$status, &$replaces): void {
            $elite = ContextualSpecialistCapsule::query()->where('symbol', strtoupper((string) $agent->symbol))
                ->where('timeframe', strtoupper((string) $agent->timeframe))->where('context_cell_key', $cellKey)
                ->where('status', 'elite')->lockForUpdate()->first();
            if ($confirmed && ! $harmful && ($elite === null || $this->dominates($pareto, (array) $elite->pareto_vector))) {
                $status = 'elite';
                if ($elite) {
                    $replaces = $elite->id;
                    $elite->update(['status' => 'retained_history']);
                }
            }
            ContextualSpecialistCapsule::query()->updateOrCreate(['capsule_key' => $capsuleKey], [
                'symbol' => strtoupper((string) $agent->symbol), 'timeframe' => strtoupper((string) $agent->timeframe),
                'context_cell_key' => $cellKey, 'model_version_id' => $agent->model_version_id,
                'lab_agent_id' => $agent->id, 'identity' => $identity,
                'components' => (array) data_get($capsule, 'components', []),
                'activation_contract' => ['exact_identity_required' => true, 'risk_veto_required' => true,
                    'outside_scope_action' => 'WAIT', 'majority_vote_forbidden' => true],
                'pareto_vector' => $pareto,
                'evidence' => ['authority' => $assessment, 'authority_evidence' => $authorityEvidence,
                    'screening_fingerprint' => hash('sha256', json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)),
                    'lower_confidence_bound' => data_get($result, 'lower_confidence_bound'), 'promotion_evidence' => false],
                'authority_level' => $confirmed ? 'contextually_confirmed' : 'research_only',
                'status' => $status, 'outside_scope_activation_count' => $outsideActivations,
                'replaces_capsule_id' => $replaces,
            ]);
        });
        $this->species->recordMembers($agent, $capsule, ['pareto_vector' => $pareto, 'authority' => $assessment]);
        $this->projectArchive($agent, $capsuleKey, $cellKey, $capsule, $pareto, $status);

        return ['protocol' => self::PROTOCOL, 'status' => $status, 'capsule_key' => $capsuleKey,
            'context_cell_key' => $cellKey, 'replaces_capsule_id' => $replaces,
            'pareto_vector' => $pareto, 'authority_level' => $authorityLevel,
            'authority_assessment' => $assessment,
            'best_known_specialist_replaced_without_proof' => false,
            'local_evidence_grants_global_inheritance' => false, 'promotion_evidence' => false];
    }

    /** @return array<string,mixed> */
    public function route(string $symbol, string $timeframe, array $context, ?array $baseline = null): array
    {
        if (! Schema::hasTable('contextual_specialist_capsules')) {
            return $this->authority->route($context, [], $baseline);
        }
        $specialists = ContextualSpecialistCapsule::query()->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe))->where('status', 'elite')->get()
            ->map(fn (ContextualSpecialistCapsule $row): array => [
                'id' => $row->id, 'identity' => $row->identity,
                'evidence' => [...(array) data_get($row->evidence, 'authority_evidence', []), 'identity' => [...(array) $row->identity, 'identity_hash' => $row->context_cell_key]],
                'lower_confidence_bound' => (float) data_get($row->evidence, 'lower_confidence_bound', -INF),
                'risk_veto' => $row->outside_scope_activation_count > 0,
            ])->all();
        return $this->authority->route($context, $specialists, $baseline);
    }

    /** @return array<string,float|int> */
    private function paretoVector(array $result, array $capsule, int $outside): array
    {
        $pf = (float) data_get($result, 'profit_factor', 0);
        $stressPf = (float) data_get($result, 'stress_test.profit_factor', data_get($result, 'stress_cost_exit.profit_factor', 0));
        $windows = (array) data_get($result, 'screening_survival.windows', []);
        $survival = $windows !== [] ? collect($windows)->filter(fn (array $row): bool => (bool) data_get($row, 'passed', false))->count() / count($windows)
            : (data_get($result, 'screening_survival.status') === 'survivor' ? 1.0 : 0.0);
        return [
            'after_cost_expectancy' => round((float) data_get($result, 'after_cost_expectancy_r', data_get($result, 'expectancy_r', data_get($result, 'net_profit_percent', 0))), 6),
            'profit_factor' => round($pf, 6),
            'temporal_survival' => round($survival, 6),
            'session_local_stability' => round((float) data_get($result, 'session_local_stability', data_get($result, 'screening_survival.stability_score', 0)), 6),
            'drawdown' => round((float) data_get($result, 'max_drawdown_percent', data_get($result, 'max_drawdown', 100)), 6),
            'tail_loss' => round((float) data_get($result, 'tail_loss', data_get($result, 'monte_carlo.expected_shortfall_percent', 100)), 6),
            'cost_sensitivity' => round(max(0, $pf - $stressPf), 6),
            'complexity' => count((array) data_get($capsule, 'component_hashes', [])),
            'outside_scope_activation' => $outside,
        ];
    }

    private function dominates(array $candidate, array $incumbent): bool
    {
        $maximize = ['after_cost_expectancy', 'profit_factor', 'temporal_survival', 'session_local_stability'];
        $minimize = ['drawdown', 'tail_loss', 'cost_sensitivity', 'complexity', 'outside_scope_activation'];
        $better = false;
        foreach ($maximize as $key) {
            if ((float) data_get($candidate, $key, -INF) < (float) data_get($incumbent, $key, -INF)) return false;
            if ((float) data_get($candidate, $key, -INF) > (float) data_get($incumbent, $key, -INF)) $better = true;
        }
        foreach ($minimize as $key) {
            if ((float) data_get($candidate, $key, INF) > (float) data_get($incumbent, $key, INF)) return false;
            if ((float) data_get($candidate, $key, INF) < (float) data_get($incumbent, $key, INF)) $better = true;
        }
        return $better;
    }

    private function projectArchive(LabAgent $agent, string $capsuleKey, string $cellKey, array $capsule, array $pareto, string $status): void
    {
        if (! Schema::hasTable('lab_evolution_archive_entries')) return;
        LabEvolutionArchiveEntry::query()->updateOrCreate([
            'archive_type' => 'contextual_capsule', 'island_key' => substr($cellKey, 0, 128),
            'model_version_id' => $agent->model_version_id,
        ], [
            'symbol' => strtoupper((string) $agent->symbol), 'timeframe' => strtoupper((string) $agent->timeframe),
            'strategy_family' => (string) $agent->strategy_family, 'lab_agent_id' => $agent->id,
            'lab_generation_id' => $agent->lab_generation_id, 'rank' => $status === 'elite' ? 1 : 0,
            'novelty_score' => $status === 'challenger' ? 1 : 0,
            'behavior_signature' => substr((string) data_get($capsule, 'genome_hash', $capsuleKey), 0, 128),
            'fitness_snapshot' => $pareto, 'metadata' => ['protocol' => self::PROTOCOL,
                'capsule_key' => $capsuleKey, 'components' => data_get($capsule, 'components'),
                'local_only' => true, 'promotion_evidence' => false], 'status' => $status,
        ]);
    }
}
