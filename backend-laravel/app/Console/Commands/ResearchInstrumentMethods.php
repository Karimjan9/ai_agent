<?php

namespace App\Console\Commands;

use App\Models\LabEvaluationRun;
use App\Services\AgentMethodLearningService;
use App\Services\AgentMethodCandidateService;
use App\Services\InstrumentMethodLibraryService;
use App\Services\InstrumentMethodResearchBindingService;
use App\Services\InstrumentMethodSourceService;
use App\Services\InstrumentMethodStudyService;
use App\Services\InstrumentMethodMetaModelService;
use App\Services\InstrumentMethodMetaBindingService;
use App\Services\CuratedInstrumentMethodCatalogService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/** Explicit research registration; only the existing arbiter dispatches registered original work. */
class ResearchInstrumentMethods extends Command
{
    protected $signature = 'trading:instrument-methods
        {action=inventory : inventory, catalog, install, source, method, recipe, compile, policy, attach, learn, propose, evolve, study, study-prepare, study-execute, settle, meta-register, meta-fit, meta-status, meta-attach or status}
        {--input= : Local JSON object file, at most 1 MiB}
        {--fetch : Explicitly fetch a public HTTPS source reference; source action only}
        {--json : Print the bounded result with exact JSON numeric values}';

    protected $description = 'Inspect and register research methods, prospectively bind an unused model, and inspect original diagnostic learning';

    public function handle(
        InstrumentMethodLibraryService $library,
        InstrumentMethodSourceService $sources,
        AgentMethodLearningService $agents,
        InstrumentMethodResearchBindingService $bindings,
    ): int {
        try {
            $action = $this->argument('action');
            if (! in_array($action, ['inventory', 'catalog', 'install', 'source', 'method', 'recipe', 'compile', 'policy', 'attach', 'learn', 'propose', 'evolve', 'study', 'study-prepare', 'study-execute', 'settle', 'meta-register', 'meta-fit', 'meta-status', 'meta-attach', 'status'], true)) {
                throw new InvalidArgumentException('METHOD_COMMAND_ACTION_INVALID');
            }
            if ($this->option('fetch') && $action !== 'source') throw new InvalidArgumentException('METHOD_COMMAND_FETCH_SOURCE_ONLY');
            $path = $this->option('input');
            if ($action === 'inventory' && filled($path)) throw new InvalidArgumentException('METHOD_COMMAND_INVENTORY_INPUT_UNSUPPORTED');
            $input = filled($path) ? $this->readInput($path) : [];
            if (! in_array($action, ['inventory', 'catalog', 'install', 'status'], true) && $input === []) throw new InvalidArgumentException('METHOD_COMMAND_INPUT_REQUIRED');
            $result = match ($action) {
                'inventory' => $library->inventory(),
                'catalog' => app(CuratedInstrumentMethodCatalogService::class)->catalog($input),
                'install' => app(CuratedInstrumentMethodCatalogService::class)->install($input),
                'source' => $this->option('fetch') ? $sources->fetchSource($input) : $sources->registerSource($input),
                'method' => $library->registerMethod($input),
                'recipe' => $library->registerRecipe($input),
                'policy' => $agents->registerPolicy($input),
                'compile' => $this->compile($input, $library, $bindings),
                'attach' => $bindings->attachToUnusedModel($this->integer($input, 'model_id'), $this->hashField($input, 'policy_hash')),
                'learn' => $agents->learnFromRun($this->originalRun($input)),
                'propose' => $agents->proposePolicy($this->hashField($input, 'policy_hash'), $this->integer($input, 'revision'),
                    isset($input['max_recipes']) ? $this->integer($input, 'max_recipes') : 3),
                'evolve' => app(AgentMethodCandidateService::class)->evolve($this->hashField($input, 'policy_hash'), $this->integer($input, 'revision'),
                    isset($input['max_recipes']) ? $this->integer($input, 'max_recipes') : 3),
                'study' => app(InstrumentMethodStudyService::class)->preregister($input),
                'study-prepare' => app(\App\Services\AgentMethodStudyPreparationService::class)->prepare($input),
                'study-execute' => $this->registerStudyExecution($input),
                'settle' => $this->settleStudy($input),
                'meta-register' => app(InstrumentMethodMetaModelService::class)->preregister($input),
                'meta-fit' => app(InstrumentMethodMetaModelService::class)->fit($this->onlyHash($input, 'registration_hash')),
                'meta-status' => app(InstrumentMethodMetaModelService::class)->inspect($this->onlyHash($input, 'registration_hash')),
                'meta-attach' => $this->attachMetaModel($input),
                'status' => $this->status($input, $library, $agents),
            };
            // Reference text is stored by the source owner, never echoed by this interface.
            if ($action === 'source') unset($result['content']);
            $result = $this->sanitizeReasons($result);
            $this->render($result);
            return ($result['status'] ?? null) === 'blocked' ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $error) {
            $this->render(['protocol' => 'instrument_method_command_v1', 'status' => 'blocked',
                'reason' => $this->reason($error->getMessage()), 'research_only' => true,
                'qualification_granted' => false, 'promotion_evidence' => false, 'economic_authority' => false]);
            return self::FAILURE;
        }
    }

