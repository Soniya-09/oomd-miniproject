<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Database;
use App\Services\ReminderService;

final class ProductivityTest extends TestCase
{
	private function iso(string $offset): string
	{
		return gmdate('Y-m-d\TH:i:s\Z', strtotime($offset));
	}

	private function task(array $data, string $student = 'priya'): array
	{
		$this->actingAs($this->ids['students'][$student]);
		$r = $this->call('POST', '/api/student/tasks', $data);
		$this->assertStatus(201, $r);
		return $this->json($r)['data'];
	}

	private function publish(array $over = []): int
	{
		$this->actingAs($this->ids['teachers']['meera']);
		$r = $this->call('POST', '/api/teacher/assignments', $over + [
			'title' => 'Essay', 'description' => 'Write it.', 'class_id' => $this->ids['classes']['FY-A'],
			'subject_id' => $this->ids['subjects']['CS101'], 'due_at' => $this->iso('+2 days'), 'allowed_extensions' => 'pdf', 'publish' => true,
		]);
		$this->assertStatus(201, $r);
		return $this->json($r)['data']['id'];
	}

	public function testPersonalTasksArePrivate(): void
	{
		$t = $this->task(['title' => 'Gym', 'priority' => 'high', 'due_at' => $this->iso('+1 day')]);
		$this->actingAs($this->ids['students']['arjun']);
		$this->assertStatus(404, $this->get("/api/student/tasks/{$t['id']}"));
		$this->assertStatus(404, $this->call('PATCH', "/api/student/tasks/{$t['id']}", ['status' => 'completed']));
		$this->assertStatus(404, $this->call('DELETE', "/api/student/tasks/{$t['id']}"));
		$this->assertCount(0, $this->json($this->get('/api/student/tasks'))['data']);
		$this->actingAs($this->ids['teachers']['meera']);
		$this->assertStatus(403, $this->get('/api/student/tasks'));
	}

	public function testCompletingTaskSetsTimestampAndLeavesAcademicAlone(): void
	{
		$a = $this->publish();
		$t = $this->task(['title' => 'Read chapter 3']);
		$this->assertSame(null, $t['completed_at']);
		$r = $this->json($this->call('PATCH', "/api/student/tasks/{$t['id']}", ['status' => 'completed']))['data'];
		$this->assertTrue($r['completed_at'] !== null);
		$this->assertFalse((bool) Database::get()->value('SELECT 1 FROM submissions WHERE assignment_id = :a', ['a' => $a]));
		$r = $this->json($this->call('PATCH', "/api/student/tasks/{$t['id']}", ['status' => 'pending']))['data'];
		$this->assertSame(null, $r['completed_at'], 'reopening clears completion');
	}

	public function testCategoriesAreOwnedAndUnique(): void
	{
		$this->actingAs($this->ids['students']['priya']);
		$c = $this->json($this->call('POST', '/api/student/categories', ['name' => 'Health', 'color' => '#22aa55']))['data']['id'];
		$this->assertStatus(422, $this->call('POST', '/api/student/categories', ['name' => 'Health']));
		$this->actingAs($this->ids['students']['arjun']);
		$this->assertStatus(422, $this->call('POST', '/api/student/tasks', ['title' => 'x', 'category_id' => $c]), 'cannot use another student\'s category');
	}

