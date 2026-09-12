<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\LabSkillZooEntry;
use App\Models\ResearchExperimentWorkItem;

/** Executes an arbiter-leased continuation; never selects new work itself. */
class ResearchExperimentWorkConsumerService
{
    public const PROTOCOL = 'research_experiment_work_consumer_v1';

    public function __construct(
        private ResearchExperimentConversionKernelService $conversion,
        private CanonicalSkillCartridgeService $cartridges,
        private AutonomousModeService $autonomy,
    ) {}

    /** @return array<string,mixed> */
    public function execute(int $workItemId, string $leaseToken, int $fenceVersion): array
    {
        $item = ResearchExperimentWorkItem::query()->with('receipt')->find($workItemId);
        if (! $item
            || (string) $item->status !== 'leased'
            || ! hash_equals((string) $item->lease_token, $leaseToken)
            || (int) $item->fence_version !== $fenceVersion) {
            return $this->blocked('WORK_LEASE_NOT_CURRENT');
        }
        $payload = (array) $item->payload;
        if ((string) data_get($payload, 'owner') !== ResearchLoopArbiterService::class) {
            $this->conversion->defer($item, 'RESEARCH_LOOP_OWNER_MISMATCH', false);

            return $this->blocked('RESEARCH_LOOP_OWNER_MISMATCH');
        }
        if (! (bool) data_get($payload, 'executable', false)) {
            $reason = (string) data_get($payload, 'retry_condition.code', 'WORK_DEPENDENCY_NOT_EXECUTABLE');
            $this->conversion->defer($item, $reason, false);

            return $this->blocked($reason);
        }
        if (! $this->autonomy->enabled((string) $item->symbol, (string) $item->timeframe)) {
            $this->conversion->defer($item, 'AUTONOMOUS_MODE_STOPPED', true);

            return $this->blocked('AUTONOMOUS_MODE_STOPPED');
        }

        return match ((string) $item->work_type) {
            'cartridge_confirmation' => $this->confirmCartridge($item, $payload),
            default => $this->unsupported($item),
        };
    }

    /** @return array<string,mixed> */
    private function confirmCartridge(ResearchExperimentWorkItem $item, array $payload): array
    {
        $cartridge = LabSkillZooEntry::query()->find((int) ($payload['cartridge_id'] ?? 0));
        $baselineAgent = $cartridge?->causal_baseline_agent_id
            ? LabAgent::query()->find((int) $cartridge->causal_baseline_agent_id)
            : null;
        if (! $cartridge || ! $baselineAgent?->model_version_id) {
            $this->conversion->defer($item, 'CANONICAL_CARTRIDGE_OR_BASELINE_MISSING', false);

            return $this->blocked('CANONICAL_CARTRIDGE_OR_BASELINE_MISSING');
        }

        $result = $this->cartridges->materializeTransplant(
            $cartridge,
            (int) $baselineAgent->model_version_id,
            [
                'research_work_item_id' => (int) $item->id,
                'source_receipt_id' => (int) $item->research_experiment_receipt_id,
                'continuation_contract' => 'owned_fenced_exact_cartridge_confirmation',
                'promotion_evidence' => false,
            ],
            true,
        );
        if (in_array((string) ($result['status'] ?? ''), ['queued', 'already_materialized'], true)) {
            $completed = $this->conversion->complete($item, [
                'status' => 'materialized',
                'continuation' => $result,
                'causal_claim_still_requires_settlement' => true,
            ]);

            return $this->result($completed ? 'completed' : 'stale_lease', [
                'work_item_id' => (int) $item->id,
                'continuation' => $result,
            ]);
        }

        $reason = (string) ($result['reason'] ?? 'CARTRIDGE_CONFIRMATION_NOT_MATERIALIZED');
        $retryable = in_array((string) ($result['status'] ?? ''), ['unavailable', 'waiting'], true);
        $this->conversion->defer($item, $reason, $retryable);

        return $this->blocked($reason, ['continuation' => $result]);
    }

    /** @return array<string,mixed> */
    private function unsupported(ResearchExperimentWorkItem $item): array
    {
        $this->conversion->defer($item, 'NO_VERSIONED_EXECUTOR_FOR_WORK_TYPE', false);

        return $this->blocked('NO_VERSIONED_EXECUTOR_FOR_WORK_TYPE', ['work_type' => $item->work_type]);
    }

    /** @return array<string,mixed> */
    private function result(string $status, array $extra = []): array
    {
        return ['protocol' => self::PROTOCOL, 'status' => $status, ...$extra, 'promotion_evidence' => false];
    }

    /** @return array<string,mixed> */
    private function blocked(string $reason, array $extra = []): array
    {
        return $this->result('blocked', ['reason' => $reason, ...$extra]);
    }
}
