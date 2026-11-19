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
        Schema::table('treasury_transfers', function (Blueprint $table) {
            $table->char('to_currency', 3)->nullable()->after('currency');
            $table->decimal('fx_rate', 18, 8)->nullable()->after('to_currency');
            $table->bigInteger('target_amount_minor')->nullable()->after('fx_rate');
            $table->string('fx_quote_id', 64)->nullable()->after('target_amount_minor');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('treasury_transfers', function (Blueprint $table) {
            $table->dropColumn(['to_currency', 'fx_rate', 'target_amount_minor', 'fx_quote_id']);
        });
    }
};
