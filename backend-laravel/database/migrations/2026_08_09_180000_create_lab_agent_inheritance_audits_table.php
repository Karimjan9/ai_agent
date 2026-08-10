<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_agent_inheritance_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lab_agent_id')->constrained('lab_agents')->cascadeOnDelete();
            $table->foreignId('source_model_version_id')->nullable()->constrained('model_versions')->nullOnDelete();
            $table->foreignId('source_agent_id')->nullable()->constrained('lab_agents')->nullOnDelete();
            $table->string('protocol', 80);
            $table->string('transition', 64);
            $table->string('decision', 24)->default('accepted');
            $table->string('semantic_group_key', 255)->nullable();
            $table->string('seed_hash', 64)->nullable();
            $table->string('child_parameter_hash', 64)->nullable();
            $table->string('contract_hash', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['lab_agent_id', 'protocol', 'transition'], 'lab_inheritance_audit_identity');
            $table->index(['source_model_version_id', 'transition'], 'lab_inheritance_source_lookup');
            $table->index(['semantic_group_key', 'decision'], 'lab_inheritance_group_lookup');
        });

        // Older clean root cohorts were already semantically scoped, but they
        // predate the explicit seed contract. Backfill only rows that are
        // parentless, canonical, non-invalidated roots. No legacy or failed
        // model becomes genetic material through this migration.
        $canonical = [
            'trend_up_specialist' => ['regime' => 'trend_up', 'volatility' => 'high_volatility'],
            'trend_down_specialist' => ['regime' => 'trend_down', 'volatility' => 'normal_volatility'],
            'range_specialist' => ['regime' => 'range', 'volatility' => 'low_volatility'],
            'transition_risk_router' => ['regime' => 'trend_up', 'volatility' => 'high_volatility'],
        ];

        DB::table('lab_agents')
            ->whereNull('parent_a_model_version_id')
            ->whereNull('parent_b_model_version_id')
            ->orderBy('id')
            ->chunkById(200, function ($agents) use ($canonical): void {
                foreach ($agents as $agent) {
                    $model = DB::table('model_versions')->where('id', $agent->model_version_id)->first();
                    if (! $model || in_array((string) ($model->evidence_status ?? ''), ['legacy_invalid', 'stale_quarantine'], true)
                        || $model->invalidated_at !== null) {
                        continue;
                    }

                    $metadata = json_decode((string) ($model->metadata ?? '{}'), true);
                    $group = is_array($metadata['semantic_group'] ?? null) ? $metadata['semantic_group'] : [];
                    $controlRoot = is_array($metadata['control_root'] ?? null) ? $metadata['control_root'] : [];
                    $role = (string) ($group['role'] ?? '');
                    if (! isset($canonical[$role])
                        || (string) ($group['regime'] ?? '') !== $canonical[$role]['regime']
                        || (string) ($group['volatility'] ?? '') !== $canonical[$role]['volatility']
                        || (string) ($controlRoot['protocol'] ?? '') !== 'explainable_control_root_v1'
                        || (string) ($controlRoot['family'] ?? '') !== (string) $agent->strategy_family
                        || (string) ($group['strategy_family'] ?? '') !== (string) $agent->strategy_family) {
                        continue;
                    }

                    $parameters = json_decode((string) ($model->parameters ?? '{}'), true);
                    if (! is_array($parameters)) $parameters = [];
                    ksort($parameters);
                    $seedHash = hash(
                        'sha256',
                        (string) $agent->strategy_family.'|'.json_encode($parameters, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES),
                    );
                    $seed = is_array($metadata['control_root_seed'] ?? null) ? $metadata['control_root_seed'] : [];
                    $seed = [
                        ...$seed,
                        'protocol' => 'control_root_specialist_inheritance_v1',
                        'status' => 'backfilled',
                        'eligible_for_specialist' => true,
                        'role' => $role,
                        'specialist_role' => $role,
                        'strategy_family' => (string) $agent->strategy_family,
                        'semantic_group_key' => (string) ($group['key'] ?? ''),
                        'control_root_id' => (string) ($controlRoot['root_id'] ?? ''),
                        'root_model_version_id' => (int) $model->id,
                        'root_agent_id' => (int) $agent->id,
                        'seed_parameter_hash' => $seedHash,
                        'inheritance_scope' => [
                            'source' => 'control_root_parameters_only',
                            'max_changed_parameters' => 1,
                            'semantic_group_frozen' => true,
                            'execution_contract_frozen' => true,
                            'promotion_evidence' => false,
                        ],
                        'promotion_evidence' => false,
                    ];
                    $metadata['control_root_seed'] = $seed;
                    if (is_array($metadata['semantic_lineage'] ?? null)) {
                        $metadata['semantic_lineage']['root_model_version_id'] = (int) $model->id;
                    }
                    if (is_array($metadata['progressive_inheritance'] ?? null)) {
                        $metadata['progressive_inheritance']['root_model_version_id'] = (int) $model->id;
                    }

                    DB::table('model_versions')->where('id', $model->id)->update([
                        'metadata' => json_encode($metadata, JSON_UNESCAPED_SLASHES),
                    ]);

                    DB::table('lab_agent_inheritance_audits')->insertOrIgnore([
                        'lab_agent_id' => $agent->id,
                        'source_model_version_id' => null,
                        'source_agent_id' => null,
                        'protocol' => 'control_root_specialist_inheritance_v1',
                        'transition' => 'control_root_seed_backfill',
                        'decision' => 'accepted',
                        'semantic_group_key' => (string) ($group['key'] ?? ''),
                        'seed_hash' => $seedHash,
                        'child_parameter_hash' => $seedHash,
                        'contract_hash' => hash('sha256', json_encode($seed, JSON_UNESCAPED_SLASHES)),
                        'metadata' => json_encode([
                            'source' => 'migration_backfill',
                            'root_model_version_id' => (int) $model->id,
                            'root_agent_id' => (int) $agent->id,
                            'promotion_evidence' => false,
                        ], JSON_UNESCAPED_SLASHES),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_agent_inheritance_audits');
    }
};
