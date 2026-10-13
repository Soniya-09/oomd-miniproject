<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Validator;

/**
 * Exams (teachers for their class+subject, admins for any) and weekly
 * timetable entries (admins). Both appear in the calendar.
 */
final class ScheduleService
{
	private Database $db;

	public function __construct(private readonly array $user)
	{
		$this->db = Database::get();
	}

	private function assertCanSchedule(int $classId, int $subjectId): void
	{
		if ($this->user['role'] === 'teacher') {
			if (!Policy::teaches((int) $this->user['id'], $classId, $subjectId)) {
				throw HttpException::validation(['subject_id' => 'You do not teach this subject to this class.']);
			}
			return;
		}
		$ok = $this->db->value(
			'SELECT 1 FROM classes c, subjects s WHERE c.id = :c AND s.id = :s AND c.institution_id = :i1 AND s.institution_id = :i2',
			['c' => $classId, 's' => $subjectId, 'i1' => $this->user['institution_id'], 'i2' => $this->user['institution_id']],
		);
		if (!$ok) {
			throw HttpException::validation(['class_id' => 'Unknown class or subject.']);
		}
	}

	// --- Exams ----------------------------------------------------------------

	public function exams(?int $classId = null): array
	{
		$where = ['e.institution_id = :i'];
		$p = ['i' => $this->user['institution_id']];
		if ($this->user['role'] === 'teacher') {
			$where[] = 'EXISTS (SELECT 1 FROM teacher_assignments ta WHERE ta.class_id = e.class_id AND ta.subject_id = e.subject_id AND ta.teacher_id = :t)';
			$p['t'] = $this->user['id'];
		}
		if ($classId) {
			$where[] = 'e.class_id = :c';
			$p['c'] = $classId;
		}
		$rows = $this->db->all(
			"SELECT e.id, e.title, e.starts_at, e.ends_at, e.room, e.class_id, e.subject_id, s.name AS subject_name, TRIM(CONCAT(c.name, ' ', c.division)) AS class_name
			 FROM exams e JOIN subjects s ON s.id = e.subject_id JOIN classes c ON c.id = e.class_id
			 WHERE " . implode(' AND ', $where) . ' ORDER BY e.starts_at DESC LIMIT 200',
			$p,
		);
		foreach ($rows as &$r) {
			$r['starts_at'] = iso_utc($r['starts_at']);
			$r['ends_at'] = iso_utc($r['ends_at']);
		}
		return $rows;
	}

	public function createExam(array $input): int
	{
		$d = Validator::make($input, [
			'title' => 'required|string|min:2|max:200',
			'class_id' => 'required|int|min:1',
			'subject_id' => 'required|int|min:1',
			'starts_at' => 'required|datetime',
			'ends_at' => 'required|datetime',
			'room' => 'nullable|string|max:60',
		]);
		if ($d['ends_at'] <= $d['starts_at']) {
			throw HttpException::validation(['ends_at' => 'End must be after start.']);
		}
		$this->assertCanSchedule($d['class_id'], $d['subject_id']);
		$id = $this->db->insert('exams', ['institution_id' => $this->user['institution_id']] + $d);
		$students = array_column($this->db->all('SELECT student_id FROM class_students WHERE class_id = :c', ['c' => $d['class_id']]), 'student_id');
		NotificationService::send($students, 'exam', 'Exam scheduled: ' . $d['title'], 'Starts ' . $d['starts_at'] . ' UTC' . (!empty($d['room']) ? ' · ' . $d['room'] : ''), 'exam', $id, '/student/calendar', "exam:$id");
		AuditLog::record('exam.created', 'exam', $id, $d);
		return $id;
	}

	public function deleteExam(int $id): void
	{
		$e = $this->db->one('SELECT * FROM exams WHERE id = :id AND institution_id = :i', ['id' => $id, 'i' => $this->user['institution_id']]) ?? throw new HttpException(404);
		if ($this->user['role'] === 'teacher' && !Policy::teaches((int) $this->user['id'], (int) $e['class_id'], (int) $e['subject_id'])) {
			throw new HttpException(404);
		}
		$this->db->run('DELETE FROM exams WHERE id = :id', ['id' => $id]);
		AuditLog::record('exam.deleted', 'exam', $id, ['title' => $e['title']]);
	}

	// --- Timetable (admin) ----------------------------------------------------

	public function timetable(int $classId): array
	{
		return $this->db->all(
			'SELECT t.id, t.weekday, t.start_time, t.end_time, t.room, t.subject_id, t.teacher_id, s.name AS subject_name, u.full_name AS teacher_name
			 FROM timetable_entries t JOIN subjects s ON s.id = t.subject_id LEFT JOIN users u ON u.id = t.teacher_id
			 JOIN classes c ON c.id = t.class_id
			 WHERE t.class_id = :c AND c.institution_id = :i ORDER BY t.weekday, t.start_time',
			['c' => $classId, 'i' => $this->user['institution_id']],
		);
	}

	public function addTimetableEntry(int $classId, array $input): int
	{
		$d = Validator::make($input, [
			'subject_id' => 'required|int|min:1',
			'teacher_id' => 'nullable|int|min:1',
			'weekday' => 'required|int|min:1|max:7',
			'start_time' => 'required|time',
			'end_time' => 'required|time',
			'room' => 'nullable|string|max:60',
		]);
		if ($d['end_time'] <= $d['start_time']) {
			throw HttpException::validation(['end_time' => 'End must be after start.']);
		}
		$this->assertCanSchedule($classId, $d['subject_id']);
		if (!empty($d['teacher_id']) && !Policy::teaches($d['teacher_id'], $classId, $d['subject_id'])) {
			throw HttpException::validation(['teacher_id' => 'That teacher is not assigned to this subject for the class.']);
		}
		$clash = $this->db->value(
			'SELECT 1 FROM timetable_entries WHERE class_id = :c AND weekday = :w AND start_time < :e AND end_time > :s',
			['c' => $classId, 'w' => $d['weekday'], 'e' => $d['end_time'], 's' => $d['start_time']],
		);
		if ($clash) {
			throw HttpException::validation(['start_time' => 'This overlaps another period for the class.']);
		}
		$id = $this->db->insert('timetable_entries', ['class_id' => $classId] + $d);
		AuditLog::record('timetable.added', 'class', $classId, $d);
		return $id;
	}

	public function deleteTimetableEntry(int $id): void
	{
		$n = $this->db->run(
			'DELETE t FROM timetable_entries t JOIN classes c ON c.id = t.class_id WHERE t.id = :id AND c.institution_id = :i',
			['id' => $id, 'i' => $this->user['institution_id']],
		)->rowCount();
		if (!$n) {
			throw new HttpException(404);
		}
	}
}
