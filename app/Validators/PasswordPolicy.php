<?php

declare(strict_types=1);

namespace App\Validators;

use App\Core\HttpException;

final class PasswordPolicy
{
	public const MIN = 8;
	public const MAX = 72; // bcrypt ignores bytes beyond 72

	/** Returns an error message, or null if the password is acceptable. */
	public static function check(string $password, string $email = ''): ?string
	{
		if (strlen($password) < self::MIN) {
			return 'Password must be at least ' . self::MIN . ' characters.';
		}
		if (strlen($password) > self::MAX) {
			return 'Password must be at most ' . self::MAX . ' bytes.';
		}
		if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
			return 'Password must contain at least one letter and one number.';
		}
		$local = strtolower(strstr($email, '@', true) ?: '');
		if ($local !== '' && strlen($local) >= 4 && str_contains(strtolower($password), $local)) {
			return 'Password must not contain your email name.';
		}
		return null;
	}

	public static function enforce(string $password, string $email = '', string $field = 'password'): void
	{
		if ($error = self::check($password, $email)) {
			throw HttpException::validation([$field => $error]);
		}
	}

	public static function hash(string $password): string
	{
		return password_hash($password, PASSWORD_DEFAULT);
	}
}
