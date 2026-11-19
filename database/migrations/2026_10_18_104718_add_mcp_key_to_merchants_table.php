<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A merchant's own access key for the merchant MCP server. Only its hash
     * is kept; the key is shown once, when it's issued.
     */
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->string('mcp_key_hash', 64)->nullable()->unique()->after('is_active');
            $table->string('mcp_key_hint', 16)->nullable()->after('mcp_key_hash');
            $table->timestamp('mcp_key_issued_at')->nullable()->after('mcp_key_hint');
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropUnique(['mcp_key_hash']);
            $table->dropColumn(['mcp_key_hash', 'mcp_key_hint', 'mcp_key_issued_at']);
        });
    }
};
