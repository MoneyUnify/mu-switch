<?php

namespace App\Models;

use App\Enums\AiProvider;
use Illuminate\Database\Eloquent\Model;

/**
 * One AI provider in an account's failover chain. A blank model or key falls
 * back to the provider's default model and .env key.
 */
class AiCredential extends Model
{
    protected $fillable = ['provider', 'model', 'api_key', 'priority'];

    protected $hidden = ['api_key'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'provider' => AiProvider::class,
            'api_key' => 'encrypted',
            'priority' => 'integer',
        ];
    }
}
