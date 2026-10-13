<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;
use Throwable;

/** Thin PDO wrapper. Always prepared statements; connection runs in UTC. */
final class Database
{
	private static ?Database $instance = null;
	private PDO $pdo;

	private function __construct(array $c)
	{
		$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['name']);
		$this->pdo = new PDO($dsn, $c['user'], $c['pass'], [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_EMULATE_PREPARES => false,
			PDO::ATTR_STRINGIFY_FETCHES => false,
		]);
		$this->pdo->exec("SET time_zone = '+00:00', sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
	}

	public static function get(): self
	{
		return self::$instance ??= new self(config('db'));
	}

	/** Used by tests to point at a different database. */
	public static function reset(?array $config = null): void
	{
		self::$instance = $config ? new self($config) : null;
	}

	public function pdo(): PDO
	{
		return $this->pdo;
	}

	public function run(string $sql, array $params = []): PDOStatement
	{
		$stmt = $this->pdo->prepare($sql);
		foreach ($params as $key => $value) {
			$name = is_int($key) ? $key + 1 : ':' . ltrim($key, ':');
			// Shared param arrays (e.g. list + count queries) may carry names this statement does not use.
			if (!is_int($key) && !preg_match('/' . preg_quote($name, '/') . '(?![A-Za-z0-9_])/', $sql)) {
				continue;
			}
			$type = match (true) {
				is_int($value) => PDO::PARAM_INT,
				is_bool($value) => PDO::PARAM_INT,
				$value === null => PDO::PARAM_NULL,
				default => PDO::PARAM_STR,
			};
			$stmt->bindValue($name, is_bool($value) ? (int) $value : $value, $type);
		}
		$stmt->execute();
		return $stmt;
	}

	public function one(string $sql, array $params = []): ?array
	{
		$row = $this->run($sql, $params)->fetch();
		return $row === false ? null : $row;
	}

	public function all(string $sql, array $params = []): array
	{
		return $this->run($sql, $params)->fetchAll();
	}

	public function value(string $sql, array $params = []): mixed
	{
		$v = $this->run($sql, $params)->fetchColumn();
		return $v === false ? null : $v;
	}

	public function insert(string $table, array $data): int
	{
		$cols = array_keys($data);
		$sql = sprintf(
			'INSERT INTO %s (%s) VALUES (%s)',
			$table,
			implode(', ', array_map(fn($c) => "`$c`", $cols)),
			implode(', ', array_map(fn($c) => ":$c", $cols)),
		);
		$this->run($sql, $data);
		return (int) $this->pdo->lastInsertId();
	}

	public function update(string $table, array $data, string $where, array $whereParams = []): int
	{
		$set = [];
		$params = [];
		foreach ($data as $col => $value) {
			$set[] = "`$col` = :set_$col";
			$params["set_$col"] = $value;
		}
		$sql = sprintf('UPDATE %s SET %s WHERE %s', $table, implode(', ', $set), $where);
		return $this->run($sql, $params + $whereParams)->rowCount();
	}

	private int $savepoint = 0;

	/** Run $fn inside a transaction; nested calls use a savepoint so they can roll back on their own. */
	public function transaction(callable $fn): mixed
	{
		if ($this->pdo->inTransaction()) {
			$name = 'sp' . (++$this->savepoint);
			$this->pdo->exec("SAVEPOINT $name");
			try {
				$result = $fn($this);
				$this->pdo->exec("RELEASE SAVEPOINT $name");
				return $result;
			} catch (Throwable $e) {
				$this->pdo->exec("ROLLBACK TO SAVEPOINT $name");
				throw $e;
			}
		}
		$this->pdo->beginTransaction();
		try {
			$result = $fn($this);
			$this->pdo->commit();
			return $result;
		} catch (Throwable $e) {
			$this->pdo->rollBack();
			throw $e;
		}
	}

	/** Build "IN (:p0, :p1)" placeholders for a list of ints. */
	public static function inList(array $values, string $prefix = 'p'): array
	{
		$values = array_values(array_unique(array_map('intval', $values)));
		if (!$values) {
			return ['(NULL)', []];
		}
		$params = [];
		foreach ($values as $i => $v) {
			$params[$prefix . $i] = $v;
		}
		return ['(' . implode(', ', array_map(fn($k) => ":$k", array_keys($params))) . ')', $params];
	}
}
