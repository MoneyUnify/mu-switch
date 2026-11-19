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
        // An aggregator's merchants: businesses that are paid through PayPal and
        // settled to their own mobile-money wallet.
        Schema::create('merchants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug', 64)->unique();
            $table->string('email')->nullable();
            $table->char('country', 2);
            $table->char('currency', 3);
            $table->string('wallet_number', 50);
            $table->string('wallet_name')->nullable();
            $table->string('network', 50)->nullable();
            // The aggregator's commission on each payment, taken from the settlement.
            $table->decimal('commission_percent', 5, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchants');
    }
};
