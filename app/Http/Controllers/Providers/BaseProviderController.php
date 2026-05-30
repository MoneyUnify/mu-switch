<?php

namespace App\Http\Controllers\Providers;

use App\Http\Controllers\Controller;
use App\DTOs\ProviderConfig;
use App\Contracts\PaymentProviderInterface;

abstract class BaseProviderController extends Controller implements PaymentProviderInterface
{
    // The protected property will be available in provider controllers that extend this base controller
    protected ProviderConfig $config;

    // This enforces that every child class must accept the ProviderConfig object
    public function __construct(ProviderConfig $config)
    {
        $this->config = $config;
    }
}
