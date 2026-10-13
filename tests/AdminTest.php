<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Database;

final class AdminTest extends TestCase
{
	public function setUp(): void
	{
		parent::setUp();
		$this->actingAs($this->ids['admin']);
	}

	public function testCreateStudentWithEnrolment(): void
	{
		$r = $this->call('POST', '/api/admin/users', [
			'role' => 'student', 'full_name' => 'Test Student', 'email' => 'Test.Student@eduflow.test',
			'password' => 'Secret123', 'admission_no' => 'RC26999', 'class_ids' => [$this->ids['classes']['FY-A']],
		]);
		$this->assertStatus(201, $r);
		$id = $this->json($r)['data']['id'];
		$u = $this->json($this->get("/api/admin/users/$id"))['data'];
		$this->assertSame('test.student@eduflow.test', $u['email']);
		$this->assertSame([$this->ids['classes']['FY-A']], $u['class_ids']);
		$this->assertFalse(isset($u['password_hash']), 'hash never exposed');
	}

	public function testUserValidation(): void
	{
		$r = $this->call('POST', '/api/admin/users', ['role' => 'student', 'full_name' => 'X', 'email' => 'bad', 'password' => '1']);
		$this->assertStatus(422, $r);
		$errors = $this->json($r)['errors'];
		$this->assertTrue(isset($errors['email'], $errors['full_name']));

		$r = $this->call('POST', '/api/admin/users', ['role' => 'student', 'full_name' => 'Dup', 'email' => 'admin@eduflow.test', 'password' => 'Secret123', 'admission_no' => 'Z1']);
		$this->assertSame('An account with this email already exists.', $this->json($r)['errors']['email']);

		$r = $this->call('POST', '/api/admin/users', ['role' => 'student', 'full_name' => 'Dup', 'email' => 'new@eduflow.test', 'password' => 'Secret123', 'admission_no' => 'RC26001']);
		$this->assertStatus(422, $r, 'duplicate admission number');
	}

	public function testAdminCannotDeactivateSelf(): void
	{
		$r = $this->call('PATCH', '/api/admin/users/' . $this->ids['admin'], ['is_active' => false]);
		$this->assertStatus(422, $r);
	}

	public function testDeactivateIsAudited(): void
	{
		$id = $this->ids['students']['dev'];
		$this->assertStatus(200, $this->call('PATCH', "/api/admin/users/$id", ['is_active' => false]));
		$log = $this->json($this->get('/api/admin/audit-logs?action=user.deactivated'))['data'];
		$this->assertCount(1, $log);
		$this->assertSame($id, $log[0]['entity_id']);
	}

	public function testUserListSearchFilterAndPagination(): void
	{
		$r = $this->json($this->get('/api/admin/users?role=student&per_page=3&sort=name'));
		$this->assertSame(7, $r['meta']['total']);
		$this->assertSame(3, $r['meta']['pages']);
		$this->assertCount(3, $r['data']);
		$this->assertSame('Arjun Mehta', $r['data'][0]['full_name']);

		$r = $this->json($this->get('/api/admin/users?q=kulkarni'));
		$this->assertCount(1, $r['data']);

		$r = $this->json($this->get('/api/admin/users?class_id=' . $this->ids['classes']['FY-B']));
		$this->assertSame(3, $r['meta']['total']);

		$this->assertStatus(422, $this->get('/api/admin/users?role=superuser'), 'filter values are validated');
	}

	public function testClassesAndEnrolment(): void
	{
		$session = (int) Database::get()->value('SELECT id FROM academic_sessions LIMIT 1');
		$r = $this->call('POST', '/api/admin/classes', ['name' => 'SY BSc CS', 'division' => 'A', 'academic_session_id' => $session]);
		$this->assertStatus(201, $r);
		$classId = $this->json($r)['data']['id'];
		$r = $this->call('POST', '/api/admin/classes', ['name' => 'SY BSc CS', 'division' => 'A', 'academic_session_id' => $session]);
		$this->assertStatus(422, $r, 'duplicate class');

		$r = $this->call('POST', "/api/admin/classes/$classId/students", ['student_ids' => [$this->ids['students']['priya'], $this->ids['students']['neha']]]);
		$this->assertSame(2, $this->json($r)['enrolled']);
		$r = $this->call('POST', "/api/admin/classes/$classId/students", ['student_ids' => [$this->ids['teachers']['meera']]]);
		$this->assertStatus(422, $r, 'cannot enroll a teacher');

		$detail = $this->json($this->get("/api/admin/classes/$classId"))['data'];
		$this->assertCount(2, $detail['students']);

		$this->assertStatus(200, $this->call('DELETE', "/api/admin/classes/$classId/students/" . $this->ids['students']['neha']));
		$this->assertCount(1, $this->json($this->get("/api/admin/classes/$classId"))['data']['students']);
	}

