<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Fee breakdown so every payment is auditable end-to-end.
            $table->decimal('collection_fee', 20, 4)->nullable()->after('fx_rate');
            $table->decimal('settlement_fee', 20, 4)->nullable()->after('collection_fee');
            $table->decimal('net_amount', 20, 4)->nullable()->after('settlement_fee');
            // Whether the collection fee is estimated from the schedule vs the
            // actual figure the provider returned.
            $table->boolean('fee_estimated')->default(true)->after('net_amount');
        });

        Schema::table('users', function (Blueprint $table) {
            // How the switch treats provider fee differences on fallback:
            // "transparent" (report the real net) or "cost_aware" (route cheapest first).
            $table->string('fee_policy')->default('transparent')->after('api_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['collection_fee', 'settlement_fee', 'net_amount', 'fee_estimated']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('fee_policy');
        });
    }
};
