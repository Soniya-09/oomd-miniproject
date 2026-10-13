<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Repositories\Recipients;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Every dashboard number comes from here, computed from database rows with the
 * definitions in docs/METRICS.md. Nothing is hard-coded.
 */
final class AnalyticsService
{
	private Database $db;

	public function __construct()
	{
		$this->db = Database::get();
	}

	// =====================================================================
	// Student
	// =====================================================================

	public function studentDashboard(int $studentId, string $tz = 'UTC'): array
	{
		$items = StudentWork::eligible((new StudentWork($studentId))->items());
		$now = now_utc();
		$soon = gmdate('Y-m-d H:i:s', time() + 72 * 3600);
		$count = fn(callable $f) => count(array_filter($items, $f));

		$open = array_values(array_filter($items, [StudentWork::class, 'isOpen']));
		usort($open, [StudentWork::class, 'byUrgency']);
		$upcoming = array_values(array_filter($open, fn($i) => $i['due_at'] !== null));

		$sub = $this->submissionPerformance(array_filter($items, fn($i) => $i['kind'] === 'academic' && $i['due_at'] !== null && $i['due_at'] <= $now));
		$completed = $count(fn($i) => $i['status'] === 'completed');

		return [
			'summary' => [
				'active' => count($open),
				'completed' => $completed,
				'pending' => $count(fn($i) => in_array($i['status'], ['pending', 'in_progress'], true)),
				'in_progress' => $count(fn($i) => $i['status'] === 'in_progress'),
				'overdue' => $count(fn($i) => $i['status'] === 'overdue'),
				'due_soon' => $count(fn($i) => $i['kind'] === 'academic' && StudentWork::isOpen($i) && $i['due_at'] !== null && $i['due_at'] >= $now && $i['due_at'] <= $soon),
				'completion_rate' => self::rate($completed, count($items)),
				'on_time_rate' => $sub['on_time_rate'],
			],
			'upcoming' => array_map([StudentWork::class, 'present'], array_slice($upcoming, 0, 8)),
			'suggestion' => $open ? StudentWork::present($open[0]) : null,
			'insights' => $this->insights($items, new DateTimeZone($tz)),
		];
	}

	/**
	 * @param array $f period (today|week|month|custom), from/to (Y-m-d, custom), kind (all|academic|personal), granularity (day|week|month)
	 */
	public function studentProgress(int $studentId, string $tz, array $f): array
	{
		$zone = new DateTimeZone($tz);
		[$start, $end] = self::periodRange($f['period'] ?? 'week', $f['from'] ?? null, $f['to'] ?? null, $zone);
		$days = (int) $start->diff($end)->days;
		$gran = $f['granularity'] ?? ($days > 62 ? 'week' : 'day');
		$buckets = self::buckets($start, $end, $gran);
		$s = self::utc($start);
		$e = self::utc($end);
		$now = now_utc();

		$all = StudentWork::eligible((new StudentWork($studentId))->items());
		$kind = $f['kind'] ?? 'all';
		$items = $kind === 'all' ? $all : array_values(array_filter($all, fn($i) => $i['kind'] === $kind));

		// A: completed per bucket.  C: same, split by kind.
		$trend = [];
		$byKind = ['academic' => [], 'personal' => []];
		foreach ($buckets as [$label, $bs, $be]) {
			$in = fn($i) => $i['completed_at'] !== null && $i['completed_at'] >= $bs && $i['completed_at'] < $be;
			$trend[] = count(array_filter($items, $in));
			foreach (['academic', 'personal'] as $k) {
				$byKind[$k][] = count(array_filter($items, fn($i) => $i['kind'] === $k && $in($i)));
			}
		}

		// Items "in" the period: due in it, completed in it, or (no deadline) created in it.
		$scoped = array_values(array_filter($items, fn($i) => self::inRange($i['due_at'], $s, $e) || self::inRange($i['completed_at'], $s, $e)
			|| ($i['due_at'] === null && self::inRange($i['created_at'], $s, $e))));

		// B: status breakdown.
		$status = ['completed' => 0, 'pending' => 0, 'in_progress' => 0, 'overdue' => 0];
		foreach ($scoped as $i) {
			$status[$i['status']]++;
		}

		// D: subject-wise progress (academic only).
		$subjects = [];
		foreach ($scoped as $i) {
			if ($i['kind'] !== 'academic') {
				continue;
			}
			$g = &$subjects[$i['subject_id']];
			$g ??= ['subject_id' => $i['subject_id'], 'subject' => $i['group'], 'assigned' => 0, 'completed' => 0, 'outstanding' => 0];
			$g['assigned']++;
			$i['status'] === 'completed' ? $g['completed']++ : $g['outstanding']++;
			unset($g);
		}
		$subjects = array_values(array_map(fn($g) => $g + ['rate' => self::rate($g['completed'], $g['assigned'])], $subjects));
		usort($subjects, fn($a, $b) => strcmp($a['subject'], $b['subject']));

		// E: submission performance for assignments due in the period whose deadline has passed.
		$sub = $this->submissionPerformance(array_filter($all, fn($i) => $i['kind'] === 'academic' && self::inRange($i['due_at'], $s, $e) && $i['due_at'] <= $now));

		$completed = $status['completed'];
		return [
			'range' => ['from' => iso_utc($s), 'to' => iso_utc($e), 'granularity' => $gran, 'kind' => $kind],
			'labels' => array_column($buckets, 0),
			'bucket_starts' => array_map(fn($b) => iso_utc($b[1]), $buckets),
			'bucket_ends' => array_map(fn($b) => iso_utc($b[2]), $buckets),
			'trend' => $trend,
			'by_kind' => $byKind,
			'status' => $status,
			'subjects' => $subjects,
			'submissions' => $sub,
			'metrics' => [
				'scoped' => count($scoped),
				'completed' => $completed,
				'completion_rate' => self::rate($completed, count($scoped)),
				'on_time_rate' => $sub['on_time_rate'],
				'pending_workload' => $status['pending'] + $status['in_progress'] + $status['overdue'],
				'overdue' => $status['overdue'],
			],
			'insights' => $this->insights($all, $zone),
		];
	}

