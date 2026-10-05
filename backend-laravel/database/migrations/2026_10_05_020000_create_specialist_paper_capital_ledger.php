<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paper_capital_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('account_key')->unique();
            $table->bigInteger('initial_balance_cents');
            $table->bigInteger('balance_cents');
            $table->bigInteger('reserved_cents')->default(0);
            $table->bigInteger('allocated_cents')->default(0);
            $table->bigInteger('reserved_risk_cents')->default(0);
            $table->bigInteger('allocated_risk_cents')->default(0);
            $table->bigInteger('gross_exposure_cents')->default(0);
            $table->timestamps();
        });
        Schema::create('paper_capital_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('paper_capital_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('paper_signal_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('paper_order_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('intent_key', 64)->unique();
            $table->string('owner_id');
            $table->string('council_id');
            $table->string('council_version', 100);
            $table->string('management_version');
            $table->string('symbol', 16);
            $table->string('direction', 8);
            $table->bigInteger('requested_units_micros');
            $table->bigInteger('filled_units_micros')->default(0);
            $table->bigInteger('remaining_units_micros')->default(0);
            $table->bigInteger('capital_cents');
            $table->bigInteger('risk_cents');
            $table->bigInteger('pending_capital_cents');
            $table->bigInteger('pending_risk_cents');
            $table->bigInteger('allocated_capital_cents')->default(0);
            $table->bigInteger('allocated_risk_cents')->default(0);
            $table->string('status')->default('reserved');
            $table->json('binding');
            $table->timestamps();
            $table->index(['paper_capital_account_id', 'owner_id', 'status'], 'paper_reservation_owner_status');
        });
        Schema::create('paper_cost_ledger', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('paper_capital_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('paper_capital_reservation_id')->constrained()->restrictOnDelete();
            $table->foreignId('paper_order_id')->constrained()->restrictOnDelete();
            $table->string('ledger_key', 64)->unique();
            $table->string('event_type');
            $table->bigInteger('units_micros')->default(0);
            $table->bigInteger('price_micros')->default(0);
            $table->bigInteger('cost_cents')->default(0);
            $table->bigInteger('realized_cents')->default(0);
            $table->json('payload')->nullable();
            $table->timestamps();
        });
        Schema::table('paper_orders', function (Blueprint $table): void {
            $table->foreignId('paper_capital_reservation_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('owner_id')->nullable()->index();
            $table->string('council_id')->nullable();
            $table->string('council_version', 100)->nullable();
            $table->string('management_version')->nullable();
            $table->bigInteger('filled_units_micros')->nullable();
            $table->bigInteger('remaining_units_micros')->nullable();
        });
        Schema::table('paper_fills', function (Blueprint $table): void {
            $table->string('fill_key', 64)->nullable()->unique();
            $table->bigInteger('units_micros')->nullable();
            $table->bigInteger('cost_cents')->nullable();
            $table->bigInteger('realized_cents')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('paper_fills', function (Blueprint $table): void {
            $table->dropUnique(['fill_key']);
            $table->dropColumn(['fill_key', 'units_micros', 'cost_cents', 'realized_cents']);
        });
        Schema::table('paper_orders', function (Blueprint $table): void {
            $table->dropForeign(['paper_capital_reservation_id']);
            $table->dropUnique(['paper_capital_reservation_id']);
            $table->dropIndex(['owner_id']);
            $table->dropColumn(['paper_capital_reservation_id', 'owner_id', 'council_id', 'council_version', 'management_version', 'filled_units_micros', 'remaining_units_micros']);
        });
        Schema::dropIfExists('paper_cost_ledger');
        Schema::dropIfExists('paper_capital_reservations');
        Schema::dropIfExists('paper_capital_accounts');
    }
};
