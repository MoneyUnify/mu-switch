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
        Schema::create('float_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(PaymentProvider::class)->constrained()->cascadeOnDelete();
            $table->char('currency', 3);
            // Whole minor units, like the ledger.
            $table->bigInteger('minimum_minor');
            $table->bigInteger('target_minor')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['payment_provider_id', 'currency']);
        });

        Schema::create('float_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('float_rule_id')->constrained()->cascadeOnDelete();
            // The balance and minimum when the alert opened.
            $table->bigInteger('balance_minor');
            $table->bigInteger('minimum_minor');
            $table->timestamp('opened_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['float_rule_id', 'resolved_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('float_alerts');
        Schema::dropIfExists('float_rules');
    }
};
