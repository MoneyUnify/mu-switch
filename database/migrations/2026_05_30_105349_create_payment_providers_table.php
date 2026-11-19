<?php

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
        Schema::create('payment_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->foreignIdFor(User::class)->constrained()->onDelete('cascade'); // The user who added the provider
            $table->json('config')->nullable();
            $table->string('class'); // The class that implements the provider logic e.g App\PaymentProviders\StripeProvider
            $table->string('logo_url')->nullable(); // Optional logo URL for the provider
            $table->boolean('is_active')->default(true); // To enable/disable providers without deleting them
            $table->timestamps();
        });

        // The transactions table is created before payment_providers and
        // customers, so its foreign keys to both are added here.
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreign('payment_provider_id')->references('id')->on('payment_providers')->onDelete('cascade');
            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['payment_provider_id']);
            $table->dropForeign(['customer_id']);
        });

        Schema::dropIfExists('payment_providers');
    }
};
