<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Auth;
use App\Core\Database;
use App\Services\AuthService;
use Database\Seeders\DemoSeeder;

final class AuthTest extends TestCase
{
	private function loginForm(string $email, string $password): \App\Core\Response
	{
		$this->actingAs(null);
		$token = \App\Core\Csrf::token();
		return self::$kernel->handle(new \App\Core\Request('POST', '/login', [], ['email' => $email, 'password' => $password, '_csrf' => $token], [], ['REMOTE_ADDR' => '10.0.0.1']));
	}

	public function testLoginRedirectsByRole(): void
	{
		$r = $this->loginForm('admin@eduflow.test', DemoSeeder::PASSWORD);
		$this->assertStatus(302, $r);
		$this->assertSame('/admin', $r->header('Location'));

		$r = $this->loginForm('PRIYA.student@eduflow.test', DemoSeeder::PASSWORD);
		$this->assertSame('/student', $r->header('Location'), 'email is case-insensitive');

		$r = $this->loginForm('meera.teacher@eduflow.test', DemoSeeder::PASSWORD);
		$this->assertSame('/teacher', $r->header('Location'));
	}

	public function testWrongPasswordDoesNotLogIn(): void
	{
		$r = $this->loginForm('admin@eduflow.test', 'nope');
		$this->assertStatus(200, $r);
		$this->assertTrue(str_contains($r->body, 'Incorrect email or password'));
		$this->assertFalse(Auth::check());
	}

	public function testLoginIsRateLimited(): void
	{
		for ($i = 0; $i < 5; $i++) {
			$this->loginForm('admin@eduflow.test', 'wrong');
		}
		$r = $this->loginForm('admin@eduflow.test', DemoSeeder::PASSWORD);
		$this->assertStatus(429, $r, 'correct password still blocked while locked out');
	}

	public function testLoginWithoutCsrfTokenIsRejected(): void
	{
		$this->actingAs(null);
		$r = self::$kernel->handle(new \App\Core\Request('POST', '/login', [], ['email' => 'admin@eduflow.test', 'password' => DemoSeeder::PASSWORD], [], ['REMOTE_ADDR' => '10.0.0.2']));
		$this->assertStatus(302, $r);
		$this->assertFalse(Auth::check());
	}

	public function testApiWritesRequireCsrf(): void
	{
		$this->actingAs($this->ids['admin']);
		$r = $this->call('PATCH', '/api/me', ['full_name' => 'X Y'], [], false);
		$this->assertStatus(419, $r);
	}

	public function testUnauthenticatedAccess(): void
	{
		$this->actingAs(null);
		$this->assertStatus(401, $this->get('/api/me'));
		$this->assertStatus(401, $this->get('/api/admin/users'));
		$r = $this->get('/admin');
		$this->assertStatus(302, $r);
		$this->assertSame('/login?next=%2Fadmin', $r->header('Location'));
	}

	public function testDeactivatedUserLosesAccessImmediately(): void
	{
		$id = $this->ids['students']['priya'];
		$this->actingAs($id);
		$this->assertStatus(200, $this->get('/api/me'));
		Database::get()->update('users', ['is_active' => 0], 'id = :id', ['id' => $id]);
		$this->assertStatus(401, $this->get('/api/me'), 'existing session stops working');

		$r = $this->loginForm('priya.student@eduflow.test', DemoSeeder::PASSWORD);
		$this->assertTrue(str_contains($r->body, 'deactivated'));
	}

	public function testStudentCannotUseAdminApi(): void
	{
		$this->actingAs($this->ids['students']['priya']);
		$this->assertStatus(403, $this->get('/api/admin/users'));
		$this->assertStatus(403, $this->call('POST', '/api/admin/classes', ['name' => 'X', 'academic_session_id' => 1]));
		$this->actingAs($this->ids['teachers']['meera']);
		$this->assertStatus(403, $this->get('/api/admin/overview'));
		$this->assertStatus(403, $this->get('/admin/users'));
	}

	public function testPasswordResetFlow(): void
	{
		$svc = new AuthService();
		$this->assertSame(null, $svc->requestReset('nobody@eduflow.test', '10.0.0.3'), 'unknown email issues nothing');
		$token = $svc->requestReset('priya.student@eduflow.test', '10.0.0.3');
		$this->assertTrue(is_string($token) && strlen($token) === 64);
		$this->assertCount(1, \App\Services\Mailer::$sent);
		$this->assertTrue(str_contains(\App\Services\Mailer::$sent[0]['body'], $token));

		try {
			$svc->resetPassword($token, 'short');
			$this->assertTrue(false, 'weak password accepted');
		} catch (\App\Core\HttpException $e) {
			$this->assertSame(422, $e->status);
		}
		$svc->resetPassword($token, 'NewPassw0rd!');
		$hash = Database::get()->value('SELECT password_hash FROM users WHERE id = :id', ['id' => $this->ids['students']['priya']]);
		$this->assertTrue(password_verify('NewPassw0rd!', $hash));
		$this->assertSame(null, $svc->findValidToken($token), 'token is single-use');
	}

	public function testPasswordsAreHashed(): void
	{
		$hash = Database::get()->value('SELECT password_hash FROM users WHERE email = :e', ['e' => 'admin@eduflow.test']);
		$this->assertTrue(str_starts_with($hash, '$2y$') || str_starts_with($hash, '$argon2'));
		$this->assertFalse(str_contains($hash, DemoSeeder::PASSWORD));
	}

	public function testChangeOwnPassword(): void
	{
		$this->actingAs($this->ids['students']['arjun']);
		$r = $this->call('POST', '/api/me/password', ['current_password' => 'bad', 'new_password' => 'Another1pass']);
		$this->assertStatus(422, $r);
		$r = $this->call('POST', '/api/me/password', ['current_password' => DemoSeeder::PASSWORD, 'new_password' => 'Another1pass']);
		$this->assertStatus(200, $r);
	}
}