	public function testDashboardCountsMatchRecords(): void
	{
		$db = Database::get();
		$a1 = $this->publish(['title' => 'Due soon']);
		$a2 = $this->publish(['title' => 'Overdue one']);
		$db->update('assignments', ['due_at' => gmdate('Y-m-d H:i:s', time() - 3600)], 'id = :id', ['id' => $a2]);
		$this->task(['title' => 'Personal overdue', 'due_at' => $this->iso('+1 hour')]);
		$db->run("UPDATE personal_tasks SET due_at = :d WHERE title = 'Personal overdue'", ['d' => gmdate('Y-m-d H:i:s', time() - 60)]);
		$done = $this->task(['title' => 'Done thing']);
		$this->call('PATCH', "/api/student/tasks/{$done['id']}", ['status' => 'completed']);
		$cancelled = $this->task(['title' => 'Dropped']);
		$this->call('PATCH', "/api/student/tasks/{$cancelled['id']}", ['status' => 'cancelled']);
		$this->task(['title' => 'No deadline']);

		$this->actingAs($this->ids['students']['priya']);
		$this->call('POST', "/api/student/assignments/$a1/submission", [], ['files' => $this->fakeUpload('a.pdf', $this->pdf())]);

		$d = $this->json($this->get('/api/student/dashboard'))['data'];
		$s = $d['summary'];
		// Eligible: a1 (completed), a2 (overdue), personal overdue, done (completed), no deadline (pending). Cancelled excluded.
		$this->assertSame(2, $s['completed']);
		$this->assertSame(2, $s['overdue']);
		$this->assertSame(1, $s['pending']);
		$this->assertSame(3, $s['active']);
		$this->assertSame(40.0, (float) $s['completion_rate']);
		$this->assertSame(0.0, (float) $s['on_time_rate'], 'a2 is missing; a1 not yet due so not counted');
		$this->assertSame('Overdue one', $d['suggestion']['title'], 'oldest overdue first');

		// Unified list filters agree with the cards.
		$this->assertSame(2, $this->json($this->get('/api/student/items?status=overdue'))['meta']['total']);
		$this->assertSame(1, $this->json($this->get('/api/student/items?status=overdue&kind=personal'))['meta']['total']);
		$this->assertSame(3, $this->json($this->get('/api/student/items?status=open'))['meta']['total']);
	}

	public function testProgressChartsUseConsistentTotals(): void
	{
		$this->task(['title' => 'A', 'due_at' => $this->iso('+1 hour')]);
		$b = $this->task(['title' => 'B', 'due_at' => $this->iso('+2 hours')]);
		$this->call('PATCH', "/api/student/tasks/{$b['id']}", ['status' => 'completed']);

		foreach (['today', 'week', 'month'] as $period) {
			$p = $this->json($this->get("/api/student/progress?period=$period"))['data'];
			$this->assertSame(array_sum($p['trend']), array_sum($p['by_kind']['academic']) + array_sum($p['by_kind']['personal']), "$period: trend = academic + personal");
			$this->assertSame($p['metrics']['scoped'], array_sum($p['status']), "$period: status slices add up");
		}
		$p = $this->json($this->get('/api/student/progress?period=today'))['data'];
		$this->assertSame(1, array_sum($p['trend']));
		$this->assertSame(['completed' => 1, 'pending' => 1, 'in_progress' => 0, 'overdue' => 0], $p['status']);

		$this->assertStatus(422, $this->get('/api/student/progress?period=custom&from=2026-02-01&to=2026-01-01'));
		$empty = $this->json($this->get('/api/student/progress?period=custom&from=2020-01-01&to=2020-01-31'))['data'];
		$this->assertSame(0, array_sum($empty['trend']), 'empty range works');
		$this->assertSame(null, $empty['metrics']['completion_rate']);
	}

	public function testNotificationsReadState(): void
	{
		$this->publish();
		$this->actingAs($this->ids['students']['priya']);
		$this->assertSame(1, $this->json($this->get('/api/notifications/unread-count'))['count']);
		$n = $this->json($this->get('/api/notifications'))['data'][0];
		$this->call('POST', "/api/notifications/{$n['id']}/read");
		$this->assertSame(0, $this->json($this->get('/api/notifications/unread-count'))['count']);

		$this->actingAs($this->ids['students']['arjun']);
		$this->call('POST', "/api/notifications/{$n['id']}/read"); // someone else's id: no effect
		$this->assertSame(1, $this->json($this->get('/api/notifications/unread-count'))['count']);
		$this->call('POST', '/api/notifications/read-all');
		$this->assertSame(0, $this->json($this->get('/api/notifications/unread-count'))['count']);
	}

