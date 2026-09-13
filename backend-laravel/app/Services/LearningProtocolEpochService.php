<?php

namespace App\Services;

use App\Models\AgentLearningCausalExperiment;
use App\Models\AgentLearningLesson;
use App\Models\AgentLearningMutationIntent;
use App\Models\AgentLearningSettlement;
use App\Models\LabGeneration;
use App\Models\LabLearningLanePair;
use App\Models\LearningProtocolEpochLink;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Creates a non-retroactive truth boundary for the learning chain.
 *
 * Historical rows remain usable as hypotheses, but can never enter a new-v2
 * denominator merely because a projection was deployed after they ran.
 */
class LearningProtocolEpochService
{
    public const PROTOCOL = 'post_v2_learning_truth_epoch_v1';

    public const CURRENT_EPOCH = 'post_v2_2026_09_13';

    /** @return array<string,mixed> */
    public function generationContract(): array
    {
        return [
            'protocol' => self::PROTOCOL,
            'epoch' => self::CURRENT_EPOCH,
            'status' => 'open_pending_verified_chain',
            'started_at' => now()->utc()->toIso8601String(),
            'legacy_rows_grandfathered' => false,
            'denominator_rule' => 'Only entities created under this sealed epoch and linked to an exact verified control chain count.',
            'required_links' => ['generation', 'intent', 'pair', 'settlement', 'lesson', 'authority'],
            'promotion_evidence' => false,
        ];
    }

    public function epochFor(?LabGeneration $generation): ?string
    {
        $contract = (array) data_get($generation?->trigger_context, 'learning_protocol_epoch', []);

        return data_get($contract, 'protocol') === self::PROTOCOL
            && data_get($contract, 'epoch') === self::CURRENT_EPOCH
            ? self::CURRENT_EPOCH
            : null;
    }

