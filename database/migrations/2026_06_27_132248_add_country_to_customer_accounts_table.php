<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The original create_customer_accounts_table migration was amended to
     * include `country` after it had already run on some databases, leaving
     * them without the column. This guarded migration backfills it without
     * conflicting with fresh installs that already have it.
     */
    public function up(): void
    {
        if (Schema::hasColumn('customer_accounts', 'country')) {
            return;
        }

        Schema::table('customer_accounts', function (Blueprint $table) {
            $table->string('country', 2)->nullable()->after('number'); // ISO country code
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('customer_accounts', 'country')) {
            return;
        }

        Schema::table('customer_accounts', function (Blueprint $table) {
            $table->dropColumn('country');
        });
    }
};
