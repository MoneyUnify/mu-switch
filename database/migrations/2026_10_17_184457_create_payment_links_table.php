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
        // A merchant's shareable pay links, each with its own currencies and price.
        Schema::create('payment_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 16)->unique();
            $table->string('label', 120)->nullable();
            // The currencies the customer may pay in; null means any the account collects.
            $table->json('pay_currencies')->nullable();
            $table->char('settle_currency', 3);
            // A fixed price in the settlement currency; null lets the customer enter it.
            $table->bigInteger('amount_minor')->nullable();
            $table->string('reference', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            // Links that took payments are archived, never erased, to keep their history.
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_links');
    }
};