    /**
     * Seal the truth epoch at the constructor boundary of a newly inserted
     * generation. The wasRecentlyCreated guard deliberately forbids using
     * this method to backfill historical evidence after it has run.
     *
     * @return array<string,mixed>
     */
    public function openForNewGeneration(LabGeneration $generation, string $symbol, string $timeframe): array
    {
        if (! $generation->wasRecentlyCreated) {
            throw new \RuntimeException('POST_V2_EPOCH_RETROACTIVE_BACKFILL_FORBIDDEN');
        }

        $contract = $this->generationContract();
        $generation->update(['trigger_context' => [
            ...((array) $generation->trigger_context),
            'learning_protocol_epoch' => $contract,
        ]]);
        $link = $this->registerGeneration($generation->fresh(), $symbol, $timeframe);

        return [
            'protocol' => self::PROTOCOL,
            'epoch' => self::CURRENT_EPOCH,
            'generation_id' => (int) $generation->id,
            'contract' => $contract,
            'link' => $link,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function registerGeneration(LabGeneration $generation, string $symbol, string $timeframe): array
    {
        return $this->link($generation, $generation, $symbol, $timeframe, [
            'linkage_complete' => false,
            'entity_role' => 'generation_root',
        ]);
    }

    /**
     * Bind every presently available source row. Missing lesson/authority
     * links remain explicit open debt; they are never synthesized.
     *
     * @return array<string,mixed>
     */
    public function bindCausalChain(
        AgentLearningCausalExperiment $experiment,
        ?LabLearningLanePair $pair,
        ?AgentLearningSettlement $settlement,
        bool $receiptValid,
    ): array {
        $generation = LabGeneration::query()->find($experiment->lab_generation_id);
        $dataHash = trim((string) ($pair?->candidate_data_hash ?: $pair?->control_data_hash));
        $executionHash = trim((string) ($pair?->candidate_execution_hash ?: $pair?->control_execution_hash));
        $exact = $pair?->isVerifiedControlPair() === true;
        $linked = $pair !== null
            && (int) $pair->candidate_agent_id === (int) $experiment->guided_agent_id
            && (int) $pair->control_agent_id === (int) $experiment->control_agent_id
            && $dataHash !== '' && $executionHash !== '';
        $common = [
            'linkage_complete' => $linked && $receiptValid,
            'exact_frozen_control' => $exact,
            'data_hash' => $dataHash,
            'execution_hash' => $executionHash,
            'source_ids' => ['experiment_id' => $experiment->id, 'pair_id' => $pair?->id, 'settlement_id' => $settlement?->id],
        ];
        $links = [
            'generation' => $generation ? $this->link($generation, $generation, $experiment->symbol, $experiment->timeframe, $common) : null,
            'experiment' => $this->link($experiment, $generation, $experiment->symbol, $experiment->timeframe, $common),
            'pair' => $pair ? $this->link($pair, $generation, $experiment->symbol, $experiment->timeframe, $common) : null,
            'settlement' => $settlement ? $this->link($settlement, $generation, $experiment->symbol, $experiment->timeframe, $common) : null,
        ];
        $intent = AgentLearningMutationIntent::query()
            ->where('lab_generation_id', $experiment->lab_generation_id)
            ->where('lab_agent_id', $experiment->guided_agent_id)
            ->where('selected_gene', $experiment->gene_key)
            ->latest('id')->first();
        $lesson = $experiment->source_lesson_id
            ? AgentLearningLesson::query()->find($experiment->source_lesson_id)
            : null;
        $links['intent'] = $intent ? $this->link($intent, $generation, $experiment->symbol, $experiment->timeframe, $common) : null;
        $links['lesson'] = $lesson ? $this->link($lesson, $generation, $experiment->symbol, $experiment->timeframe, $common) : null;
        $links['authority'] = null;
        $required = ['generation', 'intent', 'experiment', 'pair', 'settlement', 'lesson', 'authority'];
        $closed = collect($required)->every(fn (string $role): bool => data_get($links, $role.'.eligible_for_v2_denominator') === true);

        return [
            'protocol' => self::PROTOCOL,
            'epoch' => $this->epochFor($generation),
            'status' => $closed ? 'closed_verified_chain' : 'open_linkage_debt',
            'links' => $links,
            'missing_or_unverified_roles' => collect($required)->reject(
                fn (string $role): bool => data_get($links, $role.'.eligible_for_v2_denominator') === true,
            )->values()->all(),
            'eligible_for_full_chain_denominator' => $closed,
            'promotion_evidence' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $evidence
     * @return array<string,mixed>
     */
    public function link(Model $entity, ?LabGeneration $generation, string $symbol, string $timeframe, array $evidence): array
    {
        $epoch = $this->epochFor($generation);
        if ($epoch === null) {
            return [
                'protocol' => self::PROTOCOL,
                'status' => 'legacy_or_unscoped_excluded',
                'eligible_for_v2_denominator' => false,
                'legacy_authority_allowed' => false,
                'promotion_evidence' => false,
            ];
        }
        $entityId = (int) $entity->getKey();
        if ($entityId <= 0 || ! Schema::hasTable('learning_protocol_epoch_links')) {
            return [
                'protocol' => self::PROTOCOL,
                'status' => 'migration_or_entity_pending',
                'eligible_for_v2_denominator' => false,
                'promotion_evidence' => false,
            ];
        }

        $entityType = $entity::class;
        $dataHash = trim((string) data_get($evidence, 'data_hash', '')) ?: null;
        $executionHash = trim((string) data_get($evidence, 'execution_hash', '')) ?: null;
        $linkageComplete = data_get($evidence, 'linkage_complete') === true;
        $eligible = $linkageComplete
            && data_get($evidence, 'exact_frozen_control') === true
            && $dataHash !== null
            && $executionHash !== null;
        $sourceLinkHash = hash('sha256', json_encode([
            self::PROTOCOL, $epoch, $entityType, $entityId, $generation?->id,
            $dataHash, $executionHash, data_get($evidence, 'source_ids', []),
        ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        $linkKey = hash('sha256', implode('|', [$epoch, $entityType, (string) $entityId]));
        $row = LearningProtocolEpochLink::query()->updateOrCreate(
            ['link_key' => $linkKey],
            [
                'protocol_epoch' => $epoch,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'lab_generation_id' => $generation?->id,
                'symbol' => strtoupper($symbol),
                'timeframe' => strtoupper($timeframe),
                'data_hash' => $dataHash,
                'execution_hash' => $executionHash,
                'source_link_hash' => $sourceLinkHash,
                'linkage_status' => $eligible ? 'verified_exact_chain' : 'open_pending_chain',
                'eligible_for_v2_denominator' => $eligible,
                'evidence' => [...$evidence, 'promotion_evidence' => false],
            ],
        );

        return [
            'protocol' => self::PROTOCOL,
            'epoch' => $epoch,
            'status' => $row->linkage_status,
            'link_id' => (int) $row->id,
            'source_link_hash' => $sourceLinkHash,
            'eligible_for_v2_denominator' => $eligible,
            'promotion_evidence' => false,
        ];
    }
}
