<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\PaymentProvider;
use App\Enums\TransactionStatus;
use App\Models\Customer;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('transaction_id')->unique();
            $table->foreignIdFor(PaymentProvider::class)->constrained()->onDelete('cascade');
            $table->string('provider_transaction_id', 255);
            $table->unique(['payment_provider_id', 'provider_transaction_id']);
            $table->foreignIdFor(Customer::class)->constrained()->onDelete('cascade');
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
