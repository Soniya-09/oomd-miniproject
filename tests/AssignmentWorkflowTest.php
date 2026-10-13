<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Database;

final class AssignmentWorkflowTest extends TestCase
{
	private function due(string $offset): string
	{
		return gmdate('Y-m-d\TH:i:s\Z', strtotime($offset));
	}

	private function createAssignment(array $over = [], string $teacher = 'meera'): int
	{
		$this->actingAs($this->ids['teachers'][$teacher]);
		$r = $this->call('POST', '/api/teacher/assignments', $over + [
			'title' => 'Loops worksheet',
			'description' => 'Solve all 10 problems.',
			'class_id' => $this->ids['classes']['FY-A'],
			'subject_id' => $this->ids['subjects']['CS101'],
			'due_at' => $this->due('+3 days'),
			'max_marks' => 20,
			'allowed_extensions' => 'pdf',
		]);
		$this->assertStatus(201, $r);
		return $this->json($r)['data']['id'];
	}

	private function submitAs(string $student, int $assignmentId, ?array $file = null): \App\Core\Response
	{
		$this->actingAs($this->ids['students'][$student]);
		return $this->call('POST', "/api/student/assignments/$assignmentId/submission", ['note' => 'Done'], ['files' => $file ?? $this->fakeUpload('answers.pdf', $this->pdf())]);
	}

	public function testTeacherCannotCreateOutsideScope(): void
	{
		$this->actingAs($this->ids['teachers']['rahul']); // teaches MA101 only
		$r = $this->call('POST', '/api/teacher/assignments', [
			'title' => 'Sneaky', 'description' => 'x', 'class_id' => $this->ids['classes']['FY-A'], 'subject_id' => $this->ids['subjects']['CS101'],
		]);
		$this->assertStatus(422, $r);
	}

	public function testDraftHiddenFromStudentsUntilPublished(): void
	{
		$id = $this->createAssignment();
		$this->actingAs($this->ids['students']['priya']);
		$this->assertStatus(404, $this->get("/api/student/assignments/$id"));
		$this->assertCount(0, $this->json($this->get('/api/student/assignments'))['data']);

		$this->actingAs($this->ids['teachers']['meera']);
		$this->assertStatus(200, $this->call('POST', "/api/teacher/assignments/$id/publish"));
		$this->assertStatus(409, $this->call('POST', "/api/teacher/assignments/$id/publish"), 'cannot publish twice');

		$this->actingAs($this->ids['students']['priya']);
		$this->assertStatus(200, $this->get("/api/student/assignments/$id"));
		$this->actingAs($this->ids['students']['neha']); // FY-B
		$this->assertStatus(404, $this->get("/api/student/assignments/$id"), 'other class cannot see it');
	}

	public function testPublishingNotifiesRecipientsOnce(): void
	{
		$id = $this->createAssignment(['publish' => true]);
		$count = fn() => (int) Database::get()->value("SELECT COUNT(*) FROM notifications WHERE related_id = :a AND type = 'assignment_new'", ['a' => $id]);
		$this->assertSame(4, $count(), 'FY-A has 4 students');
		// Editing re-runs the notify step; the dedupe key prevents duplicates.
		$this->call('PATCH', "/api/teacher/assignments/$id", ['title' => 'Loops worksheet v2']);
		$this->assertSame(4, $count());
		$this->call('PATCH', "/api/teacher/assignments/$id", ['due_at' => $this->due('+5 days')]);
		$this->assertSame(4, (int) Database::get()->value("SELECT COUNT(*) FROM notifications WHERE related_id = :a AND type = 'deadline_changed'", ['a' => $id]));
	}

