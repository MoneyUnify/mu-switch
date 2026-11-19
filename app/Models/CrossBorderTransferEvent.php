<?php

namespace App\Models;

use App\Enums\CrossBorderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step in a cross-border transfer's timeline.
 */
class CrossBorderTransferEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['cross_border_transfer_id', 'from_status', 'to_status', 'note', 'created_at'];

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(CrossBorderTransfer::class, 'cross_border_transfer_id');
    }

    protected function casts(): array
    {
        return [
            'from_status' => CrossBorderStatus::class,
            'to_status' => CrossBorderStatus::class,
            'created_at' => 'datetime',
        ];
    }
}
