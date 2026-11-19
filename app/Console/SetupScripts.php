<?php

namespace App\Console;

use Composer\Script\Event;

/**
 * Hooks for `composer setup`.
 */
class SetupScripts
{
    /**
     * Seed the demo account when setup was run with `--demo-data`
     * (`composer setup --demo-data`, or `composer setup -- --demo-data`).
     *
     * Composer accepts the flag but doesn't pass it to the script's commands,
     * so it's read from Composer's own arguments.
     *
     * @param  Event  $event
     */
    public static function demoData(object $event): void
    {
        $arguments = method_exists($event, 'getArguments') ? $event->getArguments() : [];

        if (! self::wantsDemoData([...$arguments, ...($_SERVER['argv'] ?? [])])) {
            return;
        }

        passthru(escapeshellarg(PHP_BINARY).' artisan demo:seed', $exitCode);

        if ($exitCode !== 0) {
            throw new \RuntimeException('Seeding the demo data failed.');
        }
    }

    /**
     * @param  list<string>  $arguments
     */
    public static function wantsDemoData(array $arguments): bool
    {
        return in_array('--demo-data', $arguments, true);
    }
}
