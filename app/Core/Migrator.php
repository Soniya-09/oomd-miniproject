<?php

declare(strict_types=1);

namespace App\Core;

/** Applies database/migrations/*.sql in filename order, once each. */
final class Migrator
{
	public function __construct(private readonly Database $db)
	{
	}

	/** @return string[] files applied in this run */
	public function migrate(): array
	{
		$this->db->pdo()->exec(
			'CREATE TABLE IF NOT EXISTS schema_migrations (
				filename VARCHAR(190) PRIMARY KEY,
				applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
			) ENGINE=InnoDB',
		);
		$done = array_column($this->db->all('SELECT filename FROM schema_migrations'), 'filename');
		$files = glob(BASE_PATH . '/database/migrations/*.sql');
		sort($files);
		$applied = [];
		foreach ($files as $file) {
			$name = basename($file);
			if (in_array($name, $done, true)) {
				continue;
			}
			// MySQL DDL auto-commits, so each statement runs on its own.
			foreach (self::statements((string) file_get_contents($file)) as $sql) {
				$this->db->pdo()->exec($sql);
			}
			$this->db->insert('schema_migrations', ['filename' => $name]);
			$applied[] = $name;
		}
		return $applied;
	}

	/** Drop every table (tests only). */
	public function wipe(): void
	{
		$pdo = $this->db->pdo();
		$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
		foreach ($pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $t) {
			$pdo->exec("DROP TABLE `$t`");
		}
		$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
	}

	/** Split on semicolons at line ends, ignoring "--" comment lines. */
	public static function statements(string $sql): array
	{
		$lines = array_filter(explode("\n", str_replace("\r\n", "\n", $sql)), fn($l) => !str_starts_with(ltrim($l), '--'));
		$parts = preg_split('/;\s*$/m', implode("\n", $lines));
		return array_values(array_filter(array_map('trim', $parts), fn($s) => $s !== ''));
	}
}
