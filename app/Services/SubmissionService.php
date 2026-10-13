<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\RateLimiter;
use App\Core\Validator;

/**
 * Per-student submission states (separate from the assignment's publication state):
 *
 *   (none) ──submit──▶ submitted ──review──▶ under_review ──grade──▶ graded
 *                         │  ▲                    │                   │ ▲
 *                         │  └─update(before review, before due)      │ └─ regrade
 *                         └──────────grade / return──────────┐        │
 *                                                            ▼        ▼
 *                    resubmitted ◀──resubmit── returned ◀──return / allow-resubmission
 *
 * "Not submitted" and "missing" (deadline passed) are derived from the absence of a row.
 */
final class SubmissionService
{
	/** teacher action => [from status => to status] */
	public const TEACHER_TRANSITIONS = [
		'review' => ['submitted' => 'under_review', 'resubmitted' => 'under_review'],
		'grade' => ['submitted' => 'graded', 'resubmitted' => 'graded', 'under_review' => 'graded', 'graded' => 'graded'],
		'return' => ['submitted' => 'returned', 'resubmitted' => 'returned', 'under_review' => 'returned', 'graded' => 'returned'],
	];

	private Database $db;

	public function __construct(private readonly array $user)
	{
		$this->db = Database::get();
	}

	private function uid(): int
	{
		return (int) $this->user['id'];
	}

	// ================================================================== student

	/** Create, update, or resubmit, depending on current state. Returns the submission id. */
	public function submit(int $assignmentId, array $files, ?string $note): int
	{
		$a = Policy::assignmentForStudent($assignmentId, $this->uid());
		if (!(int) $a['requires_submission']) {
			throw new HttpException(409, 'This assignment does not take submissions.');
		}
		if ($a['status'] !== 'published') {
			throw new HttpException(409, 'This assignment is closed for submissions.');
		}
		$now = now_utc();
		$late = $a['due_at'] !== null && $now > $a['due_at'];
		if ($late && !(int) $a['allow_late']) {
			throw new HttpException(409, 'The deadline has passed and late submissions are not accepted.');
		}
		if (!$files) {
			throw HttpException::validation(['files' => 'Attach at least one file.']);
		}
		if (count($files) > 10) {
			throw HttpException::validation(['files' => 'Attach at most 10 files.']);
		}
		$note = $note !== null ? Validator::make(['n' => $note], ['n' => 'nullable|string|max:2000'])['n'] ?? null : null;
		RateLimiter::enforce('upload', 'user:' . $this->uid());

		$existing = $this->db->one('SELECT * FROM submissions WHERE assignment_id = :a AND student_id = :s', ['a' => $assignmentId, 's' => $this->uid()]);
		$mode = match (true) {
			$existing === null => 'new',
			$existing['status'] === 'returned' => 'resubmit',
			in_array($existing['status'], ['submitted', 'resubmitted'], true) => 'update',
			default => null,
		};
		if ($mode === null) {
			throw new HttpException(409, $existing['status'] === 'graded'
				? 'This work has been graded. Ask for a resubmission if you need to change it.'
				: 'Your teacher is reviewing this submission, so it cannot be changed right now.');
		}
		if ($mode === 'resubmit' && !(int) $a['allow_resubmission'] && !(int) $existing['resubmission_requested']) {
			throw new HttpException(409, 'Resubmission is not allowed for this assignment.');
		}

		// Validate and store every file before touching the database.
		$ext = json_decode($a['allowed_extensions'], true) ?: [];
		$stored = [];
		try {
			foreach ($files as $f) {
				$stored[] = FileStorage::store($f, $ext, (int) $a['max_upload_mb']);
			}
		} catch (\Throwable $e) {
			foreach ($stored as $s) {
				FileStorage::delete($s['stored_name']);
			}
			throw $e;
		}

		$replaced = [];
		$id = $this->db->transaction(function (Database $db) use ($mode, $existing, $a, $now, $late, $note, $stored, &$replaced) {
			if ($mode === 'new') {
				$id = $db->insert('submissions', [
					'assignment_id' => $a['id'], 'student_id' => $this->uid(), 'status' => 'submitted', 'attempt' => 1,
					'first_submitted_at' => $now, 'submitted_at' => $now, 'is_late' => (int) $late, 'student_note' => $note,
				]);
				$attempt = 1;
				$history = [null, 'submitted'];
			} elseif ($mode === 'update') {
				$id = (int) $existing['id'];
				$attempt = (int) $existing['attempt'];
				// Replacing files in the current attempt before review: keep metadata history small, drop old files.
				$replaced = $db->all('SELECT stored_name FROM submission_files WHERE submission_id = :s AND attempt = :n', ['s' => $id, 'n' => $attempt]);
				$db->run('DELETE FROM submission_files WHERE submission_id = :s AND attempt = :n', ['s' => $id, 'n' => $attempt]);
				$db->update('submissions', ['submitted_at' => $now, 'is_late' => (int) $late, 'student_note' => $note], 'id = :id', ['id' => $id]);
				$history = [$existing['status'], $existing['status']];
			} else {
				$id = (int) $existing['id'];
				$attempt = (int) $existing['attempt'] + 1;
				$db->update('submissions', [
					'status' => 'resubmitted', 'attempt' => $attempt, 'submitted_at' => $now, 'is_late' => (int) $late,
					'student_note' => $note, 'resubmission_requested' => 0, 'grade_released' => 0,
				], 'id = :id', ['id' => $id]);
				$history = ['returned', 'resubmitted'];
			}
			foreach ($stored as $meta) {
				$db->insert('submission_files', ['submission_id' => $id, 'attempt' => $attempt] + $meta);
			}
			$db->insert('grading_history', [
				'submission_id' => $id, 'changed_by' => $this->uid(), 'old_status' => $history[0], 'new_status' => $history[1],
				'note' => $mode === 'update' ? 'Files replaced' : ($mode === 'resubmit' ? "Attempt $attempt" : 'First submission'),
			]);
			return $id;
		});
		foreach ($replaced as $r) {
			FileStorage::delete($r['stored_name']);
		}

		NotificationService::send([$this->uid()], 'submission_received', 'Submission received: ' . $a['title'],
			($late ? 'Submitted after the deadline. ' : 'Submitted on time. ') . count($stored) . ' file(s) uploaded.',
			'assignment', (int) $a['id'], "/student/assignments/{$a['id']}", "submission:$id:" . md5($now . count($stored)));
		return $id;
	}

