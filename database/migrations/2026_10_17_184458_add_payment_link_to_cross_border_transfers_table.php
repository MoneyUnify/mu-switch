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
        Schema::table('cross_border_transfers', function (Blueprint $table) {
            // The pay link a merchant payment came through, if any.
            $table->foreignId('payment_link_id')->nullable()->after('merchant_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cross_border_transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_link_id');
        });
    }
};
