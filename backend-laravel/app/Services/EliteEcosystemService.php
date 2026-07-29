<?php
namespace App\Services;

use App\Models\AdversarialValidatorEpoch;
use App\Models\AdversarialValidatorFinding;
use App\Models\AgentFailureCase;
use App\Models\AgentSkillAtlasEntry;
use App\Models\ModelMarketPerformance;
use App\Models\LabAgent;

/** Persistent MAP-Elites archive and frozen Red-Queen validator evidence. */
class EliteEcosystemService
{
    public function __construct(private EvaluatorBankService $evaluatorBank) {}

    public function sync(ModelMarketPerformance $performance, array $result, array $capabilities): void
    {
        $epoch = $this->epoch($performance);
        foreach ($this->niches($result) as $niche) {
            $quality = $this->quality($result, $niche['regime']);
            AgentSkillAtlasEntry::updateOrCreate(['model_market_performance_id'=>$performance->id,'niche_key'=>$niche['key']], [
                'model_version_id'=>$performance->model_version_id,'symbol'=>$performance->symbol,'timeframe'=>$performance->timeframe,
                'regime'=>$niche['regime'],'volatility'=>$niche['volatility'],'direction'=>$niche['direction'],
                'role'=>$performance->status,'quality_score'=>$quality,'capabilities'=>$capabilities,
                'evidence'=>['sample_count'=>$performance->sample_count,'stress_pf'=>data_get($result,'pf_attribution.stress_cost.profit_factor'),
                    'drawdown_percent'=>data_get($result,'max_drawdown_percent', data_get($result,'max_drawdown')),
                    'coverage'=>data_get($result,'opportunity_metrics.coverage',0),'passport'=>data_get($result,'elite_agent_passport.status'),
                    'dataset_hash'=>data_get($result,'data_manifest.sha256'),'code_hash'=>data_get($result,'elite_agent_passport.code_version.sha256')],
                'validated_at'=>now(),
            ]);
        }
        foreach ((array) $epoch->validators as $validator => $rule) {
            $pass = match ($validator) {
                'RegimeBreaker' => (float) data_get($result,'transition_homework.score',0) >= 50,
                'CostBreaker' => (float) data_get($result,'pf_attribution.stress_cost.profit_factor',0) >= 1.05,
                'LeakageHunter' => data_get($result,'temporal_firewall.status') === 'passed',
                'ExecutionBreaker' => data_get($result,'execution_assumptions.intrabar_policy') === 'conservative',
                'OverfitHunter' => ! (bool) data_get($result,'is_overfit',true),
                'DataQualityBreaker' => data_get($result,'data_quality.status') === 'passed',
                default => false,
            };
            AdversarialValidatorFinding::updateOrCreate(['adversarial_validator_epoch_id'=>$epoch->id,'model_version_id'=>$performance->model_version_id,'validator'=>$validator], ['verdict'=>$pass?'passed':'failed','evidence'=>[
                'layer'=>'red_team_diagnosis', 'certification_status'=>'waiting_for_evaluator_passport',
                'rule'=>$rule,'result_hash'=>hash('sha256',json_encode($result)),
                'separation_rule'=>'Diagnosis proposes curriculum cases; only a reputation-qualified evaluator with later forward confirmation may certify a failure class.',
            ]]);
        }
        $this->failureCases($performance, $result);
        $this->evaluatorBank->refresh();
    }

    public function routerEligible(ModelMarketPerformance $performance, string $regime, string $volatility): bool
    {
        $entry = AgentSkillAtlasEntry::query()->where('model_market_performance_id',$performance->id)->where('regime',$regime)
            ->where(fn($q)=>$q->whereNull('volatility')->orWhere('volatility',$volatility))->latest('validated_at')->first();
        if (! $entry || ! $entry->validated_at) return false;
        $peers = AgentSkillAtlasEntry::query()->where('symbol',$performance->symbol)->where('timeframe',$performance->timeframe)
            ->where('regime',$regime)->where(fn($q)=>$q->whereNull('volatility')->orWhere('volatility',$volatility))->get();
        $dominated = $peers->contains(function (AgentSkillAtlasEntry $other) use ($entry): bool {
            if ($other->id === $entry->id) return false;
            $stress = (float) data_get($entry->evidence,'stress_pf',0); $otherStress=(float)data_get($other->evidence,'stress_pf',0);
            $dd = (float) data_get($entry->evidence,'drawdown_percent',100); $otherDd=(float)data_get($other->evidence,'drawdown_percent',100);
            $coverage=(float)data_get($entry->evidence,'coverage',0); $otherCoverage=(float)data_get($other->evidence,'coverage',0);
            $better = (float)$other->quality_score >= (float)$entry->quality_score && $otherStress >= $stress && $otherDd <= $dd && $otherCoverage >= $coverage;
            $strict = (float)$other->quality_score > (float)$entry->quality_score || $otherStress > $stress || $otherDd < $dd || $otherCoverage > $coverage;
            return $better && $strict;
        });
        return ! $dominated;
    }

