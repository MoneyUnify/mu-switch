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
            // Who proposed the transfer: an operator, or the treasury agent.
            $table->string('origin', 16)->default('operator')->after('method');
            $table->text('reasoning')->nullable()->after('note');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('treasury_transfers', function (Blueprint $table) {
            $table->dropColumn(['origin', 'reasoning']);
        });
    }
};
