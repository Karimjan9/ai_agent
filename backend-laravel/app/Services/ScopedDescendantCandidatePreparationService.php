<?php

namespace App\Services;

use App\Models\LabSkillZooEntry;
use App\Models\ModelVersion;
use App\Models\ResearchExperimentWorkItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/** One frozen Foundry hypothesis; prospective server slots precede child creation and capture. */
class ScopedDescendantCandidatePreparationService
{
    public const WORK_TYPE = 'scoped_descendant_candidate_registration';

    public function register(int $componentCertificateId): array
    {
        $existing = ResearchExperimentWorkItem::where('work_type', self::WORK_TYPE)
            ->where('payload->source_component_certificate_id', $componentCertificateId)->first();
        if ($existing) {
            return ['status' => 'already_registered', 'work_id' => (int) $existing->id];
        }
        $proposal = app(EvolutionaryAuthorityFoundryService::class)->proposeScopedDescendant($componentCertificateId);
        if (($proposal['status'] ?? null) !== 'prospective_hypothesis_dependency') {
            return $proposal;
        }
        $certificate = app(ScopedResearchCertificateService::class)->inspect($componentCertificateId);
        $cartridge = LabSkillZooEntry::findOrFail($proposal['source_cartridge_id']);
        $design = $certificate['design'];
        $identity = $proposal['proposal_hash'];
        $contract = ['contract_version' => ResearchExperimentConversionKernelService::CONTRACT_VERSION,
            'source' => ['type' => LabSkillZooEntry::class, 'id' => (int) $cartridge->id],
            'scope' => ['symbol' => $cartridge->symbol, 'laboratory_timeframe' => $cartridge->timeframe, 'execution_timeframe' => 'M5'],
            'identity' => ['baseline_epoch_hash' => $certificate['design_hash'], 'data_and_mtf_hash' => $identity,
                'runtime_and_contract_hash' => $design['execution_hash'], 'intervention_hash' => $identity,
                'window_plan_hash' => $identity, 'evaluator_version' => $design['evaluator_hash']],
            'arms' => array_map(fn ($arm) => ['role' => $arm], DescendantScopedProofService::ARMS)];

        return app(ResearchExperimentConversionKernelService::class)->record($contract, ['prospective_proposal' => $proposal],
            'INCONCLUSIVE', ['type' => self::WORK_TYPE, 'identity' => $identity,
                'source_component_certificate_id' => $componentCertificateId, 'frozen_proposal' => $proposal,
                'purpose' => 'prospective_scoped_descendant_candidate', 'executable' => false,
                'retry_condition' => ['code' => 'SCOPED_DESCENDANT_FUTURE_SERVER_ROSTER_REQUIRED', 'max_experiments' => 1,
                    'same_evidence_replay_forbidden' => true], 'promotion_evidence' => false]);
    }

