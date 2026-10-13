<?php

declare(strict_types=1);

namespace App\Core;

/** Minimal .env reader. Real environment variables win over the file. */
final class Env
{
	private static array $values = [];

	public static function load(string $file): void
	{
		if (!is_file($file)) {
			return;
		}
		foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
			$line = trim($line);
			if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
				continue;
			}
			[$key, $value] = array_map('trim', explode('=', $line, 2));
			if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
				$value = substr($value, 1, -1);
			}
			self::$values[$key] = $value;
		}
	}

	public static function set(string $key, string $value): void
	{
		self::$values[$key] = $value;
	}

	public static function get(string $key, mixed $default = null): mixed
	{
		$value = getenv($key);
		if ($value === false) {
			$value = self::$values[$key] ?? null;
		}
		if ($value === null) {
			return $default;
		}
		return match (strtolower((string) $value)) {
			'true' => true,
			'false' => false,
			'null' => null,
			default => $value,
		};
	}
}
