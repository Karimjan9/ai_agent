<?php

namespace App\Services;

use App\Models\CertifiedKnowledgeItem;
use App\Models\KnowledgeCemeteryEntry;
use App\Models\KnowledgeClaim;
use App\Models\QuantLaw;
use App\Models\QuantTheory;
use App\Models\RealityExperiment;
use App\Models\RealityScore;
use App\Models\RealityValidationEvent;
use App\Models\RealityVerificationRun;
use App\Models\SkepticReport;
use App\Models\StrategyScore;
use App\Models\UnifiedQuantModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class RealityVerificationService
{
    public function verify(): ?RealityVerificationRun
    {
        if (! Schema::hasTable('reality_verification_runs')) {
            return null;
        }

        $run = RealityVerificationRun::create([
            'status' => 'running',
            'started_at' => now(),
        ]);

        $sources = $this->collectSources();
        $scores = $sources->map(fn (array $source): RealityScore => $this->scoreSource($run, $source));
        $experiments = $scores->map(fn (RealityScore $score): RealityExperiment => $this->upsertExperiment($score));
        $certified = $scores->filter(fn (RealityScore $score): bool => in_array($score->validation_status, ['validated', 'institutional_grade'], true))
            ->map(fn (RealityScore $score): CertifiedKnowledgeItem => $this->certify($score));
        $cemetery = $scores->filter(fn (RealityScore $score): bool => $score->validation_status === 'reality_failed')
            ->map(fn (RealityScore $score): KnowledgeCemeteryEntry => $this->bury($score));
        $skepticReports = $scores->filter(fn (RealityScore $score): bool => (float) $score->false_discovery_risk >= 55 || $score->validation_status === 'needs_paper_validation')
            ->map(fn (RealityScore $score): SkepticReport => $this->writeSkepticReport($score));

        $run->update([
            'status' => 'success',
            'finished_at' => now(),
            'items_scored' => $scores->count(),
            'certified_count' => $certified->count(),
            'failed_count' => $scores->where('validation_status', 'reality_failed')->count(),
            'cemetery_count' => $cemetery->count(),
            'skeptic_reports_count' => $skepticReports->count(),
            'summary' => "Reality Verification scored {$scores->count()} knowledge items, certified {$certified->count()} and sent {$cemetery->count()} to the cemetery.",
            'metrics' => [
                'score_ids' => $scores->pluck('id')->values()->all(),
                'experiment_ids' => $experiments->pluck('id')->values()->all(),
                'average_reality_score' => round((float) $scores->avg('reality_score'), 2),
                'warning' => 'Reality Score is an institutional verification layer; live/paper evidence should replace proxy evidence as it grows.',
            ],
        ]);

        return $run;
    }

    private function collectSources(): Collection
    {
        return collect()
            ->merge($this->knowledgeClaims())
            ->merge($this->quantLaws())
            ->merge($this->quantTheories())
            ->merge($this->unifiedModels())
            ->values();
    }

    private function knowledgeClaims(): Collection
    {
        if (! Schema::hasTable('knowledge_claims')) {
            return collect();
        }

        return KnowledgeClaim::query()
            ->whereIn('status', ['provisional', 'validated', 'active'])
            ->orderByDesc('confidence_score')
            ->take(100)
            ->get()
            ->map(fn (KnowledgeClaim $claim): array => $this->sourcePayload(
                $claim,
                'knowledge_claim',
                $claim->title,
                $claim->claim,
                (float) $claim->confidence_score,
                (int) $claim->evidence_count,
                $claim->scope ?? [],
                $claim->metadata ?? [],
            ));
    }

    private function quantLaws(): Collection
    {
        if (! Schema::hasTable('quant_laws')) {
            return collect();
        }

        return QuantLaw::query()
            ->whereIn('status', ['active', 'emerging'])
            ->orderByDesc('confidence_score')
            ->take(100)
            ->get()
            ->map(fn (QuantLaw $law): array => $this->sourcePayload(
                $law,
                'quant_law',
                $law->title,
                $law->statement,
                (float) $law->confidence_score,
                (int) $law->evidence_count,
                $law->scope ?? [],
                $law->metadata ?? [],
            ));
    }

    private function quantTheories(): Collection
    {
        if (! Schema::hasTable('quant_theories')) {
            return collect();
        }

        return QuantTheory::query()
            ->whereIn('status', ['emerging', 'accepted', 'dominant'])
            ->orderByDesc('confidence_score')
            ->take(100)
            ->get()
            ->map(fn (QuantTheory $theory): array => $this->sourcePayload(
                $theory,
                'quant_theory',
                $theory->title,
                $theory->thesis,
                (float) $theory->confidence_score,
                (int) $theory->evidence_count,
                $theory->scope ?? [],
                $theory->metadata ?? [],
            ));
    }

    private function unifiedModels(): Collection
    {
        if (! Schema::hasTable('unified_quant_models')) {
            return collect();
        }

        return UnifiedQuantModel::query()
            ->whereIn('status', ['emerging', 'accepted', 'dominant'])
            ->orderByDesc('confidence_score')
            ->take(50)
            ->get()
            ->map(fn (UnifiedQuantModel $model): array => $this->sourcePayload(
                $model,
                'unified_model',
                $model->title,
                $model->thesis,
                (float) $model->confidence_score,
                (int) $model->theory_count + (int) $model->law_count,
                [],
                $model->metadata ?? [],
            ));
    }

    private function sourcePayload(Model $model, string $layer, string $title, string $statement, float $confidence, int $evidenceCount, array $scope, array $metadata): array
    {
        return [
            'model' => $model,
            'source_type' => $model::class,
            'source_id' => $model->getKey(),
            'source_layer' => $layer,
            'title' => $title,
            'statement' => $statement,
            'confidence' => $confidence,
            'evidence_count' => $evidenceCount,
            'scope' => $scope,
            'metadata' => $metadata,
            'keywords' => $this->keywords($title, $statement, $scope),
        ];
    }

    private function scoreSource(RealityVerificationRun $run, array $source): RealityScore
    {
        $previous = RealityScore::query()
            ->where('source_type', $source['source_type'])
            ->where('source_id', $source['source_id'])
            ->first();

        $evidence = $this->operationalEvidence($source);
        $realityScore = $this->realityScore($source, $evidence);
        $drift = abs((float) $source['confidence'] - $realityScore);
        $falseDiscoveryRisk = $this->falseDiscoveryRisk($source, $evidence, $realityScore, $drift);
        $status = $this->validationStatus($source, $evidence, $realityScore, $falseDiscoveryRisk);

        $score = RealityScore::updateOrCreate(
            ['source_type' => $source['source_type'], 'source_id' => $source['source_id']],
            [
                'reality_verification_run_id' => $run->id,
                'source_layer' => $source['source_layer'],
                'source_title' => $source['title'],
                'original_confidence' => round((float) $source['confidence'], 2),
                'reality_score' => round($realityScore, 2),
                'evidence_score' => round((float) $evidence['score'], 2),
                'drift_score' => round($drift, 2),
                'false_discovery_risk' => round($falseDiscoveryRisk, 2),
                'validation_status' => $status,
                'evidence_count' => (int) $source['evidence_count'],
                'live_sample_count' => (int) $evidence['live_samples'],
                'paper_sample_count' => (int) $evidence['paper_samples'],
                'backtest_sample_count' => (int) $evidence['backtest_samples'],
                'last_checked_at' => now(),
                'rationale' => $this->rationale($status, $source, $evidence, $realityScore),
                'metadata' => [
                    'keywords' => $source['keywords'],
                    'scope' => $source['scope'],
                    'operational_evidence' => $evidence,
                ],
            ],
        );

        RealityValidationEvent::create([
            'reality_score_id' => $score->id,
            'event_type' => $previous ? 'reverified' : 'verified',
            'previous_status' => $previous?->validation_status,
            'new_status' => $score->validation_status,
            'previous_reality_score' => $previous?->reality_score,
            'new_reality_score' => $score->reality_score,
            'evidence_summary' => "Reality score {$score->reality_score}% from {$score->paper_sample_count} paper/live and {$score->backtest_sample_count} backtest samples.",
            'metadata' => [
                'false_discovery_risk' => $score->false_discovery_risk,
                'drift_score' => $score->drift_score,
            ],
        ]);

        return $score;
    }

    private function operationalEvidence(array $source): array
    {
        if (! Schema::hasTable('strategy_scores')) {
            return $this->emptyEvidence();
        }

        $scores = StrategyScore::query()
            ->latest()
            ->take(120)
            ->get();

        if ($scores->isEmpty()) {
            return $this->emptyEvidence();
        }

        $paper = $scores->filter(fn (StrategyScore $score): bool => in_array(data_get($score->raw_result, 'execution_mode'), ['paper', 'paper_trading'], true));
        $live = $scores->filter(fn (StrategyScore $score): bool => data_get($score->raw_result, 'execution_mode') === 'live');
        $backtest = $scores->reject(fn (StrategyScore $score): bool => in_array(data_get($score->raw_result, 'execution_mode'), ['paper', 'paper_trading', 'live'], true));
        $score = $this->operationalScore($scores);

        return [
            'score' => $score,
            'sample_count' => $scores->count(),
            'paper_samples' => $paper->sum('total_trades'),
            'live_samples' => $live->sum('total_trades'),
            'backtest_samples' => $backtest->sum('total_trades'),
            'success_rate' => round((float) $scores->avg('winrate'), 2),
            'avg_forward_score' => round((float) ($scores->avg('forward_score') ?: $scores->avg('score')), 2),
            'avg_robustness_score' => round((float) ($scores->avg('robustness_score') ?: $scores->avg('score')), 2),
            'avg_profit_factor' => round((float) $scores->avg('profit_factor'), 2),
            'avg_net_profit_percent' => round((float) $scores->avg('net_profit_percent'), 2),
            'overfit_ratio' => round($scores->where('is_overfit', true)->count() / max(1, $scores->count()), 3),
        ];
    }

    private function emptyEvidence(): array
    {
        return [
            'score' => 0,
            'sample_count' => 0,
            'paper_samples' => 0,
            'live_samples' => 0,
            'backtest_samples' => 0,
            'success_rate' => 0,
            'avg_forward_score' => 0,
            'avg_robustness_score' => 0,
            'avg_profit_factor' => 0,
            'avg_net_profit_percent' => 0,
            'overfit_ratio' => 0,
        ];
    }

    private function operationalScore(Collection $scores): float
    {
        $forward = (float) ($scores->avg('forward_score') ?: $scores->avg('score'));
        $robustness = (float) ($scores->avg('robustness_score') ?: $scores->avg('score'));
        $stability = (float) ($scores->avg('stability_score') ?: $scores->avg('score'));
        $profitQuality = $this->clamp(50 + ((float) $scores->avg('net_profit_percent') * 1.5));
        $drawdownSafety = $this->clamp(100 - abs((float) $scores->avg('max_drawdown_percent') * 2));
        $profitFactor = $this->clamp((float) $scores->avg('profit_factor') * 35);
        $winrate = $this->clamp((float) $scores->avg('winrate'));
        $overfitPenalty = ($scores->where('is_overfit', true)->count() / max(1, $scores->count())) * 25;
        $ruinPenalty = $this->clamp((float) $scores->avg('mc_risk_of_ruin_percent') * 0.35);

        return $this->clamp(
            ($forward * 0.22)
            + ($robustness * 0.2)
            + ($stability * 0.14)
            + ($profitQuality * 0.14)
            + ($drawdownSafety * 0.1)
            + ($profitFactor * 0.1)
            + ($winrate * 0.1)
            - $overfitPenalty
            - $ruinPenalty
        );
    }

    private function realityScore(array $source, array $evidence): float
    {
        if ((int) $evidence['sample_count'] === 0) {
            return $this->clamp((float) $source['confidence'] * 0.42, 0, 45);
        }

        $paperLiveSamples = (int) $evidence['paper_samples'] + (int) $evidence['live_samples'];
        $sampleBonus = min(12, log(max(1, (int) $evidence['sample_count']) + 1, 2) * 2.2);
        $paperLiveBonus = min(15, $paperLiveSamples / 20);
        $evidenceWeight = $paperLiveSamples > 0 ? 0.72 : 0.58;
        $priorWeight = 1 - $evidenceWeight;

        return $this->clamp(((float) $evidence['score'] * $evidenceWeight) + ((float) $source['confidence'] * $priorWeight) + $sampleBonus + $paperLiveBonus);
    }

    private function falseDiscoveryRisk(array $source, array $evidence, float $realityScore, float $drift): float
    {
        $samplePenalty = (int) $evidence['sample_count'] < 5 ? 16 : 0;
        $paperPenalty = ((int) $evidence['paper_samples'] + (int) $evidence['live_samples']) < 30 ? 10 : 0;
        $confidenceMismatch = (float) $source['confidence'] >= 80 && $realityScore < 55 ? 18 : 0;

        return $this->clamp(((100 - $realityScore) * 0.45) + ($drift * 0.35) + $samplePenalty + $paperPenalty + $confidenceMismatch);
    }

    private function validationStatus(array $source, array $evidence, float $realityScore, float $falseDiscoveryRisk): string
    {
        $paperLiveSamples = (int) $evidence['paper_samples'] + (int) $evidence['live_samples'];

        if ((int) $evidence['sample_count'] === 0) {
            return 'needs_paper_validation';
        }

        if ((float) $source['confidence'] >= 70 && $realityScore < 45) {
            return 'reality_failed';
        }

        if ($realityScore >= 85 && $paperLiveSamples >= 100 && $falseDiscoveryRisk < 25) {
            return 'institutional_grade';
        }

        if ($realityScore >= 72 && $paperLiveSamples >= 30 && $falseDiscoveryRisk < 40) {
            return 'validated';
        }

        if ($realityScore >= 60 && $paperLiveSamples === 0) {
            return 'backtest_only';
        }

        if ($paperLiveSamples < 30) {
            return 'needs_paper_validation';
        }

        return 'draft';
    }

    private function upsertExperiment(RealityScore $score): RealityExperiment
    {
        $observed = (int) $score->paper_sample_count + (int) $score->live_sample_count;

        return RealityExperiment::updateOrCreate(
            ['experiment_key' => 'reality-exp:'.$score->source_type.':'.$score->source_id],
            [
                'reality_score_id' => $score->id,
                'source_type' => $score->source_type,
                'source_id' => $score->source_id,
                'title' => 'Paper validate '.$score->source_title,
                'mode' => 'paper_trading',
                'status' => $observed >= 100 ? 'completed' : 'observing',
                'planned_samples' => 100,
                'observed_samples' => $observed,
                'success_rate' => (float) data_get($score->metadata, 'operational_evidence.success_rate', 0),
                'confidence_score' => $score->reality_score,
                'hypothesis' => $score->source_title.' remains valid under real/paper market observation.',
                'success_criteria' => [
                    'minimum_samples' => 100,
                    'minimum_reality_score' => 72,
                    'maximum_false_discovery_risk' => 40,
                ],
                'metadata' => ['validation_status' => $score->validation_status],
            ],
        );
    }

    private function certify(RealityScore $score): CertifiedKnowledgeItem
    {
        return CertifiedKnowledgeItem::updateOrCreate(
            ['certificate_key' => 'cert:'.$score->source_type.':'.$score->source_id],
            [
                'reality_score_id' => $score->id,
                'source_type' => $score->source_type,
                'source_id' => $score->source_id,
                'title' => $score->source_title,
                'grade' => $score->validation_status,
                'reality_score' => $score->reality_score,
                'issued_at' => now(),
                'expires_at' => now()->addMonths(3),
                'evidence_summary' => "Reality score {$score->reality_score}% with false discovery risk {$score->false_discovery_risk}%.",
                'metadata' => [
                    'source_layer' => $score->source_layer,
                    'paper_samples' => $score->paper_sample_count,
                    'live_samples' => $score->live_sample_count,
                ],
            ],
        );
    }

    private function bury(RealityScore $score): KnowledgeCemeteryEntry
    {
        return KnowledgeCemeteryEntry::updateOrCreate(
            ['source_type' => $score->source_type, 'source_id' => $score->source_id],
            [
                'reality_score_id' => $score->id,
                'title' => $score->source_title,
                'failure_reason' => $score->drift_score >= 30 ? 'market_changed_or_backtest_artifact' : 'reality_failed',
                'original_confidence' => $score->original_confidence,
                'final_reality_score' => $score->reality_score,
                'status' => 'archived',
                'failed_at' => now(),
                'evidence' => [
                    'false_discovery_risk' => $score->false_discovery_risk,
                    'rationale' => $score->rationale,
                ],
            ],
        );
    }

    private function writeSkepticReport(RealityScore $score): SkepticReport
    {
        return SkepticReport::updateOrCreate(
            ['report_key' => 'skeptic:'.$score->source_type.':'.$score->source_id],
            [
                'reality_score_id' => $score->id,
                'source_type' => $score->source_type,
                'source_id' => $score->source_id,
                'verdict' => $score->validation_status === 'reality_failed' ? 'reject_or_quarantine' : 'needs_validation',
                'false_discovery_risk' => $score->false_discovery_risk,
                'objections' => $score->validation_status === 'needs_paper_validation'
                    ? 'Insufficient paper/live evidence. This may still be model reality, not market reality.'
                    : 'Original confidence and reality score diverge enough to suspect a false discovery or market change.',
                'suggested_tests' => 'Run paper trading validation with at least 100 signals and compare forward survival, drawdown and regime-specific failure rate.',
                'metadata' => [
                    'validation_status' => $score->validation_status,
                    'drift_score' => $score->drift_score,
                ],
            ],
        );
    }

    private function rationale(string $status, array $source, array $evidence, float $realityScore): string
    {
        return match ($status) {
            'institutional_grade' => 'Reality evidence is strong enough for institutional-grade knowledge.',
            'validated' => 'Paper/live evidence supports the claim; keep monitoring expiry and drift.',
            'backtest_only' => 'Backtest evidence is strong, but paper/live validation is still missing.',
            'reality_failed' => 'Original confidence is high, but reality evidence does not support it.',
            'needs_paper_validation' => 'The claim needs live or paper observation before certification.',
            default => "Reality score {$realityScore}% is not strong enough for certification yet.",
        };
    }

    private function keywords(string $title, string $statement, array $scope): array
    {
        $scopeText = implode(' ', array_map(
            fn ($value): string => is_array($value) ? implode(' ', $value) : (string) $value,
            $scope,
        ));

        return Str::of($title.' '.$statement.' '.$scopeText)
            ->replace(['_', '-', ':'], ' ')
            ->lower()
            ->explode(' ')
            ->map(fn (string $word): string => trim($word))
            ->filter(fn (string $word): bool => strlen($word) >= 5)
            ->unique()
            ->take(20)
            ->values()
            ->all();
    }

    private function clamp(float $value, float $min = 0, float $max = 100): float
    {
        return max($min, min($max, $value));
    }
}
