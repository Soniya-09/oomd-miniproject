<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\AuditLog;
use App\Services\AuthService;

/** Server-rendered login, logout and password-reset pages. */
final class AuthController
{
	public function showLogin(Request $request): Response
	{
		return View::page('auth.login', [
			'title' => 'Log in',
			'next' => $this->safeNext((string) ($request->query['next'] ?? '')),
			'error' => Session::flash('error'),
			'notice' => Session::flash('notice'),
		]);
	}

	public function login(Request $request): Response
	{
		$email = (string) ($request->post['email'] ?? '');
		$next = $this->safeNext((string) ($request->post['next'] ?? ''));
		try {
			$user = (new AuthService())->attempt($email, (string) ($request->post['password'] ?? ''), $request->ip());
		} catch (HttpException $e) {
			$message = $e->errors ? array_values($e->errors)[0] : $e->getMessage();
			return View::page('auth.login', ['title' => 'Log in', 'next' => $next, 'error' => $message, 'email' => $email], $e->status === 429 ? 429 : 200);
		}
		$home = Auth::homeFor($user['role']);
		// Only follow ?next= into the user's own area.
		return Response::redirect($next !== '' && str_starts_with($next, $home) ? $next : $home);
	}

	public function logout(Request $request): Response
	{
		if (Auth::check()) {
			AuditLog::record('logout', 'user', Auth::id());
		}
		Auth::logout();
		return Response::redirect('/login');
	}

	public function showForgot(Request $request): Response
	{
		return View::page('auth.forgot', ['title' => 'Forgot password']);
	}

	public function forgot(Request $request): Response
	{
		$email = trim((string) ($request->post['email'] ?? ''));
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
			return View::page('auth.forgot', ['title' => 'Forgot password', 'error' => 'Enter a valid email address.', 'email' => $email]);
		}
		try {
			(new AuthService())->requestReset($email, $request->ip());
		} catch (HttpException $e) {
			return View::page('auth.forgot', ['title' => 'Forgot password', 'error' => $e->getMessage(), 'email' => $email], $e->status);
		}
		Session::flash('notice', 'If an account exists for that email, a reset link has been sent. It expires in 1 hour.');
		return Response::redirect('/login');
	}

	public function showReset(Request $request): Response
	{
		$token = (string) ($request->query['token'] ?? '');
		$valid = (new AuthService())->findValidToken($token) !== null;
		return View::page('auth.reset', ['title' => 'Choose a new password', 'token' => $token, 'valid' => $valid]);
	}

	public function reset(Request $request): Response
	{
		$token = (string) ($request->post['token'] ?? '');
		$password = (string) ($request->post['password'] ?? '');
		if ($password !== (string) ($request->post['password_confirmation'] ?? '')) {
			return View::page('auth.reset', ['title' => 'Choose a new password', 'token' => $token, 'valid' => true, 'error' => 'The passwords do not match.']);
		}
		try {
			(new AuthService())->resetPassword($token, $password);
		} catch (HttpException $e) {
			$message = $e->errors ? array_values($e->errors)[0] : $e->getMessage();
			return View::page('auth.reset', ['title' => 'Choose a new password', 'token' => $token, 'valid' => !isset($e->errors['token']), 'error' => $message]);
		}
		Session::flash('notice', 'Your password has been changed. You can log in now.');
		return Response::redirect('/login');
	}

	private function safeNext(string $next): string
	{
		return preg_match('#^/[A-Za-z0-9/_-]*$#', $next) ? $next : '';
	}
}
