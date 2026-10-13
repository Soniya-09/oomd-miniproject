<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Repositories\Recipients;
use App\Services\AnalyticsService;
use App\Services\AuditLog;

/**
 * Analytics JSON for teachers/admins and CSV exports. Teachers are limited to
 * the classes/subjects they teach; admins to their institution.
 */
final class ReportController
{
	public function teacherDashboard(Request $request): Response
	{
		return Response::json(['data' => (new AnalyticsService())->teacherDashboard(Auth::id())]);
	}

	public function teacherAnalytics(Request $request): Response
	{
		$class = filter_var($request->query['class_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
		return Response::json(['data' => (new AnalyticsService())->teacherAnalytics(Auth::id(), $class)]);
	}

	public function adminAnalytics(Request $request): Response
	{
		return Response::json(['data' => (new AnalyticsService())->adminAnalytics(Auth::institutionId(), Auth::user()['timezone'])]);
	}

	/**
	 * GET /api/reports/{type}.csv
	 *   submissions — one row per student per assignment (status, timestamps, marks)
	 *   missing     — only rows with no submission after the deadline
	 *   classes     — class-wise completion
	 *   grades      — graded submissions summary per assignment
	 */
	public function export(Request $request): Response
	{
		$type = $request->param('type');
		$f = Validator::make($request->query, ['class_id' => 'int|min:1', 'assignment_id' => 'int|min:1']);
		$db = Database::get();
		$scope = Auth::is('admin')
			? ['a.institution_id = :scope', ['scope' => Auth::institutionId()]]
			: ['EXISTS (SELECT 1 FROM teacher_assignments ta WHERE ta.class_id = a.class_id AND ta.subject_id = a.subject_id AND ta.teacher_id = :scope)', ['scope' => Auth::id()]];
		$where = [$scope[0], "a.status IN ('published', 'closed')"];
		$params = $scope[1];
		if (!empty($f['class_id'])) {
			$where[] = 'a.class_id = :c';
			$params['c'] = $f['class_id'];
		}
		if (!empty($f['assignment_id'])) {
			$where[] = 'a.id = :aid';
			$params['aid'] = $f['assignment_id'];
		}
		$now = now_utc();
		$date = gmdate('Y-m-d');

		switch ($type) {
			case 'submissions':
			case 'missing':
				$sql = "SELECT a.title, s.name AS subject, TRIM(CONCAT(c.name, ' ', c.division)) AS class, a.due_at,
						st.admission_no, u.full_name, sb.status, sb.attempt, sb.first_submitted_at, sb.submitted_at, sb.is_late, sb.marks, a.max_marks
					FROM assignments a JOIN subjects s ON s.id = a.subject_id JOIN classes c ON c.id = a.class_id
					JOIN " . Recipients::SQL . " r ON r.assignment_id = a.id
					JOIN users u ON u.id = r.student_id JOIN students st ON st.user_id = u.id
					LEFT JOIN submissions sb ON sb.assignment_id = a.id AND sb.student_id = r.student_id
					WHERE " . implode(' AND ', $where) . ' AND a.requires_submission = 1'
					. ($type === 'missing' ? ' AND sb.id IS NULL AND a.due_at < :now' : '')
					. ' ORDER BY a.due_at, a.id, u.full_name';
				if ($type === 'missing') {
					$params['now'] = $now;
				}
				$rows = $db->all($sql, $params);
				$out = array_map(fn($r) => [
					$r['title'], $r['subject'], $r['class'], $r['due_at'] ? $r['due_at'] . ' UTC' : '', $r['admission_no'], $r['full_name'],
					$r['status'] ?? ($r['due_at'] && $r['due_at'] < $now ? 'missing' : 'not submitted'), $r['attempt'] ?? '',
					$r['first_submitted_at'] ?? '', $r['submitted_at'] ?? '', $r['status'] ? ((int) $r['is_late'] ? 'late' : 'on time') : '',
					$r['marks'] ?? '', $r['max_marks'] ?? '',
				], $rows);
				$header = ['Assignment', 'Subject', 'Class', 'Due', 'Admission no', 'Student', 'Status', 'Attempt', 'First submitted (UTC)', 'Last submitted (UTC)', 'Timeliness', 'Marks', 'Max marks'];
				break;

			case 'classes':
				$params['now2'] = $now;
				$rows = $db->all(
					"SELECT TRIM(CONCAT(c.name, ' ', c.division)) AS class, s.name AS subject, COUNT(DISTINCT a.id) AS assignments,
						COUNT(r.student_id) AS expected, COUNT(sb.id) AS submitted, SUM(sb.is_late = 1) AS late
					 FROM assignments a JOIN classes c ON c.id = a.class_id JOIN subjects s ON s.id = a.subject_id
					 JOIN " . Recipients::SQL . " r ON r.assignment_id = a.id
					 LEFT JOIN submissions sb ON sb.assignment_id = a.id AND sb.student_id = r.student_id
					 WHERE " . implode(' AND ', $where) . ' AND a.requires_submission = 1 AND a.due_at <= :now2
					 GROUP BY c.id, s.id ORDER BY class, subject',
					$params,
				);
				$out = array_map(fn($r) => [$r['class'], $r['subject'], $r['assignments'], $r['expected'], $r['submitted'], (int) $r['late'], (string) (AnalyticsService::rate((int) $r['submitted'], (int) $r['expected']) ?? '')], $rows);
				$header = ['Class', 'Subject', 'Assignments due', 'Expected submissions', 'Submitted', 'Late', 'Completion %'];
				break;

			case 'grades':
				$rows = $db->all(
					"SELECT a.title, s.name AS subject, TRIM(CONCAT(c.name, ' ', c.division)) AS class, a.max_marks,
						COUNT(sb.id) AS graded, AVG(sb.marks) AS avg_marks, MIN(sb.marks) AS min_marks, MAX(sb.marks) AS max_given
					 FROM assignments a JOIN classes c ON c.id = a.class_id JOIN subjects s ON s.id = a.subject_id
					 LEFT JOIN submissions sb ON sb.assignment_id = a.id AND sb.status = 'graded'
					 WHERE " . implode(' AND ', $where) . ' GROUP BY a.id ORDER BY a.due_at',
					$params,
				);
				$out = array_map(fn($r) => [$r['title'], $r['subject'], $r['class'], $r['max_marks'] ?? '', $r['graded'], $r['avg_marks'] !== null ? round((float) $r['avg_marks'], 2) : '', $r['min_marks'] ?? '', $r['max_given'] ?? ''], $rows);
				$header = ['Assignment', 'Subject', 'Class', 'Max marks', 'Graded', 'Average', 'Lowest', 'Highest'];
				break;

			default:
				throw new HttpException(404);
		}
		AuditLog::record('report.exported', null, null, ['type' => $type, 'rows' => count($out)] + $f);
		return Response::csv("eduflow-$type-$date.csv", $header, $out);
	}
}
