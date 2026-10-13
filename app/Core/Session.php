<?php

declare(strict_types=1);

namespace App\Core;

/** Server-side PHP session with hardened cookie settings. */
final class Session
{
	/** Tests replace the superglobal-backed store with an array. */
	private static ?array $testStore = null;

	public static function useArrayStore(): void
	{
		self::$testStore = [];
	}

	public static function start(): void
	{
		if (self::$testStore !== null || session_status() === PHP_SESSION_ACTIVE) {
			return;
		}
		$c = config('session');
		ini_set('session.use_strict_mode', '1');
		ini_set('session.use_only_cookies', '1');
		ini_set('session.gc_maxlifetime', (string) $c['lifetime']);
		session_name($c['name']);
		session_set_cookie_params([
			'lifetime' => 0,
			'path' => '/',
			'secure' => $c['secure'],
			'httponly' => true,
			'samesite' => 'Lax',
		]);
		session_start();

		// Idle timeout.
		$now = time();
		if (isset($_SESSION['_last_seen']) && $now - $_SESSION['_last_seen'] > $c['lifetime']) {
			$_SESSION = [];
			session_regenerate_id(true);
		}
		$_SESSION['_last_seen'] = $now;
	}

	public static function get(string $key, mixed $default = null): mixed
	{
		if (self::$testStore !== null) {
			return self::$testStore[$key] ?? $default;
		}
		return $_SESSION[$key] ?? $default;
	}

	public static function set(string $key, mixed $value): void
	{
		if (self::$testStore !== null) {
			self::$testStore[$key] = $value;
			return;
		}
		$_SESSION[$key] = $value;
	}

	public static function forget(string $key): void
	{
		if (self::$testStore !== null) {
			unset(self::$testStore[$key]);
			return;
		}
		unset($_SESSION[$key]);
	}

	/** New session id (after login) so a fixated id becomes useless. */
	public static function regenerate(): void
	{
		if (self::$testStore === null && session_status() === PHP_SESSION_ACTIVE) {
			session_regenerate_id(true);
		}
	}

	public static function destroy(): void
	{
		if (self::$testStore !== null) {
			self::$testStore = [];
			return;
		}
		$_SESSION = [];
		if (session_status() === PHP_SESSION_ACTIVE) {
			$p = session_get_cookie_params();
			setcookie(session_name(), '', [
				'expires' => time() - 3600,
				'path' => $p['path'],
				'secure' => $p['secure'],
				'httponly' => $p['httponly'],
				'samesite' => $p['samesite'],
			]);
			session_destroy();
		}
	}

	/** One-time message shown on the next page load. */
	public static function flash(string $key, ?string $value = null): ?string
	{
		if ($value !== null) {
			self::set("_flash_$key", $value);
			return null;
		}
		$v = self::get("_flash_$key");
		self::forget("_flash_$key");
		return $v;
	}
}