	public function testTargetedAssignment(): void
	{
		$id = $this->createAssignment(['target_all' => false, 'student_ids' => [$this->ids['students']['priya']], 'publish' => true]);
		$this->actingAs($this->ids['students']['priya']);
		$this->assertStatus(200, $this->get("/api/student/assignments/$id"));
		$this->actingAs($this->ids['students']['arjun']);
		$this->assertStatus(404, $this->get("/api/student/assignments/$id"), 'classmate not targeted');

		$this->actingAs($this->ids['teachers']['meera']);
		$r = $this->call('POST', '/api/teacher/assignments', [
			'title' => 'Bad target', 'description' => 'x', 'class_id' => $this->ids['classes']['FY-A'], 'subject_id' => $this->ids['subjects']['CS101'],
			'target_all' => false, 'student_ids' => [$this->ids['students']['neha']],
		]);
		$this->assertStatus(422, $r, 'targets must be enrolled in the class');
	}

	public function testPublishRequiresDueDate(): void
	{
		$this->actingAs($this->ids['teachers']['meera']);
		$r = $this->call('POST', '/api/teacher/assignments', [
			'title' => 'No due', 'description' => 'x', 'class_id' => $this->ids['classes']['FY-A'], 'subject_id' => $this->ids['subjects']['CS101'], 'publish' => true,
		]);
		$this->assertStatus(422, $r);
	}

	public function testSubmitGradeAndFeedbackFlow(): void
	{
		$id = $this->createAssignment(['publish' => true]);
		$r = $this->submitAs('priya', $id);
		$this->assertStatus(201, $r);
		$view = $this->json($r)['data'];
		$this->assertSame('submitted', $view['student_status']);
		$this->assertFalse($view['submission']['is_late']);
		$subId = $view['submission']['id'];
		$fileId = $view['submission']['files'][0]['id'];

		// Teacher sees it in the queue, saves a grade without releasing.
		$this->actingAs($this->ids['teachers']['meera']);
		$q = $this->json($this->get('/api/teacher/submissions'))['data'];
		$this->assertSame($subId, (int) $q[0]['id']);
		$this->assertStatus(422, $this->call('POST', "/api/teacher/submissions/$subId/grade", ['marks' => 25]), 'over max marks');
		$this->assertStatus(200, $this->call('POST', "/api/teacher/submissions/$subId/grade", ['marks' => 17, 'feedback' => 'Good work', 'release' => false]));

		// Student cannot see unreleased marks or feedback.
		$this->actingAs($this->ids['students']['priya']);
		$v = $this->json($this->get("/api/student/assignments/$id"))['data'];
		$this->assertSame(null, $v['submission']['marks']);
		$this->assertCount(0, $v['submission']['feedback']);
		$this->assertSame('under_review', $v['submission']['status']);

		$this->actingAs($this->ids['teachers']['meera']);
		$this->assertStatus(200, $this->call('POST', "/api/teacher/submissions/$subId/release"));
		$this->actingAs($this->ids['students']['priya']);
		$v = $this->json($this->get("/api/student/assignments/$id"))['data'];
		$this->assertSame(17.0, (float) $v['submission']['marks']);
		$this->assertSame('Good work', $v['submission']['feedback'][0]['comment']);
		$this->assertTrue((bool) Database::get()->value("SELECT 1 FROM notifications WHERE user_id = :u AND type = 'feedback'", ['u' => $this->ids['students']['priya']]));

		// Graded work cannot be changed by re-uploading.
		$this->assertStatus(409, $this->submitAs('priya', $id));

		// Download authorization.
		$this->actingAs($this->ids['students']['priya']);
		$this->assertStatus(200, $this->get("/api/files/submissions/$fileId"));
		$this->actingAs($this->ids['students']['arjun']);
		$this->assertStatus(404, $this->get("/api/files/submissions/$fileId"), 'classmate cannot download');
		$this->actingAs($this->ids['teachers']['rahul']);
		$this->assertStatus(404, $this->get("/api/files/submissions/$fileId"), 'teacher of another subject cannot download');
		$this->assertStatus(404, $this->get("/api/teacher/submissions/$subId"));
		$this->actingAs($this->ids['admin']);
		$this->assertStatus(403, $this->get("/api/teacher/submissions/$subId"));

		$history = Database::get()->value('SELECT COUNT(*) FROM grading_history WHERE submission_id = :s', ['s' => $subId]);
		$this->assertSame(3, (int) $history, 'submit, grade, release');
	}

