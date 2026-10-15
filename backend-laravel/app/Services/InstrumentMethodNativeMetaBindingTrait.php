<?php

namespace App\Services;

use App\Models\SpecialistCouncilVersion;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Prospective council targets are registry-owned, never copied model/passport metadata. */
trait InstrumentMethodNativeMetaBindingTrait
{
    public const NATIVE_TARGET_PROTOCOL = 'instrument_method_native_meta_target_v1';

    /** Account facts are an explicit prospective plan; final actual request must match them. */
    public function attachUnusedNativeCouncil(int $versionId, string $memberId, string $modelHash, array $request, ?array $mtfBundle = null): array
    {
        try {
            return DB::transaction(function () use ($versionId, $memberId, $modelHash, $request, $mtfBundle) {
                $version = SpecialistCouncilVersion::whereKey($versionId)->lockForUpdate()->firstOrFail();
                $this->need($version->state === 'draft' && ! DB::table('specialist_council_evaluation_plans')->where('specialist_council_version_id', $versionId)->exists()
                    && ! DB::table('specialist_council_evaluations')->where('specialist_council_version_id', $versionId)->exists()
                    && ! DB::table('specialist_council_data_uses')->where('specialist_council_version_id', $versionId)->exists(), 'META_BINDING_NATIVE_UNUSED_DRAFT_REQUIRED');
                $carriers = \App\Models\ModelVersion::where('metadata->specialist_council->version_id', $versionId)
                    ->orWhere('metadata->specialist_council_evaluation->version_id', $versionId)->get();
                // A prepared/enrolled carrier is already part of a question,
                // even before it owns a replay. Only version wrappers are
                // checked here; reused member models are not rewritten.
                $this->need($carriers->every(fn ($carrier) => $this->unused($carrier)), 'META_BINDING_NATIVE_UNUSED_DRAFT_REQUIRED');
                $declared = DB::table('instrument_method_meta_registrations')->where('model_key', 'like', 'native-target:'.$versionId.':%')->first();
                $this->need($declared === null, $declared && $declared->model_key === 'native-target:'.$versionId.':'.$memberId
                    ? 'META_BINDING_NATIVE_TARGET_ALREADY_DECLARED' : 'META_BINDING_NATIVE_SINGLE_ASSET_MEMBER_REQUIRED');
                $this->need(app(SpecialistCouncilContractService::class)->manifestValid((array) $version->manifest)
                    && $version->manifest_hash === $version->manifest['manifest_hash'], 'META_BINDING_NATIVE_MANIFEST_REQUIRED');
                $this->need(empty($request['strategies']) && empty($request['portfolio_members'])
                    && ! app(ResearchPaperEpochContractService::class)->researchInputHasPaperData($request), 'META_BINDING_NATIVE_PLAIN_PRE2026_PLAN_REQUIRED');
                $council = app(SpecialistCouncilLifecycleService::class)->runtimeContract($version, (string) ($request['replay_dataset_hash'] ?? ''),
                    (string) data_get($request, 'execution_contract.execution_hash'), (string) ($request['timeframe'] ?? ''), $mtfBundle, $request['symbol'] ?? null);
                $members = array_values(array_filter($council['members'], fn ($member) => $member['specialist_id'] === $memberId));
                $this->need(count($members) === 1 && isset($members[0]['method_learning']), 'META_BINDING_NATIVE_METHOD_MEMBER_REQUIRED');
                $export = $this->nativeExport($modelHash);
                $evaluation = $this->models->nativeEvaluationBinding($request, $council, $members[0]);
                $this->nativePhysicalParity($export, $evaluation);
                $key = 'native-target:'.$versionId.':'.$memberId;
                $this->need(strlen($key) <= 150, 'META_BINDING_NATIVE_TARGET_KEY_REQUIRED');
                $plan = array_intersect_key($request, array_flip(['symbol', 'timeframe', 'evaluation_mode', 'initial_balance', 'risk_per_trade', 'hard_limits', 'policy_context',
                    ...array_keys(InstrumentMethodMetaModelService::NATIVE_PARENT_PORT_DEFAULTS)]));
                $body = ['protocol' => self::NATIVE_TARGET_PROTOCOL, 'target_version_id' => $versionId,
                    'target_manifest_hash' => $version->manifest_hash, 'target_member_id' => $memberId,
                    'target_model_id' => $members[0]['model_version_id'], 'target_passport_hash' => $members[0]['passport_hash'],
                    'model_hash' => $modelHash, 'original_training_binding_hash' => $this->hash($export['artifact']['binding']),
                    'target_php_source_hash' => app(LabImmutableEvidenceService::class)->codeHash(),
                    'target_python_source_hash' => app(ResearchReleaseSealService::class)->pythonHash(),
                    'evaluation_binding' => $evaluation, 'account_plan' => $plan, 'created_at' => now()->utc()->toIso8601String(),
                    'research_only' => true, 'economic_authority' => false, 'paper_authority' => false];
                $body['issuer_signature'] = $this->nativeTargetSeal($body); $hash = $this->hash($body);
                $old = DB::table('instrument_method_meta_registrations')->where('model_key', $key)->lockForUpdate()->first();
                $this->need($old === null, 'META_BINDING_NATIVE_TARGET_ALREADY_DECLARED');
                DB::table('instrument_method_meta_registrations')->insert(['model_key' => $key, 'registration_hash' => $hash,
                    'design' => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
                    'assessment' => null, 'created_at' => now(), 'updated_at' => now()]);
                return ['status' => 'attached_native_meta_model_prospectively', 'target_hash' => $hash,
                    'target_version_id' => $versionId, 'target_member_id' => $memberId, ...$this->ceiling()];
            });
        } catch (Throwable $error) { return ['status' => 'blocked', 'reason' => str_starts_with($error->getMessage(), 'META_')
            ? $error->getMessage() : 'META_BINDING_NATIVE_ORIGINAL_TARGET_REQUIRED', ...$this->ceiling()]; }
    }

