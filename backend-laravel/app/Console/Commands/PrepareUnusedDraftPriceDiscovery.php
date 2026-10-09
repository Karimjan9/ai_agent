<?php

namespace App\Console\Commands;

use App\Models\LabGeneration;
use App\Models\ResearchLoopDecision;
use App\Services\OperatorApprovalService;
use App\Services\UnusedDraftPriceDiscoveryPreparationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/** Register user intent, then prepare/dispatch only under the original current arbiter decision. */
class PrepareUnusedDraftPriceDiscovery extends Command
{
    protected $signature = 'trading:prepare-unused-price-discovery {generation : Exact database generation id}
        {--register-intent} {--dataset=} {--question=} {--expected-intent-hash=} {--apply}
        {--approved-by=} {--approval-reason=} {--research-loop-decision=} {--dispatch}';
    protected $description = 'Bounded research-only mixed price discovery on the original unused G263 twenty models.';

    public function handle(UnusedDraftPriceDiscoveryPreparationService $owner, OperatorApprovalService $approvals): int
    {
        try {
            $generation = LabGeneration::findOrFail((int) $this->argument('generation'));
            if ((int) $generation->id !== UnusedDraftPriceDiscoveryPreparationService::GENERATION_ID) {
                throw new \LogicException('UNUSED_PRICE_DISCOVERY_EXACT_GENERATION_ARGUMENT_REQUIRED');
            }
            if ($this->option('register-intent')) {
                if ($this->option('dispatch') || $this->option('research-loop-decision')) throw new \LogicException('UNUSED_PRICE_DISCOVERY_SINGLE_OPERATION_REQUIRED');
                $preview = $owner->registration($generation, (string) $this->option('dataset'), (string) $this->option('question'));
                if (! $this->option('apply')) return $this->result(['status' => 'would_register', 'intent_hash' => $preview['intent_hash'],
                    'physical_question_key' => $preview['physical_question_key'], 'original_snapshot_hash' => $preview['original_snapshot_hash'],
                    'generation_id' => (int) $generation->id, 'resource_contract' => $preview['resource_contract']]);
                if ($this->option('expected-intent-hash') !== $preview['intent_hash']) throw new \LogicException('UNUSED_PRICE_DISCOVERY_EXACT_PREVIEW_HASH_REQUIRED');
                $approval = $approvals->requireForApply('unused-draft-price-discovery-intent', $this->option('approved-by'),
                    $this->option('approval-reason'), ['generation_id' => (int) $generation->id, 'intent_hash' => $preview['intent_hash']]);
                $intent = $owner->registerIntent($generation, $preview, $approval);
                return $this->result(['status' => 'registered', 'generation_id' => (int) $generation->id,
                    'intent_hash' => $intent['intent_hash'], 'physical_question_key' => $intent['physical_question_key'],
                    'preparation_delegated_to_existing_arbiter' => true, 'jobs_dispatched' => 0]);
            }
            if ($this->option('apply') || $this->option('dataset') || $this->option('question') || $this->option('expected-intent-hash')) {
                throw new \LogicException('UNUSED_PRICE_DISCOVERY_ARBITER_EXECUTION_TAKES_NO_CALLER_SCOPE');
            }
            $decision = ResearchLoopDecision::findOrFail((int) $this->option('research-loop-decision'));
            if (! $this->option('dispatch')) {
                $prepared = $owner->prepareFromDecision($decision);
                return $this->result(['status' => 'prepared', 'generation_id' => $prepared['generation_id'],
                    'preparation_hash' => $prepared['preparation_hash'], 'jobs_dispatched' => 0]);
            }
            $generation = $owner->decisionGeneration($decision, 'DISPATCH_UNUSED_DRAFT_PRICE_DISCOVERY');
            if (data_get($decision->arguments, '--dispatch') !== true) throw new \LogicException('UNUSED_PRICE_DISCOVERY_ORIGINAL_DISPATCH_FENCE_REQUIRED');
            $prepared = $owner->assertOwner($generation, (array) data_get($generation->trigger_context, 'mtf_bundle_manifest', []));
            if (data_get($decision->evidence_snapshot, 'price_discovery_proposal.preparation_hash') !== $prepared['preparation_hash']) {
                throw new \LogicException('UNUSED_PRICE_DISCOVERY_DISPATCH_PREPARATION_DRIFT');
            }
            $exit = Artisan::call('trading:dispatch-lab', ['symbol' => 'XAUUSD', '--timeframe' => 'H1',
                '--resume-draft-agents' => true, '--unused-price-discovery-owner' => $prepared['preparation_hash'],
                '--expected-price-discovery-generation-id' => (int) $generation->id]);
            $fresh = $generation->fresh();
            $admitted = $exit === 0 && ! empty(data_get($fresh->trigger_context, 'queue_batches.screening'));
            return $this->result(['status' => $admitted ? 'admitted' : 'deferred', 'generation_id' => (int) $generation->id,
                'reason' => $admitted ? 'CANONICAL_PRICE_DISCOVERY_QUEUE_ADMISSION' : 'CANONICAL_DISPATCH_WITHHELD',
                'preparation_hash' => $prepared['preparation_hash']]);
        } catch (\Throwable $error) {
            return $this->result(['status' => 'blocked', 'reason' => $error instanceof \LogicException
                ? $error->getMessage() : 'UNUSED_PRICE_DISCOVERY_EXECUTOR_DEPENDENCY_UNAVAILABLE']);
        }
    }

    private function result(array $result): int
    {
        $this->line(json_encode([...$result, 'promotion_evidence' => false], JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }
}
