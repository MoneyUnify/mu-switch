<?php

namespace App\Support;

use App\Contracts\PaymentProviderInterface;
use App\Contracts\PayoutProviderInterface;

/**
 * The auto-discovered provider drivers (any class in
 * app/Http/Controllers/Providers implementing a driver contract) and what
 * each one can do.
 */
class DriverRegistry
{
    public const COLLECTIONS = 'collections';

    public const PAYOUTS = 'payouts';

    /**
     * Driver classes available in this environment. Sandbox-only drivers
     * (declaring SANDBOX_ONLY = true) are left out in production.
     *
     * @return list<class-string>
     */
    public static function classes(bool $includeSandboxOnly = true): array
    {
        $classes = [];

        foreach (glob(app_path('Http/Controllers/Providers/*.php')) ?: [] as $file) {
            $class = 'App\\Http\\Controllers\\Providers\\'.basename($file, '.php');

            if (class_exists($class) && self::capabilities($class) !== [] && self::isAvailable($class)
                && ($includeSandboxOnly || ! self::isSandboxOnly($class))) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * What a driver can do: collections, payouts, or both.
     *
     * @return list<string>
     */
    public static function capabilities(string $class): array
    {
        return array_values(array_filter([
            is_subclass_of($class, PaymentProviderInterface::class) ? self::COLLECTIONS : null,
            is_subclass_of($class, PayoutProviderInterface::class) ? self::PAYOUTS : null,
        ]));
    }

    public static function isSandboxOnly(string $class): bool
    {
        return defined($class.'::SANDBOX_ONLY') && $class::SANDBOX_ONLY === true;
    }

    /**
     * Sandbox-only drivers never run in production.
     */
    public static function isAvailable(string $class): bool
    {
        return ! (self::isSandboxOnly($class) && app()->isProduction());
    }
}
