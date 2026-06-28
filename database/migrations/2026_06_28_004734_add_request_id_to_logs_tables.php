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
        // A correlation id shared by an inbound API request and every outgoing
        // gateway (MNO) call it spawned, so the two can be traced together.
        Schema::table('api_logs', function (Blueprint $table) {
            $table->uuid('request_id')->nullable()->after('id')->index();
        });

        Schema::table('provider_logs', function (Blueprint $table) {
            $table->uuid('request_id')->nullable()->after('user_id')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('api_logs', function (Blueprint $table) {
            $table->dropColumn('request_id');
        });

        Schema::table('provider_logs', function (Blueprint $table) {
            $table->dropColumn('request_id');
        });
    }
};
