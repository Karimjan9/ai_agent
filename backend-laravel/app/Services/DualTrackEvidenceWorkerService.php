<?php

namespace App\Services;

use App\Models\DualTrackEvidenceWorkItem;
use App\Models\DualTrackMemoryReplay;
use App\Models\ModelMarketPerformance;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/** Executes queued evidence work with bounded retries and no gate bypass. */
class DualTrackEvidenceWorkerService
{
    public function __construct(
        private DualTrackEvidenceWorkItemService $items,
        private TwinRedTeamService $redTeam,
        private CouncilMemberCreditService $memberCredits,
        private DualTrackGeneProofService $geneProofs,
        private SealedHoldoutService $holdouts,
    ) {}

    public function process(int $limit = 10): array
    {
        $stats = ['claimed' => 0, 'completed' => 0, 'blocked' => 0, 'retried' => 0, 'memory_replayed' => 0];
        foreach ($this->items->claim($limit) as $item) {
            $stats['claimed']++;
            try {
                $result = $this->execute($item);
                if (($result['status'] ?? '') === 'deferred') {
                    $this->items->defer($item, (string) ($result['reason'] ?? 'evidence_deferred'));
                    $stats['retried']++;
                    continue;
                }
                if (($result['status'] ?? '') === 'blocked') $stats['blocked']++;
                else $stats['completed']++;
                $this->items->complete($item, $result);
            } catch (\Throwable $error) {
                $this->items->retry($item, get_class($error).': '.$error->getMessage());
                $stats['retried']++;
            }
        }
        if (Schema::hasTable('dual_track_memory_replay_queue')) {
            $memory = DualTrackMemoryReplay::query()->whereIn('status', ['queued', 'retry'])->orderByDesc('priority_score')->limit(max(1, min(50, $limit)))->get();
            foreach ($memory as $row) {
                $row->update(['status' => 'replayed', 'replay_count' => (int) $row->replay_count + 1, 'last_replayed_at' => now(), 'evidence' => [...((array) $row->evidence), 'protocol' => PrioritizedMemoryReplayService::PROTOCOL, 'replay_mode' => 'priority_first', 'promotion_evidence' => false]]);
                $stats['memory_replayed']++;
            }
        }
        return [...$stats, 'promotion_evidence' => false];
    }

    /** @return array<string, mixed> */
    private function execute(DualTrackEvidenceWorkItem $item): array
    {
        $payload = (array) $item->payload;
        $url = rtrim((string) config('services.ai_service.url'), '/');
        $headers = ['X-Internal-Token' => (string) config('services.internal_api.token')];
        if ($item->work_type === 'red_team') {
            if ((array) ($payload['request'] ?? []) === []) return ['status' => 'blocked', 'reason' => 'sealed_twin_request_missing', 'promotion_evidence' => false];
            $response = Http::timeout(180)->acceptJson()->withHeaders($headers)->post($url.'/api/paper/twin/red-team', ['request' => $payload['request'], 'target_lane' => data_get($item->payload, 'target_lane', data_get($item->payload, 'adversary_type') === 'council_member_removal' ? 'council' : 'champion'), 'adversary_type' => data_get($item->payload, 'adversary_type')]);
            if ($response->failed()) throw new \RuntimeException('red-team HTTP '.$response->status());
            $evidence = (array) $response->json();
            $baseline = (array) data_get($payload, 'baseline.'.data_get($evidence, 'target_lane', 'champion'), []);
            $challenged = (array) data_get($evidence, 'output', []);
            $damage = data_get($baseline, 'decision') !== data_get($challenged, 'decision') ? 1.0 : abs((float) data_get($baseline, 'confidence', 0) - (float) data_get($challenged, 'confidence', 0));
            return $this->redTeam->execute((string) data_get($payload, 'trial_key'), [...$evidence, 'damage_score' => min(1, $damage)]);
        }
        if ($item->work_type === 'council_ablation') {
            if ((array) ($payload['request'] ?? []) === []) return ['status' => 'blocked', 'reason' => 'sealed_twin_request_missing', 'promotion_evidence' => false];
            $response = Http::timeout(180)->acceptJson()->withHeaders($headers)->post($url.'/api/paper/twin/ablation', ['request' => $payload['request'], 'member_key' => data_get($payload, 'member_key')]);
            if ($response->failed()) throw new \RuntimeException('ablation HTTP '.$response->status());
            return $this->memberCredits->recordAblation((string) data_get($payload, 'credit_key'), (array) $response->json());
        }
        if ($item->work_type === 'forward_holdout') {
            $candidate = ModelMarketPerformance::query()->find((int) data_get($payload, 'candidate_id'));
            if (! $candidate) return ['status' => 'blocked', 'reason' => 'candidate_missing', 'promotion_evidence' => false];
            if ($candidate->paper_status === 'passed' && $candidate->holdout_status === 'sealed') {
                $this->holdouts->release($candidate);
                return $this->geneProofs->record($candidate->fresh());
            }
            return ['status' => 'deferred', 'reason' => 'paper_or_sealed_holdout_not_ready', 'promotion_evidence' => false];
        }
        return ['status' => 'blocked', 'reason' => 'unknown_work_type', 'promotion_evidence' => false];
    }
}
