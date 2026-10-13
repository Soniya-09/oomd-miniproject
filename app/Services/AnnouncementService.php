<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Paginator;
use App\Core\Validator;

/**
 * Announcements. Admins may address the whole institution or any classes;
 * teachers only classes they teach. Visibility is computed from the same rule
 * for lists, the calendar, notifications and downloads.
 */
final class AnnouncementService
{
	private Database $db;

	public function __construct(private readonly array $user)
	{
		$this->db = Database::get();
	}

	private function uid(): int
	{
		return (int) $this->user['id'];
	}

	private function inst(): int
	{
		return (int) $this->user['institution_id'];
	}

	/** SQL condition (alias "an") for announcements this user may read, plus params. */
	private function visibility(): array
	{
		$p = ['vi' => $this->inst()];
		$base = 'an.institution_id = :vi';
		return match ($this->user['role']) {
			'admin' => [$base, $p],
			'teacher' => ["$base AND (an.audience = 'institution' OR an.author_id = :vu1 OR EXISTS (SELECT 1 FROM announcement_targets t JOIN teacher_assignments ta ON ta.class_id = t.class_id WHERE t.announcement_id = an.id AND ta.teacher_id = :vu2))", $p + ['vu1' => $this->uid(), 'vu2' => $this->uid()]],
			default => ["$base AND (an.audience = 'institution' OR EXISTS (SELECT 1 FROM announcement_targets t JOIN class_students cs ON cs.class_id = t.class_id WHERE t.announcement_id = an.id AND cs.student_id = :vu1))", $p + ['vu1' => $this->uid()]],
		};
	}

	public function list(Paginator $p, array $f): array
	{
		[$vis, $params] = $this->visibility();
		$where = [$vis];
		$now = now_utc();
		if ($this->user['role'] === 'student' || empty($f['include_inactive'])) {
			$where[] = 'an.published_at <= :n1 AND (an.expires_at IS NULL OR an.expires_at > :n2)';
			$params += ['n1' => $now, 'n2' => $now];
		}
		if (!empty($f['q'])) {
			$where[] = '(an.title LIKE :q1 OR an.body LIKE :q2)';
			$params += ['q1' => Paginator::like($f['q']), 'q2' => Paginator::like($f['q'])];
		}
		$params['ru'] = $this->uid();
		$result = $p->run(
			"SELECT an.id, an.title, an.body, an.audience, an.published_at, an.expires_at, an.author_id, u.full_name AS author_name,
				(SELECT GROUP_CONCAT(TRIM(CONCAT(c.name, ' ', c.division)) ORDER BY c.name SEPARATOR ', ') FROM announcement_targets t JOIN classes c ON c.id = t.class_id WHERE t.announcement_id = an.id) AS classes,
				EXISTS (SELECT 1 FROM announcement_reads r WHERE r.announcement_id = an.id AND r.user_id = :ru) AS is_read",
			'FROM announcements an JOIN users u ON u.id = an.author_id WHERE ' . implode(' AND ', $where),
			$params,
			'an.published_at DESC',
		);
		foreach ($result['data'] as &$a) {
			$a['published_at'] = iso_utc($a['published_at']);
			$a['expires_at'] = iso_utc($a['expires_at']);
			$a['is_read'] = (bool) $a['is_read'];
			$a['can_delete'] = $this->user['role'] === 'admin' || (int) $a['author_id'] === $this->uid();
			$a['attachments'] = $this->db->all('SELECT id, original_name, size_bytes FROM announcement_attachments WHERE announcement_id = :a', ['a' => $a['id']]);
		}
		return $result;
	}

	/** Used by the calendar: currently visible announcements published in [from, to). */
	public function visibleBetween(string $from, string $to): array
	{
		[$vis, $params] = $this->visibility();
		return $this->db->all(
			"SELECT an.id, an.title, an.published_at, u.full_name AS author_name FROM announcements an JOIN users u ON u.id = an.author_id
			 WHERE $vis AND an.published_at >= :f AND an.published_at < :t AND an.published_at <= :n",
			$params + ['f' => $from, 't' => $to, 'n' => now_utc()],
		);
	}

	public function findVisible(int $id): array
	{
		[$vis, $params] = $this->visibility();
		$a = $this->db->one("SELECT an.* FROM announcements an WHERE an.id = :id AND $vis", ['id' => $id] + $params);
		if (!$a || ($this->user['role'] === 'student' && $a['published_at'] > now_utc())) {
			throw new HttpException(404);
		}
		return $a;
	}