    private function onlyHash(array $input, string $field): string
    {
        if (count($input) !== 1 || ! array_key_exists($field, $input)) {
            throw new InvalidArgumentException('METHOD_COMMAND_EXACT_META_REFERENCE_REQUIRED');
        }
        return $this->hashField($input, $field);
    }

    private function attachMetaModel(array $input): array
    {
        if (count($input) !== 2 || array_diff(array_keys($input), ['model_id', 'model_hash']) !== []) {
            throw new InvalidArgumentException('METHOD_COMMAND_EXACT_META_ATTACHMENT_REQUIRED');
        }
        return app(InstrumentMethodMetaBindingService::class)->attachUnusedModel(
            $this->integer($input, 'model_id'), $this->hashField($input, 'model_hash'));
    }

    private function settleStudy(array $input): array
    {
        if (! is_array($input['run_ids'] ?? null) || ! array_is_list($input['run_ids'])
            || $input['run_ids'] === [] || count($input['run_ids']) > 32) throw new InvalidArgumentException('METHOD_COMMAND_ORIGINAL_STUDY_RUNS_REQUIRED');
        foreach ($input['run_ids'] as $runId) {
            if (! is_string($runId) || ! preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $runId)) throw new InvalidArgumentException('METHOD_COMMAND_ORIGINAL_RUN_ID_REQUIRED');
        }
        return app(InstrumentMethodStudyService::class)->settle($this->hashField($input, 'study_hash'), $input['run_ids']);
    }

    private function registerStudyExecution(array $input): array
    {
        if (count($input) !== 2 || ! is_array($input['source'] ?? null)
            || array_diff(array_keys($input), ['study_hash', 'source']) !== []) {
            throw new InvalidArgumentException('METHOD_COMMAND_ORIGINAL_STUDY_EXECUTION_REQUIRED');
        }
        return app(\App\Services\InstrumentMethodStudyExecutionService::class)->registerWork(
            $this->hashField($input, 'study_hash'), $input['source']);
    }

    private function compile(array $input, InstrumentMethodLibraryService $library, InstrumentMethodResearchBindingService $bindings): array
    {
        if (! is_array($input['baseline'] ?? null) || ! is_array($input['scope'] ?? null)
            || isset($input['recipe_hash']) === isset($input['policy_hash'])) throw new InvalidArgumentException('METHOD_COMMAND_COMPILE_BINDING_REQUIRED');
        if (isset($input['recipe_hash'])) return $library->compileRecipe($this->hashField($input, 'recipe_hash'), $input['baseline'], $input['scope']);
        if (! is_string($input['agent_key'] ?? null) || ! preg_match('/^[A-Za-z0-9_.:\/-]{1,150}$/D', $input['agent_key'])) {
            throw new InvalidArgumentException('METHOD_COMMAND_AGENT_KEY_REQUIRED');
        }
        // Keep the sealed native object intact; adding command status fields would invalidate its hash.
        return $bindings->compilePolicy($this->hashField($input, 'policy_hash'), $input['baseline'], $input['scope'], $input['agent_key']);
    }

    private function status(array $input, InstrumentMethodLibraryService $library, AgentMethodLearningService $agents): array
    {
        if ($input === []) return $agents->statistics();
        if (count($input) !== 1) throw new InvalidArgumentException('METHOD_COMMAND_STATUS_REFERENCE_INVALID');
        return match (array_key_first($input)) {
            'method_hash' => $library->inspectMethod($this->hashField($input, 'method_hash')),
            'recipe_hash' => $library->inspectRecipe($this->hashField($input, 'recipe_hash')),
            'policy_hash' => $agents->policy($this->hashField($input, 'policy_hash')),
            default => throw new InvalidArgumentException('METHOD_COMMAND_STATUS_REFERENCE_INVALID'),
        };
    }

    private function originalRun(array $input): LabEvaluationRun
    {
        $runId = $input['run_id'] ?? null;
        if (! is_string($runId) || ! preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $runId)) throw new InvalidArgumentException('METHOD_COMMAND_ORIGINAL_RUN_ID_REQUIRED');
        $run = LabEvaluationRun::where('run_id', $runId)->first();
        if (! $run) throw new InvalidArgumentException('METHOD_COMMAND_ORIGINAL_RUN_NOT_FOUND');
        return $run;
    }

    private function hashField(array $input, string $field): string
    {
        if (! is_string($input[$field] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $input[$field])) throw new InvalidArgumentException('METHOD_COMMAND_HASH_REFERENCE_REQUIRED');
        return $input[$field];
    }

    private function integer(array $input, string $field): int
    {
        if (! is_int($input[$field] ?? null) || $input[$field] < 1 || $input[$field] > 2147483647) throw new InvalidArgumentException('METHOD_COMMAND_POSITIVE_INTEGER_REQUIRED');
        return $input[$field];
    }

    private function readInput(mixed $path): array
    {
        if (! is_string($path) || preg_match('/[\x00-\x1f]/', $path) || str_starts_with($path, '\\\\') || str_starts_with($path, '//')
            || preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:\/\//', $path) || ! stream_is_local($path)
            || is_link($path) || ! is_file($path)) throw new InvalidArgumentException('METHOD_COMMAND_LOCAL_REGULAR_INPUT_REQUIRED');
        $resolved = realpath($path);
        if ($resolved === false) throw new InvalidArgumentException('METHOD_COMMAND_LOCAL_REGULAR_INPUT_REQUIRED');
        // Refuse symbolic links in the explicit path, including linked parent directories.
        $parent = dirname($path);
        while ($parent !== '.' && $parent !== dirname($parent)) {
            if (is_link($parent)) throw new InvalidArgumentException('METHOD_COMMAND_LOCAL_REGULAR_INPUT_REQUIRED');
            $parent = dirname($parent);
        }
        $before = lstat($path);
        if (! is_array($before) || ($before['mode'] & 0170000) !== 0100000 || $before['size'] > 1048576) throw new InvalidArgumentException('METHOD_COMMAND_INPUT_SIZE_OR_TYPE_INVALID');
        $stream = fopen($resolved, 'rb');
        if (! is_resource($stream)) throw new InvalidArgumentException('METHOD_COMMAND_INPUT_UNREADABLE');
        try {
            $opened = fstat($stream);
            $raw = stream_get_contents($stream, 1048577);
        } finally { fclose($stream); }
        clearstatcache(true, $path);
        $after = lstat($path);
        if (! is_array($opened) || ! is_array($after) || ($opened['mode'] & 0170000) !== 0100000
            || ($after['mode'] & 0170000) !== 0100000 || $opened['ino'] !== $after['ino'] || $opened['dev'] !== $after['dev']
            || ! is_string($raw) || strlen($raw) > 1048576) throw new InvalidArgumentException('METHOD_COMMAND_INPUT_CHANGED_OR_TOO_LARGE');
        try { $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new InvalidArgumentException('METHOD_COMMAND_INPUT_INVALID_JSON'); }
        if (! is_array($decoded) || ! str_starts_with(ltrim($raw), '{') || ($decoded !== [] && array_is_list($decoded))) {
            throw new InvalidArgumentException('METHOD_COMMAND_JSON_OBJECT_REQUIRED');
        }
        return $decoded;
    }

    private function sanitizeReasons(array $items): array
    {
        if (($items['status'] ?? null) === 'blocked') {
            foreach (['reason', 'reason_code'] as $key) {
                if (isset($items[$key])) $items[$key] = $this->reason(is_string($items[$key]) ? $items[$key] : '');
            }
        }
        foreach (['methods', 'recipes'] as $key) {
            if (isset($items[$key]) && is_array($items[$key])) {
                foreach ($items[$key] as $index => $item) if (is_array($item)) $items[$key][$index] = $this->sanitizeReasons($item);
            }
        }
        return $items;
    }

    private function reason(string $message): string
    {
        return preg_match('/^[A-Z][A-Z0-9_]{2,119}$/D', $message) ? $message : 'METHOD_COMMAND_OPERATION_REFUSED';
    }

    private function render(array $result): void
    {
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
            return;
        }
        $summary = (string) ($result['status'] ?? (isset($result['contract_hash']) ? 'compiled_research_contract' : 'read_only_status'));
        if (isset($result['reason'])) $summary .= ': '.$result['reason'];
        foreach (['source_key', 'method_hash', 'recipe_hash', 'policy_hash', 'contract_hash', 'library_hash', 'registration_hash', 'model_hash'] as $field) {
            if (isset($result[$field])) $summary .= '; '.$field.'='.$result[$field];
        }
        $this->line($summary.'; research_only=true; promotion_evidence=false');
    }
}