	public function testRemindersAreIdempotentAndPrivate(): void
	{
		$a = $this->publish(['due_at' => $this->iso('+3 hours')]);
		$t = $this->task(['title' => 'Call home', 'remind_at' => $this->iso('+1 hour')]);
		Database::get()->run('UPDATE task_reminders SET remind_at = :r WHERE task_id = :t', ['r' => gmdate('Y-m-d H:i:s', time() - 5), 't' => $t['id']]);

		$first = (new ReminderService())->run();
		$this->assertSame(4, $first['due_soon'], 'four FY-A students');
		$this->assertSame(1, $first['task_reminders']);
		$second = (new ReminderService())->run();
		$this->assertSame(0, $second['due_soon'] + $second['task_reminders'], 'rerun creates nothing new');

		$owners = Database::get()->all("SELECT user_id FROM notifications WHERE type = 'task_reminder'");
		$this->assertSame([['user_id' => $this->ids['students']['priya']]], $owners);
	}

	public function testCalendarRespectsScope(): void
	{
		$a = $this->publish(['due_at' => $this->iso('+1 day')]);
		$this->task(['title' => 'Private plan', 'due_at' => $this->iso('+1 day')]);
		$from = gmdate('Y-m-d\T00:00:00\Z');
		$to = gmdate('Y-m-d\T00:00:00\Z', strtotime('+7 days'));

		$this->actingAs($this->ids['students']['priya']);
		$types = array_column($this->json($this->get("/api/calendar?from=$from&to=$to"))['data'], 'type');
		$this->assertTrue(in_array('assignment', $types, true) && in_array('task', $types, true));

		$this->actingAs($this->ids['students']['neha']);
		$this->assertCount(0, $this->json($this->get("/api/calendar?from=$from&to=$to"))['data'], 'other class, no private tasks');

		$this->actingAs($this->ids['teachers']['meera']);
		$events = $this->json($this->get("/api/calendar?from=$from&to=$to"))['data'];
		$this->assertSame(['assignment'], array_values(array_unique(array_column($events, 'type'))), 'teacher never sees student tasks');
	}

	public function testExamsAndTimetableInCalendar(): void
	{
		$this->actingAs($this->ids['teachers']['meera']);
		$r = $this->call('POST', '/api/exams', ['title' => 'Unit test 1', 'class_id' => $this->ids['classes']['FY-A'], 'subject_id' => $this->ids['subjects']['CS101'], 'starts_at' => $this->iso('+2 days'), 'ends_at' => $this->iso('+2 days 1 hour')]);
		$this->assertStatus(201, $r);
		$r = $this->call('POST', '/api/exams', ['title' => 'Not mine', 'class_id' => $this->ids['classes']['FY-A'], 'subject_id' => $this->ids['subjects']['MA101'], 'starts_at' => $this->iso('+2 days'), 'ends_at' => $this->iso('+2 days 1 hour')]);
		$this->assertStatus(422, $r, 'teacher can only schedule own subject');

		$this->actingAs($this->ids['admin']);
		$cls = $this->ids['classes']['FY-A'];
		$this->assertStatus(201, $this->call('POST', "/api/admin/classes/$cls/timetable", ['subject_id' => $this->ids['subjects']['CS101'], 'weekday' => 1, 'start_time' => '09:00', 'end_time' => '10:00']));
		$this->assertStatus(422, $this->call('POST', "/api/admin/classes/$cls/timetable", ['subject_id' => $this->ids['subjects']['MA101'], 'weekday' => 1, 'start_time' => '09:30', 'end_time' => '10:30']), 'overlap');

		$this->actingAs($this->ids['students']['priya']);
		$from = gmdate('Y-m-d\T00:00:00\Z');
		$to = gmdate('Y-m-d\T00:00:00\Z', strtotime('+8 days'));
		$types = array_count_values(array_column($this->json($this->get("/api/calendar?from=$from&to=$to"))['data'], 'type'));
		$this->assertSame(1, $types['exam'] ?? 0);
		$this->assertTrue(($types['class'] ?? 0) >= 1, 'weekly class expanded');
	}