	/** on_time / late / missing over academic items that need a submission and whose deadline passed. */
	private function submissionPerformance(iterable $academic): array
	{
		$onTime = $late = $missing = 0;
		foreach ($academic as $i) {
			if ($i['submission_status'] === null) {
				$missing++;
			} elseif ($i['is_late']) {
				$late++;
			} else {
				$onTime++;
			}
		}
		return ['on_time' => $onTime, 'late' => $late, 'missing' => $missing, 'on_time_rate' => self::rate($onTime, $onTime + $late + $missing)];
	}

	/** Plain, explainable observations. Never judgements about the student. */
	private function insights(array $items, DateTimeZone $zone): array
	{
		$monday = (new DateTimeImmutable('today', $zone))->modify('monday this week');
		$weekStart = self::utc($monday);
		$prevStart = self::utc($monday->sub(new DateInterval('P7D')));
		$nextWeek = gmdate('Y-m-d H:i:s', time() + 7 * 86400);
		$now = now_utc();

		$thisWeek = array_filter($items, fn($i) => $i['completed_at'] !== null && $i['completed_at'] >= $weekStart);
		$lastWeek = count(array_filter($items, fn($i) => $i['completed_at'] !== null && $i['completed_at'] >= $prevStart && $i['completed_at'] < $weekStart));
		$days = count(array_unique(array_map(fn($i) => substr($i['completed_at'], 0, 10), $thisWeek)));
		$upcoming = count(array_filter($items, fn($i) => StudentWork::isOpen($i) && $i['due_at'] !== null && $i['due_at'] >= $now && $i['due_at'] <= $nextWeek));
		$overdue = count(array_filter($items, fn($i) => $i['status'] === 'overdue'));

		$outstanding = [];
		foreach ($items as $i) {
			if ($i['kind'] === 'academic' && StudentWork::isOpen($i)) {
				$outstanding[$i['group']] = ($outstanding[$i['group']] ?? 0) + 1;
			}
		}
		arsort($outstanding);

		$open = array_values(array_filter($items, [StudentWork::class, 'isOpen']));
		usort($open, [StudentWork::class, 'byUrgency']);

		return [
			'completed_this_week' => count($thisWeek),
			'completed_last_week' => $lastWeek,
			'change_vs_last_week' => count($thisWeek) - $lastWeek,
			'active_days_this_week' => $days,
			'due_next_7_days' => $upcoming,
			'overdue' => $overdue,
			'most_outstanding_subject' => $outstanding ? ['subject' => array_key_first($outstanding), 'count' => reset($outstanding)] : null,
			'suggested_next' => $open ? StudentWork::present($open[0]) : null,
		];
	}

	// =====================================================================
	// Teacher
	// =====================================================================

