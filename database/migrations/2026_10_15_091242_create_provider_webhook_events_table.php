<?php

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
        Schema::create('provider_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(PaymentProvider::class)->constrained()->cascadeOnDelete();
            $table->string('event_id');
            $table->string('event_type');
            $table->json('payload');
            // Set once handled, so redelivered events are acknowledged without reprocessing.
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['payment_provider_id', 'event_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('provider_webhook_events');
    }
};