    private function epoch(ModelMarketPerformance $performance): AdversarialValidatorEpoch
    {
        $generation = (int) LabAgent::query()->where('model_version_id', $performance->model_version_id)
            ->latest()->value('lab_generation_id');
        // One immutable topology per generation.  Its commitment is written
        // before a finding is stored and it is never injected into candidate
        // mutation parameters.  A later generation receives a new topology.
        $key = 'red_queen_epoch_g'.max(0, $generation);
        $validators = $this->validatorTopology($generation);
        return AdversarialValidatorEpoch::firstOrCreate(['epoch_key'=>$key], [
            'status'=>'frozen','validators'=>$validators,
            'commitment_hash'=>hash('sha256',json_encode($validators, JSON_PRESERVE_ZERO_FRACTION)),'frozen_at'=>now(),
        ]);
    }

    private function validatorTopology(int $generation): array
    {
        $variant = max(0, $generation) % 3;
        return [
            'RegimeBreaker' => ['transition/unseen-regime', 'reversal-boundary', 'session-regime-handoff'][$variant],
            'CostBreaker' => ['double-cost/latency', 'spread-spike/slippage-tail', 'commission-and-swap-shock'][$variant],
            'LeakageHunter' => ['temporal-firewall', 'future-feature-perturbation', 'decision-time-alignment'][$variant],
            'ExecutionBreaker' => ['conservative-next-candle', 'one-candle-latency', 'stale-candle-cancel'][$variant],
            'OverfitHunter' => ['DSR/PBO/perturbation', 'parameter-plateau', 'window-permutation'][$variant],
            'DataQualityBreaker' => ['canonical-data', 'provider-disagreement', 'gap-and-duplicate-audit'][$variant],
        ];
    }
    private function niches(array $result): array
    {
        $regimes=array_keys((array)data_get($result,'regime_performance',[])); if($regimes===[])$regimes=['unknown'];
        $vols=array_keys((array)data_get($result,'volatility_performance',[])); if($vols===[])$vols=[null];
        return collect($regimes)->flatMap(fn($regime)=>collect($vols)->map(fn($vol)=>['key'=>implode('|',[$regime,$vol?:'any']),'regime'=>$regime,'volatility'=>$vol,'direction'=>null]))->all();
    }
    private function quality(array $result, string $regime): float { return round((float)data_get($result,"pf_attribution.breakdown.by_regime.{$regime}.net_pf",0)*100 - (float)data_get($result,'max_drawdown_percent',100)*2 + min(30,(int)data_get($result,'total_trades',0)),3); }
    private function failureCases(ModelMarketPerformance $performance, array $result): void
    {
        $failures=[];
        if((float)data_get($result,'pf_attribution.stress_cost.profit_factor',99)<1.05)$failures[]=['cost_fragility','WAIT_OR_REDUCE_RISK'];
        if((float)data_get($result,'transition_homework.score',100)<50)$failures[]=['transition_failure','WAIT_OR_ROUTE'];
        if(data_get($result,'temporal_firewall.status')==='failed')$failures[]=['leakage_risk','WAIT'];
        foreach($failures as [$type,$safe]){ $slice=hash('sha256',$performance->symbol.'|'.$performance->timeframe.'|'.$type.'|'.data_get($result,'data_manifest.sha256','unknown')); AgentFailureCase::firstOrCreate(['failure_case_key'=>substr($slice,0,64)],['market_slice_hash'=>$slice,'symbol'=>$performance->symbol,'timeframe'=>$performance->timeframe,'regime'=>data_get($result,'edge_claim.target_regime'),'failure_type'=>$type,'expected_safe_behavior'=>$safe,'discovered_by'=>'RedQueenEpoch1','source_model_version_id'=>$performance->model_version_id,'regression_status'=>'open','evidence'=>['result_hash'=>hash('sha256',json_encode($result))]]); }
    }
}
