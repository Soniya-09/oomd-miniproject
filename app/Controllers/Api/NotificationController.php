<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

/** The logged-in user's own notifications only. */
final class NotificationController
{
	public function index(Request $request): Response
	{
		$f = Validator::make($request->query, ['unread' => 'in:0,1', 'type' => 'string|max:40|regex:/^[a-z_]+$/']);
		$where = ['user_id = :u'];
		$params = ['u' => Auth::id()];
		if (($f['unread'] ?? '') === '1') {
			$where[] = 'read_at IS NULL';
		}
		if (!empty($f['type'])) {
			$where[] = 'type = :t';
			$params['t'] = $f['type'];
		}
		$result = (new Paginator($request, 20))->run(
			'SELECT id, type, title, message, related_type, related_id, action_url, read_at, created_at',
			'FROM notifications WHERE ' . implode(' AND ', $where),
			$params,
			'id DESC',
		);
		foreach ($result['data'] as &$n) {
			$n['read_at'] = iso_utc($n['read_at']);
			$n['created_at'] = iso_utc($n['created_at']);
		}
		return Response::json($result);
	}

	public function unreadCount(Request $request): Response
	{
		$n = (int) Database::get()->value('SELECT COUNT(*) FROM notifications WHERE user_id = :u AND read_at IS NULL', ['u' => Auth::id()]);
		return Response::json(['count' => $n]);
	}

	public function markRead(Request $request): Response
	{
		Database::get()->run(
			'UPDATE notifications SET read_at = :now WHERE id = :id AND user_id = :u AND read_at IS NULL',
			['now' => now_utc(), 'id' => $request->intParam('id'), 'u' => Auth::id()],
		);
		return Response::json(['ok' => true]);
	}

	public function markAllRead(Request $request): Response
	{
		$n = Database::get()->run(
			'UPDATE notifications SET read_at = :now WHERE user_id = :u AND read_at IS NULL',
			['now' => now_utc(), 'u' => Auth::id()],
		)->rowCount();
		return Response::json(['ok' => true, 'updated' => $n]);
	}
}
