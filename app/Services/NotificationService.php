<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * In-app notifications. A dedupe key makes delivery idempotent: the same event
 * (e.g. "assignment 12 published") never creates a second row for a user, so
 * edits and cron retries are safe.
 */
final class NotificationService
{
	/**
	 * @param int[] $userIds
	 * @return int number of new notifications created
	 */
	public static function send(
		array $userIds,
		string $type,
		string $title,
		string $message,
		?string $relatedType = null,
		?int $relatedId = null,
		?string $actionUrl = null,
		?string $dedupeKey = null,
	): int {
		$db = Database::get();
		$n = 0;
		foreach (array_unique(array_map('intval', $userIds)) as $uid) {
			$n += $db->run(
				'INSERT IGNORE INTO notifications (user_id, type, title, message, related_type, related_id, action_url, dedupe_key)
				 VALUES (:u, :type, :title, :msg, :rt, :rid, :url, :dk)',
				[
					'u' => $uid, 'type' => $type, 'title' => mb_substr($title, 0, 200), 'msg' => mb_substr($message, 0, 1000),
					'rt' => $relatedType, 'rid' => $relatedId, 'url' => $actionUrl, 'dk' => $dedupeKey,
				],
			)->rowCount();
		}
		return $n;
	}
}
