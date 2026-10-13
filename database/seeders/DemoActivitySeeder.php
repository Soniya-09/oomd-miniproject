<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Database;

/**
 * Realistic, fictional activity for the demo institution so dashboards and
 * charts have something to show: assignments over the last few weeks with
 * on-time / late / missing / graded submissions, personal tasks completed on
 * different days, announcements, exams and a weekly timetable.
 *
 * Submission files are tiny generated PDFs stored like real uploads.
 */
final class DemoActivitySeeder
{
	private Database $db;

	public function __construct(private readonly array $ids)
	{
		$this->db = Database::get();
	}

	private static function at(string $relative, string $time = '17:00'): string
	{
		return gmdate('Y-m-d H:i:s', strtotime("$relative $time UTC"));
	}

	public function run(): void
	{
		$this->db->transaction(function () {
			$this->assignments();
			$this->personalTasks();
			$this->schedule();
			$this->announcements();
		});
	}

	private function assignments(): void
	{
		$c = $this->ids['classes'];
		$s = $this->ids['subjects'];
		$t = $this->ids['teachers'];
		$st = $this->ids['students'];
		$fyA = ['priya', 'arjun', 'sara', 'dev'];
		$fyB = ['neha', 'kabir', 'isha'];

		// [title, class, subject, teacher, due, max, status, per-student outcome]
		// outcome: on|late|miss|graded:<marks>|returned|none (not yet due)
		$plan = [
			['Variables & data types worksheet', 'FY-A', 'CS101', 'meera', '-20 days', 10, 'closed', ['priya' => 'graded:9', 'arjun' => 'graded:7', 'sara' => 'graded:8.5', 'dev' => 'late']],
			['ER diagram for a library', 'FY-A', 'CS102', 'meera', '-12 days', 20, 'published', ['priya' => 'graded:18', 'arjun' => 'late', 'sara' => 'on', 'dev' => 'miss']],
			['Sets and relations problem set', 'FY-A', 'MA101', 'rahul', '-8 days', 25, 'published', ['priya' => 'graded:21', 'arjun' => 'graded:15', 'sara' => 'miss', 'dev' => 'miss']],
			['Loops practice (10 programs)', 'FY-A', 'CS101', 'meera', '-3 days', 20, 'published', ['priya' => 'on', 'arjun' => 'returned', 'sara' => 'on', 'dev' => 'miss']],
			['Normalization case study', 'FY-A', 'CS102', 'meera', '+2 days', 30, 'published', ['priya' => 'none', 'arjun' => 'on', 'sara' => 'none', 'dev' => 'none']],
			['Proof techniques reading', 'FY-A', 'MA101', 'rahul', '+5 days', null, 'published', []],
			['HTML portfolio page', 'FY-B', 'CS103', 'meera', '-6 days', 20, 'published', ['neha' => 'graded:17', 'kabir' => 'on', 'isha' => 'late']],
			['Functions quiz prep', 'FY-B', 'CS101', 'meera', '+1 day', 10, 'published', ['neha' => 'on']],
			['Graph theory exercises', 'FY-B', 'MA101', 'rahul', '-4 days', 20, 'published', ['neha' => 'on', 'kabir' => 'miss', 'isha' => 'graded:16']],
			['CSS layout challenge (draft)', 'FY-B', 'CS103', 'meera', '+9 days', 15, 'draft', []],
		];

		foreach ($plan as [$title, $class, $subject, $teacher, $due, $max, $status, $outcomes]) {
			$dueAt = self::at($due, '18:30');
			$published = gmdate('Y-m-d H:i:s', strtotime($dueAt) - 7 * 86400);
			$aid = $this->db->insert('assignments', [
				'institution_id' => $this->ids['institution'], 'class_id' => $c[$class], 'subject_id' => $s[$subject], 'teacher_id' => $t[$teacher],
				'title' => $title,
				'description' => "Complete the $title and upload your work as a PDF.\n\nShow your reasoning where it applies. Cite any sources you used.",
				'priority' => $max !== null && $max >= 20 ? 'high' : 'medium', 'max_marks' => $max,
				'requires_submission' => (int) ($max !== null), 'allowed_extensions' => json_encode(['pdf', 'docx']), 'max_upload_mb' => 10,
				'allow_late' => 1, 'allow_resubmission' => 1, 'target_all' => 1, 'status' => $status, 'due_at' => $dueAt,
				'published_at' => $status === 'draft' ? null : $published, 'closed_at' => $status === 'closed' ? self::at('-15 days') : null,
				'created_at' => $published,
			]);
			if ($status === 'draft') {
				continue;
			}
			foreach ($class === 'FY-A' ? $fyA : $fyB as $who) {
				$sid = $st[$who];
				$this->notify($sid, 'assignment_new', "New assignment: $title", 'Due ' . $dueAt . ' UTC', $aid, "/student/assignments/$aid", "assignment:$aid:published", $published);
				$outcome = $outcomes[$who] ?? 'none';
				if ($outcome === 'none' || $outcome === 'miss') {
					continue;
				}
				$late = $outcome === 'late';
				$submitted = $late ? gmdate('Y-m-d H:i:s', strtotime($dueAt) + 26 * 3600) : gmdate('Y-m-d H:i:s', strtotime($dueAt) - random_int(2, 60) * 3600);
				[$state, $marks] = str_starts_with($outcome, 'graded:') ? ['graded', (float) substr($outcome, 7)] : [$outcome === 'returned' ? 'returned' : 'submitted', null];
				$subId = $this->db->insert('submissions', [
					'assignment_id' => $aid, 'student_id' => $sid, 'status' => $state, 'attempt' => 1,
					'first_submitted_at' => $submitted, 'submitted_at' => $submitted, 'is_late' => (int) $late,
					'marks' => $marks, 'grade_released' => (int) ($state === 'graded'),
					'graded_by' => $state === 'graded' ? $t[$teacher] : null, 'graded_at' => $state === 'graded' ? gmdate('Y-m-d H:i:s', strtotime($submitted) + 2 * 86400) : null,
					'created_at' => $submitted,
				]);
				$this->file($subId, "$who-" . preg_replace('/\W+/', '-', strtolower($title)) . '.pdf', $submitted);
				$this->db->insert('grading_history', ['submission_id' => $subId, 'changed_by' => $sid, 'new_status' => 'submitted', 'note' => 'First submission', 'created_at' => $submitted]);
				if ($state === 'graded') {
					$this->db->insert('submission_feedback', ['submission_id' => $subId, 'author_id' => $t[$teacher], 'attempt' => 1, 'is_released' => 1,
						'comment' => $marks / $max >= 0.8 ? 'Clear and well organised. Good work.' : 'Good start. Review the worked examples from class and check your edge cases.']);
					$this->db->insert('grading_history', ['submission_id' => $subId, 'changed_by' => $t[$teacher], 'old_status' => 'submitted', 'new_status' => 'graded', 'new_marks' => $marks, 'note' => 'Grade released']);
					$this->notify($sid, 'feedback', "Graded: $title", "Marks: $marks / $max", $aid, "/student/assignments/$aid", "graded:$subId:1:$marks", gmdate('Y-m-d H:i:s', strtotime($submitted) + 2 * 86400));
				}
				if ($state === 'returned') {
					$this->db->insert('submission_feedback', ['submission_id' => $subId, 'author_id' => $t[$teacher], 'attempt' => 1, 'is_released' => 1,
						'comment' => 'Programs 6–10 are missing. Please add them and resubmit.']);
					$this->db->insert('grading_history', ['submission_id' => $subId, 'changed_by' => $t[$teacher], 'old_status' => 'submitted', 'new_status' => 'returned', 'note' => 'Returned for revision']);
					$this->notify($sid, 'returned', "Returned for revision: $title", 'Programs 6–10 are missing.', $aid, "/student/assignments/$aid", "returned:$subId:1", self::at('-1 day', '10:00'));
				}
			}
		}
	}

