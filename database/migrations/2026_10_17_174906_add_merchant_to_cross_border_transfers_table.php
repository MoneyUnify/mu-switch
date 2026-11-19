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
            // Set when the transfer settles a merchant's payment.
            $table->foreignId('merchant_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            // The aggregator's commission, in the payout currency, kept from the settlement.
            $table->bigInteger('commission_minor')->default(0)->after('target_amount_minor');
            $table->string('payer_email')->nullable()->after('recipient_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cross_border_transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('merchant_id');
            $table->dropColumn(['commission_minor', 'payer_email']);
        });
    }
};