	/** Ask the teacher to reopen graded work. */
	public function requestResubmission(int $assignmentId, string $reason): void
	{
		$a = Policy::assignmentForStudent($assignmentId, $this->uid());
		$s = $this->db->one('SELECT * FROM submissions WHERE assignment_id = :a AND student_id = :s', ['a' => $assignmentId, 's' => $this->uid()]);
		if (!$s || $s['status'] !== 'graded') {
			throw new HttpException(409, 'You can request a resubmission only after your work is graded.');
		}
		if (!(int) $a['allow_resubmission']) {
			throw new HttpException(409, 'Resubmission is not allowed for this assignment.');
		}
		$reason = Validator::make(['r' => $reason], ['r' => 'required|string|max:500'])['r'];
		$this->db->update('submissions', ['resubmission_requested' => 1], 'id = :id', ['id' => $s['id']]);
		$this->db->insert('submission_feedback', ['submission_id' => $s['id'], 'author_id' => $this->uid(), 'attempt' => $s['attempt'], 'comment' => "Resubmission request: $reason", 'is_released' => 1]);
		$teachers = array_column($this->db->all('SELECT teacher_id FROM teacher_assignments WHERE class_id = :c AND subject_id = :s', ['c' => $a['class_id'], 's' => $a['subject_id']]), 'teacher_id');
		NotificationService::send($teachers, 'resubmission_requested', 'Resubmission requested: ' . $a['title'],
			$this->user['full_name'] . ' asked to resubmit: ' . $reason, 'assignment', (int) $a['id'], "/teacher/assignments/{$a['id']}", "resubmit-request:{$s['id']}:{$s['attempt']}");
	}

	/** Student's view of one assignment and their own submission. */
	public function studentView(int $assignmentId): array
	{
		$a = Policy::assignmentForStudent($assignmentId, $this->uid());
		$meta = $this->db->one(
			'SELECT s.name AS subject_name, s.code AS subject_code, c.name AS class_name, c.division, u.full_name AS teacher_name
			 FROM subjects s, classes c, users u WHERE s.id = :s AND c.id = :c AND u.id = :t',
			['s' => $a['subject_id'], 'c' => $a['class_id'], 't' => $a['teacher_id']],
		);
		$sub = $this->db->one('SELECT * FROM submissions WHERE assignment_id = :a AND student_id = :s', ['a' => $assignmentId, 's' => $this->uid()]);
		$out = [
			'id' => (int) $a['id'], 'title' => $a['title'], 'description' => $a['description'], 'priority' => $a['priority'],
			'status' => $a['status'], 'due_at' => iso_utc($a['due_at']), 'published_at' => iso_utc($a['published_at']),
			'max_marks' => $a['max_marks'] !== null ? (float) $a['max_marks'] : null,
			'requires_submission' => (bool) $a['requires_submission'], 'allow_late' => (bool) $a['allow_late'],
			'allow_resubmission' => (bool) $a['allow_resubmission'], 'max_upload_mb' => (int) $a['max_upload_mb'],
			'allowed_extensions' => json_decode($a['allowed_extensions'], true),
			'attachments' => $this->db->all('SELECT id, original_name, mime_type, size_bytes FROM assignment_attachments WHERE assignment_id = :a ORDER BY id', ['a' => $assignmentId]),
		] + $meta;
		$out['submission'] = $sub ? $this->presentSubmission($sub, true) : null;
		$out['student_status'] = self::studentStatus($a, $sub);
		$out['can_submit'] = $this->canSubmit($a, $sub);
		return $out;
	}

