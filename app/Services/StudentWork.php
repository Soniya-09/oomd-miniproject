<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\Recipients;

/**
 * One normalized list of a student's work: academic assignments + private
 * personal tasks. Dashboard cards, the unified list, the calendar and every
 * productivity chart read from here so the numbers always agree.
 *
 * Unified status (see docs/METRICS.md):
 *   completed   academic: a submission exists and is not "returned"; personal: status completed
 *   in_progress academic: returned for revision; personal: status in_progress
 *   overdue     not completed and the deadline has passed
 *   pending     everything else that is still open
 * Excluded: cancelled personal tasks, cancelled/draft assignments, and
 * assignments that need no submission (shown as "info" items, never counted).
 */
final class StudentWork
{
	private Database $db;

	public function __construct(private readonly int $studentId)
	{
		$this->db = Database::get();
	}

	/** @return array<int, array> */
	public function items(): array
	{
		$now = now_utc();
		$items = [];

		$academic = $this->db->all(
			"SELECT a.id, a.title, a.priority, a.due_at, a.requires_submission, a.status AS assignment_status, a.published_at,
				s.id AS subject_id, s.name AS subject_name,
				sb.status AS sub_status, sb.first_submitted_at, sb.submitted_at, sb.is_late, sb.attempt
			 FROM " . Recipients::SQL . " r
			 JOIN assignments a ON a.id = r.assignment_id
			 JOIN subjects s ON s.id = a.subject_id
			 LEFT JOIN submissions sb ON sb.assignment_id = a.id AND sb.student_id = r.student_id
			 WHERE r.student_id = :s AND a.status IN ('published', 'closed')",
			['s' => $this->studentId],
		);
		foreach ($academic as $a) {
			if (!(int) $a['requires_submission']) {
				$status = 'info';
				$completedAt = null;
			} elseif ($a['sub_status'] !== null && $a['sub_status'] !== 'returned') {
				$status = 'completed';
				// Productivity counts the moment the work was first handed in.
				$completedAt = $a['first_submitted_at'];
			} elseif ($a['sub_status'] === 'returned') {
				$status = $a['due_at'] !== null && $a['due_at'] < $now && $a['assignment_status'] === 'closed' ? 'overdue' : 'in_progress';
				$completedAt = null;
			} else {
				$status = $a['due_at'] !== null && $a['due_at'] < $now ? 'overdue' : 'pending';
				$completedAt = null;
			}
			$items[] = [
				'kind' => 'academic',
				'id' => (int) $a['id'],
				'key' => 'a' . $a['id'],
				'title' => $a['title'],
				'group' => $a['subject_name'],
				'subject_id' => (int) $a['subject_id'],
				'priority' => $a['priority'],
				'due_at' => $a['due_at'],
				'created_at' => $a['published_at'],
				'status' => $status,
				'completed_at' => $completedAt,
				'submission_status' => $a['sub_status'],
				'is_late' => $a['sub_status'] !== null ? (bool) $a['is_late'] : null,
				'url' => '/student/assignments/' . $a['id'],
			];
		}

		$personal = $this->db->all(
			"SELECT t.id, t.title, t.priority, t.status, t.due_at, t.starts_at, t.duration_min, t.completed_at, t.created_at, c.name AS category_name, c.color AS category_color
			 FROM personal_tasks t LEFT JOIN task_categories c ON c.id = t.category_id
			 WHERE t.user_id = :u AND t.deleted_at IS NULL AND t.status <> 'cancelled'",
			['u' => $this->studentId],
		);
		foreach ($personal as $t) {
			$status = $t['status'];
			if ($status !== 'completed' && $t['due_at'] !== null && $t['due_at'] < $now) {
				$status = 'overdue';
			}
			$items[] = [
				'kind' => 'personal',
				'id' => (int) $t['id'],
				'key' => 't' . $t['id'],
				'title' => $t['title'],
				'group' => $t['category_name'] ?? 'Personal',
				'category_color' => $t['category_color'],
				'subject_id' => null,
				'priority' => $t['priority'],
				'due_at' => $t['due_at'],
				'starts_at' => $t['starts_at'],
				'duration_min' => (int) $t['duration_min'],
				'created_at' => $t['created_at'],
				'status' => $status,
				'completed_at' => $status === 'completed' ? $t['completed_at'] : null,
				'url' => '/student/tasks?task=' . $t['id'],
			];
		}
		return $items;
	}

	/** Items that count toward productivity metrics. */
	public static function eligible(array $items): array
	{
		return array_values(array_filter($items, fn($i) => $i['status'] !== 'info'));
	}

	/** Deadline first (overdue at the top), then priority. No-deadline items last. */
	public static function byUrgency(array $a, array $b): int
	{
		$rank = ['high' => 0, 'medium' => 1, 'low' => 2];
		if (($a['due_at'] === null) !== ($b['due_at'] === null)) {
			return $a['due_at'] === null ? 1 : -1;
		}
		return [$a['due_at'], $rank[$a['priority']] ?? 1] <=> [$b['due_at'], $rank[$b['priority']] ?? 1];
	}

	public static function isOpen(array $i): bool
	{
		return in_array($i['status'], ['pending', 'in_progress', 'overdue'], true);
	}

	/** Browser-facing shape (ISO dates). */
	public static function present(array $i): array
	{
		foreach (['due_at', 'completed_at', 'created_at', 'starts_at'] as $k) {
			if (array_key_exists($k, $i)) {
				$i[$k] = iso_utc($i[$k]);
			}
		}
		return $i;
	}
}
