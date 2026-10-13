<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Validator;
use PDOException;

/**
 * Academic structure: sessions, departments, classes, subjects, enrolments and
 * teacher-to-class-subject assignments. Everything is scoped to one institution.
 */
final class AcademicService
{
	private Database $db;

	public function __construct(private readonly int $institutionId)
	{
		$this->db = Database::get();
	}

	// --- Lookups used for scoping ---------------------------------------------

	public function assertOwned(string $table, ?int $id, string $field): void
	{
		if ($id === null) {
			return;
		}
		$allowed = ['classes', 'subjects', 'departments', 'academic_sessions'];
		if (!in_array($table, $allowed, true)) {
			throw new \InvalidArgumentException($table);
		}
		if (!$this->db->value("SELECT 1 FROM $table WHERE id = :id AND institution_id = :i", ['id' => $id, 'i' => $this->institutionId])) {
			throw HttpException::validation([$field => 'Unknown or inaccessible record.']);
		}
	}

	/** Filters a list of user ids down to students of this institution; throws on any stranger. */
	private function assertStudents(array $ids): void
	{
		if (!$ids) {
			return;
		}
		[$in, $p] = Database::inList($ids);
		$n = (int) $this->db->value("SELECT COUNT(*) FROM students WHERE institution_id = :i AND user_id IN $in", ['i' => $this->institutionId] + $p);
		if ($n !== count(array_unique($ids))) {
			throw HttpException::validation(['student_ids' => 'One or more students are unknown.']);
		}
	}

	// --- Academic sessions ----------------------------------------------------

	public function sessions(): array
	{
		return $this->db->all(
			'SELECT s.*, (SELECT COUNT(*) FROM classes c WHERE c.academic_session_id = s.id) AS class_count
			 FROM academic_sessions s WHERE s.institution_id = :i ORDER BY s.starts_on DESC',
			['i' => $this->institutionId],
		);
	}

	public function saveSession(?int $id, array $input): int
	{
		$d = Validator::make($input, [
			'name' => ($id ? '' : 'required|') . 'string|max:60',
			'starts_on' => ($id ? '' : 'required|') . 'date',
			'ends_on' => ($id ? '' : 'required|') . 'date',
			'is_current' => 'bool',
		]);
		if ($id) {
			$current = $this->db->one('SELECT * FROM academic_sessions WHERE id = :id AND institution_id = :i', ['id' => $id, 'i' => $this->institutionId]) ?? throw new HttpException(404);
			$d += $current;
		}
		if ($d['ends_on'] <= $d['starts_on']) {
			throw HttpException::validation(['ends_on' => 'End date must be after the start date.']);
		}
		return $this->db->transaction(function (Database $db) use ($id, $d) {
			if (!empty($d['is_current'])) {
				$db->run('UPDATE academic_sessions SET is_current = 0 WHERE institution_id = :i', ['i' => $this->institutionId]);
			}
			$row = ['name' => $d['name'], 'starts_on' => $d['starts_on'], 'ends_on' => $d['ends_on'], 'is_current' => (int) !empty($d['is_current'])];
			$id = $this->saveRow('academic_sessions', $id, $row, 'name', 'A session with this name already exists.');
			AuditLog::record('session.saved', 'academic_session', $id, $row);
			return $id;
		});
	}

	// --- Departments ----------------------------------------------------------

	public function departments(): array
	{
		return $this->db->all('SELECT * FROM departments WHERE institution_id = :i ORDER BY name', ['i' => $this->institutionId]);
	}

	public function saveDepartment(?int $id, array $input): int
	{
		$d = Validator::make($input, [
			'name' => ($id ? '' : 'required|') . 'string|max:150',
			'code' => ($id ? '' : 'required|') . 'string|max:30|regex:/^[A-Za-z0-9_-]+$/',
		]);
		$id = $this->saveRow('departments', $id, $d, 'code', 'A department with this code already exists.');
		AuditLog::record('department.saved', 'department', $id, $d);
		return $id;
	}

	// --- Subjects -------------------------------------------------------------

