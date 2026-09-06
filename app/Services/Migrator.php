<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Logger;

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

    /**
     * Apply pending migrations automatically after a deployment. Cheap: only touches the database
     * when the set of migration files changed since the last successful run.
     */
    public static function autoRun(): void
    {
        $files = glob(APP_ROOT . '/database/migrations/*.sql') ?: [];
        $signature = md5(implode('|', array_map('basename', $files)));
        $marker = APP_ROOT . '/storage/cache/schema.marker';
        if (is_file($marker) && trim((string) @file_get_contents($marker)) === $signature) {
            return;
        }
        try {
            $applied = self::run(DB::instance());
            if ($applied) {
                Logger::info('Applied database updates: ' . implode(', ', $applied));
            }
            @file_put_contents($marker, $signature);
        } catch (\Throwable $e) {
            Logger::error('Automatic database update failed: ' . $e->getMessage());
        }
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
