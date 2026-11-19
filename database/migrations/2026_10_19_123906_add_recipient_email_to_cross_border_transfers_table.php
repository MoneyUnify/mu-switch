<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where to tell the recipient their money has arrived (optional).
     */
    public function up(): void
    {
        Schema::table('cross_border_transfers', function (Blueprint $table) {
            $table->string('recipient_email')->nullable()->after('recipient_name');
        });
    }

    public function down(): void
    {
        Schema::table('cross_border_transfers', function (Blueprint $table) {
            $table->dropColumn('recipient_email');
        });
    }
};
