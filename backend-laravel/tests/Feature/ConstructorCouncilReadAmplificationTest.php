<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\CandidateGateDecision;
use App\Models\ModelMarketPerformance;
use App\Services\LabPopulationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class ConstructorCouncilReadAmplificationTest extends TestCase
{
    private LabPopulationService $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = (new ReflectionClass(LabPopulationService::class))->newInstanceWithoutConstructor();
        config(['services.lab_selection.forward_failure_source_limit' => 0]);
    }

    public function test_original_selection_ties_clusters_and_recall_fields_are_unchanged(): void
    {
        foreach (range(0, 19) as $variant) {
            [$rows, $decisions] = $this->fixtures($variant);
            $expected = $this->originalSelection($rows, $decisions);
            $actual = $this->invoke('forwardFailureCouncilNiches', $rows, $decisions);
            $this->assertSame($expected, $actual, 'Original selection parity variant '.$variant);
            $this->assertSame(['trend_up', 'trend_down', 'range'], array_column($actual, 'regime'));
            foreach ($actual as $niche) {
                $this->assertTrue($niche['non_target_parent_freeze']);
                $this->assertSame('combined_portfolio_only', $niche['promotion_rule']);
                $this->assertArrayNotHasKey('_performance', $niche);
                $this->assertFalse($niche['state_cluster']['promotion_evidence'] ?? false);
            }
        }
    }

    public function test_full_metrics_decode_count_is_linear_plus_at_most_three_winners(): void
    {
        [$rows, $decisions] = $this->fixtures(7);
        foreach ($rows as $row) $row->metricsReads = 0;
        $this->invoke('forwardFailureCouncilNiches', $rows, $decisions);
        $this->assertSame($rows->count() + 3, $rows->sum('metricsReads'));
        foreach ($rows as $row) $row->metricsReads = 0;
        $this->originalSelection($rows, $decisions);
        $this->assertGreaterThan($rows->count() * 3, $rows->sum('metricsReads'));
    }

    public function test_equal_rank_keeps_original_first_source_and_does_not_consume_mutation_rng(): void
    {
        $metrics = ['pf_attribution' => ['breakdown' => ['by_regime_volatility' => [
            'trend_up|normal_volatility' => ['trades' => 9, 'net_pf' => .8],
            'trend_up|high_volatility' => ['trades' => 9, 'net_pf' => 1.7],
        ]]], 'entry_funnel' => ['dominant_rejection' => 'regime_transition_wait',
            'rejected' => ['regime_transition_wait' => 8]]];
        $first = new CountedConstructorPerformance(['strategy_family' => 'hybrid', 'metrics' => $metrics]);
        $first->id = 27;
        $second = new CountedConstructorPerformance(['strategy_family' => 'differential_router', 'metrics' => $metrics]);
        $second->id = 28;
        $decisions = collect([27 => collect([new CandidateGateDecision(['reason_codes' => ['FIRST']])]),
            28 => collect([new CandidateGateDecision(['reason_codes' => ['SECOND']])])]);
        mt_srand(424242); $expectedDraw = mt_rand();
        mt_srand(424242);
        $actual = $this->invoke('forwardFailureCouncilNiches', collect([$first, $second]), $decisions);
        $this->assertSame($expectedDraw, mt_rand());
        $this->assertSame(27, $actual[0]['source_performance_id']);
        $this->assertSame('normal_volatility', $actual[0]['volatility']);
        $this->assertSame(['FIRST'], $actual[0]['failed_gate_reasons']);
        $reverse = $this->invoke('forwardFailureCouncilNiches', collect([$second, $first]), $decisions);
        $this->assertSame(28, $reverse[0]['source_performance_id']);
    }

    public function test_empty_context_defaults_still_abstain_without_claiming_evidence(): void
    {
        $actual = $this->invoke('forwardFailureCouncilNiches', collect(), collect());
        $this->assertSame(['normal_volatility', 'normal_volatility', 'low_volatility'], array_column($actual, 'volatility'));
        foreach ($actual as $niche) {
            $this->assertNull($niche['source_performance_id']);
            $this->assertNull($niche['state_cluster']);
            $this->assertNull($niche['recall_variant']);
            $this->assertSame(0, $niche['observed_trades']);
        }
    }

    public function test_real_forward_query_does_not_hydrate_unused_models_and_rechecks_fresh_rows(): void
    {
        $this->minimalTables();
        $metrics = ['pf_attribution' => ['breakdown' => ['by_regime_volatility' => [
            'trend_up|normal_volatility' => ['trades' => 8, 'net_pf' => .8],
        ]]], 'entry_funnel' => ['dominant_rejection' => 'regime_transition_wait',
            'rejected' => ['regime_transition_wait' => 12]]];
        DB::table('model_market_performance')->insert($this->persistedRow(1, $metrics));
        DB::table('candidate_gate_decisions')->insert(['model_market_performance_id' => 1,
            'stage' => 'statistical_forward_gate', 'decision' => 'failed',
            'reason_codes' => json_encode(['FAILED_PASSPORT_OPPORTUNITY_RECALL']), 'evaluated_at' => '2025-12-01 00:00:00']);
        $lab = new AiLaboratory(['symbol' => 'XAUUSD', 'timeframe' => 'H1']);
        DB::enableQueryLog();
        $first = $this->invoke('forwardFailureCouncilCurriculum', $lab);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(2, $queries);
        foreach ($queries as $query) {
            $this->assertStringStartsWith('select ', strtolower($query['query']));
            $this->assertStringNotContainsString('model_versions', $query['query']);
        }
        $this->assertSame(1, $first['niches'][0]['source_performance_id']);
        $this->assertSame(12, $first['niches'][0]['transition_wait_rejections']);
        $this->assertSame('transition_wait_shortening', $first['niches'][0]['recall_variant']);
        $original = DB::table('model_market_performance')->value('metrics');
        $this->assertSame(json_encode($metrics), $original);
        $metrics['entry_funnel']['rejected']['regime_transition_wait'] = 31;
        DB::table('model_market_performance')->where('id', 1)->update(['metrics' => json_encode($metrics)]);
        $second = $this->invoke('forwardFailureCouncilCurriculum', $lab);
        $this->assertSame(31, $second['niches'][0]['transition_wait_rejections']);
        $this->assertSame(12, $first['niches'][0]['transition_wait_rejections']);
    }

    public function test_robustness_frontier_also_uses_only_performance_evidence(): void
    {
        $this->minimalTables();
        DB::table('model_market_performance')->insert($this->persistedRow(1, ['robustness_matrix' => [
            'weakest_envelopes' => [['regime' => 'range', 'volatility' => 'low_volatility',
                'direction' => 'BUY', 'session' => '07', 'trades' => 9, 'net_pf' => .7]],
        ]]));
        $lab = new AiLaboratory(['symbol' => 'XAUUSD', 'timeframe' => 'H1']);
        DB::enableQueryLog();
        $frontier = $this->invoke('robustnessMatrixFrontier', $lab);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(1, $queries);
        $this->assertStringNotContainsString('model_versions', $queries[0]['query']);
        $this->assertCount(1, $frontier);
        $this->assertSame('diagnostic_recurrence_only', $frontier[0]['calendar_role']);
        $this->assertSame(1, $frontier[0]['source_performance_id']);
    }

    private function invoke(string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod($this->owner, $method))->invoke($this->owner, ...$args);
    }

    /** Original traversal/ranking used as a differential oracle, not an optimized copy. */
    private function originalSelection(Collection $rows, Collection $decisions): array
    {
        $out = [];
        foreach (['trend_up' => 'normal_volatility', 'trend_down' => 'normal_volatility', 'range' => 'low_volatility'] as $regime => $default) {
            $best = null;
            foreach ($rows as $performance) {
                foreach ((array) data_get($performance->metrics, 'pf_attribution.breakdown.by_regime_volatility', []) as $key => $row) {
                    [$contextRegime, $volatility] = array_pad(explode('|', (string) $key, 2), 2, null);
                    if ($contextRegime !== $regime || ! in_array($volatility, ['low_volatility', 'normal_volatility', 'high_volatility'], true)
                        || (int) data_get($row, 'trades', 0) < 3) continue;
                    $trades = (int) data_get($row, 'trades', 0);
                    $dominant = (string) data_get($performance->metrics, 'entry_funnel.dominant_rejection', '');
                    $waits = (int) data_get($performance->metrics, 'entry_funnel.rejected.regime_transition_wait', 0);
                    $priority = $dominant === 'regime_transition_wait';
                    $preferred = in_array($performance->strategy_family, ['hybrid', 'differential_router'], true);
                    $oldPreferred = $best && in_array($best['family'], ['hybrid', 'differential_router'], true);
                    $oldPriority = $best['priority'] ?? false;
                    // The old algorithm also builds a cluster for discarded candidates.
                    $cluster = $this->invoke('stateClusterForPerformance', $performance, $regime, $volatility);
                    if ($best === null || ($preferred && ! $oldPreferred)
                        || (($preferred === $oldPreferred) && (($priority && ! $oldPriority)
                            || ($priority === $oldPriority && $waits > $best['waits'])
                            || (! $priority && ! $oldPriority && $trades > $best['trades'])))) {
                        $best = ['family' => $performance->strategy_family, 'priority' => $priority, 'waits' => $waits,
                            'trades' => $trades, 'volatility' => $volatility, 'id' => $performance->id,
                            'pf' => (float) data_get($row, 'net_pf', data_get($row, 'profit_factor', 0)),
                            'reasons' => array_values(array_unique((array) data_get($decisions->get($performance->id)->first(), 'reason_codes', []))),
                            'dominant' => $dominant ?: null, 'cluster' => $cluster];
                    }
                }
            }
            $out[] = ['protocol' => 'portfolio_council_v1', 'role' => $regime.'_specialist', 'regime' => $regime,
                'volatility' => $best['volatility'] ?? $default, 'direction' => null, 'objective' => 'regime_coverage',
                'source_performance_id' => $best['id'] ?? null, 'observed_trades' => $best['trades'] ?? 0,
                'observed_profit_factor' => $best['pf'] ?? 0, 'failed_gate_reasons' => $best['reasons'] ?? [],
                'dominant_entry_rejection' => $best['dominant'] ?? null, 'transition_wait_rejections' => $best['waits'] ?? 0,
                'state_cluster' => $best['cluster'] ?? null,
                'recall_variant' => ($best['priority'] ?? false) ? 'transition_wait_shortening' : null,
                'recall_source_performance_id' => ($best['priority'] ?? false) ? $best['id'] : null,
                'research_reason' => 'forward_failure_without_combined_portfolio', 'non_target_parent_freeze' => true,
                'promotion_rule' => 'combined_portfolio_only'];
        }
        return $out;
    }

    private function fixtures(int $variant): array
    {
        $rows = collect(); $decisions = collect();
        foreach (range(1, 12) as $id) {
            $contexts = [];
            foreach (['trend_up', 'trend_down', 'range', 'unknown'] as $r => $regime) {
                foreach (['low_volatility', 'normal_volatility', 'high_volatility', 'unknown'] as $v => $vol) {
                    $contexts[$regime.'|'.$vol] = ['trades' => ($id * 3 + $r + $v + $variant) % 17,
                        'net_pf' => (($id + $v + $variant) % 5) / 3];
                }
            }
            $dominant = ($id + $variant) % 3 === 0 ? 'regime_transition_wait' : 'minimum_confidence';
            $row = new CountedConstructorPerformance(['strategy_family' => ['trend', 'hybrid', 'differential_router'][$id % 3],
                'metrics' => ['unused_original_document' => str_repeat('x', 8192),
                    'pf_attribution' => ['breakdown' => ['by_regime_volatility' => $contexts]],
                    'entry_funnel' => ['dominant_rejection' => $dominant, 'rejected' => ['regime_transition_wait' => ($id + $variant) % 4]],
                    'veto_regret' => ['by_regime_context' => [
                        $dominant.'|trend_up|normal_volatility|liquid' => ['shadow_trades' => 17],
                        $dominant.'|range|low_volatility|unknown' => ['shadow_trades' => 19]]],
                    'transition_homework' => ['transition_events' => $id, 'false_entry_rate' => .25]]]);
            $row->id = $id;
            $rows->push($row);
            $decisions->put($id, collect([new CandidateGateDecision(['reason_codes' => ['FAILED_RECALL', 'FAILED_RECALL', 'FAILED_COST']])]));
        }
        return [$rows, $decisions];
    }

    private function minimalTables(): void
    {
        Schema::create('model_market_performance', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('model_version_id');
            foreach (['symbol', 'timeframe', 'strategy_family', 'status', 'evidence_status'] as $key) $table->string($key);
            $table->text('metrics'); $table->timestamps();
        });
        Schema::create('candidate_gate_decisions', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('model_market_performance_id');
            $table->string('stage'); $table->string('decision'); $table->text('reason_codes'); $table->dateTime('evaluated_at');
        });
    }

    private function persistedRow(int $id, array $metrics): array
    {
        return ['id' => $id, 'model_version_id' => 999, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'hybrid', 'status' => 'rejected', 'evidence_status' => 'valid',
            'metrics' => json_encode($metrics), 'created_at' => '2025-12-01 00:00:00', 'updated_at' => '2025-12-01 00:00:00'];
    }
}

class CountedConstructorPerformance extends ModelMarketPerformance
{
    public int $metricsReads = 0;

    public function getAttribute($key)
    {
        if ($key === 'metrics') $this->metricsReads++;
        return parent::getAttribute($key);
    }
}