	private function canSubmit(array $a, ?array $s): bool
	{
		if ($a['status'] !== 'published' || !(int) $a['requires_submission']) {
			return false;
		}
		if ($a['due_at'] && now_utc() > $a['due_at'] && !(int) $a['allow_late']) {
			return false;
		}
		return $s === null || in_array($s['status'], ['submitted', 'resubmitted'], true)
			|| ($s['status'] === 'returned' && ((int) $a['allow_resubmission'] || (int) $s['resubmission_requested']));
	}

	/** Status a student sees for one assignment, including derived ones. */
	public static function studentStatus(array $a, ?array $s): string
	{
		if ($s) {
			return $s['status'];
		}
		if (!(int) $a['requires_submission']) {
			return 'no_submission';
		}
		return $a['due_at'] && now_utc() > $a['due_at'] ? 'missing' : 'pending';
	}

	// ================================================================== teacher

	public function teacherView(int $submissionId): array
	{
		$s = Policy::submissionFor($submissionId, $this->user);
		$student = $this->db->one('SELECT u.full_name, u.email, st.admission_no, st.roll_no FROM users u JOIN students st ON st.user_id = u.id WHERE u.id = :id', ['id' => $s['student_id']]);
		$out = $this->presentSubmission($s, false) + ['student' => $student];
		$out['history'] = $this->db->all(
			'SELECT h.old_status, h.new_status, h.old_marks, h.new_marks, h.note, h.created_at, u.full_name AS by_name
			 FROM grading_history h JOIN users u ON u.id = h.changed_by WHERE h.submission_id = :s ORDER BY h.id',
			['s' => $submissionId],
		);
		foreach ($out['history'] as &$h) {
			$h['created_at'] = iso_utc($h['created_at']);
		}
		return $out;
	}

