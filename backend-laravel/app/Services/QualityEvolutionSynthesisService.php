<?php

namespace App\Services;

use App\Models\AiLaboratory;
use App\Models\LabGeneration;
use Illuminate\Support\Facades\DB;

/**
 * Converts a terminal, falsifiable five-arm research result into one bounded
 * twenty-seat evolution cohort. Causal experiments remain isolated; this is
 * the separate synthesis lane where parent, mentor, tactic, risk and
 * autonomous exploration tooling may compete under unchanged quality gates.
 */
class QualityEvolutionSynthesisService
{
    public const PROTOCOL = 'quality_evolution_synthesis_v1';

    public function __construct(
        private readonly LabPopulationService $populations,
        private readonly LabLifecycleOrchestrator $lifecycle,
    ) {}

    public function nextSource(string $symbol, string $timeframe): ?LabGeneration
    {
        $lab = AiLaboratory::query()->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))->first();
        if (! $lab) return null;
        $source = $lab->generations()->where('trigger_type', 'skill_cartridge_transplant')->latest('generation')->first();
        if (! $source || ! in_array((string) $source->status, ['completed', 'screened'], true)
            || ! $this->terminalCartridgeCohort($source)) {
            return null;
        }

        $attempts = $lab->generations()->where('trigger_type', 'quality_evolution_synthesis')->get()
            ->filter(fn (LabGeneration $generation): bool => (int) data_get($generation->trigger_context, 'quality_evolution_synthesis.source_generation_id') === (int) $source->id)
            ->values();
        $hasUsableAttempt = $attempts->contains(fn (LabGeneration $generation): bool => (string) $generation->status !== 'technical_quarantine');
        // One construction-only retry is enough to recover a repaired
        // invariant without turning a genuine quality failure into a loop.
        return $hasUsableAttempt || $attempts->count() >= 2 ? null : $source;
    }

    /** @return array<string,mixed> */
    public function materialize(LabGeneration $source): array
    {
        $source->loadMissing('laboratory', 'agents.modelVersion');
        $lab = $source->laboratory;
        if (! $lab || ! in_array((string) $source->status, ['completed', 'screened'], true)) {
            return ['status' => 'blocked', 'reason' => 'QUALITY_SYNTHESIS_SOURCE_NOT_TERMINAL', 'promotion_evidence' => false];
        }
        if (! $this->terminalCartridgeCohort($source)) {
            return ['status' => 'blocked', 'reason' => 'QUALITY_SYNTHESIS_SOURCE_SETTLEMENT_INCOMPLETE', 'promotion_evidence' => false];
        }

        // The quality-synthesis trigger is explicitly admitted by the normal
        // population builder, but remains a bounded research generation. Its
        // twenty seats use the existing parent/mentor, risk, tactic and
        // autonomous exploration contracts rather than copying a failed arm.
        // Null deliberately selects the normal configured twenty-seat plan.
        // Passing 20 selects the bounded-root recovery branch, which is a
        // different four-seat diagnostic contract.
        $generation = $this->populations->build($lab->symbol, 'quality_evolution_synthesis', false, $lab->timeframe, [], false, false, null);
        if (! $generation) {
            return ['status' => 'blocked', 'reason' => data_get($this->populations->lastBuildOutcome(), 'reason_code', 'QUALITY_SYNTHESIS_BUILD_BLOCKED'),
                'build_outcome' => $this->populations->lastBuildOutcome(), 'promotion_evidence' => false];
        }
        $generation->refresh();
        if ($generation->agents()->count() !== 20) {
            return ['status' => 'blocked', 'reason' => 'QUALITY_SYNTHESIS_EXACT_TWENTY_CONTRACT_FAILED',
                'generation_id' => $generation->id, 'actual_population' => $generation->agents()->count(), 'promotion_evidence' => false];
        }

        $context = (array) $generation->trigger_context;
        $context['quality_evolution_synthesis'] = [
            'protocol' => self::PROTOCOL,
            'source_generation_id' => $source->id,
            'source_generation' => $source->generation,
            'source_trigger_type' => $source->trigger_type,
            'population_contract' => 20,
            'retry_attempt' => $this->qualityAttemptNumber($lab, $source),
            'quality_first' => true,
            'toolbox' => ['parent_mentor', 'strategy_tactic', 'risk_management', 'hybrid_evolution', 'autonomous_curiosity', 'adversarial_falsification'],
            'source_outcome_is_not_parent_or_promotion_evidence' => true,
            'promotion_evidence' => false,
        ];
        $generation->update(['trigger_context' => $context]);

        $cycle = $this->lifecycle->run($lab->symbol, $lab->timeframe, null, true);
        return ['status' => 'queued', 'generation_id' => $generation->id, 'generation' => $generation->generation,
            'population_size' => 20, 'source_generation_id' => $source->id, 'lifecycle' => $cycle, 'promotion_evidence' => false];
    }

    private function terminalCartridgeCohort(LabGeneration $source): bool
    {
        $agents = $source->agents()->get(['model_version_id', 'lifecycle_status']);
        if ($agents->isEmpty() || $agents->contains(fn ($agent): bool => in_array((string) $agent->lifecycle_status, ['draft', 'queued', 'screening', 'training', 'full_queued', 'full_validation'], true))) return false;
        $trials = DB::table('skill_cartridge_transplant_trials')->whereIn('child_model_version_id', $agents->pluck('model_version_id'))->get(['status']);
        return $trials->count() === $agents->count()
            && ! $trials->contains(fn ($trial): bool => in_array((string) $trial->status, ['planned', 'queued', 'running'], true));
    }

    private function qualityAttemptNumber(AiLaboratory $lab, LabGeneration $source): int
    {
        return $lab->generations()->where('trigger_type', 'quality_evolution_synthesis')->get()
            ->filter(fn (LabGeneration $generation): bool => (int) data_get($generation->trigger_context, 'quality_evolution_synthesis.source_generation_id') === (int) $source->id)
            ->count() + 1;
    }
}
