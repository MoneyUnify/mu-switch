<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\User;

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
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_providers');
    }
};
