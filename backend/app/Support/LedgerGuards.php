<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Database triggers that make the money records append-only for everyone, the SQL console included:
 * wallet_ledger, admin_actions and loyalty_entries refuse UPDATE and DELETE.
 */
final class LedgerGuards
{
    private const TABLES = ['wallet_ledger', 'admin_actions', 'loyalty_entries'];

    public static function install(): void
    {
        $driver = DB::getDriverName();
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue; // a later migration installs it once the table exists
            }
            foreach (['UPDATE', 'DELETE'] as $op) {
                $name = "{$table}_no_".strtolower($op);
                DB::unprepared("DROP TRIGGER IF EXISTS {$name}");
                if ($driver === 'sqlite') {
                    DB::unprepared("CREATE TRIGGER {$name} BEFORE {$op} ON {$table} BEGIN SELECT RAISE(ABORT, '{$table} is append-only'); END");
                } elseif ($driver === 'mysql' || $driver === 'mariadb') {
                    DB::unprepared("CREATE TRIGGER {$name} BEFORE {$op} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table} is append-only'");
                }
            }
        }
    }

    public static function uninstall(): void
    {
        foreach (self::TABLES as $table) {
            foreach (['update', 'delete'] as $op) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_{$op}");
            }
        }
    }
}
