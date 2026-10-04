<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\CandidateGateDecision;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use Illuminate\Support\Collection;
use Throwable;

/** Prequeue identity gate for a research-only prospective venue-phase probe. */
class PhaseScopeProbeContractService
{
    public function __construct(
        private StrategyParameterSchemaService $schemas,
        private LabImmutableEvidenceService $evidence,
    ) {}

    /** @return array<int,string> */
    public function reasons(LabGeneration $generation): array
    {
        $generation->loadMissing('agents.modelVersion');
        $groups = $generation->agents->filter(fn ($agent): bool =>
            data_get($agent->modelVersion?->metadata, 'cooperative_experiment_block.block_type') === 'phase_scope_probe'
        )->groupBy(fn ($agent): string => (string) data_get($agent->modelVersion?->metadata,
            'cooperative_experiment_block.block_key', ''));
        $planned = collect((array) data_get($generation->trigger_context, 'generation_plan', []))
            ->filter(fn (mixed $slot): bool => is_array($slot)
                && data_get($slot, 'niche.cooperative_experiment_block.block_type') === 'phase_scope_probe')
            ->groupBy(fn (array $slot): string => (string) data_get($slot,
                'niche.cooperative_experiment_block.block_key', ''));
        $reasons = [];
        $proposal = (array) data_get($generation->trigger_context,
            'specialist_council_contract.contextual_allocator.phase_scope_probe.proposal', []);
        foreach ($planned as $key => $slots) {
            if ($key === '' || $slots->count() !== 2 || ! $groups->has($key)) {
                $reasons[] = 'PHASE_PROBE_PLANNED_BLOCK_MISSING';
                continue;
            }
            $agentsByArm = $groups->get($key)->keyBy(fn ($agent): string => (string) data_get(
                $agent->modelVersion?->metadata, 'cooperative_experiment_block.arm', ''));
            foreach ($slots as $slot) {
                $arm = (string) data_get($slot, 'niche.cooperative_experiment_block.arm', '');
                $model = $agentsByArm->get($arm)?->modelVersion;
                $actualProbe = (array) data_get($model?->metadata, 'phase_scope_probe', []);
                unset($actualProbe['executable_hash']);
                if (! $model
                    || ! $this->evidence->equivalentJsonValue(
                        data_get($slot, 'niche.cooperative_experiment_block'),
                        data_get($model->metadata, 'cooperative_experiment_block'))
                    || ! $this->evidence->equivalentJsonValue(
                        data_get($slot, 'niche.phase_scope_probe'), $actualProbe)
                    || (string) data_get($slot, 'niche.phase_scope_probe.probe_key', '')
                        !== (string) data_get($proposal, 'probe_key', '')
                    || (string) data_get($slot, 'niche.phase_scope_probe.venue_phase', '')
                        !== (string) data_get($proposal, 'venue_phase', '')) {
                    $reasons[] = 'PHASE_PROBE_PLANNED_IDENTITY_DRIFT';
                }
            }
        }
        foreach ($groups as $key => $agents) {
            if (! $planned->has($key)) {
                $reasons[] = 'PHASE_PROBE_PLANNED_BLOCK_MISSING';
            }
            if ($key === '') {
                $reasons[] = 'PHASE_PROBE_BLOCK_KEY_MISSING';
            } else {
                $reasons = [...$reasons, ...$this->blockReasons($agents, $generation)];
            }
        }

        return array_values(array_unique($reasons));
    }