    /** Called by the existing canonical council compiler after every meta-free member exists. */
    public function compileNativeCouncil(array $council): array
    {
        $versionId = $council['version_id'] ?? null;
        if (! is_int($versionId)) return $council;
        $targets = DB::table('instrument_method_meta_registrations')->where('model_key', 'like', 'native-target:'.$versionId.':%')->get();
        if ($targets->isEmpty()) return $council;
        $this->need($targets->count() === 1, 'META_BINDING_NATIVE_SINGLE_ASSET_MEMBER_REQUIRED');
        $declaration = json_decode($targets[0]->design, true, 64, JSON_THROW_ON_ERROR);
        foreach ($council['members'] as $peer) $this->need(($peer['specialist_id'] ?? null) === ($declaration['target_member_id'] ?? null)
            || ! array_key_exists('meta_model', (array) ($peer['method_learning'] ?? [])), 'META_BINDING_NATIVE_PEER_ASSET_UNSUPPORTED');
        $plain = $this->nativePlainCouncil($council);
        foreach ($targets as $row) {
            $target = $this->nativeTarget($row, $plain);
            $request = [...$target['account_plan'], 'specialist_council_contract' => $plain,
                'replay_dataset_hash' => $plain['replay_dataset_hash'],
                'execution_contract' => app(ExecutionContractService::class)->for($plain['symbol'], $plain['execution_timeframe'])];
            $indexes = array_keys(array_filter($plain['members'], fn ($member) => $member['specialist_id'] === $target['target_member_id']));
            $this->need(count($indexes) === 1, 'META_BINDING_NATIVE_TARGET_MEMBER_MISSING'); $index = $indexes[0];
            $evaluation = $this->models->nativeEvaluationBinding($request, $plain, $plain['members'][$index]);
            $this->need($this->hash($evaluation) === $this->hash($target['evaluation_binding']), 'META_BINDING_NATIVE_TARGET_PHYSICAL_DRIFT');
            $export = $this->nativeExport($target['model_hash']); $this->nativePhysicalParity($export, $evaluation);
            $this->need($this->hash($export['artifact']['binding']) === $target['original_training_binding_hash'], 'META_BINDING_NATIVE_ORIGINAL_TRAINING_DRIFT');
            $method = $council['members'][$index]['method_learning'] ?? null;
            $this->need(is_array($method), 'META_BINDING_NATIVE_METHOD_MEMBER_REQUIRED');
            $body = array_diff_key($method, array_flip(['contract_hash', 'contract_json']));
            $body['meta_model'] = ['artifact' => $export['artifact'], 'expected_training_binding' => $export['expected_binding'], 'evaluation_binding' => $evaluation];
            $council['members'][$index]['method_learning'] = [...$body, 'contract_hash' => $this->hash($body), 'contract_json' => $this->nativeJson($body)];
        }
        $body = array_diff_key($council, array_flip(['contract_hash', 'contract_json']));
        return [...$body, 'contract_hash' => $this->hash($body), 'contract_json' => $this->nativeJson($body)];
    }

    /** Actual full parent account is checked before the original request barrier/HTTP publication. */
    public function bindNativeRequests(array $request): array
    {
        $bind = function (array $council) use ($request): array {
            $compiled = $this->compileNativeCouncil($council);
            $this->need($this->hash($compiled) === $this->hash($council), 'META_BINDING_NATIVE_CANONICAL_CONTRACT_REQUIRED');
            foreach ($compiled['members'] ?? [] as $member) {
                if (! isset($member['method_learning']['meta_model'])) continue;
                $evaluation = $this->models->nativeEvaluationBinding($request, $compiled, $member);
                $this->need($this->hash($evaluation) === $this->hash($member['method_learning']['meta_model']['evaluation_binding']),
                    'META_BINDING_NATIVE_ACTUAL_PARENT_ACCOUNT_DRIFT');
            }
            return $compiled;
        };
        if (isset($request['specialist_council_contract'])) $request['specialist_council_contract'] = $bind($request['specialist_council_contract']);
        foreach ($request['strategies'] ?? [] as $index => $strategy) if (isset($strategy['specialist_council_contract']))
            $request['strategies'][$index]['specialist_council_contract'] = $bind($strategy['specialist_council_contract']);
        return $request;
    }

