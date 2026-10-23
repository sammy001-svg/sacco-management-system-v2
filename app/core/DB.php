<?php
defined('SACCO') or exit;

/**
 * Thin PDO wrapper. All queries use positional placeholders.
 */
final class DB
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $c = $GLOBALS['config']['db'];
            $dsn = "mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset={$c['charset']}";
            self::$pdo = new PDO($dsn, $c['user'], $c['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
            self::$pdo->exec("SET time_zone = '" . date('P') . "'");
            if (!empty($c['sql_mode'])) {
                self::$pdo->prepare('SET SESSION sql_mode = ?')->execute([$c['sql_mode']]);
            }
        }
        return self::$pdo;
    }

    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute(array_values($params));
        return $stmt;
    }

    public static function row(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $v = self::query($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** @return array<int|string, mixed> first column => second column */
    public static function pairs(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            $table,
            implode('`,`', $cols),
            implode(',', array_fill(0, count($cols), '?'))
        );
        self::query($sql, array_values($data));
        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $params = []): int
    {
        $set = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($data)));
        return self::query("UPDATE `$table` SET $set WHERE $where", [...array_values($data), ...$params])->rowCount();
    }

    /**
     * Run $fn inside a transaction. Nested calls join the outer transaction.
     */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
