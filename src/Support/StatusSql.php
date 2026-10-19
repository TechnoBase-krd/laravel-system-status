<?php

namespace Technobase\SystemStatus\Support;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * The few SQL expressions that differ between databases. PostgreSQL and SQLite share one
 * form; MySQL and MariaDB get their own.
 */
class StatusSql
{
    /**
     * Update clause for an upsert that adds the new values to the existing row
     * (`column = column + incoming`).
     *
     * @param  array<int, string>  $columns
     * @return array<string, Expression>
     */
    public static function incrementOnConflict(string $table, array $columns, ?ConnectionInterface $connection = null): array
    {
        $connection ??= DB::connection();
        $grammar = $connection->getQueryGrammar();
        $update = [];

        foreach ($columns as $column) {
            $incoming = match (true) {
                ! self::isMysql($connection) => 'excluded.'.$grammar->wrap($column),
                (bool) $connection->getConfig('use_upsert_alias') => $grammar->wrap('laravel_upsert_alias').'.'.$grammar->wrap($column),
                default => 'VALUES('.$grammar->wrap($column).')',
            };

            $update[$column] = DB::raw($grammar->wrap($table.'.'.$column).' + '.$incoming);
        }

        return $update;
    }

    /**
     * A timestamp column formatted as `YYYY-MM-DD HH:00`.
     */
    public static function hour(string $column, ?ConnectionInterface $connection = null): string
    {
        $connection ??= DB::connection();
        $wrapped = $connection->getQueryGrammar()->wrap($column);

        return match ($connection->getDriverName()) {
            'mysql', 'mariadb' => "DATE_FORMAT({$wrapped}, '%Y-%m-%d %H:00')",
            'sqlite' => "strftime('%Y-%m-%d %H:00', {$wrapped})",
            default => "to_char(date_trunc('hour', {$wrapped}), 'YYYY-MM-DD HH24:00')",
        };
    }

    private static function isMysql(ConnectionInterface $connection): bool
    {
        return in_array($connection->getDriverName(), ['mysql', 'mariadb'], true);
    }
}
