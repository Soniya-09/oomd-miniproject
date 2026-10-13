<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\RateLimiter;
use App\Repositories\UserRepository;
use App\Validators\PasswordPolicy;

final class AuthService
{
	private const RESET_TTL = 3600;

	/** Returns the logged-in user row or throws 422 / 429. */
	public function attempt(string $email, string $password, string $ip): array
	{
		$email = mb_strtolower(trim($email));
		[$max, $window] = config('rate_limits.login');
		[$ipMax, $ipWindow] = config('rate_limits.login_ip');
		$key = "login|$email|$ip";
		$ipKey = "login_ip|$ip";

		if (RateLimiter::tooMany($key, $max) || RateLimiter::tooMany($ipKey, $ipMax)) {
			throw new HttpException(429, 'Too many login attempts. Please wait 15 minutes and try again.');
		}

		$user = (new UserRepository())->findByEmail($email);
		// Always run password_verify so response time does not reveal whether the email exists.
		static $dummy = null;
		$hash = $user['password_hash'] ?? ($dummy ??= password_hash(random_bytes(16), PASSWORD_DEFAULT));
		$ok = password_verify($password, $hash) && $user !== null;

		if (!$ok || (int) $user['is_active'] !== 1) {
			RateLimiter::hit($key, $max, $window);
			RateLimiter::hit($ipKey, $ipMax, $ipWindow);
			if ($ok) {
				AuditLog::record('login.blocked_inactive', 'user', (int) $user['id'], [], (int) $user['id'], (int) $user['institution_id']);
				throw HttpException::validation(['email' => 'This account has been deactivated. Contact your administrator.']);
			}
			throw HttpException::validation(['email' => 'Incorrect email or password.']);
		}

		RateLimiter::clear($key);
		if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
			Database::get()->update('users', ['password_hash' => PasswordPolicy::hash($password)], 'id = :id', ['id' => $user['id']]);
		}
		Database::get()->update('users', ['last_login_at' => now_utc()], 'id = :id', ['id' => $user['id']]);
		Auth::login($user);
		AuditLog::record('login', 'user', (int) $user['id'], [], (int) $user['id'], (int) $user['institution_id']);
		return $user;
	}

	/**
	 * Always behaves the same whether or not the email exists (no account enumeration).
	 * Returns the raw token when one was issued (tests use it).
	 */
	public function requestReset(string $email, string $ip): ?string
	{
		RateLimiter::enforce('password_reset', $ip);
		$user = (new UserRepository())->findByEmail($email);
		if (!$user || (int) $user['is_active'] !== 1) {
			return null;
		}
		$token = bin2hex(random_bytes(32));
		$db = Database::get();
		$db->transaction(function (Database $db) use ($user, $token) {
			// Only the newest link works.
			$db->run('UPDATE password_reset_tokens SET used_at = :now WHERE user_id = :u AND used_at IS NULL', ['now' => now_utc(), 'u' => $user['id']]);
			$db->insert('password_reset_tokens', [
				'user_id' => $user['id'],
				'token_hash' => hash('sha256', $token),
				'expires_at' => gmdate('Y-m-d H:i:s', time() + self::RESET_TTL),
			]);
		});
		$link = config('app.url') . '/reset-password?token=' . $token;
		Mailer::send(
			$user['email'],
			'Reset your EduFlow password',
			"Hello {$user['full_name']},\n\nUse this link within 1 hour to choose a new password:\n$link\n\nIf you did not ask for this, you can ignore this email.",
		);
		AuditLog::record('password.reset_requested', 'user', (int) $user['id'], [], (int) $user['id'], (int) $user['institution_id']);
		return $token;
	}

	public function findValidToken(string $token): ?array
	{
		if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
			return null;
		}
		return Database::get()->one(
			'SELECT t.id, t.user_id, u.email FROM password_reset_tokens t JOIN users u ON u.id = t.user_id
			 WHERE t.token_hash = :h AND t.used_at IS NULL AND t.expires_at > :now AND u.is_active = 1 AND u.deleted_at IS NULL',
			['h' => hash('sha256', $token), 'now' => now_utc()],
		);
	}

	public function resetPassword(string $token, string $password): void
	{
		$row = $this->findValidToken($token);
		if (!$row) {
			throw HttpException::validation(['token' => 'This reset link is invalid or has expired. Request a new one.']);
		}
		PasswordPolicy::enforce($password, $row['email']);
		Database::get()->transaction(function (Database $db) use ($row, $password) {
			$db->update('users', ['password_hash' => PasswordPolicy::hash($password)], 'id = :id', ['id' => $row['user_id']]);
			$db->update('password_reset_tokens', ['used_at' => now_utc()], 'id = :id', ['id' => $row['id']]);
		});
		AuditLog::record('password.reset', 'user', (int) $row['user_id'], [], (int) $row['user_id']);
	}

	public function changePassword(int $userId, string $current, string $new): void
	{
		$user = Database::get()->one('SELECT email, password_hash FROM users WHERE id = :id', ['id' => $userId]);
		if (!$user || !password_verify($current, $user['password_hash'])) {
			throw HttpException::validation(['current_password' => 'Current password is incorrect.']);
		}
		PasswordPolicy::enforce($new, $user['email'], 'new_password');
		Database::get()->update('users', ['password_hash' => PasswordPolicy::hash($new)], 'id = :id', ['id' => $userId]);
		AuditLog::record('password.changed', 'user', $userId);
	}
}
