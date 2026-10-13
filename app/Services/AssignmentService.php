<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Paginator;
use App\Core\Validator;
use App\Repositories\Recipients;

/**
 * Teacher-side assignment lifecycle.
 *
 * Publication states (independent of each student's submission state):
 *   draft ──publish──▶ published ──close──▶ closed
 *     │                   │  ▲                 │
 *     └──cancel──▶ cancelled ◀──cancel──┘  └──reopen──┘
 */
final class AssignmentService
{
	public const TRANSITIONS = [
		'publish' => ['draft' => 'published'],
		'close' => ['published' => 'closed'],
		'reopen' => ['closed' => 'published'],
		'cancel' => ['draft' => 'cancelled', 'published' => 'cancelled'],
	];

	private Database $db;

	public function __construct(private readonly array $teacher)
	{
		$this->db = Database::get();
	}

	private function tid(): int
	{
		return (int) $this->teacher['id'];
	}

	public static function rules(bool $creating): array
	{
		$r = $creating ? 'required|' : '';
		return [
			'title' => $r . 'string|min:3|max:200',
			'description' => $r . 'string|min:1|max:20000',
			'class_id' => $r . 'int|min:1',
			'subject_id' => $r . 'int|min:1',
			'priority' => 'in:low,medium,high',
			'due_at' => 'nullable|datetime',
			'max_marks' => 'nullable|numeric|min:0|max:1000',
			'requires_submission' => 'bool',
			'allowed_extensions' => 'string|max:200',
			'max_upload_mb' => 'int|min:1|max:50',
			'allow_late' => 'bool',
			'allow_resubmission' => 'bool',
			'target_all' => 'bool',
			'student_ids' => 'ids',
			'publish' => 'bool',
		];
	}

	/** "pdf, docx" or ["pdf","docx"] -> validated list. */
	private function extensions(mixed $value): array
	{
		$list = is_array($value) ? $value : preg_split('/[\s,]+/', strtolower((string) $value), -1, PREG_SPLIT_NO_EMPTY);
		$list = array_values(array_unique(array_map(fn($e) => ltrim(strtolower(trim((string) $e)), '.'), $list)));
		$bad = array_diff($list, FileStorage::allowedExtensions());
		if (!$list || $bad) {
			throw HttpException::validation(['allowed_extensions' => 'Choose from: ' . implode(', ', FileStorage::allowedExtensions()) . '.']);
		}
		return $list;
	}

	public function create(array $input): int
	{
		if (isset($input['allowed_extensions']) && is_array($input['allowed_extensions'])) {
			$input['allowed_extensions'] = implode(',', $input['allowed_extensions']);
		}
		$d = Validator::make($input, self::rules(true));
		if (!Policy::teaches($this->tid(), $d['class_id'], $d['subject_id'])) {
			throw HttpException::validation(['subject_id' => 'You do not teach this subject to this class.']);
		}
		$targetAll = $d['target_all'] ?? true;
		$students = $targetAll ? [] : $this->validTargets($d['class_id'], $d['student_ids'] ?? []);
		$publish = (bool) ($d['publish'] ?? false);
		if ($publish) {
			$this->checkPublishable($d);
		}
		$ext = $this->extensions($d['allowed_extensions'] ?? 'pdf,docx');

		$id = $this->db->transaction(function (Database $db) use ($d, $targetAll, $students, $ext) {
			$id = $db->insert('assignments', [
				'institution_id' => (int) $this->teacher['institution_id'],
				'class_id' => $d['class_id'],
				'subject_id' => $d['subject_id'],
				'teacher_id' => $this->tid(),
				'title' => $d['title'],
				'description' => $d['description'],
				'priority' => $d['priority'] ?? 'medium',
				'due_at' => $d['due_at'] ?? null,
				'max_marks' => $d['max_marks'] ?? null,
				'requires_submission' => (int) ($d['requires_submission'] ?? true),
				'allowed_extensions' => json_encode($ext),
				'max_upload_mb' => $d['max_upload_mb'] ?? 10,
				'allow_late' => (int) ($d['allow_late'] ?? true),
				'allow_resubmission' => (int) ($d['allow_resubmission'] ?? true),
				'target_all' => (int) $targetAll,
			]);
			foreach ($students as $s) {
				$db->insert('assignment_targets', ['assignment_id' => $id, 'student_id' => $s]);
			}
			return $id;
		});
		if ($publish) {
			$this->transition($id, 'publish');
		}
		return $id;
	}

