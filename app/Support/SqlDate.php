<?php

namespace App\Support;

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

/**
 * SQL for grouping rows by day or hour, written for whichever database the
 * query runs on: SQLite locally, Postgres or MySQL when deployed.
 */
class SqlDate
{
    /**
     * An expression that formats `$column` as "2026-10-19" (day) or
     * "2026-10-19 14:00:00" (hour), for use as a chart bucket.
     *
     * @param  'day'|'hour'  $grain
     */
    public static function bucket(BuilderContract|EloquentBuilder $query, string $column = 'created_at', string $grain = 'day'): string
    {
        $hourly = $grain === 'hour';

        return match ($query->getConnection()->getDriverName()) {
            'pgsql' => $hourly ? "to_char({$column}, 'YYYY-MM-DD HH24:00:00')" : "to_char({$column}, 'YYYY-MM-DD')",
            'mysql', 'mariadb' => $hourly ? "date_format({$column}, '%Y-%m-%d %H:00:00')" : "date_format({$column}, '%Y-%m-%d')",
            default => $hourly ? "strftime('%Y-%m-%d %H:00:00', {$column})" : "strftime('%Y-%m-%d', {$column})",
        };
    }
}
