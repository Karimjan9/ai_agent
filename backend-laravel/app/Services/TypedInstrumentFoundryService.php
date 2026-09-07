<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A deliberately small, temporal-safe DSL. Compilation is research-only and
 * can target only declared runtime primitives; it never creates live policy.
 */
class TypedInstrumentFoundryService
{
    public const PROTOCOL = 'typed_instrument_foundry_v1';
    private const MAX_NODES = 48;

    /** @return array<string,mixed> */
    public function compile(array $ast, array $context, array $gates): array
    {
        if (! Schema::hasTable('research_instrument_programs')) return $this->unavailable();
        if ((int) ($gates['confirmed_cartridges'] ?? 0) < 1 || (int) ($gates['successful_transfers'] ?? 0) < 1) {
            return $this->blocked('CONFIRMED_CARTRIDGE_AND_SUCCESSFUL_TRANSFER_REQUIRED');
        }
        if (($context['pre_2026_only'] ?? false) !== true || ! filled($context['data_hash'] ?? null) || ! filled($context['execution_hash'] ?? null)) {
            return $this->blocked('PRE2026_DATA_AND_EXECUTION_IDENTITY_REQUIRED');
        }
        $validation = $this->infer($ast);
        if (($validation['valid'] ?? false) !== true) return $this->blocked((string) $validation['reason']);
        if ((int) $validation['node_count'] > self::MAX_NODES) return $this->blocked('DSL_COMPLEXITY_BUDGET_EXCEEDED');
        $normalized = $this->canonicalize($ast);
        $astHash = $this->hash($normalized);
        $symbol = strtoupper((string) ($context['symbol'] ?? 'XAUUSD'));
        $timeframe = strtoupper((string) ($context['timeframe'] ?? 'H1'));
        $existing = DB::table('research_instrument_programs')->where('symbol', $symbol)->where('timeframe', $timeframe)->where('ast_hash', $astHash)->first();
        if ($existing) return ['protocol' => self::PROTOCOL, 'status' => 'semantic_duplicate', 'program_key' => $existing->program_key,
            'promotion_evidence' => false];
        $compiled = ['protocol' => self::PROTOCOL, 'version' => 'typed_dsl_v1', 'result_type' => $validation['type'],
            'runtime_primitives' => $validation['primitives'], 'prefix_invariant' => true,
            'available_at_required' => true, 'data_hash' => $context['data_hash'], 'execution_hash' => $context['execution_hash'],
            'research_only' => true, 'promotion_evidence' => false];
        $key = hash('sha256', implode('|', [self::PROTOCOL, $symbol, $timeframe, $astHash, $context['data_hash'], $context['execution_hash']]));
        DB::table('research_instrument_programs')->insert([
            'program_key' => $key, 'symbol' => $symbol, 'timeframe' => $timeframe, 'status' => 'compiled_research_only',
            'complexity' => $validation['node_count'], 'ast_hash' => $astHash, 'ast' => json_encode($normalized),
            'compiled_contract' => json_encode($compiled), 'evidence' => json_encode(['gates' => $gates, 'context' => $context, 'promotion_evidence' => false]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return ['protocol' => self::PROTOCOL, 'status' => 'compiled_research_only', 'program_key' => $key,
            'compiled_contract' => $compiled, 'promotion_evidence' => false];
    }

    /** Store only a semantics-preserving normalized AST; replay is still required. */
    public function compress(string $programKey): array
    {
        if (! Schema::hasTable('research_instrument_programs')) return $this->unavailable();
        $program = DB::table('research_instrument_programs')->where('program_key', $programKey)->first();
        if (! $program) return $this->blocked('INSTRUMENT_PROGRAM_NOT_FOUND');
        $ast = (array) json_decode((string) $program->ast, true);
        $normalized = $this->canonicalize($ast);
        $unchanged = $this->hash($ast) === $this->hash($normalized);
        DB::table('research_instrument_programs')->where('id', $program->id)->update([
            'status' => $unchanged ? 'compiled_research_only' : 'compression_replay_required',
            'ast' => json_encode($normalized), 'updated_at' => now(),
        ]);
        return ['protocol' => self::PROTOCOL, 'status' => $unchanged ? 'already_normalized' : 'compression_replay_required',
            'promotion_evidence' => false];
    }

    /** @return array<string,mixed> */
    public function recordEmitterOutcome(string $emitter, array $scope, array $result): array
    {
        if (! Schema::hasTable('research_emitter_credit_profiles')) return $this->unavailable();
        if (($result['settled'] ?? false) !== true) return $this->blocked('SETTLED_EMITTER_OUTCOME_REQUIRED');
        $symbol = strtoupper((string) ($scope['symbol'] ?? 'XAUUSD')); $timeframe = strtoupper((string) ($scope['timeframe'] ?? 'H1'));
        $scopeKey = $this->hash(['failure_stage' => $scope['failure_stage'] ?? 'unknown', 'strategy_family' => $scope['strategy_family'] ?? 'unknown', 'context' => $scope['context'] ?? []]);
        $key = hash('sha256', implode('|', [self::PROTOCOL, 'emitter', $symbol, $timeframe, $emitter, $scopeKey]));
        $previous = DB::table('research_emitter_credit_profiles')->where('profile_key', $key)->first();
        $old = $previous ? (array) json_decode((string) $previous->results, true) : ['cohorts'=>0,'behavior_changed'=>0,'stage_advanced'=>0,'reusable_skills'=>0,'successful_transfers'=>0,'no_op'=>0,'compute_minutes'=>0,'duplicate_behavior'=>0,'overfit_exposure'=>0,'uncertainty_resolved'=>0,'archive_coverage'=>0];
        foreach (array_keys($old) as $metric) $old[$metric] += (float) ($result[$metric] ?? 0);
        $cohorts = max(1., $old['cohorts']);
        $estimate = (($old['stage_advanced'] * 2) + ($old['reusable_skills'] * 2) + ($old['successful_transfers'] * 3) + $old['uncertainty_resolved'] + $old['archive_coverage']
            - ($old['duplicate_behavior'] * 2) - ($old['no_op'] * 1.5) - ($old['overfit_exposure'] * 2) - ($old['compute_minutes'] / 60)) / $cohorts;
        DB::table('research_emitter_credit_profiles')->updateOrInsert(['profile_key' => $key], [
            'symbol'=>$symbol,'timeframe'=>$timeframe,'emitter'=>$emitter,'scope_key'=>$scopeKey,'estimate'=>$estimate,
            'uncertainty'=>round(1 / sqrt($cohorts), 6),'settled_cohorts'=>(int) $cohorts,'results'=>json_encode($old),
            'evidence'=>json_encode(['protocol'=>self::PROTOCOL,'scope'=>$scope,'promotion_evidence'=>false]),'assessed_at'=>now(),'updated_at'=>now(),'created_at'=>now(),
        ]);
        return ['protocol'=>self::PROTOCOL,'status'=>'profiled','estimate'=>round($estimate,6),'settled_cohorts'=>(int)$cohorts,'promotion_evidence'=>false];
    }

    /** @return array<string,mixed> */
    public function planCompoundingBenchmark(array $contract): array
    {
        if (! Schema::hasTable('research_compounding_benchmarks')) return $this->unavailable();
        if ((int) ($contract['confirmed_cartridges'] ?? 0) < 1 || (int) ($contract['successful_transfers'] ?? 0) < 1) return $this->blocked('CONFIRMED_CARTRIDGE_AND_TRANSFER_REQUIRED');
        if (! filled($contract['sealed_challenge_hash'] ?? null) || ! isset($contract['equal_compute_limit'])) return $this->blocked('SEALED_CHALLENGE_AND_EQUAL_COMPUTE_REQUIRED');
        $symbol=strtoupper((string)($contract['symbol']??'XAUUSD')); $timeframe=strtoupper((string)($contract['timeframe']??'H1'));
        $key=$this->hash([self::PROTOCOL,$symbol,$timeframe,$contract['sealed_challenge_hash'],$contract['equal_compute_limit']]);
        DB::table('research_compounding_benchmarks')->updateOrInsert(['benchmark_key'=>$key],[
            'symbol'=>$symbol,'timeframe'=>$timeframe,'status'=>'planned','sealed_contract'=>json_encode(['protocol'=>self::PROTOCOL,...$contract,'promotion_evidence'=>false]),
            'updated_at'=>now(),'created_at'=>now(),
        ]);
        return ['protocol'=>self::PROTOCOL,'status'=>'planned','benchmark_key'=>$key,'promotion_evidence'=>false];
    }

    private function infer(array $node): array
    {
        $op = strtoupper((string) ($node['op'] ?? '')); $args = (array) ($node['args'] ?? []);
        $leaf = ['PRICE_CLOSE'=>'price','ATR'=>'atr','DURATION'=>'duration','BOOL'=>'bool','NUMBER'=>'number'];
        if (isset($leaf[$op])) {
            if (! filled($node['available_at'] ?? null)) return ['valid'=>false,'reason'=>'DSL_AVAILABLE_AT_REQUIRED'];
            return ['valid'=>true,'type'=>$leaf[$op],'node_count'=>1,'primitives'=>[$op]];
        }
        if ($op === 'CONST') return ['valid'=>true,'type'=>(string)($node['type']??'number'),'node_count'=>1,'primitives'=>[]];
        $children=[]; foreach ($args as $arg) { if (! is_array($arg)) return ['valid'=>false,'reason'=>'DSL_AST_ARGUMENT_REQUIRED']; $child=$this->infer($arg); if (!($child['valid']??false)) return $child; $children[]=$child; }
        $types=array_column($children,'type'); $count=1+array_sum(array_column($children,'node_count')); $primitives=array_values(array_unique(array_merge(...array_map(fn($c)=>(array)$c['primitives'],$children ?: [['primitives'=>[]]]))));
        $ok = match ($op) {
            'GREATER_THAN','LESS_THAN' => count($types)===2 && in_array($types[0],['price','atr','number'],true) && in_array($types[1],['price','atr','number'],true),
            'AND','OR' => count($types)>=2 && !in_array(false,array_map(fn($t)=>$t==='bool',$types),true),
            'NOT' => $types===['bool'],
            'SEQUENCE' => count($types)>=2 && !in_array(false,array_map(fn($t)=>$t==='bool',$types),true),
            'WITHIN' => $types===['bool','duration'],
            'CONFIRMED_BY' => $types===['bool','bool'],
            default => false,
        };
        return $ok ? ['valid'=>true,'type'=>'bool','node_count'=>$count,'primitives'=>$primitives] : ['valid'=>false,'reason'=>'DSL_TYPE_OR_OPERATOR_INVALID'];
    }

    private function canonicalize(mixed $value): mixed { if (!is_array($value)) return $value; if (!array_is_list($value)) ksort($value); foreach($value as $key=>$item) $value[$key]=$this->canonicalize($item); return $value; }
    private function hash(mixed $value): string { return hash('sha256',json_encode($this->canonicalize($value),JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION)); }
    private function unavailable(): array { return ['protocol'=>self::PROTOCOL,'status'=>'migration_pending','promotion_evidence'=>false]; }
    private function blocked(string $reason): array { return ['protocol'=>self::PROTOCOL,'status'=>'blocked','reason'=>$reason,'promotion_evidence'=>false]; }
}
