<?php

namespace App\Console\Commands;

use App\Models\ModelVersion;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\SpecialistCouncilVersion;
use App\Services\SpecialistCouncilLifecycleService;
use App\Services\SpecialistCouncilPreparationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Operator entrypoint; native population/dispatcher remains the only generation/queue owner. */
class ManageSpecialistCouncil extends Command
{
    protected $signature = 'trading:specialist-council {action : prepare|register|plan|attach-arm|status|approve|schedule|rollback}
        {--generation-id= : Constructor-complete unused canonical draft ID, for prepare}
        {--preparation= : Workspace JSON prospective manifest and complete research plan}
        {--version-id= : Persisted council version ID}
        {--manifest= : Workspace JSON manifest, for register}
        {--carrier-model= : Unobserved native aggregate model ID, for register}
        {--evaluation-plan= : Workspace JSON preregistration plan}
        {--evaluator= : Independent evaluator identity, for plan}
        {--actor= : Attributable creator or approver identity}
        {--arm= : Preregistered arm key, for attach-arm}
        {--model= : Native arm model ID, for attach-arm}
        {--effective-at= : Explicit UTC adoption boundary}
        {--reason= : Attributable rollback reason}';

    protected $description = 'Prepare and inspect specialist council candidates through canonical research and paper guards.';

    public function handle(SpecialistCouncilLifecycleService $owner): int
    {
        try {
            $action = (string) $this->argument('action');
            if ($action === 'prepare') {
                $result = app(SpecialistCouncilPreparationService::class)->prepare(
                    LabGeneration::findOrFail($this->positiveId('generation-id')),
                    $this->jsonFile((string) $this->option('preparation')),
                );
            } elseif ($action === 'register') {
                $manifest = $this->jsonFile((string) $this->option('manifest'));
                $carrier = ModelVersion::findOrFail($this->positiveId('carrier-model'));
                if (! LabAgent::where('model_version_id', $carrier->id)->exists()) throw new InvalidArgumentException('CANONICAL_UNOBSERVED_LAB_CARRIER_REQUIRED');
                $result = DB::transaction(function () use ($owner, $manifest, $carrier): array {
                    $version = $owner->registerDraft($manifest, $this->required('actor'));
                    $owner->attachResearchModel($version, $carrier);
                    return ['status' => 'draft_registered', 'version_id' => $version->id,
                        'manifest_hash' => $version->manifest_hash, 'carrier_model_version_id' => $carrier->id,
                        'next_owner' => 'canonical_lab_population_and_dispatcher', 'promotion_evidence' => false];
                });
            } else {
                $version = SpecialistCouncilVersion::findOrFail($this->positiveId('version-id'));
                $result = match ($action) {
                    'plan' => $owner->sealEvaluationPlan($version, $this->required('evaluator'), $this->jsonFile((string) $this->option('evaluation-plan'))),
                    'attach-arm' => $this->attachArm($owner, $version),
                    'status' => $this->status($owner, $version),
                    'approve' => $owner->approve($version, $this->required('actor')),
                    'schedule' => $owner->schedule($version, $this->required('effective-at')),
                    'rollback' => $owner->rollback($version, $this->required('reason')),
                    default => throw new InvalidArgumentException('UNKNOWN_SPECIALIST_COUNCIL_ACTION'),
                };
            }
            $this->line(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
            return ($result['allowed'] ?? true) ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $error) {
            $this->line(json_encode(['status' => 'withheld', 'reason_code' => $error->getMessage(),
                'promotion_evidence' => false], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            return self::FAILURE;
        }
    }

    private function attachArm(SpecialistCouncilLifecycleService $owner, SpecialistCouncilVersion $version): array
    {
        $model = $owner->attachEvaluationArm($version, $this->required('arm'), ModelVersion::findOrFail($this->positiveId('model')));
        return ['status' => 'original_evaluation_arm_attached', 'version_id' => $version->id,
            'model_version_id' => $model->id, 'next_owner' => 'canonical_lab_dispatcher', 'promotion_evidence' => false];
    }

    private function status(SpecialistCouncilLifecycleService $owner, SpecialistCouncilVersion $version): array
    {
        $carrierIds = ModelVersion::where('metadata->specialist_council->version_id', $version->id)->limit(128)->pluck('id')->all();
        return ['status' => $version->state, 'version_id' => $version->id, 'manifest_hash' => $version->manifest_hash,
            'assessment' => $version->assessment, 'effective_at' => $version->effective_at?->toIso8601String(),
            'progress' => $owner->progressForModels($carrierIds), 'promotion_evidence' => false];
    }

    private function jsonFile(string $path): array
    {
        $resolved = realpath($path);
        $root = realpath(dirname(base_path()));
        if ($resolved === false || $root === false || ! is_file($resolved) || is_link($resolved)
            || ! str_starts_with(strtolower($resolved), strtolower($root).DIRECTORY_SEPARATOR)
            || ! str_ends_with(strtolower($resolved), '.json') || filesize($resolved) > 1048576) {
            throw new InvalidArgumentException('SPECIALIST_COUNCIL_JSON_REQUIRES_BOUNDED_WORKSPACE_FILE');
        }
        $decoded = json_decode(file_get_contents($resolved), true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || array_is_list($decoded)) throw new InvalidArgumentException('SPECIALIST_COUNCIL_JSON_OBJECT_REQUIRED');
        return $decoded;
    }

    private function positiveId(string $name): int
    {
        $value = $this->required($name);
        if (! ctype_digit($value) || (int) $value < 1) throw new InvalidArgumentException('POSITIVE_NATIVE_ID_REQUIRED:'.$name);
        return (int) $value;
    }

    private function required(string $name): string
    {
        $value = (string) $this->option($name);
        if ($value === '') throw new InvalidArgumentException('SPECIALIST_COUNCIL_OPTION_REQUIRED:'.$name);
        return $value;
    }
}