    public function inspectWork(ResearchExperimentWorkItem $work): array
    {
        try {
            $proposal = (array) data_get($work->payload, 'frozen_proposal');
            $hash = app(ResearchPaperEpochContractService::class)->parameterHash(array_diff_key($proposal, ['proposal_hash' => true]));
            if ($work->work_type !== self::WORK_TYPE || data_get($work->payload, 'owner') !== ResearchLoopArbiterService::class
                || data_get($work->payload, 'executor') !== ResearchExperimentWorkConsumerService::class
                || ($proposal['proposal_hash'] ?? null) !== $hash || ($proposal['max_proposals'] ?? null) !== 1
                || data_get($work->receipt?->payload, 'evidence.prospective_proposal.proposal_hash') !== $hash
                || ($proposal['current_source_hash'] ?? null) !== app(LabImmutableEvidenceService::class)->codeHash()
                || ($proposal['source_component_certificate_id'] ?? null) !== data_get($work->payload, 'source_component_certificate_id')) {
                throw new LogicException('SCOPED_DESCENDANT_FROZEN_PROPOSAL_OWNER_DRIFT');
            }
            $certificate = app(ScopedResearchCertificateService::class)->inspect($proposal['source_component_certificate_id']);
            if (data_get($certificate, 'original_authority.component.confirmed') !== true
                || ($certificate['design_hash'] ?? null) !== $proposal['source_design_hash']
                || data_get($certificate, 'original_authority.component.context_hash') !== $proposal['context_hash']) {
                throw new LogicException('SCOPED_DESCENDANT_ORIGINAL_COMPONENT_AUTHORITY_REQUIRED');
            }
            $cartridge = LabSkillZooEntry::find($proposal['source_cartridge_id'] ?? 0);
            $revision = $cartridge ? DB::table('skill_cartridge_revisions')->where('lab_skill_zoo_entry_id', $cartridge->id)
                ->where('revision', $proposal['source_cartridge_revision'] ?? 0)->first() : null;
            if (! $cartridge || $cartridge->status !== 'scoped_confirmed' || $cartridge->component_status !== 'scoped_component_confirmed'
                || data_get($cartridge->evidence, 'scoped_component_certificate_id') !== $proposal['source_component_certificate_id']
                || ! $revision || app(ResearchPaperEpochContractService::class)->parameterHash(json_decode($revision->payload, true))
                    !== $proposal['source_revision_payload_hash']) {
                throw new LogicException('SCOPED_DESCENDANT_ORIGINAL_COMPONENT_REVISION_DRIFT');
            }
            if (CarbonImmutable::parse($proposal['window_draft'][0]['start_inclusive'])->lte(now()->utc())) {
                throw new LogicException('SCOPED_DESCENDANT_PROSPECTIVE_CAPTURE_DEADLINE_PASSED');
            }
            $ids = [];
            foreach ($proposal['window_draft'] as $period) {
                $records = array_values(array_filter((array) config('services.instrument_policy.authorized_research_windows', []),
                    fn ($row): bool => is_array($row) && ($row['purpose'] ?? null) === 'instrument_independent_validation'
                        && is_string($row['authorization_id'] ?? null) && $row['authorization_id'] !== ''
                        && CarbonImmutable::parse((string) ($row['start_inclusive'] ?? ''))->eq(CarbonImmutable::parse($period['start_inclusive']))
                        && CarbonImmutable::parse((string) ($row['end_exclusive'] ?? ''))->eq(CarbonImmutable::parse($period['end_exclusive']))));
                if (count($records) !== 1) {
                    throw new LogicException('SCOPED_DESCENDANT_FUTURE_SERVER_ROSTER_REQUIRED');
                }
                $ids[] = $records[0]['authorization_id'];
            }
            if (count(array_unique($ids)) !== count($ids)) {
                throw new LogicException('SCOPED_DESCENDANT_SERVER_WINDOW_IDENTITIES_REUSED');
            }

            return ['executable' => true, 'proposal' => $proposal, 'parent_certificate' => $certificate, 'authorization_ids' => $ids];
        } catch (Throwable $error) {
            return ['executable' => false, 'reason_code' => $error instanceof LogicException
                ? $error->getMessage() : 'SCOPED_DESCENDANT_ORIGINAL_PROPOSAL_DEPENDENCY', 'promotion_evidence' => false];
        }
    }

