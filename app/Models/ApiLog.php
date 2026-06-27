<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiLog extends Model
{
    protected $fillable = [
        'user_id',
        'method',
        'url',
        'route',
        'ip_address',
        'user_agent',
        'request_headers',
        'request_body',
        'response_status',
        'response_body',
        'duration_ms',
        'exception_class',
        'exception_message',
        'exception_trace',
    ];

    /**
     * The API consumer the request was authenticated as, if any.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether this request resulted in an error response or threw midway.
     */
    public function failed(): bool
    {
        return $this->exception_class !== null || ($this->response_status ?? 0) >= 400;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'request_headers' => 'array',
            'request_body' => 'array',
            'response_status' => 'integer',
            'duration_ms' => 'integer',
        ];
    }
}
