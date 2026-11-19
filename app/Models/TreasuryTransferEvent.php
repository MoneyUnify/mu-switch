<?php

namespace App\Models;

use App\Enums\TransferStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One status change on a transfer: who, when and why.
 */
class TreasuryTransferEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['treasury_transfer_id', 'user_id', 'from_status', 'to_status', 'note', 'created_at'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'from_status' => TransferStatus::class,
            'to_status' => TransferStatus::class,
            'created_at' => 'datetime',
        ];
    }
}