	public function teacherDashboard(int $teacherId): array
	{
		$p = ['t' => $teacherId];
		$scope = 'JOIN teacher_assignments ta ON ta.class_id = a.class_id AND ta.subject_id = a.subject_id AND ta.teacher_id = :t';
		$now = now_utc();
		$soon = gmdate('Y-m-d H:i:s', time() + 72 * 3600);
		$counts = $this->db->one(
			"SELECT
				(SELECT COUNT(DISTINCT class_id) FROM teacher_assignments WHERE teacher_id = :t1) AS classes,
				(SELECT COUNT(DISTINCT subject_id) FROM teacher_assignments WHERE teacher_id = :t2) AS subjects",
			['t1' => $teacherId, 't2' => $teacherId],
		);
		$a = $this->db->one(
			"SELECT SUM(a.status = 'published') AS active,
				SUM(a.status = 'published' AND a.due_at BETWEEN :now AND :soon) AS due_soon
			 FROM assignments a $scope",
			$p + ['now' => $now, 'soon' => $soon],
		);
		$s = $this->db->one(
			"SELECT COUNT(*) AS received, SUM(sb.status IN ('submitted', 'resubmitted', 'under_review')) AS to_review
			 FROM submissions sb JOIN assignments a ON a.id = sb.assignment_id $scope",
			$p,
		);
		$upcoming = $this->db->all(
			"SELECT a.id, a.title, a.due_at, c.name AS class_name, c.division, s.name AS subject_name,
				(SELECT COUNT(*) FROM " . Recipients::SQL . " r WHERE r.assignment_id = a.id) AS recipients,
				(SELECT COUNT(*) FROM submissions sb WHERE sb.assignment_id = a.id) AS submitted
			 FROM assignments a $scope JOIN classes c ON c.id = a.class_id JOIN subjects s ON s.id = a.subject_id
			 WHERE a.status = 'published' AND a.due_at >= :now ORDER BY a.due_at LIMIT 6",
			$p + ['now' => $now],
		);
		foreach ($upcoming as &$u) {
			$u['due_at'] = iso_utc($u['due_at']);
		}
		return [
			'summary' => [
				'classes' => (int) $counts['classes'],
				'subjects' => (int) $counts['subjects'],
				'active_assignments' => (int) $a['active'],
				'submissions_received' => (int) $s['received'],
				'awaiting_review' => (int) $s['to_review'],
				'due_soon' => (int) $a['due_soon'],
			],
			'upcoming' => $upcoming,
		];
	}