	private function file(int $submissionId, string $name, string $at): void
	{
		$stored = bin2hex(random_bytes(20));
		$dir = rtrim((string) config('uploads.path'), '/\\') . DIRECTORY_SEPARATOR . substr($stored, 0, 2);
		if (!is_dir($dir)) {
			mkdir($dir, 0770, true);
		}
		$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Count 0/Kids[]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
		file_put_contents($dir . DIRECTORY_SEPARATOR . $stored, $pdf);
		$this->db->insert('submission_files', ['submission_id' => $submissionId, 'attempt' => 1, 'original_name' => $name, 'stored_name' => $stored, 'mime_type' => 'application/pdf', 'size_bytes' => strlen($pdf), 'created_at' => $at]);
	}

	private function notify(int $user, string $type, string $title, string $msg, int $assignmentId, string $url, string $key, string $at): void
	{
		$this->db->run(
			'INSERT IGNORE INTO notifications (user_id, type, title, message, related_type, related_id, action_url, dedupe_key, created_at, read_at)
			 VALUES (:u, :t, :ti, :m, \'assignment\', :r, :url, :k, :c, :ra)',
			['u' => $user, 't' => $type, 'ti' => $title, 'm' => $msg, 'r' => $assignmentId, 'url' => $url, 'k' => $key, 'c' => $at, 'ra' => strtotime($at) < time() - 5 * 86400 ? $at : null],
		);
	}

	private function personalTasks(): void
	{
		foreach ($this->ids['students'] as $slug => $uid) {
			$study = $this->db->insert('task_categories', ['user_id' => $uid, 'name' => 'Study', 'color' => '#1d4ed8']);
			$health = $this->db->insert('task_categories', ['user_id' => $uid, 'name' => 'Health', 'color' => '#0d9488']);
			$daily = $this->db->insert('task_categories', ['user_id' => $uid, 'name' => 'Daily', 'color' => '#9333ea']);
			$tasks = [
				['Revise DBMS notes', $study, 'high', '-9 days', 'completed', '-9 days'],
				['Morning run', $health, 'medium', '-8 days', 'completed', '-8 days'],
				['Read chapter 4 (Discrete Maths)', $study, 'medium', '-6 days', 'completed', '-7 days'],
				['Laundry', $daily, 'low', '-5 days', 'completed', '-5 days'],
				['Practice 5 SQL queries', $study, 'high', '-3 days', 'completed', '-2 days'],
				['Gym', $health, 'medium', '-2 days', 'completed', '-2 days'],
				['Call home', $daily, 'low', '-1 day', 'completed', '-1 day'],
				['Organise lecture notes', $study, 'medium', '-1 day', 'pending', null],
				['Make flashcards for sets & relations', $study, 'high', '+1 day', 'in_progress', null],
				['Yoga session', $health, 'low', '+2 days', 'pending', null],
				['Plan next week', $daily, 'medium', '+4 days', 'pending', null],
				['Learn Git basics', $study, 'low', null, 'pending', null],
			];
			// Vary per student so each dashboard looks different.
			$offset = crc32($slug) % 4;
			foreach (array_slice($tasks, $offset % 3) as [$title, $cat, $prio, $due, $status, $done]) {
				$dueAt = $due ? self::at($due, sprintf('%02d:00', 9 + $offset * 2)) : null;
				$this->db->insert('personal_tasks', [
					'user_id' => $uid, 'category_id' => $cat, 'title' => $title, 'priority' => $prio, 'status' => $status,
					'due_at' => $dueAt, 'duration_min' => 45, 'completed_at' => $done ? self::at($done, '15:30') : null,
					'created_at' => self::at('-12 days', '08:00'),
				]);
			}
		}
	}

	private function schedule(): void
	{
		$c = $this->ids['classes'];
		$s = $this->ids['subjects'];
		$t = $this->ids['teachers'];
		$periods = [
			[$c['FY-A'], 'CS101', 'meera', [1, 3, 5], '09:00', '10:00', 'Lab 1'],
			[$c['FY-A'], 'CS102', 'meera', [2, 4], '10:15', '11:15', 'Room 204'],
			[$c['FY-A'], 'MA101', 'rahul', [1, 2, 4], '11:30', '12:30', 'Room 101'],
			[$c['FY-B'], 'CS101', 'meera', [2, 4], '09:00', '10:00', 'Lab 1'],
			[$c['FY-B'], 'CS103', 'meera', [1, 3], '10:15', '11:15', 'Lab 2'],
			[$c['FY-B'], 'MA101', 'rahul', [3, 5], '11:30', '12:30', 'Room 101'],
		];
		foreach ($periods as [$class, $code, $teacher, $days, $start, $end, $room]) {
			foreach ($days as $d) {
				$this->db->insert('timetable_entries', ['class_id' => $class, 'subject_id' => $s[$code], 'teacher_id' => $t[$teacher], 'weekday' => $d, 'start_time' => $start, 'end_time' => $end, 'room' => $room]);
			}
		}
		foreach ([
			[$c['FY-A'], 'CS101', 'Unit test 1 — Programming Fundamentals', '+6 days', 'Hall A'],
			[$c['FY-A'], 'MA101', 'Unit test 1 — Discrete Mathematics', '+8 days', 'Hall B'],
			[$c['FY-B'], 'CS103', 'Practical exam — Web Technologies', '+7 days', 'Lab 2'],
		] as [$class, $code, $title, $when, $room]) {
			$startsAt = self::at($when, '04:30'); // 10:00 IST
			$this->db->insert('exams', ['institution_id' => $this->ids['institution'], 'class_id' => $class, 'subject_id' => $s[$code], 'title' => $title,
				'starts_at' => $startsAt, 'ends_at' => gmdate('Y-m-d H:i:s', strtotime($startsAt) + 5400), 'room' => $room]);
		}
	}

	private function announcements(): void
	{
		$inst = $this->ids['institution'];
		$a1 = $this->db->insert('announcements', ['institution_id' => $inst, 'author_id' => $this->ids['admin'], 'title' => 'Library hours extended during exams',
			'body' => "The library will stay open until 9 pm on weekdays for the next three weeks.\nBring your ID card.", 'audience' => 'institution', 'published_at' => self::at('-2 days', '06:00')]);
		$a2 = $this->db->insert('announcements', ['institution_id' => $inst, 'author_id' => $this->ids['teachers']['meera'], 'title' => 'Lab 1 moved on Wednesday',
			'body' => 'Wednesday\'s Programming Fundamentals lab will be held in Lab 2 because of maintenance.', 'audience' => 'classes', 'published_at' => self::at('-1 day', '07:00')]);
		$this->db->insert('announcement_targets', ['announcement_id' => $a2, 'class_id' => $this->ids['classes']['FY-A']]);
		foreach ($this->ids['students'] as $slug => $uid) {
			$this->db->run('INSERT IGNORE INTO notifications (user_id, type, title, message, related_type, related_id, action_url, dedupe_key, created_at) VALUES (:u, \'announcement\', :t, :m, \'announcement\', :r, :url, :k, :c)',
				['u' => $uid, 't' => 'Announcement: Library hours extended during exams', 'm' => 'The library will stay open until 9 pm on weekdays.', 'r' => $a1, 'url' => "/announcements#a$a1", 'k' => "announcement:$a1", 'c' => self::at('-2 days', '06:00')]);
		}
	}
}
