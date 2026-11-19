<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Raised when an account's balance falls below its float rule's minimum.
 */
class FloatAlert extends Model
{
    protected $fillable = ['float_rule_id', 'balance_minor', 'minimum_minor', 'opened_at', 'resolved_at'];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(FloatRule::class, 'float_rule_id');
    }

    protected function casts(): array
    {
        return [
            'balance_minor' => 'integer',
            'minimum_minor' => 'integer',
            'opened_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}
