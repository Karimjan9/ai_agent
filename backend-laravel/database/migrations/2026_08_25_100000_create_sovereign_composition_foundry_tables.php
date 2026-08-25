<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_priors', function (Blueprint $table): void {
            $table->id();
            $table->string('prior_id', 96)->unique();
            $table->string('source_class', 96);
            $table->string('symbol_scope', 16)->default('XAUUSD');
            $table->string('knowledge_tier', 4);
            $table->json('hypothesis');
            $table->json('temporal_roles')->nullable();
            $table->json('risk_contract')->nullable();
            $table->json('management_contract')->nullable();
            $table->json('falsification_contract');
            $table->json('authority_contract');
            $table->decimal('external_score', 8, 4)->default(0);
            $table->decimal('influence_weight', 8, 4)->default(0);
            $table->string('status', 32)->default('research_only');
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
            $table->index(['symbol_scope', 'status', 'knowledge_tier'], 'knowledge_prior_scope_idx');
        });

        Schema::create('composition_settlements', function (Blueprint $table): void {
            $table->id();
            $table->string('settlement_key', 128)->unique();
            $table->foreignId('lab_agent_id')->nullable()->constrained('lab_agents')->nullOnDelete();
            $table->foreignId('model_version_id')->nullable()->constrained('model_versions')->nullOnDelete();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('composition_id', 96)->nullable();
            $table->string('status', 48);
            $table->json('components');
            $table->json('evidence');
            $table->dateTime('settled_at', 6);
            $table->timestamps();
            $table->index(['symbol', 'timeframe', 'composition_id', 'status'], 'composition_settlement_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('composition_settlements');
        Schema::dropIfExists('knowledge_priors');
    }
};