	public function update(int $id, array $input): void
	{
		$a = Policy::assignmentForTeacher($id, $this->tid());
		if (in_array($a['status'], ['cancelled'], true)) {
			throw new HttpException(409, 'Cancelled assignments cannot be edited.');
		}
		if (isset($input['allowed_extensions']) && is_array($input['allowed_extensions'])) {
			$input['allowed_extensions'] = implode(',', $input['allowed_extensions']);
		}
		$d = Validator::make($input, self::rules(false));
		unset($d['publish']);
		$hasSubmissions = (bool) $this->db->value('SELECT 1 FROM submissions WHERE assignment_id = :a LIMIT 1', ['a' => $id]);

		$classId = $d['class_id'] ?? (int) $a['class_id'];
		$subjectId = $d['subject_id'] ?? (int) $a['subject_id'];
		if (($classId !== (int) $a['class_id'] || $subjectId !== (int) $a['subject_id'])) {
			if ($a['status'] !== 'draft') {
				throw HttpException::validation(['class_id' => 'Class and subject can only be changed while the assignment is a draft.']);
			}
			if (!Policy::teaches($this->tid(), $classId, $subjectId)) {
				throw HttpException::validation(['subject_id' => 'You do not teach this subject to this class.']);
			}
		}
		if ($hasSubmissions && array_key_exists('requires_submission', $d) && !$d['requires_submission']) {
			throw HttpException::validation(['requires_submission' => 'Students have already submitted work.']);
		}
		if ($a['status'] !== 'draft') {
			$this->checkPublishable($d + $a);
		}

		$fields = array_intersect_key($d, array_flip(['title', 'description', 'class_id', 'subject_id', 'priority', 'due_at', 'max_marks', 'max_upload_mb']));
		foreach (['requires_submission', 'allow_late', 'allow_resubmission', 'target_all'] as $flag) {
			if (array_key_exists($flag, $d)) {
				$fields[$flag] = (int) $d[$flag];
			}
		}
		if (array_key_exists('allowed_extensions', $d)) {
			$fields['allowed_extensions'] = json_encode($this->extensions($d['allowed_extensions']));
		}
		$targetAll = array_key_exists('target_all', $d) ? $d['target_all'] : (bool) $a['target_all'];
		$newTargets = null;
		if (!$targetAll && (array_key_exists('student_ids', $d) || array_key_exists('target_all', $d))) {
			$newTargets = $this->validTargets($classId, $d['student_ids'] ?? []);
		}

		$dueChanged = array_key_exists('due_at', $d) && $d['due_at'] !== $a['due_at'];
		$this->db->transaction(function (Database $db) use ($id, $fields, $targetAll, $newTargets) {
			if ($fields) {
				$db->update('assignments', $fields, 'id = :id', ['id' => $id]);
			}
			if ($targetAll) {
				$db->run('DELETE FROM assignment_targets WHERE assignment_id = :a', ['a' => $id]);
			} elseif ($newTargets !== null) {
				$db->run('DELETE FROM assignment_targets WHERE assignment_id = :a', ['a' => $id]);
				foreach ($newTargets as $s) {
					$db->insert('assignment_targets', ['assignment_id' => $id, 'student_id' => $s]);
				}
			}
		});

		if ($a['status'] === 'published') {
			// Students newly added to the audience get the "new assignment" notice (dedupe skips the rest).
			$this->notifyPublished($id);
			if ($dueChanged) {
				$due = $d['due_at'] ?? null;
				NotificationService::send(
					$this->recipientIds($id),
					'deadline_changed',
					'Deadline changed: ' . ($d['title'] ?? $a['title']),
					$due ? 'The new deadline is ' . $due . ' UTC.' : 'The deadline was removed.',
					'assignment', $id, "/student/assignments/$id",
					"assignment:$id:due:" . ($due ?? 'none'),
				);
			}
		}
	}

