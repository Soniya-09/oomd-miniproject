<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;

/** Records important administrative and security actions. */
final class AuditLog
{
	public static function record(
		string $action,
		?string $entityType = null,
		?int $entityId = null,
		array $details = [],
		?int $userId = null,
		?int $institutionId = null,
	): void {
		$user = Auth::user();
		Database::get()->insert('audit_logs', [
			'institution_id' => $institutionId ?? ($user['institution_id'] ?? null),
			'user_id' => $userId ?? ($user['id'] ?? null),
			'action' => $action,
			'entity_type' => $entityType,
			'entity_id' => $entityId,
			'details' => $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
			'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
		]);
	}
}
