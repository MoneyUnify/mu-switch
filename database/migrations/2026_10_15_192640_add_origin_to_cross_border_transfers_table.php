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
            // Where the transfer was started: the API, the dashboard, or the AI agent.
            $table->string('origin', 16)->default('api')->after('idempotency_key');
            // The agent conversation that started it, for audit.
            $table->string('conversation_id', 36)->nullable()->after('origin')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cross_border_transfers', function (Blueprint $table) {
            $table->dropColumn(['origin', 'conversation_id']);
        });
    }
};
