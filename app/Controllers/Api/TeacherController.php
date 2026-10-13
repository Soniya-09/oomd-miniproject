<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Repositories\Recipients;
use App\Services\AssignmentService;
use App\Services\Policy;
use App\Services\SubmissionService;

/** Teacher JSON API. Scope = rows in teacher_assignments for the logged-in teacher. */
final class TeacherController
{
	private function assignments(): AssignmentService
	{
		return new AssignmentService(Auth::user());
	}

	/** Classes and subjects this teacher teaches, with student counts. */
	public function classes(Request $request): Response
	{
		$rows = Database::get()->all(
			'SELECT ta.class_id, ta.subject_id, c.name AS class_name, c.division, s.name AS subject_name, s.code AS subject_code,
				(SELECT COUNT(*) FROM class_students cs WHERE cs.class_id = c.id) AS student_count,
				(SELECT COUNT(*) FROM assignments a WHERE a.class_id = c.id AND a.subject_id = s.id AND a.status = \'published\') AS active_assignments
			 FROM teacher_assignments ta JOIN classes c ON c.id = ta.class_id JOIN subjects s ON s.id = ta.subject_id
			 WHERE ta.teacher_id = :t AND c.is_active = 1 ORDER BY c.name, c.division, s.name',
			['t' => Auth::id()],
		);
		return Response::json(['data' => $rows]);
	}

	public function classStudents(Request $request): Response
	{
		$classId = $request->intParam('id');
		if (!Policy::teachesClass(Auth::id(), $classId)) {
			throw new HttpException(404);
		}
		$rows = Database::get()->all(
			'SELECT u.id, u.full_name, st.admission_no, st.roll_no,
				(SELECT COUNT(*) FROM ' . Recipients::SQL . ' r JOIN assignments a ON a.id = r.assignment_id
					JOIN teacher_assignments ta ON ta.class_id = a.class_id AND ta.subject_id = a.subject_id AND ta.teacher_id = :t1
					WHERE r.student_id = u.id AND a.requires_submission = 1 AND a.status IN (\'published\', \'closed\') AND a.due_at < :now1
					AND NOT EXISTS (SELECT 1 FROM submissions sb WHERE sb.assignment_id = a.id AND sb.student_id = u.id)) AS missing
			 FROM class_students cs JOIN users u ON u.id = cs.student_id JOIN students st ON st.user_id = u.id
			 WHERE cs.class_id = :c AND u.is_active = 1 ORDER BY st.roll_no + 0, u.full_name',
			['c' => $classId, 't1' => Auth::id(), 'now1' => now_utc()],
		);
		return Response::json(['data' => $rows]);
	}

	// --- Assignments ----------------------------------------------------------

	public function index(Request $request): Response
	{
		$f = Validator::make($request->query, [
			'class_id' => 'int|min:1', 'subject_id' => 'int|min:1', 'status' => 'in:draft,published,closed,cancelled', 'q' => 'string|max:100',
		]);
		$order = Paginator::order($request, ['due' => 'a.due_at', 'created' => 'a.created_at', 'title' => 'a.title'], 'a.created_at DESC');
		$result = $this->assignments()->list(new Paginator($request), $f, $order);
		foreach ($result['data'] as &$a) {
			$a['due_at'] = iso_utc($a['due_at']);
			$a['published_at'] = iso_utc($a['published_at']);
			$a['created_at'] = iso_utc($a['created_at']);
		}
		return Response::json($result);
	}

	public function show(Request $request): Response
	{
		$a = $this->assignments()->detail($request->intParam('id'));
		foreach (['due_at', 'published_at', 'closed_at', 'created_at', 'updated_at'] as $k) {
			$a[$k] = iso_utc($a[$k]);
		}
		return Response::json(['data' => $a]);
	}

	public function store(Request $request): Response
	{
		$id = $this->assignments()->create($request->input());
		return Response::json(['data' => ['id' => $id]], 201);
	}

	public function update(Request $request): Response
	{
		$this->assignments()->update($request->intParam('id'), $request->input());
		return Response::json(['ok' => true]);
	}

	public function transition(Request $request): Response
	{
		$action = $request->param('action');
		if (!isset(AssignmentService::TRANSITIONS[$action])) {
			throw new HttpException(404);
		}
		$status = $this->assignments()->transition($request->intParam('id'), $action);
		return Response::json(['ok' => true, 'status' => $status]);
	}

	public function uploadAttachments(Request $request): Response
	{
		$ids = $this->assignments()->addAttachments($request->intParam('id'), $request->uploadedFiles('files'));
		return Response::json(['data' => ['ids' => $ids]], 201);
	}

	public function deleteAttachment(Request $request): Response
	{
		$this->assignments()->removeAttachment($request->intParam('id'), $request->intParam('attachmentId'));
		return Response::json(['ok' => true]);
	}

	// --- Submissions ----------------------------------------------------------

	/** Review queue across all assignments the teacher can see. */
	public function queue(Request $request): Response
	{
		$f = Validator::make($request->query, [
			'status' => 'in:submitted,resubmitted,under_review,graded,returned,to_review', 'assignment_id' => 'int|min:1', 'class_id' => 'int|min:1', 'q' => 'string|max:100',
		]);
		$where = ['ta.teacher_id = :t'];
		$params = ['t' => Auth::id()];
		if (($f['status'] ?? 'to_review') === 'to_review') {
			$where[] = "sb.status IN ('submitted', 'resubmitted', 'under_review')";
		} else {
			$where[] = 'sb.status = :st';
			$params['st'] = $f['status'];
		}
		foreach (['assignment_id' => 'a.id', 'class_id' => 'a.class_id'] as $k => $col) {
			if (!empty($f[$k])) {
				$where[] = "$col = :$k";
				$params[$k] = $f[$k];
			}
		}
		if (!empty($f['q'])) {
			$where[] = '(u.full_name LIKE :q1 OR a.title LIKE :q2)';
			$params += ['q1' => Paginator::like($f['q']), 'q2' => Paginator::like($f['q'])];
		}
		$result = (new Paginator($request))->run(
			'SELECT sb.id, sb.status, sb.attempt, sb.submitted_at, sb.is_late, sb.marks, sb.grade_released, sb.resubmission_requested,
				a.id AS assignment_id, a.title, a.max_marks, a.due_at, u.full_name AS student_name, st.admission_no,
				c.name AS class_name, c.division, s.name AS subject_name',
			'FROM submissions sb
			 JOIN assignments a ON a.id = sb.assignment_id
			 JOIN teacher_assignments ta ON ta.class_id = a.class_id AND ta.subject_id = a.subject_id
			 JOIN users u ON u.id = sb.student_id JOIN students st ON st.user_id = u.id
			 JOIN classes c ON c.id = a.class_id JOIN subjects s ON s.id = a.subject_id
			 WHERE ' . implode(' AND ', $where),
			$params,
			Paginator::order($request, ['submitted' => 'sb.submitted_at', 'student' => 'u.full_name', 'due' => 'a.due_at'], 'sb.submitted_at ASC'),
		);
		foreach ($result['data'] as &$r) {
			$r['submitted_at'] = iso_utc($r['submitted_at']);
			$r['due_at'] = iso_utc($r['due_at']);
		}
		return Response::json($result);
	}

	public function submission(Request $request): Response
	{
		return Response::json(['data' => (new SubmissionService(Auth::user()))->teacherView($request->intParam('id'))]);
	}

	public function submissionAction(Request $request): Response
	{
		$action = $request->param('action');
		$svc = new SubmissionService(Auth::user());
		if ($action === 'release') {
			$svc->releaseGrade($request->intParam('id'));
			return Response::json(['data' => $svc->teacherView($request->intParam('id'))]);
		}
		if (!isset(SubmissionService::TEACHER_TRANSITIONS[$action])) {
			throw new HttpException(404);
		}
		return Response::json(['data' => $svc->teacherAction($request->intParam('id'), $action, $request->input())]);
	}
}
