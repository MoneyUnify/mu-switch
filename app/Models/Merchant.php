<?php

namespace App\Models;

use Database\Factories\MerchantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A business an aggregator collects for: its customers pay with PayPal, and it
 * is settled to its own mobile-money wallet in its own currency.
 */
class Merchant extends Model
{
    /** @use HasFactory<MerchantFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'name', 'slug', 'email', 'country', 'currency',
        'wallet_number', 'wallet_name', 'network', 'commission_percent', 'is_active',
    ];

    protected $hidden = ['mcp_key_hash'];

    /** Merchant MCP keys start with this, so they're easy to recognise (and to scan for if leaked). */
    public const MCP_KEY_PREFIX = 'mu_mk_';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The payments collected for this merchant, each settled to its wallet.
     */
    public function settlements(): HasMany
    {
        return $this->hasMany(CrossBorderTransfer::class);
    }

    /**
     * The merchant's saved pay links, newest first.
     */
    public function paymentLinks(): HasMany
    {
        return $this->hasMany(PaymentLink::class)->latest('id');
    }

    /**
     * A unique, readable slug for the merchant's public pay link.
     */
    public static function uniqueSlug(string $name): string
    {
        $base = Str::limit(Str::slug($name) ?: 'merchant', 48, '');

        do {
            $slug = $base.'-'.Str::lower(Str::random(5));
        } while (self::query()->where('slug', $slug)->exists());

        return $slug;
    }

    /**
     * Issue a new MCP access key, replacing any earlier one. Only its hash is
     * stored, so the key is returned once and can't be shown again.
     */
    public function issueMcpKey(): string
    {
        $key = self::MCP_KEY_PREFIX.Str::random(40);

        $this->forceFill([
            'mcp_key_hash' => hash('sha256', $key),
            'mcp_key_hint' => substr($key, 0, 10).'…'.substr($key, -4),
            'mcp_key_issued_at' => now(),
        ])->save();

        return $key;
    }

    public function revokeMcpKey(): void
    {
        $this->forceFill(['mcp_key_hash' => null, 'mcp_key_hint' => null, 'mcp_key_issued_at' => null])->save();
    }

    /**
     * The merchant an MCP access key belongs to.
     */
    public static function findByMcpKey(?string $key): ?self
    {
        if (! is_string($key) || ! str_starts_with($key, self::MCP_KEY_PREFIX)) {
            return null;
        }

        return self::query()->where('mcp_key_hash', hash('sha256', $key))->first();
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected function casts(): array
    {
        return [
            'commission_percent' => 'decimal:2',
            'is_active' => 'boolean',
            'mcp_key_issued_at' => 'datetime',
        ];
    }
}
