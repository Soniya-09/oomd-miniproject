<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Calendar events visible to one user between two UTC instants.
 * Students: their assignment deadlines, own personal tasks, exams and timetable of
 * their classes, announcements addressed to them. Teachers: the same for the
 * classes/subjects they teach. Every source is filtered by the user's scope.
 */
final class CalendarService
{
	private Database $db;

	public function __construct(private readonly array $user)
	{
		$this->db = Database::get();
	}

	public function events(string $from, string $to): array
	{
		if ($to <= $from) {
			throw HttpException::validation(['to' => 'End must be after start.']);
		}
		if ((strtotime($to) - strtotime($from)) > 92 * 86400) {
			throw HttpException::validation(['to' => 'Ask for at most about three months at a time.']);
		}
		$events = [];
		$uid = (int) $this->user['id'];
		$role = $this->user['role'];

		if ($role === 'student') {
			$work = (new StudentWork($uid))->items();
			foreach ($work as $i) {
				if ($i['kind'] === 'academic' && $i['due_at'] !== null && $i['due_at'] >= $from && $i['due_at'] < $to) {
					$events[] = ['type' => 'assignment', 'title' => $i['title'], 'subtitle' => $i['group'], 'start' => $i['due_at'], 'end' => null, 'url' => $i['url'], 'status' => $i['status'], 'priority' => $i['priority']];
				}
				if ($i['kind'] === 'personal') {
					$at = $i['due_at'] ?? $i['starts_at'];
					if ($at !== null && $at >= $from && $at < $to) {
						$events[] = ['type' => 'task', 'title' => $i['title'], 'subtitle' => $i['group'], 'start' => $at, 'end' => null, 'url' => $i['url'], 'status' => $i['status'], 'priority' => $i['priority']];
					}
				}
			}
			$classIds = array_map('intval', array_column($this->db->all('SELECT class_id FROM class_students WHERE student_id = :s', ['s' => $uid]), 'class_id'));
			$pairs = null; // all subjects of those classes
		} else {
			$teach = $this->db->all('SELECT class_id, subject_id FROM teacher_assignments WHERE teacher_id = :t', ['t' => $uid]);
			$classIds = array_values(array_unique(array_map('intval', array_column($teach, 'class_id'))));
			$pairs = array_map(fn($r) => $r['class_id'] . ':' . $r['subject_id'], $teach);
			$rows = $this->db->all(
				"SELECT a.id, a.title, a.due_at, s.name AS subject FROM assignments a
				 JOIN teacher_assignments ta ON ta.class_id = a.class_id AND ta.subject_id = a.subject_id AND ta.teacher_id = :t
				 JOIN subjects s ON s.id = a.subject_id
				 WHERE a.status IN ('published', 'closed', 'draft') AND a.due_at >= :f AND a.due_at < :to",
				['t' => $uid, 'f' => $from, 'to' => $to],
			);
			foreach ($rows as $r) {
				$events[] = ['type' => 'assignment', 'title' => $r['title'], 'subtitle' => $r['subject'], 'start' => $r['due_at'], 'end' => null, 'url' => "/teacher/assignments/{$r['id']}"];
			}
		}

		if ($classIds) {
			[$in, $p] = Database::inList($classIds);
			$exams = $this->db->all(
				"SELECT e.title, e.starts_at, e.ends_at, e.room, e.class_id, e.subject_id, s.name AS subject
				 FROM exams e JOIN subjects s ON s.id = e.subject_id
				 WHERE e.class_id IN $in AND e.starts_at < :to AND e.ends_at > :f",
				$p + ['f' => $from, 'to' => $to],
			);
			foreach ($exams as $e) {
				if ($pairs !== null && !in_array($e['class_id'] . ':' . $e['subject_id'], $pairs, true)) {
					continue;
				}
				$events[] = ['type' => 'exam', 'title' => $e['title'], 'subtitle' => $e['subject'] . ($e['room'] ? ' · ' . $e['room'] : ''), 'start' => $e['starts_at'], 'end' => $e['ends_at'], 'url' => null];
			}
			$events = array_merge($events, $this->timetable($classIds, $pairs, $from, $to));
		}

		foreach ((new AnnouncementService($this->user))->visibleBetween($from, $to) as $a) {
			$events[] = ['type' => 'announcement', 'title' => $a['title'], 'subtitle' => $a['author_name'], 'start' => $a['published_at'], 'end' => null, 'url' => '/notifications?announcement=' . $a['id']];
		}

		usort($events, fn($a, $b) => strcmp($a['start'], $b['start']));
		return array_map(function ($e) {
			$e['start'] = iso_utc($e['start']);
			$e['end'] = iso_utc($e['end']);
			return $e;
		}, $events);
	}

	/** Expand weekly timetable entries into dated events (institution time zone). */
	private function timetable(array $classIds, ?array $pairs, string $from, string $to): array
	{
		[$in, $p] = Database::inList($classIds);
		$rows = $this->db->all(
			"SELECT t.class_id, t.subject_id, t.weekday, t.start_time, t.end_time, t.room, s.name AS subject, TRIM(CONCAT(c.name, ' ', c.division)) AS class
			 FROM timetable_entries t JOIN subjects s ON s.id = t.subject_id JOIN classes c ON c.id = t.class_id
			 WHERE t.class_id IN $in",
			$p,
		);
		if (!$rows) {
			return [];
		}
		$zone = new DateTimeZone($this->user['timezone']);
		$utc = new DateTimeZone('UTC');
		$day = (new DateTimeImmutable($from, $utc))->setTimezone($zone)->setTime(0, 0);
		$end = (new DateTimeImmutable($to, $utc))->setTimezone($zone);
		$out = [];
		for (; $day < $end; $day = $day->add(new DateInterval('P1D'))) {
			$dow = (int) $day->format('N');
			foreach ($rows as $r) {
				if ((int) $r['weekday'] !== $dow || ($pairs !== null && !in_array($r['class_id'] . ':' . $r['subject_id'], $pairs, true))) {
					continue;
				}
				[$h, $m] = explode(':', $r['start_time']);
				[$eh, $em] = explode(':', $r['end_time']);
				$out[] = [
					'type' => 'class', 'title' => $r['subject'], 'subtitle' => $r['class'] . ($r['room'] ? ' · ' . $r['room'] : ''),
					'start' => $day->setTime((int) $h, (int) $m)->setTimezone($utc)->format('Y-m-d H:i:s'),
					'end' => $day->setTime((int) $eh, (int) $em)->setTimezone($utc)->format('Y-m-d H:i:s'),
					'url' => null,
				];
			}
		}
		return $out;
	}
}