    /** @return array<int,string> */
    public function blockReasons(Collection $agents, LabGeneration $generation): array
    {
        $byArm = $agents->keyBy(fn ($agent): string => (string) data_get($agent->modelVersion?->metadata,
            'cooperative_experiment_block.arm', ''));
        if ($agents->count() !== 2 || $byArm->count() !== 2
            || ! $byArm->has('phase_control') || ! $byArm->has('diagnostic_candidate')) {
            return ['PHASE_PROBE_TWO_ARMS_REQUIRED'];
        }
        $control = $byArm->get('phase_control');
        $candidate = $byArm->get('diagnostic_candidate');
        $contract = (array) data_get($control->modelVersion?->metadata, 'phase_scope_probe', []);
        $probeProtocol = (string) data_get($contract, 'protocol', ProofFrontierService::PHASE_PROBE_PROTOCOL);
        $block = (array) data_get($control->modelVersion?->metadata, 'cooperative_experiment_block', []);
        $phase = (string) data_get($contract, 'venue_phase', '');
        $components = (array) data_get($control->modelVersion?->metadata,
            'smart_composition.composition_passport.components', []);
        $cellHash = (string) data_get($control->modelVersion?->metadata,
            'specialist_council_membership.contextual_cell.cell_hash', '');
        $manifest = hash('sha256', json_encode([
            $probeProtocol, (string) data_get($block, 'block_key'),
            data_get($contract, 'probe_key'), data_get($contract, 'source_agent_id'),
            data_get($contract, 'source_model_version_id'), data_get($contract, 'source_parameter_hash'),
            data_get($contract, 'source_run_id'), data_get($contract, 'source_response_hash'),
            data_get($contract, 'source_data_hash'), data_get($contract, 'source_execution_hash'),
            data_get($contract, 'source_mtf_bundle_hash'), data_get($contract, 'source_composition_id'),
            ...($probeProtocol === ProofFrontierService::PHASE_PROBE_REFREEZE_PROTOCOL
                ? [data_get($contract, 'prospective_composition_id'),
                    data_get($contract, 'prospective_passport_hash')] : []),
            $components, $phase, data_get($contract, 'diagnostic_intervention'), $cellHash,
        ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        $reasons = [];
        $source = LabAgent::query()->with(['modelVersion', 'generation'])
            ->find((int) data_get($contract, 'source_agent_id', 0));
        $sourceRun = $source ? LabEvaluationRun::query()->where('lab_agent_id', $source->id)
            ->where('run_id', (string) data_get($contract, 'source_run_id', ''))
            ->where('phase', 'screening')->where('status', 'completed')->first() : null;
        $sourceHash = hash('sha256', json_encode(
            $this->schemas->canonicalizeForIdentity((string) $control->strategy_family,
                (array) $source?->modelVersion?->parameters),
            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        if (! $source?->modelVersion || ! $sourceRun
            || (int) $source->generation?->ai_laboratory_id !== (int) $generation->ai_laboratory_id
            || (int) $source->model_version_id !== (int) data_get($contract, 'source_model_version_id', 0)
            || (string) $source->strategy_family !== (string) $control->strategy_family
            || (string) $sourceRun->data_hash !== (string) data_get($contract, 'source_data_hash')
            || (string) $sourceRun->response_hash !== (string) data_get($contract, 'source_response_hash')
            || (array) data_get($source->modelVersion->metadata,
                'smart_composition.composition_passport.components', []) !== $components
            || (string) data_get($source->modelVersion->metadata,
                'smart_composition.composition_passport.composition_id', '')
                !== (string) data_get($contract, 'source_composition_id', '')
            || filled(data_get($source->modelVersion->metadata,
                'specialist_council_membership.contextual_cell.venue_phase'))
            || ! hash_equals((string) data_get($contract, 'source_parameter_hash', ''), $sourceHash)) {
            $reasons[] = 'PHASE_PROBE_SOURCE_IDENTITY_MISMATCH';
        }
        if ($source?->modelVersion) {
            $sourcePassport = (array) data_get($source->modelVersion->metadata,
                'smart_composition.composition_passport', []);
            if ($probeProtocol === ProofFrontierService::PHASE_PROBE_REFREEZE_PROTOCOL) {
                try {
                    $runtimeBase = $this->schemas->runtimeBaseStrategy(
                        (string) $source->modelVersion->strategy,
                        data_get($source->modelVersion->metadata, 'base_strategy'),
                        (string) $source->strategy_family,
                    );
                    $prospective = app(CompositionAuthorityKernelService::class)
                        ->refreezeHistoricalHypothesis($sourcePassport, $runtimeBase);
                    $prospectiveHash = hash('sha256', json_encode($prospective,
                        JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
                    if ((string) data_get($contract, 'prospective_composition_id')
                            !== (string) data_get($prospective, 'composition_id')
                        || ! hash_equals((string) data_get($contract, 'prospective_passport_hash', ''),
                            $prospectiveHash)
                        || ! $this->evidence->equivalentJsonValue($prospective,
                            data_get($control->modelVersion?->metadata,
                                'smart_composition.composition_passport'))) {
                        $reasons[] = 'PHASE_PROBE_PROSPECTIVE_PASSPORT_INVALID';
                    }
                } catch (Throwable) {
                    $reasons[] = 'PHASE_PROBE_PROSPECTIVE_PASSPORT_INVALID';
                }
            } elseif ($probeProtocol === ProofFrontierService::PHASE_PROBE_PROTOCOL
                && (array) data_get($sourcePassport, 'strategy_signal_scope', []) === []) {
                $reasons[] = 'PHASE_PROBE_LEGACY_SCOPE_UNBOUND';
            }
        }
        if ($sourceRun) {
            try {
                $sourceDecision = CandidateGateDecision::query()
                    ->where('lab_agent_id', $source?->id)->where('stage', 'screening')
                    ->latest('id')->first();
                $request = $this->evidence->latestArtifactPayload($sourceRun, 'evaluation_request');
                $response = $this->evidence->latestArtifactPayload($sourceRun);
                $strategy = is_array($request) ? collect((array) data_get($request, 'strategies', []))
                    ->first(fn (mixed $row): bool => is_array($row)
                        && (int) data_get($row, 'lab_agent_id', 0) === (int) $source?->id) : null;
                if (! $this->evidence->learningEligibility($sourceRun)['complete']
                    || ! is_array($request) || ! is_array($response) || ! is_array($strategy)
                    || ! $this->evidence->equivalentJsonValue($request,
                        data_get($sourceRun->request_meta, 'payload'))
                    || ! $this->evidence->equivalentJsonValue(
                        data_get($response, 'composition_runtime_trace'),
                        data_get($sourceDecision?->metrics, 'composition_runtime_trace'))
                    || (string) data_get($sourceDecision?->metrics, 'evidence_run_id', '')
                        !== (string) $sourceRun->run_id
                    || filled(data_get($strategy, 'specialist_context_contract.venue_phase'))
                    || (string) data_get($strategy, 'composition_runtime_contract.composition_id', '')
                        !== (string) data_get($contract, 'source_composition_id', '')
                    || (string) data_get($request, 'execution_contract.execution_hash', '')
                        !== (string) data_get($contract, 'source_execution_hash', '')
                    || (string) data_get($sourceRun->request_meta, 'dataset_manifest.mtf_bundle_hash', '')
                        !== (string) data_get($contract, 'source_mtf_bundle_hash', '')) {
                    $reasons[] = 'PHASE_PROBE_SOURCE_EVIDENCE_INVALID';
                }
            } catch (Throwable) {
                $reasons[] = 'PHASE_PROBE_SOURCE_EVIDENCE_INVALID';
            }
        }
        if ((string) data_get($block, 'phase_probe_manifest_hash') !== $manifest
            || ! in_array($probeProtocol, [ProofFrontierService::PHASE_PROBE_PROTOCOL,
                ProofFrontierService::PHASE_PROBE_REFREEZE_PROTOCOL], true)
            || strlen((string) data_get($contract, 'probe_key', '')) !== 64
            || strlen($cellHash) !== 64
            || $components === [] || $phase !== 'london_comex_overlap'
            || (string) data_get($contract, 'phase_selection_policy') !== 'fixed_before_replay_v1'
            || data_get($contract, 'credit_allowed') !== false
            || data_get($contract, 'promotion_evidence') !== false) {
            $reasons[] = 'PHASE_PROBE_MANIFEST_INVALID';
        }
        $cutoff = (string) data_get($generation->trigger_context,
            'mtf_bundle_manifest.streams.M5.last_candle_at', '');
        if ($cutoff === '' || strcmp($cutoff, '2026-01-01') >= 0) {
            $reasons[] = 'PHASE_PROBE_PAPER_EPOCH_FORBIDDEN';
        }
        foreach (['phase_control' => $control, 'diagnostic_candidate' => $candidate] as $arm => $agent) {
            $actualBlock = (array) data_get($agent->modelVersion?->metadata, 'cooperative_experiment_block', []);
            $actual = (array) data_get($agent->modelVersion?->metadata, 'phase_scope_probe', []);
            $actualCell = (array) data_get($agent->modelVersion?->metadata,
                'specialist_council_membership.contextual_cell', []);
            $expectedHash = hash('sha256', json_encode([
                $probeProtocol, (string) $agent->strategy_family,
                $this->schemas->canonicalizeForIdentity((string) $agent->strategy_family,
                    (array) $agent->modelVersion?->parameters), $components, $cellHash,
                ...($probeProtocol === ProofFrontierService::PHASE_PROBE_REFREEZE_PROTOCOL
                    ? [(string) data_get($contract, 'prospective_passport_hash', '')] : []),
            ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
            if ((string) data_get($actualBlock, 'phase_probe_manifest_hash') !== $manifest
                || (string) data_get($actual, 'probe_key') !== (string) data_get($contract, 'probe_key')
                || (string) data_get($actual, 'arm') !== $arm
                || (string) data_get($actual, 'executable_hash') !== $expectedHash
                || (string) data_get($actualCell, 'venue_phase') !== $phase
                || (string) data_get($actualCell, 'cell_hash') !== $cellHash
                || (array) data_get($agent->modelVersion?->metadata,
                    'smart_composition.composition_passport.components', []) !== $components) {
                $reasons[] = 'PHASE_PROBE_ARM_IDENTITY_MISMATCH';
            }
        }
        $controlParameters = (array) $control->modelVersion?->parameters;
        $candidateParameters = (array) $candidate->modelVersion?->parameters;
        $intervention = (array) data_get($contract, 'diagnostic_intervention', []);
        $gene = (string) data_get($intervention, 'gene', '');
        $changed = [];
        foreach (array_unique([...array_keys($controlParameters), ...array_keys($candidateParameters)]) as $key) {
            if (json_encode($controlParameters[$key] ?? null, JSON_PRESERVE_ZERO_FRACTION)
                !== json_encode($candidateParameters[$key] ?? null, JSON_PRESERVE_ZERO_FRACTION)) {
                $changed[] = $key;
            }
        }
        if ((int) data_get($control->modelVersion?->metadata, 'phase_scope_baseline_model_version_id', 0)
                !== (int) data_get($contract, 'source_model_version_id', 0)
            || ! hash_equals((string) data_get($contract, 'source_parameter_hash', ''),
                hash('sha256', json_encode($this->schemas->canonicalizeForIdentity(
                    (string) $control->strategy_family, $controlParameters),
                    JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)))
            || (int) data_get($candidate->modelVersion?->metadata, 'causal_baseline_model_version_id', 0)
                !== (int) $control->model_version_id
            || $changed !== [$gene]
            || json_encode($candidateParameters[$gene] ?? null, JSON_PRESERVE_ZERO_FRACTION)
                !== json_encode(data_get($intervention, 'value'), JSON_PRESERVE_ZERO_FRACTION)) {
            $reasons[] = 'PHASE_PROBE_EXECUTABLE_DELTA_MISMATCH';
        }

        return array_values(array_unique($reasons));
    }
}