	public function testReturnAndResubmit(): void
	{
		$id = $this->createAssignment(['publish' => true]);
		$subId = $this->json($this->submitAs('arjun', $id))['data']['submission']['id'];
		$this->actingAs($this->ids['teachers']['meera']);
		$this->assertStatus(422, $this->call('POST', "/api/teacher/submissions/$subId/return", []), 'feedback required');
		$this->assertStatus(200, $this->call('POST', "/api/teacher/submissions/$subId/return", ['feedback' => 'Show your working.']));

		$r = $this->submitAs('arjun', $id);
		$this->assertStatus(201, $r);
		$v = $this->json($r)['data']['submission'];
		$this->assertSame('resubmitted', $v['status']);
		$this->assertSame(2, $v['attempt']);
		$this->assertCount(2, $v['files'], 'history of both attempts kept');
	}

	public function testResubmissionPolicyAndRequest(): void
	{
		$id = $this->createAssignment(['publish' => true, 'allow_resubmission' => false]);
		$subId = $this->json($this->submitAs('sara', $id))['data']['submission']['id'];
		$this->actingAs($this->ids['teachers']['meera']);
		$this->call('POST', "/api/teacher/submissions/$subId/grade", ['marks' => 10]);
		$this->actingAs($this->ids['students']['sara']);
		$r = $this->call('POST', "/api/student/assignments/$id/resubmission-request", ['reason' => 'Uploaded wrong file']);
		$this->assertStatus(409, $r, 'policy forbids it');

		$id2 = $this->createAssignment(['publish' => true, 'title' => 'Second']);
		$sub2 = $this->json($this->submitAs('sara', $id2))['data']['submission']['id'];
		$this->actingAs($this->ids['teachers']['meera']);
		$this->call('POST', "/api/teacher/submissions/$sub2/grade", ['marks' => 10]);
		$this->actingAs($this->ids['students']['sara']);
		$this->assertStatus(200, $this->call('POST', "/api/student/assignments/$id2/resubmission-request", ['reason' => 'Uploaded wrong file']));
		$this->assertTrue((bool) Database::get()->value("SELECT 1 FROM notifications WHERE user_id = :u AND type = 'resubmission_requested'", ['u' => $this->ids['teachers']['meera']]));
	}

	public function testLateSubmissionRules(): void
	{
		$db = Database::get();
		$late = $this->createAssignment(['publish' => true]);
		$db->update('assignments', ['due_at' => gmdate('Y-m-d H:i:s', time() - 3600)], 'id = :id', ['id' => $late]);
		$r = $this->submitAs('dev', $late);
		$this->assertStatus(201, $r);
		$this->assertTrue($this->json($r)['data']['submission']['is_late']);

		$strict = $this->createAssignment(['publish' => true, 'allow_late' => false, 'title' => 'Strict']);
		$db->update('assignments', ['due_at' => gmdate('Y-m-d H:i:s', time() - 60)], 'id = :id', ['id' => $strict]);
		$this->assertStatus(409, $this->submitAs('dev', $strict));

		$this->actingAs($this->ids['students']['priya']);
		$list = $this->json($this->get('/api/student/assignments?status=missing'))['data'];
		$this->assertCount(2, $list, 'both overdue assignments are missing for priya');
	}