	/** Class analytics, optionally narrowed to one class. */
	public function teacherAnalytics(int $teacherId, ?int $classId): array
	{
		$now = now_utc();
		$where = "ta.teacher_id = :t AND a.status IN ('published', 'closed') AND a.requires_submission = 1";
		$p = ['t' => $teacherId];
		if ($classId) {
			if (!Policy::teachesClass($teacherId, $classId)) {
				throw new HttpException(404);
			}
			$where .= ' AND a.class_id = :c';
			$p['c'] = $classId;
		}
		// One row per (assignment, recipient) with their submission.
		$rows = $this->db->all(
			"SELECT a.id AS assignment_id, a.title, a.due_at, a.max_marks, a.class_id, c.name AS class_name, c.division,
				r.student_id, u.full_name, sb.id AS submission_id, sb.is_late, sb.status, sb.marks
			 FROM assignments a
			 JOIN teacher_assignments ta ON ta.class_id = a.class_id AND ta.subject_id = a.subject_id
			 JOIN classes c ON c.id = a.class_id
			 JOIN " . Recipients::SQL . " r ON r.assignment_id = a.id
			 JOIN users u ON u.id = r.student_id
			 LEFT JOIN submissions sb ON sb.assignment_id = a.id AND sb.student_id = r.student_id
			 WHERE $where ORDER BY a.due_at, a.id",
			$p,
		);

		$byAssignment = [];
		$byClass = [];
		$missingByStudent = [];
		foreach ($rows as $r) {
			$aid = $r['assignment_id'];
			$due = $r['due_at'] !== null && $r['due_at'] <= $now;
			$x = &$byAssignment[$aid];
			$x ??= ['id' => (int) $aid, 'title' => $r['title'], 'due_at' => iso_utc($r['due_at']), 'max_marks' => $r['max_marks'] !== null ? (float) $r['max_marks'] : null,
				'recipients' => 0, 'submitted' => 0, 'on_time' => 0, 'late' => 0, 'missing' => 0, 'pending' => 0, 'marks' => []];
			$x['recipients']++;
			if ($r['submission_id']) {
				$x['submitted']++;
				$r['is_late'] ? $x['late']++ : $x['on_time']++;
				if ($r['status'] === 'graded' && $r['marks'] !== null) {
					$x['marks'][] = (float) $r['marks'];
				}
			} elseif ($due) {
				$x['missing']++;
				$missingByStudent[$r['student_id']] ??= ['student_id' => (int) $r['student_id'], 'full_name' => $r['full_name'], 'class' => trim($r['class_name'] . ' ' . $r['division']), 'missing' => 0];
				$missingByStudent[$r['student_id']]['missing']++;
			} else {
				$x['pending']++;
			}
			unset($x);

			if ($due) {
				$cl = &$byClass[$r['class_id']];
				$cl ??= ['class_id' => (int) $r['class_id'], 'class' => trim($r['class_name'] . ' ' . $r['division']), 'expected' => 0, 'submitted' => 0];
				$cl['expected']++;
				$r['submission_id'] && $cl['submitted']++;
				unset($cl);
			}
		}
		$assignments = array_values(array_map(function ($x) {
			$x['submission_rate'] = self::rate($x['submitted'], $x['recipients']);
			$x['average_marks'] = $x['marks'] ? round(array_sum($x['marks']) / count($x['marks']), 2) : null;
			$x['graded'] = count($x['marks']);
			unset($x['marks']);
			return $x;
		}, $byAssignment));
		$classes = array_values(array_map(fn($c) => $c + ['completion_rate' => self::rate($c['submitted'], $c['expected'])], $byClass));
		$repeat = array_values(array_filter($missingByStudent, fn($s) => $s['missing'] >= 2));
		usort($repeat, fn($a, $b) => $b['missing'] <=> $a['missing']);

		return [
			'assignments' => array_slice($assignments, -12),
			'classes' => $classes,
			'totals' => [
				'on_time' => array_sum(array_column($assignments, 'on_time')),
				'late' => array_sum(array_column($assignments, 'late')),
				'missing' => array_sum(array_column($assignments, 'missing')),
				'pending' => array_sum(array_column($assignments, 'pending')),
			],
			'repeated_missing' => $repeat,
		];
	}

	// =====================================================================
	// Admin
	// =====================================================================

	public function institutionSubmissionRate(int $institutionId): ?float
	{
		$row = $this->db->one(
			"SELECT COUNT(*) AS expected, COUNT(s.id) AS submitted
			 FROM " . Recipients::SQL . " r
			 JOIN assignments a ON a.id = r.assignment_id
			 LEFT JOIN submissions s ON s.assignment_id = r.assignment_id AND s.student_id = r.student_id
			 WHERE a.institution_id = :i AND a.requires_submission = 1 AND a.status IN ('published', 'closed')
			   AND a.due_at IS NOT NULL AND a.due_at <= :now",
			['i' => $institutionId, 'now' => now_utc()],
		);
		return self::rate((int) $row['submitted'], (int) $row['expected']);
	}

