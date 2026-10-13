<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\AuditLog;
use App\Services\AuthService;

/** Endpoints any logged-in user can call about themselves. */
final class ProfileController
{
	public function me(Request $request): Response
	{
		$u = Auth::user();
		return Response::json(['data' => [
			'id' => (int) $u['id'],
			'role' => $u['role'],
			'email' => $u['email'],
			'full_name' => $u['full_name'],
			'phone' => $u['phone'],
			'institution' => $u['institution_name'],
			'timezone' => $u['timezone'],
		]]);
	}

	/** Users may change their display name and phone; email/role are admin-managed. */
	public function update(Request $request): Response
	{
		$d = Validator::make($request->input(), [
			'full_name' => 'string|min:2|max:150',
			'phone' => 'nullable|string|max:30|regex:/^[0-9+()\- ]*$/',
		]);
		if ($d) {
			Database::get()->update('users', $d, 'id = :id', ['id' => Auth::id()]);
			AuditLog::record('profile.updated', 'user', Auth::id(), ['fields' => array_keys($d)]);
		}
		return Response::json(['ok' => true]);
	}

	public function changePassword(Request $request): Response
	{
		$d = Validator::make($request->input(), [
			'current_password' => 'required|string',
			'new_password' => 'required|string',
		]);
		(new AuthService())->changePassword(Auth::id(), $d['current_password'], $d['new_password']);
		return Response::json(['ok' => true]);
	}
}
