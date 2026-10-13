<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Session authentication. The user row is re-read on every request, so a
 * deactivated or deleted account loses access immediately.
 */
final class Auth
{
	private static ?array $user = null;
	private static bool $loaded = false;

	public static function user(): ?array
	{
		if (!self::$loaded) {
			self::$loaded = true;
			$id = Session::get('user_id');
			if ($id) {
				$row = Database::get()->one(
					'SELECT u.id, u.institution_id, u.role, u.email, u.full_name, u.phone, u.is_active, i.timezone, i.name AS institution_name
					 FROM users u JOIN institutions i ON i.id = u.institution_id
					 WHERE u.id = :id AND u.deleted_at IS NULL',
					['id' => (int) $id],
				);
				if ($row && (int) $row['is_active'] === 1) {
					self::$user = $row;
				} else {
					Session::forget('user_id');
				}
			}
		}
		return self::$user;
	}

	public static function check(): bool
	{
		return self::user() !== null;
	}

	public static function id(): int
	{
		$u = self::user();
		if (!$u) {
			throw new HttpException(401);
		}
		return (int) $u['id'];
	}

	public static function role(): ?string
	{
		return self::user()['role'] ?? null;
	}

	public static function institutionId(): int
	{
		$u = self::user();
		if (!$u) {
			throw new HttpException(401);
		}
		return (int) $u['institution_id'];
	}

	public static function is(string ...$roles): bool
	{
		return in_array(self::role(), $roles, true);
	}

	public static function login(array $user): void
	{
		Session::regenerate();
		Session::set('user_id', (int) $user['id']);
		Csrf::rotate();
		self::$loaded = false;
		self::$user = null;
	}

	public static function logout(): void
	{
		Session::destroy();
		self::forgetCached();
	}

	/** Clear the per-request cache (tests switch users within one process). */
	public static function forgetCached(): void
	{
		self::$loaded = false;
		self::$user = null;
	}

	public static function homeFor(string $role): string
	{
		return match ($role) {
			'admin' => '/admin',
			'teacher' => '/teacher',
			default => '/student',
		};
	}
}
