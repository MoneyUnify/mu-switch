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
            // Where to POST the final (successful/failed) result, supplied by
            // the merchant on the request-to-pay call.
            $table->string('callback_url')->nullable()->after('provider_response');

            // Set once the callback has been dispatched, so we never notify twice.
            $table->timestamp('callback_notified_at')->nullable()->after('callback_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['callback_url', 'callback_notified_at']);
        });
    }
};