    private function nativePlainCouncil(array $council): array
    {
        foreach ($council['members'] as &$member) {
            if (! isset($member['method_learning']['meta_model'])) continue;
            $body = array_diff_key($member['method_learning'], array_flip(['meta_model', 'contract_hash', 'contract_json']));
            $member['method_learning'] = [...$body, 'contract_hash' => $this->hash($body), 'contract_json' => $this->nativeJson($body)];
        }
        unset($member);
        $body = array_diff_key($council, array_flip(['contract_hash', 'contract_json']));
        return [...$body, 'contract_hash' => $this->hash($body), 'contract_json' => $this->nativeJson($body)];
    }

    private function nativeTarget(object $row, array $council): array
    {
        $target = json_decode($row->design, true, 64, JSON_THROW_ON_ERROR);
        $this->need($row->assessment === null && $row->registration_hash === $this->hash($target)
            && ($target['protocol'] ?? null) === self::NATIVE_TARGET_PROTOCOL
            && hash_equals($this->nativeTargetSeal(array_diff_key($target, ['issuer_signature' => true])), (string) ($target['issuer_signature'] ?? ''))
            && ($target['target_version_id'] ?? null) === ($council['version_id'] ?? null)
            && ($target['target_manifest_hash'] ?? null) === ($council['manifest_hash'] ?? null)
            && ($target['target_php_source_hash'] ?? null) === app(LabImmutableEvidenceService::class)->codeHash()
            && ($target['target_python_source_hash'] ?? null) === app(ResearchReleaseSealService::class)->pythonHash(), 'META_BINDING_NATIVE_TARGET_SEAL_REQUIRED');
        $version = SpecialistCouncilVersion::find($target['target_version_id']);
        $this->need($version !== null && $version->manifest_hash === $target['target_manifest_hash']
            && app(SpecialistCouncilContractService::class)->manifestValid((array) $version->manifest), 'META_BINDING_NATIVE_CURRENT_MANIFEST_REQUIRED');
        foreach ($version->manifest['members'] as $declared) {
            if (! in_array($declared['role'], SpecialistCouncilContractService::TRADING_ROLES, true)) continue;
            $model = \App\Models\ModelVersion::find($declared['model_version_id']);
            $this->need($model !== null && app(SpecialistCouncilContractService::class)->modelHash($model) === $declared['source_model_hash'],
                'META_BINDING_NATIVE_CURRENT_MEMBER_SOURCE_DRIFT');
        }
        $plan = DB::table('specialist_council_evaluation_plans')->where('specialist_council_version_id', $version->id)->first();
        $this->need($plan === null || \Carbon\CarbonImmutable::parse($target['created_at'])->lessThanOrEqualTo(\Carbon\CarbonImmutable::parse($plan->sealed_at)),
            'META_BINDING_NATIVE_TARGET_MUST_PRECEDE_PLAN');
        $members = array_values(array_filter($council['members'], fn ($member) => $member['specialist_id'] === $target['target_member_id']));
        $this->need(count($members) === 1 && $members[0]['model_version_id'] === $target['target_model_id']
            && $members[0]['passport_hash'] === $target['target_passport_hash'], 'META_BINDING_NATIVE_TARGET_OWNER_DRIFT');
        return $target;
    }

    private function nativeExport(string $hash): array
    {
        $export = $this->models->exportOriginalModel($hash);
        $this->need(($export['status'] ?? null) === 'exported_diagnostic'
            && ($export['artifact']['protocol'] ?? null) === InstrumentMethodMetaModelService::NATIVE_PROTOCOL,
            'META_BINDING_NATIVE_ORIGINAL_ISSUER_REQUIRED');
        return $export;
    }
    private function nativePhysicalParity(array $export, array $evaluation): void
    {
        foreach (InstrumentMethodMetaModelService::NATIVE_PHYSICAL_FIELDS as $field)
            $this->need(($export['artifact']['binding'][$field] ?? null) === ($evaluation[$field] ?? null), 'META_BINDING_NATIVE_PHYSICAL_SCOPE_DRIFT');
    }
    private function nativeTargetSeal(array $body): string
    {
        $key = config('app.key'); $this->need(is_string($key) && $key !== '', 'META_BINDING_NATIVE_SERVER_KEY_REQUIRED');
        return hash_hmac('sha256', self::NATIVE_TARGET_PROTOCOL.':'.$this->hash($body), $key);
    }
    private function nativeJson(array $body): string
    {
        $sort = function ($value) use (&$sort) { if (! is_array($value)) return $value; if (! array_is_list($value)) ksort($value); return array_map($sort, $value); };
        return json_encode($sort($body), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