	public function create(array $input, array $files): int
	{
		$d = Validator::make($input, [
			'title' => 'required|string|min:3|max:200',
			'body' => 'required|string|min:1|max:10000',
			'audience' => 'in:institution,classes',
			'class_ids' => 'ids',
			'published_at' => 'nullable|datetime',
			'expires_at' => 'nullable|datetime',
		]);
		$audience = $d['audience'] ?? 'classes';
		$classIds = $d['class_ids'] ?? [];
		if ($this->user['role'] === 'teacher') {
			if ($audience !== 'classes') {
				throw HttpException::validation(['audience' => 'Teachers can post to their own classes only.']);
			}
			foreach ($classIds as $c) {
				if (!Policy::teachesClass($this->uid(), $c)) {
					throw HttpException::validation(['class_ids' => 'You can only post to classes you teach.']);
				}
			}
		} elseif ($classIds) {
			[$in, $p] = Database::inList($classIds);
			if ((int) $this->db->value("SELECT COUNT(*) FROM classes WHERE institution_id = :i AND id IN $in", ['i' => $this->inst()] + $p) !== count($classIds)) {
				throw HttpException::validation(['class_ids' => 'Unknown class.']);
			}
		}
		if ($audience === 'classes' && !$classIds) {
			throw HttpException::validation(['class_ids' => 'Choose at least one class.']);
		}
		$published = $d['published_at'] ?? now_utc();
		if (!empty($d['expires_at']) && $d['expires_at'] <= $published) {
			throw HttpException::validation(['expires_at' => 'Expiry must be after publication.']);
		}
		$stored = [];
		foreach (array_slice($files, 0, 5) as $f) {
			$stored[] = FileStorage::store($f, FileStorage::allowedExtensions(), (int) config('uploads.max_mb'));
		}

		$id = $this->db->transaction(function (Database $db) use ($d, $audience, $classIds, $published, $stored) {
			$id = $db->insert('announcements', [
				'institution_id' => $this->inst(), 'author_id' => $this->uid(), 'title' => $d['title'], 'body' => $d['body'],
				'audience' => $audience, 'published_at' => $published, 'expires_at' => $d['expires_at'] ?? null,
			]);
			if ($audience === 'classes') {
				foreach ($classIds as $c) {
					$db->insert('announcement_targets', ['announcement_id' => $id, 'class_id' => $c]);
				}
			}
			foreach ($stored as $meta) {
				$db->insert('announcement_attachments', ['announcement_id' => $id] + $meta);
			}
			return $id;
		});
		AuditLog::record('announcement.published', 'announcement', $id, ['audience' => $audience, 'class_ids' => $classIds]);
		if ($published <= now_utc()) {
			$this->notify($id);
		}
		return $id;
	}

	/** Notify the audience (idempotent; also used by the cron for scheduled announcements). */
	public function notify(int $id): int
	{
		$a = $this->db->one('SELECT * FROM announcements WHERE id = :id', ['id' => $id]);
		if ($a['audience'] === 'institution') {
			$users = $this->db->all("SELECT id FROM users WHERE institution_id = :i AND is_active = 1 AND deleted_at IS NULL AND role <> 'admin' AND id <> :a", ['i' => $a['institution_id'], 'a' => $a['author_id']]);
		} else {
			$users = $this->db->all(
				'SELECT DISTINCT cs.student_id AS id FROM announcement_targets t JOIN class_students cs ON cs.class_id = t.class_id WHERE t.announcement_id = :id
				 UNION SELECT DISTINCT ta.teacher_id FROM announcement_targets t JOIN teacher_assignments ta ON ta.class_id = t.class_id WHERE t.announcement_id = :id2 AND ta.teacher_id <> :a',
				['id' => $id, 'id2' => $id, 'a' => $a['author_id']],
			);
		}
		return NotificationService::send(array_column($users, 'id'), 'announcement', 'Announcement: ' . $a['title'], mb_substr($a['body'], 0, 240), 'announcement', $id, '/announcements#a' . $id, "announcement:$id");
	}

	public function markRead(int $id): void
	{
		$this->findVisible($id);
		$this->db->run('INSERT IGNORE INTO announcement_reads (announcement_id, user_id) VALUES (:a, :u)', ['a' => $id, 'u' => $this->uid()]);
	}

	public function delete(int $id): void
	{
		$a = $this->findVisible($id);
		if ($this->user['role'] !== 'admin' && (int) $a['author_id'] !== $this->uid()) {
			throw new HttpException(403);
		}
		$files = $this->db->all('SELECT stored_name FROM announcement_attachments WHERE announcement_id = :a', ['a' => $id]);
		$this->db->run('DELETE FROM announcements WHERE id = :id', ['id' => $id]);
		foreach ($files as $f) {
			FileStorage::delete($f['stored_name']);
		}
		AuditLog::record('announcement.deleted', 'announcement', $id, ['title' => $a['title']]);
	}

	public function attachment(int $attachmentId): array
	{
		$f = $this->db->one('SELECT * FROM announcement_attachments WHERE id = :id', ['id' => $attachmentId]) ?? throw new HttpException(404);
		$this->findVisible((int) $f['announcement_id']);
		return $f;
	}
}
