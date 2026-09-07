<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('research_knowledge_entries')) {
            Schema::create('research_knowledge_entries', function (Blueprint $table): void {
                $table->id();
                $table->string('knowledge_key', 160)->unique();
                $table->string('knowledge_type', 32);
                $table->string('subject_type', 96);
                $table->string('subject_key', 160);
                $table->string('symbol', 16)->nullable();
                $table->string('timeframe', 16)->nullable();
                $table->string('authority', 32)->default('research_only');
                $table->string('freshness', 32)->default('active');
                $table->string('status', 32)->default('recorded');
                $table->json('scope');
                $table->json('claim');
                $table->json('evidence');
                $table->json('dependencies')->nullable();
                $table->timestamp('recorded_at');
                $table->timestamps();
                $table->index(['symbol', 'timeframe', 'knowledge_type'], 'research_knowledge_scope_type_idx');
                $table->index(['subject_type', 'subject_key'], 'research_knowledge_subject_idx');
            });
        }

        if (! Schema::hasTable('research_skill_portfolio_entries')) {
            Schema::create('research_skill_portfolio_entries', function (Blueprint $table): void {
                $table->id();
                $table->string('portfolio_key', 160)->unique();
                $table->unsignedBigInteger('host_model_version_id')->nullable();
                $table->unsignedBigInteger('skill_cartridge_id')->nullable();
                $table->string('symbol', 16);
                $table->string('timeframe', 16);
                $table->string('status', 40)->default('paired_transplant_required');
                $table->json('contextual_trust');
                $table->json('outcome_vector');
                $table->json('self_knowledge');
                $table->json('evidence');
                $table->timestamps();
                $table->index(['host_model_version_id', 'status'], 'research_portfolio_host_status_idx');
                $table->index(['symbol', 'timeframe', 'status'], 'research_portfolio_scope_status_idx');
            });
        }

        if (! Schema::hasTable('research_instrument_programs')) {
            Schema::create('research_instrument_programs', function (Blueprint $table): void {
                $table->id();
                $table->string('program_key', 160)->unique();
                $table->string('symbol', 16);
                $table->string('timeframe', 16);
                $table->string('status', 40);
                $table->unsignedInteger('complexity');
                $table->string('ast_hash', 128);
                $table->json('ast');
                $table->json('compiled_contract');
                $table->json('evidence');
                $table->timestamps();
                $table->unique(['symbol', 'timeframe', 'ast_hash'], 'research_instrument_ast_scope_uq');
                $table->index(['symbol', 'timeframe', 'status'], 'research_instrument_scope_status_idx');
            });
        }

        if (! Schema::hasTable('research_emitter_credit_profiles')) {
            Schema::create('research_emitter_credit_profiles', function (Blueprint $table): void {
                $table->id();
                $table->string('profile_key', 160)->unique();
                $table->string('symbol', 16);
                $table->string('timeframe', 16);
                $table->string('emitter', 96);
                $table->string('scope_key', 160);
                $table->decimal('estimate', 14, 6)->default(0);
                $table->decimal('uncertainty', 14, 6)->default(1);
                $table->unsignedInteger('settled_cohorts')->default(0);
                $table->json('results');
                $table->json('evidence');
                $table->timestamp('assessed_at');
                $table->timestamps();
                $table->index(['symbol', 'timeframe', 'emitter'], 'research_emitter_scope_idx');
            });
        }

        if (! Schema::hasTable('research_compounding_benchmarks')) {
            Schema::create('research_compounding_benchmarks', function (Blueprint $table): void {
                $table->id();
                $table->string('benchmark_key', 160)->unique();
                $table->string('symbol', 16);
                $table->string('timeframe', 16);
                $table->string('status', 40);
                $table->json('sealed_contract');
                $table->json('memory_enabled_result')->nullable();
                $table->json('memory_blinded_result')->nullable();
                $table->json('assessment')->nullable();
                $table->timestamps();
                $table->index(['symbol', 'timeframe', 'status'], 'research_compounding_scope_status_idx');
            });
        }

        if (! Schema::hasTable('research_specialist_league_entries')) {
            Schema::create('research_specialist_league_entries', function (Blueprint $table): void {
                $table->id();
                $table->string('league_key', 160)->unique();
                $table->unsignedBigInteger('model_version_id')->nullable();
                $table->string('symbol', 16);
                $table->string('timeframe', 16);
                $table->string('role', 48);
                $table->string('status', 48);
                $table->string('specialty_key', 160);
                $table->json('challenge_contract');
                $table->json('evidence');
                $table->timestamps();
                $table->index(['symbol', 'timeframe', 'role', 'status'], 'research_league_scope_role_idx');
            });
        }

        if (! Schema::hasTable('research_challenge_archive')) {
            Schema::create('research_challenge_archive', function (Blueprint $table): void {
                $table->id();
                $table->string('challenge_key', 160)->unique();
                $table->string('symbol', 16);
                $table->string('timeframe', 16);
                $table->string('status', 48);
                $table->string('niche_key', 160);
                $table->json('prerequisites');
                $table->json('contract');
                $table->json('evidence')->nullable();
                $table->timestamps();
                $table->index(['symbol', 'timeframe', 'status'], 'research_challenge_scope_status_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('research_challenge_archive');
        Schema::dropIfExists('research_specialist_league_entries');
        Schema::dropIfExists('research_compounding_benchmarks');
        Schema::dropIfExists('research_emitter_credit_profiles');
        Schema::dropIfExists('research_instrument_programs');
        Schema::dropIfExists('research_skill_portfolio_entries');
        Schema::dropIfExists('research_knowledge_entries');
    }
};
