<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * Applies SQL migration files from database/migrations in order.
 */
final class Migrator
{
    public static function pending(DB $db): array
    {
        self::ensureTable($db);
        $applied = $db->fetchPairs('SELECT migration, id FROM migrations');
        $files = glob(APP_ROOT . '/database/migrations/*.sql') ?: [];
        sort($files);
        $pending = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (!isset($applied[$name])) {
                $pending[] = $file;
            }
        }
        return $pending;
    }

    /** @return string[] names of applied migrations */
    public static function run(DB $db): array
    {
        $applied = [];
        foreach (self::pending($db) as $file) {
            $sql = (string) file_get_contents($file);
            $db->runScript($sql);
            $db->insert('migrations', ['migration' => basename($file), 'applied_at' => now()]);
            $applied[] = basename($file);
        }
        return $applied;
    }

    private static function ensureTable(DB $db): void
    {
        $db->exec('CREATE TABLE IF NOT EXISTS migrations (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            migration VARCHAR(190) NOT NULL,
            applied_at DATETIME NOT NULL,
            UNIQUE KEY uq_migration (migration)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
}
