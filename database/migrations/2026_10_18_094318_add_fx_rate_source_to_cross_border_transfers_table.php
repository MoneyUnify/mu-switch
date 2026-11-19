<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where each transfer's locked rate came from: PayPal's FX or MoneyUnify's table.
     */
    public function up(): void
    {
        Schema::table('cross_border_transfers', function (Blueprint $table) {
            $table->string('fx_rate_source', 20)->nullable()->after('fx_margin_minor');
        });
    }

    public function down(): void
    {
        Schema::table('cross_border_transfers', function (Blueprint $table) {
            $table->dropColumn('fx_rate_source');
        });
    }
};
