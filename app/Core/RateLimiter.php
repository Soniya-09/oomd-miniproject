<?php

declare(strict_types=1);

namespace App\Core;

/** Fixed-window counters stored in MySQL so they work across PHP workers. */
final class RateLimiter
{
	/** Returns true if this hit is allowed. */
	public static function hit(string $key, int $max, int $windowSeconds): bool
	{
		$db = Database::get();
		$key = substr(hash('sha256', $key), 0, 64);
		return $db->transaction(function (Database $db) use ($key, $max, $windowSeconds) {
			$row = $db->one('SELECT hits, expires_at FROM rate_limits WHERE bucket_key = :k FOR UPDATE', ['k' => $key]);
			$now = now_utc();
			if (!$row || $row['expires_at'] <= $now) {
				$db->run(
					'REPLACE INTO rate_limits (bucket_key, hits, expires_at) VALUES (:k, 1, :e)',
					['k' => $key, 'e' => gmdate('Y-m-d H:i:s', time() + $windowSeconds)],
				);
				return true;
			}
			$db->run('UPDATE rate_limits SET hits = hits + 1 WHERE bucket_key = :k', ['k' => $key]);
			return (int) $row['hits'] + 1 <= $max;
		});
	}

	public static function tooMany(string $key, int $max): bool
	{
		$row = Database::get()->one(
			'SELECT hits FROM rate_limits WHERE bucket_key = :k AND expires_at > :now',
			['k' => substr(hash('sha256', $key), 0, 64), 'now' => now_utc()],
		);
		return $row !== null && (int) $row['hits'] >= $max;
	}

	public static function clear(string $key): void
	{
		Database::get()->run('DELETE FROM rate_limits WHERE bucket_key = :k', ['k' => substr(hash('sha256', $key), 0, 64)]);
	}

	/** Throw 429 when over the configured limit, e.g. enforce('upload', "user:5"). */
	public static function enforce(string $limitName, string $subject): void
	{
		[$max, $window] = config("rate_limits.$limitName");
		if (!self::hit("$limitName|$subject", $max, $window)) {
			throw new HttpException(429);
		}
	}
}
