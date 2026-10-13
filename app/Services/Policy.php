<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Repositories\Recipients;

/**
 * Resource-level authorization. Every lookup re-checks the authenticated user
 * against the database; ids from the browser are never trusted on their own.
 * Unauthorized resources return 404 so their existence is not revealed.
 */
final class Policy
{
	public static function teaches(int $teacherId, int $classId, int $subjectId): bool
	{
		return (bool) Database::get()->value(
			'SELECT 1 FROM teacher_assignments WHERE teacher_id = :t AND class_id = :c AND subject_id = :s',
			['t' => $teacherId, 'c' => $classId, 's' => $subjectId],
		);
	}

	public static function teachesClass(int $teacherId, int $classId): bool
	{
		return (bool) Database::get()->value(
			'SELECT 1 FROM teacher_assignments WHERE teacher_id = :t AND class_id = :c',
			['t' => $teacherId, 'c' => $classId],
		);
	}

	/** Teacher may manage an assignment if they teach its class+subject. */
	public static function assignmentForTeacher(int $assignmentId, int $teacherId): array
	{
		$a = Database::get()->one(
			'SELECT a.* FROM assignments a
			 JOIN teacher_assignments ta ON ta.class_id = a.class_id AND ta.subject_id = a.subject_id AND ta.teacher_id = :t
			 WHERE a.id = :id',
			['id' => $assignmentId, 't' => $teacherId],
		);
		return $a ?? throw new HttpException(404, 'Assignment not found.');
	}

	/** Student sees an assignment only once published (or closed) and only if it is meant for them. */
	public static function assignmentForStudent(int $assignmentId, int $studentId): array
	{
		$a = Database::get()->one(
			'SELECT a.* FROM assignments a JOIN ' . Recipients::SQL . " r ON r.assignment_id = a.id AND r.student_id = :s
			 WHERE a.id = :id AND a.status IN ('published', 'closed')",
			['id' => $assignmentId, 's' => $studentId],
		);
		return $a ?? throw new HttpException(404, 'Assignment not found.');
	}

	public static function isRecipient(int $assignmentId, int $studentId): bool
	{
		return (bool) Database::get()->value(
			'SELECT 1 FROM ' . Recipients::SQL . ' r WHERE r.assignment_id = :a AND r.student_id = :s',
			['a' => $assignmentId, 's' => $studentId],
		);
	}

	/** Submission visible to its student, or to a teacher of the assignment. */
	public static function submissionFor(int $submissionId, array $user): array
	{
		$s = Database::get()->one('SELECT * FROM submissions WHERE id = :id', ['id' => $submissionId]) ?? throw new HttpException(404);
		if ($user['role'] === 'student') {
			if ((int) $s['student_id'] !== (int) $user['id']) {
				throw new HttpException(404);
			}
			self::assignmentForStudent((int) $s['assignment_id'], (int) $user['id']);
		} elseif ($user['role'] === 'teacher') {
			self::assignmentForTeacher((int) $s['assignment_id'], (int) $user['id']);
		} else {
			throw new HttpException(404);
		}
		return $s;
	}
}
