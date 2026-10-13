<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\Recipients;

/** Work done by bin/reminders.php. Idempotent: rerunning never duplicates notifications. */
final class ReminderService
{
	private Database $db;

	public function __construct(private readonly int $dueSoonHours = 24)
	{
		$this->db = Database::get();
	}

	public function run(): array
	{
		return [
			'due_soon' => $this->dueSoon(),
			'overdue' => $this->overdue(),
			'task_reminders' => $this->taskReminders(),
			'announcements' => $this->scheduledAnnouncements(),
		];
	}

	/** Assignments due within the window that the student has not submitted. */
	public function dueSoon(): int
	{
		// Window per institution (Admin → Settings), defaulting to the constructor value.
		$windows = [];
		foreach ($this->db->all('SELECT id, settings FROM institutions') as $i) {
			$windows[(int) $i['id']] = (int) ((json_decode((string) $i['settings'], true) ?: [])['due_soon_hours'] ?? $this->dueSoonHours);
		}
		$rows = $this->db->all(
			"SELECT a.id, a.institution_id, a.title, a.due_at, r.student_id FROM " . Recipients::SQL . " r
			 JOIN assignments a ON a.id = r.assignment_id JOIN users u ON u.id = r.student_id AND u.is_active = 1
			 LEFT JOIN submissions sb ON sb.assignment_id = a.id AND sb.student_id = r.student_id
			 WHERE a.status = 'published' AND a.requires_submission = 1 AND sb.id IS NULL
			   AND a.due_at > :now AND a.due_at <= :until",
			['now' => now_utc(), 'until' => gmdate('Y-m-d H:i:s', time() + 168 * 3600)],
		);
		$n = 0;
		foreach ($rows as $r) {
			$hours = $windows[(int) $r['institution_id']] ?? $this->dueSoonHours;
			if (strtotime($r['due_at'] . ' UTC') > time() + $hours * 3600) {
				continue;
			}
			// Key includes the deadline so a moved deadline gets a fresh reminder.
			$n += NotificationService::send([(int) $r['student_id']], 'deadline_soon', 'Due soon: ' . $r['title'],
				'Due ' . $r['due_at'] . ' UTC. You have not submitted yet.', 'assignment', (int) $r['id'],
				"/student/assignments/{$r['id']}", "due-soon:{$r['id']}:{$r['due_at']}");
		}
		return $n;
	}

	/** Deadline passed in the last 7 days, still no submission. */
	public function overdue(): int
	{
		$rows = $this->db->all(
			"SELECT a.id, a.title, a.due_at, a.allow_late, r.student_id FROM " . Recipients::SQL . " r
			 JOIN assignments a ON a.id = r.assignment_id JOIN users u ON u.id = r.student_id AND u.is_active = 1
			 LEFT JOIN submissions sb ON sb.assignment_id = a.id AND sb.student_id = r.student_id
			 WHERE a.status IN ('published', 'closed') AND a.requires_submission = 1 AND sb.id IS NULL
			   AND a.due_at <= :now AND a.due_at > :since",
			['now' => now_utc(), 'since' => gmdate('Y-m-d H:i:s', time() - 7 * 86400)],
		);
		$n = 0;
		foreach ($rows as $r) {
			$n += NotificationService::send([(int) $r['student_id']], 'overdue', 'Overdue: ' . $r['title'],
				(int) $r['allow_late'] ? 'The deadline has passed. Late submissions are still accepted.' : 'The deadline has passed.',
				'assignment', (int) $r['id'], "/student/assignments/{$r['id']}", "overdue:{$r['id']}:{$r['due_at']}");
		}
		return $n;
	}

	/** Personal-task reminders go only to the task's owner. */
	public function taskReminders(): int
	{
		$rows = $this->db->all(
			"SELECT r.id, r.task_id, r.remind_at, t.user_id, t.title, t.due_at FROM task_reminders r
			 JOIN personal_tasks t ON t.id = r.task_id
			 WHERE r.sent_at IS NULL AND r.remind_at <= :now AND t.deleted_at IS NULL AND t.status IN ('pending', 'in_progress')",
			['now' => now_utc()],
		);
		$n = 0;
		foreach ($rows as $r) {
			$n += NotificationService::send([(int) $r['user_id']], 'task_reminder', 'Reminder: ' . $r['title'],
				$r['due_at'] ? 'Due ' . $r['due_at'] . ' UTC.' : 'You asked to be reminded about this task.',
				'personal_task', (int) $r['task_id'], '/student/tasks?task=' . $r['task_id'], "task-reminder:{$r['id']}");
			$this->db->update('task_reminders', ['sent_at' => now_utc()], 'id = :id', ['id' => $r['id']]);
		}
		return $n;
	}

	/** Announcements scheduled for the future are announced once their time comes. */
	public function scheduledAnnouncements(): int
	{
		$rows = $this->db->all(
			"SELECT an.id, an.author_id FROM announcements an WHERE an.published_at <= :now AND an.published_at > :since
			 AND (an.expires_at IS NULL OR an.expires_at > :now2)",
			['now' => now_utc(), 'now2' => now_utc(), 'since' => gmdate('Y-m-d H:i:s', time() - 7 * 86400)],
		);
		$n = 0;
		foreach ($rows as $r) {
			$author = $this->db->one('SELECT id, role, institution_id FROM users WHERE id = :id', ['id' => $r['author_id']]);
			$n += (new AnnouncementService($author))->notify((int) $r['id']);
		}
		return $n;
	}
}