	public function subjects(): array
	{
		return $this->db->all(
			'SELECT s.*, d.name AS department_name FROM subjects s LEFT JOIN departments d ON d.id = s.department_id
			 WHERE s.institution_id = :i ORDER BY s.name',
			['i' => $this->institutionId],
		);
	}

	public function saveSubject(?int $id, array $input): int
	{
		$d = Validator::make($input, [
			'name' => ($id ? '' : 'required|') . 'string|max:150',
			'code' => ($id ? '' : 'required|') . 'string|max:30|regex:/^[A-Za-z0-9_-]+$/',
			'department_id' => 'nullable|int|min:1',
			'is_active' => 'bool',
		]);
		$this->assertOwned('departments', $d['department_id'] ?? null, 'department_id');
		if (isset($d['is_active'])) {
			$d['is_active'] = (int) $d['is_active'];
		}
		$id = $this->saveRow('subjects', $id, $d, 'code', 'A subject with this code already exists.');
		AuditLog::record('subject.saved', 'subject', $id, $d);
		return $id;
	}

	// --- Classes --------------------------------------------------------------

	public function classes(?int $sessionId = null): array
	{
		$params = ['i' => $this->institutionId];
		$where = 'c.institution_id = :i';
		if ($sessionId) {
			$where .= ' AND c.academic_session_id = :s';
			$params['s'] = $sessionId;
		}
		return $this->db->all(
			"SELECT c.*, s.name AS session_name, d.name AS department_name,
				(SELECT COUNT(*) FROM class_students cs WHERE cs.class_id = c.id) AS student_count,
				(SELECT COUNT(*) FROM teacher_assignments ta WHERE ta.class_id = c.id) AS teaching_count
			 FROM classes c
			 JOIN academic_sessions s ON s.id = c.academic_session_id
			 LEFT JOIN departments d ON d.id = c.department_id
			 WHERE $where ORDER BY s.starts_on DESC, c.name, c.division",
			$params,
		);
	}

	public function saveClass(?int $id, array $input): int
	{
		$d = Validator::make($input, [
			'name' => ($id ? '' : 'required|') . 'string|max:100',
			'division' => 'string|max:20',
			'academic_session_id' => ($id ? '' : 'required|') . 'int|min:1',
			'department_id' => 'nullable|int|min:1',
			'is_active' => 'bool',
		]);
		$this->assertOwned('academic_sessions', $d['academic_session_id'] ?? null, 'academic_session_id');
		$this->assertOwned('departments', $d['department_id'] ?? null, 'department_id');
		if (isset($d['is_active'])) {
			$d['is_active'] = (int) $d['is_active'];
		}
		$id = $this->saveRow('classes', $id, $d, 'name', 'This class and division already exists in the session.');
		AuditLog::record('class.saved', 'class', $id, $d);
		return $id;
	}

	public function classStudents(int $classId): array
	{
		$this->assertOwned('classes', $classId, 'class_id');
		return $this->db->all(
			'SELECT u.id, u.full_name, u.email, u.is_active, s.admission_no, s.roll_no, cs.enrolled_at
			 FROM class_students cs JOIN users u ON u.id = cs.student_id JOIN students s ON s.user_id = u.id
			 WHERE cs.class_id = :c AND u.deleted_at IS NULL ORDER BY s.roll_no, u.full_name',
			['c' => $classId],
		);
	}

	public function enroll(array $classIds, array $studentIds): int
	{
		foreach ($classIds as $c) {
			$this->assertOwned('classes', $c, 'class_id');
		}
		$this->assertStudents($studentIds);
		$n = 0;
		foreach ($classIds as $c) {
			foreach ($studentIds as $s) {
				$n += $this->db->run('INSERT IGNORE INTO class_students (class_id, student_id) VALUES (:c, :s)', ['c' => $c, 's' => $s])->rowCount();
			}
		}
		AuditLog::record('class.enrolled', 'class', count($classIds) === 1 ? $classIds[0] : null, ['class_ids' => $classIds, 'student_ids' => $studentIds]);
		return $n;
	}

