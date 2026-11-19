<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the single per-account `ai_settings` row with an ordered list of AI
 * providers, so an account's agents can fail over between several keys.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->string('provider');
            // Blank means the provider's default model.
            $table->string('model')->nullable();
            // Encrypted at rest by the model cast; blank falls back to the provider's .env key.
            $table->text('api_key')->nullable();
            // Failover order: lowest is tried first.
            $table->unsignedInteger('priority')->default(1);
            $table->timestamps();

            $table->unique(['user_id', 'provider']);
            $table->index(['user_id', 'priority']);
        });

        if (Schema::hasTable('ai_settings')) {
            DB::table('ai_settings')->whereNotNull('provider')->orderBy('id')->each(function (object $setting): void {
                DB::table('ai_credentials')->insert([
                    'user_id' => $setting->user_id,
                    'provider' => $setting->provider,
                    'model' => $setting->model,
                    'api_key' => $setting->api_key,
                    'priority' => 1,
                    'created_at' => $setting->created_at,
                    'updated_at' => $setting->updated_at,
                ]);
            });

            Schema::drop('ai_settings');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('ai_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->unique()->constrained()->cascadeOnDelete();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->text('api_key')->nullable();
            $table->timestamps();
        });

        // Keep each account's first-choice provider.
        DB::table('ai_credentials')->orderBy('priority')->orderBy('id')->get()->unique('user_id')->each(function (object $credential): void {
            DB::table('ai_settings')->insert([
                'user_id' => $credential->user_id,
                'provider' => $credential->provider,
                'model' => $credential->model,
                'api_key' => $credential->api_key,
                'created_at' => $credential->created_at,
                'updated_at' => $credential->updated_at,
            ]);
        });

        Schema::dropIfExists('ai_credentials');
    }
};
