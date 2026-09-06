<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;

/**
 * Thin PDO wrapper. All queries use prepared statements.
 */
final class DB
{
    private static ?self $instance = null;
    private PDO $pdo;

    private function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = self::connect((array) App::config('db', []));
        }
        return self::$instance;
    }

    public static function connect(array $cfg): self
    {
        $host = $cfg['host'] ?? 'localhost';
        $port = (int) ($cfg['port'] ?? 3306);
        $name = $cfg['name'] ?? '';
        $charset = $cfg['charset'] ?? 'utf8mb4';
        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";
        if (!empty($cfg['socket'])) {
            $dsn = "mysql:unix_socket={$cfg['socket']};dbname={$name};charset={$charset}";
        }
        $pdo = new PDO($dsn, (string) ($cfg['user'] ?? ''), (string) ($cfg['pass'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset} COLLATE utf8mb4_unicode_ci, time_zone = '+00:00', sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'",
        ]);
        return new self($pdo);
    }

    public static function setInstance(self $db): void
    {
        self::$instance = $db;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $param = is_int($key) ? $key + 1 : (str_starts_with((string) $key, ':') ? $key : ':' . $key);
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_INT,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue($param, is_bool($value) ? (int) $value : $value, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    public function fetch(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function fetchColumn(string $sql, array $params = [], int $column = 0): mixed
    {
        $value = $this->query($sql, $params)->fetchColumn($column);
        return $value === false ? null : $value;
    }

    public function fetchPairs(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $placeholders = array_map(static fn($c) => ':' . $c, $columns);
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`, `', $columns) . '`) VALUES (' . implode(', ', $placeholders) . ')';
        $this->query($sql, $data);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(string $table, array $data, string $where, array $params = []): int
    {
        $sets = [];
        $bind = [];
        foreach ($data as $column => $value) {
            $sets[] = '`' . $column . '` = :set_' . $column;
            $bind['set_' . $column] = $value;
        }
        foreach ($params as $k => $v) {
            $bind[$k] = $v;
        }
        $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $sets) . ' WHERE ' . $where;
        return $this->query($sql, $bind)->rowCount();
    }

    public function delete(string $table, string $where, array $params = []): int
    {
        return $this->query('DELETE FROM `' . $table . '` WHERE ' . $where, $params)->rowCount();
    }

    public function exists(string $table, string $where, array $params = []): bool
    {
        return (bool) $this->fetchColumn('SELECT 1 FROM `' . $table . '` WHERE ' . $where . ' LIMIT 1', $params);
    }

    public function count(string $table, string $where = '1=1', array $params = []): int
    {
        return (int) $this->fetchColumn('SELECT COUNT(*) FROM `' . $table . '` WHERE ' . $where, $params);
    }

    public function exec(string $sql): int
    {
        return (int) $this->pdo->exec($sql);
    }

    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $callback($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    /** Run a multi-statement SQL script (schema/migrations). */
    public function runScript(string $sql): int
    {
        $count = 0;
        foreach (self::splitStatements($sql) as $statement) {
            if (trim($statement) === '') {
                continue;
            }
            $this->pdo->exec($statement);
            $count++;
        }
        return $count;
    }

    public static function splitStatements(string $sql): array
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $sql = preg_replace('#/\*.*?\*/#s', '', $sql) ?? $sql;
        $statements = [];
        $buffer = '';
        $inString = null;
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            if ($inString !== null) {
                $buffer .= $ch;
                if ($ch === '\\') {
                    $buffer .= $sql[++$i] ?? '';
                } elseif ($ch === $inString) {
                    $inString = null;
                }
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $inString = $ch;
                $buffer .= $ch;
                continue;
            }
            if ($ch === ';') {
                $statements[] = trim($buffer);
                $buffer = '';
                continue;
            }
            $buffer .= $ch;
        }
        if (trim($buffer) !== '') {
            $statements[] = trim($buffer);
        }
        return array_values(array_filter($statements, static fn($s) => $s !== ''));
    }
}
