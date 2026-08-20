<?php

namespace App\Services;

use App\Models\DualTrackGeneProof;
use App\Models\ModelMarketPerformance;
use Illuminate\Support\Facades\Schema;

/** Converts replay statistics into a conservative, auditable gene proof. */
class DualTrackGeneProofService
{
    public const PROTOCOL = 'dual_track_strong_gene_proof_v1';

    public function record(ModelMarketPerformance $candidate): array
    {
        if (! Schema::hasTable('dual_track_gene_proofs')) return ['status' => 'unavailable', 'passed' => false, 'promotion_evidence' => false];
        $metrics = (array) $candidate->metrics;
        $stat = (array) data_get($metrics, 'statistical_evidence', []);
        $edge = (array) data_get($stat, 'edge_quality', []);
        $bootstrap = (float) (data_get($edge, 'bootstrap_pf.pf_5_percentile_lower_bound') ?? data_get($stat, 'bootstrap_pf.pf_5_percentile_lower_bound', 0));
        $dsr = (float) (data_get($stat, 'deflated_sharpe.deflated_sharpe_probability') ?? data_get($stat, 'deflated_sharpe_probability', 0));
        $pbo = (float) (data_get($metrics, 'selection_validation.probability_of_backtest_overfitting') ?? data_get($metrics, 'pbo.probability_of_backtest_overfitting', 1));
        $sample = max((int) $candidate->sample_count, (int) data_get($stat, 'trade_count', 0));
        $passed = $sample >= 30 && $bootstrap >= (float) config('services.twin_intelligence.gene_bootstrap_pf_floor', 1.05)
            && $dsr >= (float) config('services.twin_intelligence.gene_dsr_probability_floor', .95)
            && $pbo <= (float) config('services.twin_intelligence.gene_pbo_ceiling', .20);
        $proof = DualTrackGeneProof::query()->updateOrCreate(['proof_key' => self::PROTOCOL.'|candidate|'.$candidate->id], [
            'model_market_performance_id' => $candidate->id, 'symbol' => $candidate->symbol, 'timeframe' => $candidate->timeframe,
            'cell_key' => data_get($metrics, 'dual_track.cell_key'), 'sample_count' => $sample,
            'bootstrap_lower_bound' => $bootstrap, 'deflated_sharpe_probability' => $dsr, 'pbo_probability' => $pbo,
            'status' => $passed ? 'proven' : ($sample < 30 ? 'insufficient' : 'failed'),
            'evidence' => ['protocol' => self::PROTOCOL, 'thresholds' => ['bootstrap_pf' => config('services.twin_intelligence.gene_bootstrap_pf_floor', 1.05), 'dsr' => config('services.twin_intelligence.gene_dsr_probability_floor', .95), 'pbo' => config('services.twin_intelligence.gene_pbo_ceiling', .20)], 'promotion_evidence' => false],
        ]);
        return ['status' => $proof->status, 'passed' => $passed, 'proof_id' => $proof->id, 'promotion_evidence' => false];
    }
}
