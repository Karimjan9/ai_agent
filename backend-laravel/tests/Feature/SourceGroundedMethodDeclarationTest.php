<?php

namespace Tests\Feature;

use App\Services\InstrumentMethodLibraryService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SourceGroundedMethodDeclarationTest extends TestCase
{
    use RefreshDatabase;

    private const DIRECTORIES = ['closed-macd-cross', 'closed-rsi-recovery', 'closed-chandelier-exit'];

    public function test_primary_source_supplements_are_append_only_and_do_not_qualify_or_rebind_agents(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $first = $this->registerAll();
        $this->assertSame($first, $this->registerAll());
        $this->assertDatabaseCount('instrument_method_sources', 3);
        $this->assertDatabaseCount('instrument_method_revisions', 3);
        $this->assertDatabaseCount('instrument_recipe_revisions', 4);
        foreach (['agent_method_policy_revisions', 'agent_method_experiences', 'model_versions', 'jobs',
            'lab_generations', 'lab_evolution_credit_events'] as $table) $this->assertDatabaseCount($table, 0);
        $this->assertSame('ef8e53d5549ccbb55800dc4ec2f25e3d7d466b8eb64faf325d4202314e497e20', hash_file('sha256', resource_path('research/instrument-methods/curated-v1.json')));
        $this->assertSame('2a62f3d69e223b1cd4cc180c10f3f0628949a9af6ca02645abd92c5e23090ae7', hash_file('sha256', resource_path('research/instrument-methods/curated-v2.json')));
    }

    public function test_all_single_and_hybrid_methods_compile_on_exact_baselines_without_physical_mutation(): void
    {
        $recipes = $this->registerAll(); $owner = app(InstrumentMethodLibraryService::class);
        $targets = [];
        foreach (['ema_rsi', 'differential_router'] as $family) {
            $schema = app(StrategyParameterSchemaService::class);
            $parameters = array_intersect_key($schema->defaults($family), $schema->schema($family));
            foreach ($recipes as $hash) {
                $recipe = $owner->inspectRecipe($hash)['contract'];
                $compiled = $owner->compileRecipe($hash, ['strategy_family'=>$family, 'parameters'=>$parameters],
                    ['symbol'=>'XAUUSD', 'timeframe'=>'M5', 'pre_2026_only'=>true, 'data_hash'=>str_repeat('a',64),
                        'execution_hash'=>str_repeat('b',64), 'context_predicate'=>$recipe['context_predicate']]);
                $this->assertSame('compiled_research_recipe', $compiled['status'], json_encode($compiled));
                $this->assertSame($parameters, $compiled['parameters']);
                $this->assertSame([], $compiled['runtime_recipe']['parameter_adapters']);
                $this->assertFalse($compiled['qualification_granted']);
                foreach ($compiled['runtime_recipe']['operators'] as $operator) {
                    $targets[$operator['target']] = true;
                    $this->assertSame(InstrumentMethodLibraryService::FEATURE_OWNER, $operator['source_context']['method_feature_owner']);
                }
            }
        }
        $this->assertEqualsCanonicalizing(['confirmation', 'exit'], array_keys($targets));
    }

    public function test_wrong_types_and_retroactive_rule_replacement_are_refused(): void
    {
        $this->registerAll(); $owner = app(InstrumentMethodLibraryService::class);
        $original = $this->input('closed-chandelier-exit', 'method');
        $changed = $original; $changed['implementation']['input_bindings']['effect'] = 'method_spread_atr';
        $changed['method_key'] = 'wrong_chandelier_type';
        $this->assertSame('METHOD_TYPED_AST_UNSUPPORTED_OR_UNBOUND', $owner->registerMethod($changed)['reason']);
        $changed = $original; $changed['implementation']['ast'] = ['op'=>'CONST', 'type'=>'bool', 'value'=>true];
        $this->assertSame('METHOD_IMMUTABLE_REVISION_COLLISION', $owner->registerMethod($changed)['reason']);
        $stored = DB::table('instrument_method_revisions')->where('method_key',$original['method_key'])->first();
        $this->assertEquals($original['implementation']['ast'], $owner->inspectMethod($stored->method_hash)['contract']['implementation']['ast']);
        $this->assertDatabaseCount('instrument_method_revisions',3);
    }

    private function registerAll(): array
    {
        $hashes = [];
        foreach (self::DIRECTORIES as $directory) foreach (['source','method','recipe'] as $action) {
            $code = Artisan::call('trading:instrument-methods',['action'=>$action,'--input'=>$this->path($directory,$action),'--json'=>true]);
            $printed = Artisan::output(); $this->assertSame(0,$code,$printed);
            $result = json_decode($printed,true,128,JSON_THROW_ON_ERROR);
            $this->assertFalse($result['qualification_granted']);
            if ($action==='source') $this->assertSame($result['source_key'],$this->input($directory,'method')['source_keys'][0]);
            if ($action==='method') $this->assertSame($result['method_hash'],$this->input($directory,'recipe')['method_hashes'][0]);
            if ($action==='recipe') $hashes[$directory]=$result['recipe_hash'];
        }
        $hybrid = app(InstrumentMethodLibraryService::class)->registerRecipe($this->input('closed-macd-cross','hybrid'));
        $this->assertSame('registered_research_only',$hybrid['status'],json_encode($hybrid));
        $this->assertTrue($hybrid['contract']['hybrid']); $hashes['hybrid']=$hybrid['recipe_hash'];
        return $hashes;
    }

    private function input(string $directory,string $action): array { return json_decode(file_get_contents($this->path($directory,$action)),true,64,JSON_THROW_ON_ERROR); }
    private function path(string $directory,string $action): string { return resource_path('research/instrument-methods/'.$directory.'/'.$action.'.json'); }
}