	/**
	 * $action: review | grade | return. Grade takes marks (+ optional feedback, release);
	 * return requires feedback and reopens the work for a new attempt.
	 */
	public function teacherAction(int $submissionId, string $action, array $input): array
	{
		$s = Policy::submissionFor($submissionId, $this->user);
		if ($this->user['role'] !== 'teacher') {
			throw new HttpException(403);
		}
		$a = $this->db->one('SELECT * FROM assignments WHERE id = :id', ['id' => $s['assignment_id']]);
		$to = self::TEACHER_TRANSITIONS[$action][$s['status']] ?? null;
		if ($to === null) {
			throw new HttpException(409, "Cannot $action a submission that is " . str_replace('_', ' ', $s['status']) . '.');
		}
		$d = Validator::make($input, [
			'marks' => 'nullable|numeric|min:0|max:1000',
			'feedback' => 'nullable|string|max:5000',
			'release' => 'bool',
		]);
		if ($action === 'grade') {
			if ($a['max_marks'] !== null && ($d['marks'] ?? null) === null) {
				throw HttpException::validation(['marks' => 'Enter marks.']);
			}
			if ($a['max_marks'] !== null && $d['marks'] > (float) $a['max_marks']) {
				throw HttpException::validation(['marks' => 'Marks cannot exceed ' . (float) $a['max_marks'] . '.']);
			}
		}
		if ($action === 'return' && empty($d['feedback'])) {
			throw HttpException::validation(['feedback' => 'Explain what needs to change.']);
		}

		$release = $action === 'return' || ($action === 'grade' && ($d['release'] ?? true));
		$this->db->transaction(function (Database $db) use ($s, $a, $action, $to, $d, $release) {
			$fields = ['status' => $to];
			if ($action === 'grade') {
				$fields += ['marks' => $d['marks'] ?? null, 'graded_by' => $this->uid(), 'graded_at' => now_utc(), 'grade_released' => (int) $release];
			}
			if ($action === 'return') {
				$fields += ['resubmission_requested' => 0, 'grade_released' => 0];
			}
			$db->update('submissions', $fields, 'id = :id AND status = :from', ['id' => $s['id'], 'from' => $s['status']]);
			if (!empty($d['feedback'])) {
				$db->insert('submission_feedback', ['submission_id' => $s['id'], 'author_id' => $this->uid(), 'attempt' => $s['attempt'], 'comment' => $d['feedback'], 'is_released' => (int) $release]);
			}
			if ($release) {
				$db->run('UPDATE submission_feedback SET is_released = 1 WHERE submission_id = :s AND attempt = :n', ['s' => $s['id'], 'n' => $s['attempt']]);
			}
			$db->insert('grading_history', [
				'submission_id' => $s['id'], 'changed_by' => $this->uid(), 'old_status' => $s['status'], 'new_status' => $to,
				'old_marks' => $s['marks'], 'new_marks' => $action === 'grade' ? ($d['marks'] ?? null) : $s['marks'],
				'note' => $action === 'grade' ? ($release ? 'Grade released' : 'Grade saved (not released)') : ($action === 'return' ? 'Returned for revision' : 'Review started'),
			]);
		});

		if ($action === 'grade' && $release) {
			NotificationService::send([(int) $s['student_id']], 'feedback', 'Graded: ' . $a['title'],
				($d['marks'] ?? null) !== null ? 'Marks: ' . (float) $d['marks'] . ($a['max_marks'] !== null ? ' / ' . (float) $a['max_marks'] : '') : 'Your work has been reviewed.',
				'assignment', (int) $a['id'], "/student/assignments/{$a['id']}", "graded:{$s['id']}:{$s['attempt']}:" . ($d['marks'] ?? 'x'));
		} elseif ($action === 'return') {
			NotificationService::send([(int) $s['student_id']], 'returned', 'Returned for revision: ' . $a['title'],
				mb_substr($d['feedback'], 0, 300), 'assignment', (int) $a['id'], "/student/assignments/{$a['id']}", "returned:{$s['id']}:{$s['attempt']}");
		}
		return $this->teacherView((int) $s['id']);
	}

	/** Release a grade saved earlier without releasing. */
	public function releaseGrade(int $submissionId): void
	{
		$s = Policy::submissionFor($submissionId, $this->user);
		if ($s['status'] !== 'graded') {
			throw new HttpException(409, 'Only graded work can be released.');
		}
		$this->teacherAction($submissionId, 'grade', ['marks' => $s['marks'], 'release' => true]);
	}

	// ================================================================== shared

	private function presentSubmission(array $s, bool $forStudent): array
	{
		$files = $this->db->all('SELECT id, attempt, original_name, mime_type, size_bytes, created_at FROM submission_files WHERE submission_id = :s ORDER BY attempt DESC, id', ['s' => $s['id']]);
		foreach ($files as &$f) {
			$f['created_at'] = iso_utc($f['created_at']);
		}
		$feedbackSql = 'SELECT f.attempt, f.comment, f.created_at, u.full_name AS author, u.role AS author_role FROM submission_feedback f JOIN users u ON u.id = f.author_id WHERE f.submission_id = :s'
			. ($forStudent ? ' AND f.is_released = 1' : '') . ' ORDER BY f.id';
		$feedback = $this->db->all($feedbackSql, ['s' => $s['id']]);
		foreach ($feedback as &$fb) {
			$fb['created_at'] = iso_utc($fb['created_at']);
		}
		$showMarks = !$forStudent || (int) $s['grade_released'] === 1;
		return [
			'id' => (int) $s['id'],
			'assignment_id' => (int) $s['assignment_id'],
			'status' => $forStudent && $s['status'] === 'graded' && !(int) $s['grade_released'] ? 'under_review' : $s['status'],
			'attempt' => (int) $s['attempt'],
			'submitted_at' => iso_utc($s['submitted_at']),
			'first_submitted_at' => iso_utc($s['first_submitted_at']),
			'is_late' => (bool) $s['is_late'],
			'student_note' => $s['student_note'],
			'marks' => $showMarks && $s['marks'] !== null ? (float) $s['marks'] : null,
			'grade_released' => (bool) $s['grade_released'],
			'resubmission_requested' => (bool) $s['resubmission_requested'],
			'graded_at' => $showMarks ? iso_utc($s['graded_at']) : null,
			'files' => $files,
			'feedback' => $feedback,
		];
	}
}
