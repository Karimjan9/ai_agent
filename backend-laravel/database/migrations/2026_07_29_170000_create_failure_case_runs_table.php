<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration { public function up(): void { Schema::create('failure_case_runs', function (Blueprint $table): void { $table->id(); $table->foreignId('agent_failure_case_id')->constrained()->cascadeOnDelete(); $table->foreignId('model_market_performance_id')->nullable()->constrained('model_market_performance')->nullOnDelete(); $table->string('status',32); $table->decimal('score_penalty',8,3)->default(0); $table->json('evidence')->nullable(); $table->timestamp('evaluated_at'); $table->timestamps(); $table->unique(['agent_failure_case_id','model_market_performance_id'],'failure_case_performance_uq'); }); } public function down(): void { Schema::dropIfExists('failure_case_runs'); } };
