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
        // One payment collected through PayPal and delivered to a mobile wallet,
        // with the FX locked at quote time.
        Schema::create('cross_border_transfers', function (Blueprint $table) {
            $table->id();
            // A string column, so looking up a malformed reference finds nothing
            // instead of failing on Postgres.
            $table->string('reference', 64)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key')->nullable();

            // Funding leg: what the sender pays through PayPal.
            $table->foreignId('funding_provider_id')->constrained('payment_providers')->restrictOnDelete();
            $table->foreignId('funding_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->char('source_currency', 3);
            $table->bigInteger('source_amount_minor');

            // Payout leg: what the recipient receives on mobile money.
            $table->foreignId('payout_provider_id')->nullable()->constrained('payment_providers')->nullOnDelete();
            $table->foreignId('payout_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->char('target_currency', 3);
            $table->bigInteger('target_amount_minor');
            $table->char('recipient_country', 2);
            $table->string('recipient_account', 50);
            $table->string('recipient_name')->nullable();
            $table->string('recipient_network', 50)->nullable();
            $table->string('narration', 100)->nullable();

            // The locked FX.
            $table->string('fx_quote_id')->nullable();
            $table->decimal('fx_base_rate', 18, 8);
            $table->decimal('fx_rate', 18, 8);
            $table->decimal('fx_margin_percent', 8, 4)->default(0);
            $table->bigInteger('fx_margin_minor')->default(0);

            // Refund of the funding leg when delivery fails.
            $table->foreignId('refund_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();

            $table->string('status', 24)->index();
            $table->text('failure_reason')->nullable();
            $table->timestamp('funded_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key']);
        });

        Schema::create('cross_border_transfer_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cross_border_transfer_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->text('note')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cross_border_transfer_events');
        Schema::dropIfExists('cross_border_transfers');
    }
};
