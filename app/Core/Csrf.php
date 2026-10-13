<?php

declare(strict_types=1);

namespace App\Core;

/** Per-session synchronizer token. Sent as a form field or X-CSRF-Token header. */
final class Csrf
{
	public static function token(): string
	{
		$token = Session::get('_csrf');
		if (!$token) {
			$token = bin2hex(random_bytes(32));
			Session::set('_csrf', $token);
		}
		return $token;
	}

	public static function rotate(): void
	{
		Session::set('_csrf', bin2hex(random_bytes(32)));
	}

	public static function valid(?string $given): bool
	{
		$expected = Session::get('_csrf');
		return is_string($given) && is_string($expected) && $given !== '' && hash_equals($expected, $given);
	}
}