	public function testTeacherAssignment(): void
	{
		$r = $this->call('POST', '/api/admin/teaching', [
			'class_id' => $this->ids['classes']['FY-A'], 'subject_id' => $this->ids['subjects']['CS103'], 'teacher_id' => $this->ids['teachers']['rahul'],
		]);
		$this->assertStatus(201, $r);
		$r = $this->call('POST', '/api/admin/teaching', [
			'class_id' => $this->ids['classes']['FY-A'], 'subject_id' => $this->ids['subjects']['CS103'], 'teacher_id' => $this->ids['teachers']['rahul'],
		]);
		$this->assertStatus(422, $r, 'duplicate');
		$r = $this->call('POST', '/api/admin/teaching', [
			'class_id' => $this->ids['classes']['FY-A'], 'subject_id' => $this->ids['subjects']['CS103'], 'teacher_id' => $this->ids['students']['priya'],
		]);
		$this->assertStatus(422, $r, 'students cannot teach');
	}

	public function testTenantIsolation(): void
	{
		$db = Database::get();
		$other = $db->insert('institutions', ['name' => 'Other School', 'code' => 'OTHER', 'kind' => 'school']);
		$otherSession = $db->insert('academic_sessions', ['institution_id' => $other, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
		$otherClass = $db->insert('classes', ['institution_id' => $other, 'academic_session_id' => $otherSession, 'name' => 'Grade 5']);
		$otherUser = $db->insert('users', ['institution_id' => $other, 'role' => 'student', 'email' => 'x@other.test', 'password_hash' => 'x', 'full_name' => 'Other Kid']);

		$this->assertStatus(404, $this->get("/api/admin/users/$otherUser"));
		$this->assertStatus(404, $this->get("/api/admin/classes/$otherClass"));
		$this->assertStatus(422, $this->call('POST', "/api/admin/classes/$otherClass/students", ['student_ids' => [$this->ids['students']['priya']]]));
		$this->assertStatus(422, $this->call('POST', '/api/admin/classes', ['name' => 'Sneaky', 'academic_session_id' => $otherSession]));
		$all = $this->json($this->get('/api/admin/users?q=Other'))['data'];
		$this->assertCount(0, $all);
	}

	public function testCsvImportIsAllOrNothing(): void
	{
		$csv = "role,full_name,email,password,admission_no,class\n"
			. "student,Import One,import1@eduflow.test,Secret123,IMP1,FY BSc CS A\n"
			. "student,Import Two,import2@eduflow.test,Secret123,IMP2,No Such Class\n";
		$r = $this->call('POST', '/api/admin/users/import', [], ['file' => $this->fakeUpload('users.csv', $csv)]);
		$this->assertStatus(422, $r);
		$this->assertFalse((bool) Database::get()->value("SELECT 1 FROM users WHERE email = 'import1@eduflow.test'"));

		$csv = "role,full_name,email,password,admission_no,employee_no,class\n"
			. "student,Import One,import1@eduflow.test,Secret123,IMP1,,FY BSc CS A\n"
			. "teacher,Import Teacher,importt@eduflow.test,Secret123,,T-900,\n";
		$r = $this->call('POST', '/api/admin/users/import', [], ['file' => $this->fakeUpload('users.csv', $csv)]);
		$this->assertStatus(200, $r);
		$this->assertSame(2, $this->json($r)['created']);
	}

	public function testOverviewCountsMatchDatabase(): void
	{
		$d = $this->json($this->get('/api/admin/overview'))['data'];
		$this->assertSame(7, $d['students']);
		$this->assertSame(2, $d['teachers']);
		$this->assertSame(2, $d['classes']);
		$this->assertSame(4, $d['subjects']);
	}

	public function testSettings(): void
	{
		$this->assertStatus(422, $this->call('PATCH', '/api/admin/settings', ['timezone' => 'Mars/Olympus']));
		$this->assertStatus(200, $this->call('PATCH', '/api/admin/settings', ['timezone' => 'Europe/London', 'due_soon_hours' => 48]));
		$s = $this->json($this->get('/api/admin/settings'))['data'];
		$this->assertSame('Europe/London', $s['timezone']);
		$this->assertSame(48, $s['settings']['due_soon_hours']);
		$this->actingAs($this->ids['teachers']['meera']);
		$this->assertStatus(403, $this->call('PATCH', '/api/admin/settings', ['name' => 'Hijacked']));
	}

	public function testAdminPagesRender(): void
	{
		foreach (['/admin', '/admin/users', '/admin/students', '/admin/classes', '/admin/subjects', '/admin/sessions', '/admin/settings', '/admin/audit-logs', '/profile'] as $p) {
			$r = $this->get($p);
			$this->assertStatus(200, $r, $p);
			$this->assertTrue(str_contains($r->body, 'csrf-token'), "$p has CSRF meta");
		}
	}
}
