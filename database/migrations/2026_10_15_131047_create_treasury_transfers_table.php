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
        Schema::create('treasury_transfers', function (Blueprint $table) {
            $table->id();
            // A string column, so looking up a malformed reference finds nothing
            // instead of failing on Postgres.
            $table->string('reference', 64)->unique();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->foreignId('from_provider_id')->constrained('payment_providers')->cascadeOnDelete();
            $table->foreignId('to_provider_id')->constrained('payment_providers')->cascadeOnDelete();
            $table->char('currency', 3);
            // Whole minor units, like the ledger.
            $table->bigInteger('amount_minor');
            // Charged on top when sending (e.g. a bank fee).
            $table->bigInteger('fee_minor')->default(0);
            // What actually arrived; any shortfall is reconciled as a fee.
            $table->bigInteger('received_minor')->nullable();
            // requested, approved, in_transit, settled, failed, cancelled.
            $table->string('status');
            // How the money moves: manual for now (e.g. bank transfer).
            $table->string('method')->default('manual');
            $table->string('external_reference')->nullable();
            $table->text('note')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('treasury_transfer_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('treasury_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->text('note')->nullable();
            $table->timestamp('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('treasury_transfer_events');
        Schema::dropIfExists('treasury_transfers');
    }
};
