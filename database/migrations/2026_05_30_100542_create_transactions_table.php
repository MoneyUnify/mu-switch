<?php

use App\Enums\TransactionStatus;
use App\Models\Customer;
use App\Models\PaymentProvider;
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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            // A string, not a uuid column: several drivers use their own reference
            // format (e.g. MU-20260701-ABC123), which Postgres rejects as a uuid.
            $table->string('transaction_id', 100)->unique();
            // The foreign keys to payment_providers and customers are added once
            // those tables exist (in the payment_providers migration): Postgres
            // and MySQL can't reference a table that hasn't been created yet.
            $table->foreignIdFor(PaymentProvider::class);
            $table->string('provider_transaction_id', 255);
            $table->unique(['payment_provider_id', 'provider_transaction_id']);
            $table->foreignIdFor(Customer::class);
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3);
            $table->enum('status', array_column(TransactionStatus::cases(), 'value'))->default(TransactionStatus::DRAFT->value);
            $table->json('provider_response')->nullable();
            $table->enum('direction', ['credit', 'debit'])->default('credit')->comment('Accounting: credit for incoming payments, debit for refunds or payouts');
            $table->boolean('is_fx')->default(false)->comment('Indicates if this transaction involves foreign exchange');
            $table->decimal('fx_rate', 18, 8)->nullable()->comment('The FX rate applied if is_fx is true');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