	public function unenroll(int $classId, int $studentId): void
	{
		$this->assertOwned('classes', $classId, 'class_id');
		$this->db->run('DELETE FROM class_students WHERE class_id = :c AND student_id = :s', ['c' => $classId, 's' => $studentId]);
		AuditLog::record('class.unenrolled', 'class', $classId, ['student_id' => $studentId]);
	}

	// --- Teacher assignments --------------------------------------------------

	public function teaching(?int $classId = null, ?int $teacherId = null): array
	{
		$where = ['c.institution_id = :i'];
		$params = ['i' => $this->institutionId];
		if ($classId) {
			$where[] = 'ta.class_id = :c';
			$params['c'] = $classId;
		}
		if ($teacherId) {
			$where[] = 'ta.teacher_id = :t';
			$params['t'] = $teacherId;
		}
		return $this->db->all(
			'SELECT ta.id, ta.class_id, ta.subject_id, ta.teacher_id, c.name AS class_name, c.division,
				sub.name AS subject_name, sub.code AS subject_code, u.full_name AS teacher_name
			 FROM teacher_assignments ta
			 JOIN classes c ON c.id = ta.class_id
			 JOIN subjects sub ON sub.id = ta.subject_id
			 JOIN users u ON u.id = ta.teacher_id
			 WHERE ' . implode(' AND ', $where) . ' ORDER BY c.name, c.division, sub.name',
			$params,
		);
	}

	public function assignTeacher(array $input): int
	{
		$d = Validator::make($input, [
			'class_id' => 'required|int|min:1',
			'subject_id' => 'required|int|min:1',
			'teacher_id' => 'required|int|min:1',
		]);
		$this->assertOwned('classes', $d['class_id'], 'class_id');
		$this->assertOwned('subjects', $d['subject_id'], 'subject_id');
		if (!$this->db->value(
			'SELECT 1 FROM teachers t JOIN users u ON u.id = t.user_id WHERE t.user_id = :t AND t.institution_id = :i AND u.is_active = 1',
			['t' => $d['teacher_id'], 'i' => $this->institutionId],
		)) {
			throw HttpException::validation(['teacher_id' => 'Unknown or inactive teacher.']);
		}
		try {
			$id = $this->db->insert('teacher_assignments', $d);
		} catch (PDOException $e) {
			if ($e->errorInfo[1] === 1062) {
				throw HttpException::validation(['teacher_id' => 'This teacher already teaches this subject to this class.']);
			}
			throw $e;
		}
		AuditLog::record('teacher.assigned', 'teacher_assignment', $id, $d);
		return $id;
	}

	public function unassignTeacher(int $id): void
	{
		$row = $this->db->one(
			'SELECT ta.* FROM teacher_assignments ta JOIN classes c ON c.id = ta.class_id WHERE ta.id = :id AND c.institution_id = :i',
			['id' => $id, 'i' => $this->institutionId],
		) ?? throw new HttpException(404);
		$this->db->run('DELETE FROM teacher_assignments WHERE id = :id', ['id' => $id]);
		AuditLog::record('teacher.unassigned', 'teacher_assignment', $id, $row);
	}

	// --- Shared ---------------------------------------------------------------

	/** Insert or update a row in an institution-owned table, mapping duplicate keys to a field error. */
	private function saveRow(string $table, ?int $id, array $data, string $dupField, string $dupMessage): int
	{
		try {
			if ($id) {
				if (!$this->db->value("SELECT 1 FROM $table WHERE id = :id AND institution_id = :i", ['id' => $id, 'i' => $this->institutionId])) {
					throw new HttpException(404);
				}
				if ($data) {
					$this->db->update($table, $data, 'id = :id AND institution_id = :i', ['id' => $id, 'i' => $this->institutionId]);
				}
				return $id;
			}
			return $this->db->insert($table, ['institution_id' => $this->institutionId] + $data);
		} catch (PDOException $e) {
			if (($e->errorInfo[1] ?? null) === 1062) {
				throw HttpException::validation([$dupField => $dupMessage]);
			}
			throw $e;
		}
	}
}
