<?php

namespace App\DTOs;

class ProviderConfig
{
    public function __construct(
        public string $apiKey,
        public ?string $publicKey,
        public ?string $secretKey = null, // Optional if some providers don't use it
        public array $meta = [] // For provider-specific edge cases
    ) {}
}