	public function transition(int $id, string $action): string
	{
		$a = Policy::assignmentForTeacher($id, $this->tid());
		$to = self::TRANSITIONS[$action][$a['status']] ?? null;
		if ($to === null) {
			throw new HttpException(409, "Cannot $action an assignment that is {$a['status']}.");
		}
		if ($to === 'published') {
			$this->checkPublishable($a);
		}
		$fields = ['status' => $to];
		if ($action === 'publish' && !$a['published_at']) {
			$fields['published_at'] = now_utc();
		}
		if ($to === 'closed') {
			$fields['closed_at'] = now_utc();
		}
		$this->db->update('assignments', $fields, 'id = :id AND status = :from', ['id' => $id, 'from' => $a['status']]);
		AuditLog::record("assignment.$action", 'assignment', $id, ['from' => $a['status'], 'to' => $to]);

		if ($to === 'published') {
			$this->notifyPublished($id);
		} elseif ($to === 'cancelled' && $a['status'] === 'published') {
			NotificationService::send($this->recipientIds($id), 'assignment_cancelled', 'Assignment cancelled: ' . $a['title'],
				'Your teacher cancelled this assignment. No submission is needed.', 'assignment', $id, null, "assignment:$id:cancelled");
		}
		return $to;
	}

	private function checkPublishable(array $a): void
	{
		$requires = (bool) ($a['requires_submission'] ?? true);
		$due = $a['due_at'] ?? null;
		if ($requires && !$due) {
			throw HttpException::validation(['due_at' => 'Set a due date before publishing an assignment that needs a submission.']);
		}
		if (!(bool) ($a['target_all'] ?? true) && isset($a['id']) && !$this->recipientIds((int) $a['id'])) {
			throw HttpException::validation(['student_ids' => 'Pick at least one student.']);
		}
	}

	private function notifyPublished(int $id): void
	{
		$a = $this->db->one(
			'SELECT a.title, a.due_at, s.name AS subject FROM assignments a JOIN subjects s ON s.id = a.subject_id WHERE a.id = :id',
			['id' => $id],
		);
		NotificationService::send(
			$this->recipientIds($id),
			'assignment_new',
			'New assignment: ' . $a['title'],
			$a['subject'] . ($a['due_at'] ? ' · due ' . $a['due_at'] . ' UTC' : ''),
			'assignment', $id, "/student/assignments/$id",
			"assignment:$id:published",
		);
	}

	public function recipientIds(int $id): array
	{
		return array_map('intval', array_column($this->db->all(
			'SELECT r.student_id FROM ' . Recipients::SQL . ' r JOIN users u ON u.id = r.student_id AND u.is_active = 1 WHERE r.assignment_id = :a',
			['a' => $id],
		), 'student_id'));
	}

	private function validTargets(int $classId, array $ids): array
	{
		if (!$ids) {
			throw HttpException::validation(['student_ids' => 'Pick at least one student, or target the whole class.']);
		}
		[$in, $p] = Database::inList($ids);
		$n = (int) $this->db->value("SELECT COUNT(*) FROM class_students WHERE class_id = :c AND student_id IN $in", ['c' => $classId] + $p);
		if ($n !== count(array_unique($ids))) {
			throw HttpException::validation(['student_ids' => 'Every selected student must be enrolled in the class.']);
		}
		return array_values(array_unique($ids));
	}

	// --- Attachments ----------------------------------------------------------

	public function addAttachments(int $id, array $files): array
	{
		$a = Policy::assignmentForTeacher($id, $this->tid());
		if ($a['status'] === 'cancelled') {
			throw new HttpException(409, 'Cancelled assignments cannot be changed.');
		}
		if (!$files) {
			throw HttpException::validation(['files' => 'Choose at least one file.']);
		}
		$added = [];
		foreach ($files as $f) {
			$meta = FileStorage::store($f, FileStorage::allowedExtensions(), (int) config('uploads.max_mb'));
			$added[] = $this->db->insert('assignment_attachments', ['assignment_id' => $id, 'uploaded_by' => $this->tid()] + $meta);
		}
		return $added;
	}

	public function removeAttachment(int $id, int $attachmentId): void
	{
		Policy::assignmentForTeacher($id, $this->tid());
		$row = $this->db->one('SELECT * FROM assignment_attachments WHERE id = :id AND assignment_id = :a', ['id' => $attachmentId, 'a' => $id]) ?? throw new HttpException(404);
		$this->db->run('DELETE FROM assignment_attachments WHERE id = :id', ['id' => $attachmentId]);
		FileStorage::delete($row['stored_name']);
	}

