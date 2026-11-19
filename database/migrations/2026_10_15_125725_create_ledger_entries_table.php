<?php

use App\Models\PaymentProvider;
use App\Models\User;
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
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(PaymentProvider::class)->constrained()->cascadeOnDelete();
            // provider (the merchant's account at the provider), counterparty or fees.
            $table->string('account');
            // collection, payout, fee, transfer_out, transfer_in, adjustment, opening_balance.
            $table->string('type');
            $table->char('currency', 3);
            // Signed whole minor units (cents, ngwee), so sums are exact.
            $table->bigInteger('amount_minor');
            // True for fees taken from a published schedule rather than the provider's report.
            $table->boolean('estimated')->default(false);
            $table->nullableMorphs('source');
            $table->timestamp('occurred_at');
            $table->json('meta')->nullable();
            $table->timestamps();

            // One entry per event, side and source, so re-syncing never duplicates.
            $table->unique(['source_type', 'source_id', 'type', 'account'], 'ledger_entries_source_unique');
            $table->index(['user_id', 'payment_provider_id', 'currency', 'account'], 'ledger_entries_balance_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