	public function testUploadValidation(): void
	{
		$id = $this->createAssignment(['publish' => true]);
		$r = $this->submitAs('priya', $id, $this->fakeUpload('evil.php', '<?php echo 1;'));
		$this->assertStatus(422, $r, 'php rejected');
		$r = $this->submitAs('priya', $id, $this->fakeUpload('fake.pdf', 'just text pretending'));
		$this->assertStatus(422, $r, 'content does not match extension');
		$r = $this->submitAs('priya', $id, $this->fakeUpload('photo.png', "\x89PNG\r\n\x1a\n" . str_repeat("\0", 64)));
		$this->assertStatus(422, $r, 'png not allowed for this assignment');
		$r = $this->submitAs('priya', $id, $this->fakeUpload('a.php.pdf', $this->pdf()));
		$this->assertStatus(422, $r, 'double extension rejected');

		Database::get()->update('assignments', ['max_upload_mb' => 1], 'id = :id', ['id' => $id]);
		$r = $this->submitAs('priya', $id, $this->fakeUpload('big.pdf', $this->pdf() . str_repeat('A', 1024 * 1024 + 10)));
		$this->assertStatus(422, $r, 'too large');
		$this->assertFalse((bool) Database::get()->value('SELECT 1 FROM submissions WHERE assignment_id = :a', ['a' => $id]));
	}

	public function testClosedAndCancelledAssignments(): void
	{
		$id = $this->createAssignment(['publish' => true]);
		$this->assertStatus(200, $this->call('POST', "/api/teacher/assignments/$id/close"));
		$this->assertStatus(409, $this->submitAs('priya', $id), 'closed');
		$this->actingAs($this->ids['teachers']['meera']);
		$this->assertStatus(200, $this->call('POST', "/api/teacher/assignments/$id/reopen"));
		$this->assertStatus(200, $this->call('POST', "/api/teacher/assignments/$id/cancel"));
		$this->assertStatus(409, $this->call('POST', "/api/teacher/assignments/$id/publish"), 'cancelled is final');
		$this->actingAs($this->ids['students']['priya']);
		$this->assertStatus(404, $this->get("/api/student/assignments/$id"));
	}

	public function testAttachmentsAndDownloadScope(): void
	{
		$id = $this->createAssignment();
		$r = $this->call('POST', "/api/teacher/assignments/$id/attachments", [], ['files' => $this->fakeUpload('notes.pdf', $this->pdf())]);
		$this->assertStatus(201, $r);
		$att = $this->json($r)['data']['ids'][0];

		$this->actingAs($this->ids['students']['priya']);
		$this->assertStatus(404, $this->get("/api/files/attachments/$att"), 'draft attachment hidden');
		$this->actingAs($this->ids['teachers']['meera']);
		$this->call('POST', "/api/teacher/assignments/$id/publish");
		$this->actingAs($this->ids['students']['priya']);
		$r = $this->get("/api/files/attachments/$att");
		$this->assertStatus(200, $r);
		$this->assertSame('application/octet-stream', $r->header('Content-Type'));
		$this->actingAs($this->ids['students']['neha']);
		$this->assertStatus(404, $this->get("/api/files/attachments/$att"));
	}

	public function testStudentCannotUseTeacherApi(): void
	{
		$this->actingAs($this->ids['students']['priya']);
		$this->assertStatus(403, $this->get('/api/teacher/assignments'));
		$this->assertStatus(403, $this->call('POST', '/api/teacher/submissions/1/grade', ['marks' => 100]));
	}

	public function testRosterShowsMissingStudents(): void
	{
		$id = $this->createAssignment(['publish' => true]);
		$this->submitAs('priya', $id);
		Database::get()->update('assignments', ['due_at' => gmdate('Y-m-d H:i:s', time() - 60)], 'id = :id', ['id' => $id]);
		$this->actingAs($this->ids['teachers']['meera']);
		$roster = $this->json($this->get("/api/teacher/assignments/$id"))['data']['roster'];
		$statuses = array_count_values(array_column($roster, 'status'));
		$this->assertSame(['submitted' => 1, 'missing' => 3], $statuses);
	}
}
