<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Repositories\Recipients;
use App\Services\SubmissionService;

/** Student assignment API. Only published/closed assignments addressed to the student. */
final class StudentController
{
	public function assignments(Request $request): Response
	{
		$f = Validator::make($request->query, [
			'subject_id' => 'int|min:1',
			'status' => 'in:pending,missing,submitted,under_review,graded,returned,resubmitted,done,open',
			'q' => 'string|max:100',
		]);
		$sid = Auth::id();
		$where = ["r.student_id = :s", "a.status IN ('published', 'closed')"];
		$params = ['s' => $sid];
		if (!empty($f['subject_id'])) {
			$where[] = 'a.subject_id = :subject';
			$params['subject'] = $f['subject_id'];
		}
		if (!empty($f['q'])) {
			$where[] = 'a.title LIKE :q';
			$params['q'] = Paginator::like($f['q']);
		}
		$now = now_utc();
		$statusSql = [
			'pending' => "sb.id IS NULL AND a.requires_submission = 1 AND (a.due_at IS NULL OR a.due_at >= :now)",
			'missing' => "sb.id IS NULL AND a.requires_submission = 1 AND a.due_at < :now",
			'open' => "(sb.id IS NULL AND a.requires_submission = 1) OR sb.status = 'returned'",
			'done' => "sb.status IN ('submitted', 'resubmitted', 'under_review', 'graded')",
		];
		if (!empty($f['status'])) {
			if (isset($statusSql[$f['status']])) {
				$where[] = '(' . $statusSql[$f['status']] . ')';
				if (str_contains($statusSql[$f['status']], ':now')) {
					$params['now'] = $now;
				}
			} else {
				$where[] = 'sb.status = :st';
				$params['st'] = $f['status'];
			}
		}
		$result = (new Paginator($request))->run(
			'SELECT a.id, a.title, a.priority, a.status AS assignment_status, a.due_at, a.max_marks, a.requires_submission, a.allow_late,
				s.id AS subject_id, s.name AS subject_name, s.code AS subject_code,
				sb.status AS submission_status, sb.submitted_at, sb.is_late, sb.grade_released, IF(sb.grade_released = 1, sb.marks, NULL) AS marks',
			'FROM ' . Recipients::SQL . ' r
			 JOIN assignments a ON a.id = r.assignment_id
			 JOIN subjects s ON s.id = a.subject_id
			 LEFT JOIN submissions sb ON sb.assignment_id = a.id AND sb.student_id = r.student_id
			 WHERE ' . implode(' AND ', $where),
			$params,
			Paginator::order($request, ['due' => 'a.due_at', 'title' => 'a.title', 'priority' => "FIELD(a.priority, 'high', 'medium', 'low')"], 'a.due_at IS NULL, a.due_at ASC'),
		);
		foreach ($result['data'] as &$r) {
			$sub = $r['submission_status'] ? ['status' => $r['submission_status']] : null;
			$status = SubmissionService::studentStatus($r, $sub);
			if ($status === 'graded' && !(int) $r['grade_released']) {
				$status = 'under_review';
			}
			$r['student_status'] = $status;
			$r['due_at'] = iso_utc($r['due_at']);
			$r['submitted_at'] = iso_utc($r['submitted_at']);
			unset($r['submission_status'], $r['grade_released']);
		}
		return Response::json($result);
	}

	/** Subjects taught in the student's classes (for filters). */
	public function subjects(Request $request): Response
	{
		return Response::json(['data' => \App\Core\Database::get()->all(
			'SELECT DISTINCT s.id, s.name, s.code FROM class_students cs
			 JOIN teacher_assignments ta ON ta.class_id = cs.class_id JOIN subjects s ON s.id = ta.subject_id
			 WHERE cs.student_id = :s ORDER BY s.name',
			['s' => Auth::id()],
		)]);
	}

	public function assignment(Request $request): Response
	{
		return Response::json(['data' => (new SubmissionService(Auth::user()))->studentView($request->intParam('id'))]);
	}

	public function submit(Request $request): Response
	{
		$svc = new SubmissionService(Auth::user());
		$svc->submit($request->intParam('id'), $request->uploadedFiles('files'), isset($request->post['note']) ? (string) $request->post['note'] : null);
		return Response::json(['data' => $svc->studentView($request->intParam('id'))], 201);
	}

	public function requestResubmission(Request $request): Response
	{
		$svc = new SubmissionService(Auth::user());
		$svc->requestResubmission($request->intParam('id'), (string) $request->get('reason', ''));
		return Response::json(['ok' => true]);
	}
}