	public function testAnnouncementAudience(): void
	{
		$this->actingAs($this->ids['teachers']['rahul']);
		$this->assertStatus(422, $this->call('POST', '/api/announcements', ['title' => 'All hands', 'body' => 'x', 'audience' => 'institution']));
		$r = $this->call('POST', '/api/announcements', ['title' => 'Maths quiz Friday', 'body' => 'Bring calculators.', 'audience' => 'classes', 'class_ids' => [$this->ids['classes']['FY-B']]]);
		$this->assertStatus(201, $r);

		$this->actingAs($this->ids['students']['neha']);
		$this->assertCount(1, $this->json($this->get('/api/announcements'))['data']);
		$this->actingAs($this->ids['students']['priya']);
		$this->assertCount(0, $this->json($this->get('/api/announcements'))['data'], 'FY-A does not see FY-B notice');
		$this->assertStatus(403, $this->call('POST', '/api/announcements', ['title' => 'Student post', 'body' => 'x']));

		$this->actingAs($this->ids['admin']);
		$this->assertStatus(201, $this->call('POST', '/api/announcements', ['title' => 'Holiday on Monday', 'body' => 'Campus closed.', 'audience' => 'institution']));
		$this->actingAs($this->ids['students']['priya']);
		$this->assertCount(1, $this->json($this->get('/api/announcements'))['data']);
		$this->assertTrue((bool) Database::get()->value("SELECT 1 FROM notifications WHERE user_id = :u AND type = 'announcement'", ['u' => $this->ids['students']['priya']]));
	}

	public function testTeacherAnalyticsAndExportScope(): void
	{
		$a = $this->publish();
		$this->actingAs($this->ids['students']['priya']);
		$this->call('POST', "/api/student/assignments/$a/submission", [], ['files' => $this->fakeUpload('a.pdf', $this->pdf())]);
		Database::get()->update('assignments', ['due_at' => gmdate('Y-m-d H:i:s', time() - 60)], 'id = :id', ['id' => $a]);

		$this->actingAs($this->ids['teachers']['meera']);
		$an = $this->json($this->get('/api/teacher/analytics'))['data'];
		$this->assertSame(['on_time' => 1, 'late' => 0, 'missing' => 3, 'pending' => 0], $an['totals']);
		$this->assertSame(25.0, (float) $an['assignments'][0]['submission_rate']);
		$d = $this->json($this->get('/api/teacher/dashboard'))['data']['summary'];
		$this->assertSame(1, $d['awaiting_review']);

		$csv = $this->get('/api/reports/missing.csv');
		$this->assertStatus(200, $csv);
		$this->assertSame(4, count(array_filter(explode("\n", trim($csv->body)))), 'header + 3 missing');

		$this->actingAs($this->ids['teachers']['rahul']);
		$csv = $this->get('/api/reports/submissions.csv');
		$this->assertSame(1, count(array_filter(explode("\n", trim($csv->body)))), 'rahul sees none of meera\'s rows');
		$this->assertStatus(404, $this->get('/api/teacher/analytics?class_id=999999'));

		$this->actingAs($this->ids['students']['priya']);
		$this->assertStatus(403, $this->get('/api/reports/submissions.csv'));

		$this->actingAs($this->ids['admin']);
		$ad = $this->json($this->get('/api/admin/analytics'))['data'];
		$fyA = array_values(array_filter($ad['classes'], fn($c) => $c['class'] === 'FY BSc CS A'))[0];
		$this->assertSame(25.0, (float) $fyA['completion_rate']);
		$this->assertSame(25.0, (float) $this->json($this->get('/api/admin/overview'))['data']['submission_rate'], 'overview and analytics agree');
	}

	public function testAllPagesRenderForEachRole(): void
	{
		$pages = [
			'admin' => ['/admin', '/admin/announcements', '/admin/reports'],
			'teacher' => ['/teacher', '/teacher/classes', '/teacher/assignments', '/teacher/submissions', '/teacher/announcements', '/teacher/analytics', '/notifications'],
			'student' => ['/student', '/student/assignments', '/student/tasks', '/student/calendar', '/student/progress', '/notifications', '/announcements'],
		];
		$who = ['admin' => $this->ids['admin'], 'teacher' => $this->ids['teachers']['meera'], 'student' => $this->ids['students']['priya']];
		foreach ($pages as $role => $paths) {
			$this->actingAs($who[$role]);
			foreach ($paths as $p) {
				$this->assertStatus(200, $this->get($p), "$role $p");
			}
		}
		$this->actingAs($who['student']);
		$this->assertStatus(403, $this->get('/teacher/assignments'));
	}
}