    public function execute(ResearchExperimentWorkItem $work): array
    {
        $native = app(DescendantScopedExecutionService::class);
        try {
            $native->assertLease($work);
            $proof = $this->inspectWork($work);
            if (! $proof['executable']) {
                app(ResearchExperimentConversionKernelService::class)->defer($work, $proof['reason_code'], false);

                return ['status' => 'blocked', ...$proof];
            }

            return DB::transaction(function () use ($work, $native, $proof): array {
                $current = ResearchExperimentWorkItem::whereKey($work->id)->lockForUpdate()->firstOrFail();
                $native->assertLease($work);
                if (data_get($current->result, 'registered_descendant') !== null) {
                    throw new LogicException('SCOPED_DESCENDANT_ORIGINAL_CHILD_ALREADY_REGISTERED');
                }
                $proposal = $proof['proposal'];
                $parent = $proof['parent_certificate']['design'];
                $cartridge = LabSkillZooEntry::findOrFail($proposal['source_cartridge_id']);
                $models = [];
                foreach (DescendantScopedProofService::ARMS as $arm) {
                    $native->assertLease($work);
                    $source = ModelVersion::findOrFail($proposal['source_model_ids'][in_array($arm, ['P', 'P+U'], true) ? 'P' : 'P+T']);
                    $metadata = app(EvolutionaryAuthorityFoundryService::class)->scopedChildMetadata($source,
                        ['work_item_id' => (int) $work->id, 'proposal_hash' => $proposal['proposal_hash'],
                            'arm' => $arm, 'source_component_certificate_id' => $proposal['source_component_certificate_id'],
                            'new_version' => $source->version.'-scoped-'.$work->id],
                        $proposal['parameter_vectors'][$arm]);
                    $models[$arm] = ModelVersion::create(['name' => 'scoped descendant '.$work->id.' '.$arm,
                        'strategy' => $source->strategy, 'version' => $source->version.'-scoped-'.$work->id,
                        'status' => 'testing', 'parameters' => $proposal['parameter_vectors'][$arm], 'metadata' => $metadata]);
                }
                $input = $parent['native_execution'];
                $nativeInput = Arr::only($input, ['execution_timeframe', 'initial_capital', 'risk_policy', 'full_replay_runtime_policy']);
                $native->assertLease($work);
                $registration = app(CanonicalSkillCartridgeService::class)->preregisterDescendantProof($cartridge, $models, [
                    'validation_start' => $proposal['window_draft'][0]['start_inclusive'],
                    'validation_end' => $proposal['window_draft'][count($proposal['window_draft']) - 1]['end_exclusive'],
                    'validation_windows' => $proposal['window_draft'], 'context' => $proposal['context'],
                    'exposure_policy' => $parent['exposure_policy'], 'execution_hash' => $parent['execution_hash'],
                    'statistical_guard' => $parent['statistical_guard'], 'risk_guard' => $parent['risk_guard'],
                    'evaluator_hash' => app(LabImmutableEvidenceService::class)->codeHash(), 'metric' => $parent['metric'],
                    'stopping_rule' => $parent['stopping_rule'], 'native_execution' => [...$nativeInput,
                        'source_component_certificate_id' => $proposal['source_component_certificate_id'],
                        'authorization_ids' => $proof['authorization_ids']]]);
                if (($registration['status'] ?? null) !== 'scoped_preregistered') {
                    throw new LogicException($registration['reason_code'] ?? 'SCOPED_DESCENDANT_ORIGINAL_REGISTRATION_REFUSED');
                }
                $native->assertLease($work);
                $completed = app(ResearchExperimentConversionKernelService::class)->complete($work, [
                    'status' => 'prospective_descendant_registered', 'registered_descendant' => $registration,
                    'original_component_certificate_id' => $proposal['source_component_certificate_id'],
                    'actual_data_available' => false, 'promotion_evidence' => false]);
                if (! $completed) {
                    throw new LogicException('DESCENDANT_WORK_LEASE_NOT_CURRENT');
                }

                return ['status' => 'completed', 'registration' => $registration, 'promotion_evidence' => false];
            });
        } catch (Throwable $error) {
            $reason = $error instanceof LogicException ? $error->getMessage() : 'SCOPED_DESCENDANT_PROSPECTIVE_CONSTRUCTION_DEPENDENCY';
            app(ResearchExperimentConversionKernelService::class)->defer($work, $reason, false);

            return ['status' => 'blocked', 'reason_code' => $reason, 'promotion_evidence' => false];
        }
    }
}