	// --- Reads ----------------------------------------------------------------

	public function list(Paginator $p, array $f, string $orderBy): array
	{
		$where = ['ta.teacher_id = :t'];
		$params = ['t' => $this->tid()];
		foreach (['class_id' => 'a.class_id', 'subject_id' => 'a.subject_id', 'status' => 'a.status'] as $k => $col) {
			if (!empty($f[$k])) {
				$where[] = "$col = :$k";
				$params[$k] = $f[$k];
			}
		}
		if (!empty($f['q'])) {
			$where[] = 'a.title LIKE :q';
			$params['q'] = Paginator::like($f['q']);
		}
		return $p->run(
			'SELECT a.id, a.title, a.status, a.priority, a.due_at, a.published_at, a.max_marks, a.requires_submission, a.target_all, a.created_at,
				c.id AS class_id, c.name AS class_name, c.division, s.id AS subject_id, s.name AS subject_name, s.code AS subject_code,
				(SELECT COUNT(*) FROM ' . Recipients::SQL . ' r WHERE r.assignment_id = a.id) AS recipients,
				(SELECT COUNT(*) FROM submissions sb WHERE sb.assignment_id = a.id) AS submitted,
				(SELECT COUNT(*) FROM submissions sb WHERE sb.assignment_id = a.id AND sb.status IN (\'submitted\', \'resubmitted\', \'under_review\')) AS to_review',
			'FROM assignments a
			 JOIN teacher_assignments ta ON ta.class_id = a.class_id AND ta.subject_id = a.subject_id
			 JOIN classes c ON c.id = a.class_id JOIN subjects s ON s.id = a.subject_id
			 WHERE ' . implode(' AND ', $where),
			$params,
			$orderBy,
		);
	}

	public function detail(int $id): array
	{
		$a = Policy::assignmentForTeacher($id, $this->tid());
		$meta = $this->db->one(
			'SELECT c.name AS class_name, c.division, s.name AS subject_name, s.code AS subject_code FROM classes c, subjects s WHERE c.id = :c AND s.id = :s',
			['c' => $a['class_id'], 's' => $a['subject_id']],
		);
		$a += $meta;
		$a['allowed_extensions'] = json_decode($a['allowed_extensions'], true);
		$a['attachments'] = $this->db->all('SELECT id, original_name, mime_type, size_bytes, created_at FROM assignment_attachments WHERE assignment_id = :a ORDER BY id', ['a' => $id]);
		$a['target_student_ids'] = array_map('intval', array_column($this->db->all('SELECT student_id FROM assignment_targets WHERE assignment_id = :a', ['a' => $id]), 'student_id'));
		$a['roster'] = $this->roster($a);
		return $a;
	}

	/** One row per recipient with their submission state (or not submitted / missing). */
	public function roster(array $a): array
	{
		$rows = $this->db->all(
			'SELECT u.id AS student_id, u.full_name, st.admission_no, st.roll_no,
				sb.id AS submission_id, sb.status, sb.attempt, sb.submitted_at, sb.is_late, sb.marks, sb.grade_released, sb.resubmission_requested,
				(SELECT COUNT(*) FROM submission_files f WHERE f.submission_id = sb.id AND f.attempt = sb.attempt) AS file_count
			 FROM ' . Recipients::SQL . ' r
			 JOIN users u ON u.id = r.student_id JOIN students st ON st.user_id = u.id
			 LEFT JOIN submissions sb ON sb.assignment_id = r.assignment_id AND sb.student_id = r.student_id
			 WHERE r.assignment_id = :a ORDER BY st.roll_no + 0, u.full_name',
			['a' => $a['id']],
		);
		$now = now_utc();
		foreach ($rows as &$r) {
			if ($r['status'] === null) {
				$r['status'] = ($a['due_at'] && $a['due_at'] < $now && $a['requires_submission']) ? 'missing' : 'not_submitted';
			}
			$r['submitted_at'] = iso_utc($r['submitted_at']);
		}
		return $rows;
	}
}
