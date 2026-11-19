<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class PaymentProvider extends Model
{
    protected $fillable = ['name', 'user_id', 'config', 'class', 'logo_url', 'is_active'];

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function providerLogs()
    {
        return $this->hasMany(ProviderLog::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The saved logo, else the driver's default logo.
     */
    protected function logoUrl(): Attribute
    {
        return Attribute::get(function (?string $value): ?string {
            if ($value) {
                return $value;
            }

            $driver = $this->attributes['class'] ?? null;

            return $driver && defined($driver.'::DEFAULT_LOGO') ? $driver::DEFAULT_LOGO : null;
        });
    }

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