	public function adminAnalytics(int $institutionId, string $tz): array
	{
		$now = now_utc();
		$classes = $this->db->all(
			"SELECT c.id, TRIM(CONCAT(c.name, ' ', c.division)) AS class,
				COUNT(r.student_id) AS expected, COUNT(sb.id) AS submitted
			 FROM classes c
			 LEFT JOIN assignments a ON a.class_id = c.id AND a.requires_submission = 1 AND a.status IN ('published', 'closed') AND a.due_at <= :now
			 LEFT JOIN " . Recipients::SQL . " r ON r.assignment_id = a.id
			 LEFT JOIN submissions sb ON sb.assignment_id = a.id AND sb.student_id = r.student_id
			 WHERE c.institution_id = :i AND c.is_active = 1
			 GROUP BY c.id ORDER BY c.name, c.division",
			['i' => $institutionId, 'now' => $now],
		);
		foreach ($classes as &$c) {
			$c['completion_rate'] = self::rate((int) $c['submitted'], (int) $c['expected']);
		}
		unset($c);

		$subjects = $this->db->all(
			"SELECT s.id, s.name AS subject,
				COUNT(DISTINCT a.id) AS assignments,
				(SELECT COUNT(*) FROM submissions sb JOIN assignments a2 ON a2.id = sb.assignment_id WHERE a2.subject_id = s.id) AS submissions
			 FROM subjects s LEFT JOIN assignments a ON a.subject_id = s.id AND a.status IN ('published', 'closed')
			 WHERE s.institution_id = :i AND s.is_active = 1 GROUP BY s.id ORDER BY s.name",
			['i' => $institutionId],
		);

		$enrolment = $this->db->all(
			"SELECT TRIM(CONCAT(c.name, ' ', c.division)) AS class, COUNT(cs.student_id) AS students
			 FROM classes c LEFT JOIN class_students cs ON cs.class_id = c.id
			 WHERE c.institution_id = :i AND c.is_active = 1 GROUP BY c.id ORDER BY c.name, c.division",
			['i' => $institutionId],
		);

		// Submissions per week for the last 8 weeks (week starts Monday, institution time).
		$zone = new DateTimeZone($tz);
		$end = (new DateTimeImmutable('monday next week', $zone))->setTime(0, 0);
		$start = $end->sub(new DateInterval('P8W'));
		$buckets = self::buckets($start, $end, 'week');
		$subs = $this->db->all(
			'SELECT sb.first_submitted_at AS at, sb.is_late FROM submissions sb JOIN assignments a ON a.id = sb.assignment_id
			 WHERE a.institution_id = :i AND sb.first_submitted_at >= :s',
			['i' => $institutionId, 's' => self::utc($start)],
		);
		$onTime = $late = [];
		foreach ($buckets as $k => [, $bs, $be]) {
			$in = array_filter($subs, fn($x) => $x['at'] >= $bs && $x['at'] < $be);
			$late[$k] = count(array_filter($in, fn($x) => (int) $x['is_late'] === 1));
			$onTime[$k] = count($in) - $late[$k];
		}
		return [
			'classes' => $classes,
			'subjects' => $subjects,
			'enrolment' => $enrolment,
			'trend' => ['labels' => array_column($buckets, 0), 'on_time' => array_values($onTime), 'late' => array_values($late)],
		];
	}

	// =====================================================================
	// Helpers
	// =====================================================================

	public static function rate(int|float $part, int|float $whole): ?float
	{
		return $whole > 0 ? round($part / $whole * 100, 1) : null;
	}

	private static function inRange(?string $at, string $s, string $e): bool
	{
		return $at !== null && $at >= $s && $at < $e;
	}

	private static function utc(DateTimeImmutable $d): string
	{
		return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
	}

	/** Local-time period -> [start, end) in that zone. */
	public static function periodRange(string $period, ?string $from, ?string $to, DateTimeZone $zone): array
	{
		$today = new DateTimeImmutable('today', $zone);
		switch ($period) {
			case 'today':
				return [$today, $today->add(new DateInterval('P1D'))];
			case 'month':
				$s = $today->modify('first day of this month');
				return [$s, $s->add(new DateInterval('P1M'))];
			case 'custom':
				if (!$from || !$to) {
					throw HttpException::validation(['from' => 'Choose a start and end date.']);
				}
				$s = new DateTimeImmutable($from, $zone);
				$e = (new DateTimeImmutable($to, $zone))->add(new DateInterval('P1D'));
				if ($e <= $s) {
					throw HttpException::validation(['to' => 'End date must be on or after the start date.']);
				}
				if ($s->diff($e)->days > 366) {
					throw HttpException::validation(['to' => 'Choose a range of one year or less.']);
				}
				return [$s, $e];
			default:
				$s = $today->modify('monday this week');
				return [$s, $s->add(new DateInterval('P7D'))];
		}
	}

	/** @return array<int, array{0:string,1:string,2:string}> [label, utcStart, utcEnd] */
	public static function buckets(DateTimeImmutable $start, DateTimeImmutable $end, string $gran): array
	{
		$step = match ($gran) {
			'week' => new DateInterval('P1W'),
			'month' => new DateInterval('P1M'),
			default => new DateInterval('P1D'),
		};
		$cursor = match ($gran) {
			'week' => $start->modify('monday this week'),
			'month' => $start->modify('first day of this month'),
			default => $start,
		};
		$out = [];
		while ($cursor < $end && count($out) < 400) {
			$next = $cursor->add($step);
			$label = match ($gran) {
				'week' => 'Wk of ' . $cursor->format('j M'),
				'month' => $cursor->format('M Y'),
				default => $cursor->format('D j M'),
			};
			$bs = max($cursor, $start);
			$be = min($next, $end);
			$out[] = [$label, self::utc($bs), self::utc($be)];
			$cursor = $next;
		}
		return $out;
	}
}
