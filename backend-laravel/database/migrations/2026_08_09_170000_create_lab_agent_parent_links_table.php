<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_agent_parent_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lab_agent_id')->constrained('lab_agents')->cascadeOnDelete();
            $table->foreignId('parent_model_version_id')->nullable()->constrained('model_versions')->nullOnDelete();
            $table->string('relation_type', 32);
            // Empty string keeps the unique key deterministic on MySQL; a
            // nullable skill would allow duplicate NULL contribution rows.
            $table->string('contribution_key', 128)->default('');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(
                ['lab_agent_id', 'parent_model_version_id', 'relation_type', 'contribution_key'],
                'lab_agent_parent_links_unique_contribution',
            );
            $table->index(['parent_model_version_id', 'relation_type'], 'lab_parent_graph_lookup');
        });

        // Preserve the graph already present in the two compatibility
        // columns and in the old crossover metadata. This is a projection
        // repair only; no model/evidence row is deleted or reclassified.
        DB::table('lab_agents')->orderBy('id')->chunkById(250, function ($agents): void {
            foreach ($agents as $agent) {
                $metadata = DB::table('model_versions')
                    ->where('id', $agent->model_version_id)
                    ->value('metadata');
                if (is_string($metadata)) {
                    $metadata = json_decode($metadata, true) ?: [];
                }
                $metadata = is_array($metadata) ? $metadata : [];
                $sources = (array) ($metadata['skill_crossover_sources'] ?? []);

                $parentB = $agent->parent_b_model_version_id;
                if ($parentB === null && $agent->origin === 'robust_crossover') {
                    foreach (array_values(array_unique(array_map('intval', $sources))) as $candidate) {
                        if ($candidate > 0 && $candidate !== (int) $agent->parent_a_model_version_id) {
                            $parentB = $candidate;
                            break;
                        }
                    }
                    if ($parentB !== null) {
                        DB::table('lab_agents')->where('id', $agent->id)->update([
                            'parent_b_model_version_id' => $parentB,
                            'updated_at' => now(),
                        ]);
                    }
                }

                $links = [];
                foreach ([
                    'parent_a' => $agent->parent_a_model_version_id,
                    'parent_b' => $parentB,
                ] as $relation => $parentId) {
                    if (! $parentId) continue;
                    $links[] = [
                        'lab_agent_id' => $agent->id,
                        'parent_model_version_id' => $parentId,
                        'relation_type' => $relation,
                        'contribution_key' => $relation,
                        'metadata' => json_encode(['backfilled' => true, 'source' => 'compatibility_column']),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                foreach ($sources as $skill => $parentId) {
                    if (! is_numeric($parentId) || (int) $parentId <= 0) continue;
                    $links[] = [
                        'lab_agent_id' => $agent->id,
                        'parent_model_version_id' => (int) $parentId,
                        'relation_type' => 'skill_crossover',
                        'contribution_key' => (string) $skill,
                        'metadata' => json_encode(['backfilled' => true, 'source' => 'skill_crossover_sources', 'skill' => (string) $skill]),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                if ($links !== []) {
                    DB::table('lab_agent_parent_links')->insertOrIgnore($links);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_agent_parent_links');
    }
};
