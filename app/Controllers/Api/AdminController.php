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
use App\Repositories\UserRepository;
use App\Services\AcademicService;
use App\Services\AuditLog;
use App\Services\UserService;

/** Admin-only JSON endpoints. Routes are wrapped in role:admin + csrf middleware. */
final class AdminController
{
	private function academic(): AcademicService
	{
		return new AcademicService(Auth::institutionId());
	}

	// --- Overview -------------------------------------------------------------

	public function overview(Request $request): Response
	{
		$i = Auth::institutionId();
		$db = Database::get();
		$counts = $db->one(
			"SELECT
				(SELECT COUNT(*) FROM users WHERE institution_id = :i1 AND role = 'student' AND is_active = 1 AND deleted_at IS NULL) AS students,
				(SELECT COUNT(*) FROM users WHERE institution_id = :i2 AND role = 'teacher' AND is_active = 1 AND deleted_at IS NULL) AS teachers,
				(SELECT COUNT(*) FROM classes WHERE institution_id = :i3 AND is_active = 1) AS classes,
				(SELECT COUNT(*) FROM subjects WHERE institution_id = :i4 AND is_active = 1) AS subjects,
				(SELECT COUNT(*) FROM assignments WHERE institution_id = :i5 AND status = 'published') AS active_assignments",
			['i1' => $i, 'i2' => $i, 'i3' => $i, 'i4' => $i, 'i5' => $i],
		);
		$counts = array_map('intval', $counts);
		$counts['submission_rate'] = (new \App\Services\AnalyticsService())->institutionSubmissionRate($i);
		return Response::json(['data' => $counts]);
	}

	// --- Users ----------------------------------------------------------------

	public function users(Request $request): Response
	{
		$f = Validator::make($request->query, [
			'role' => 'in:admin,teacher,student',
			'active' => 'in:0,1',
			'class_id' => 'int|min:1',
			'q' => 'string|max:100',
		]);
		$order = Paginator::order($request, [
			'name' => 'u.full_name', 'email' => 'u.email', 'role' => 'u.role', 'created' => 'u.created_at', 'last_login' => 'u.last_login_at',
		], 'u.full_name ASC');
		$result = (new UserRepository())->paginate(Auth::institutionId(), new Paginator($request), $f, $order);
		$result['data'] = array_map(fn($u) => $this->presentUser($u), $result['data']);
		return Response::json($result);
	}

	public function user(Request $request): Response
	{
		$u = (new UserRepository())->find(Auth::institutionId(), $request->intParam('id')) ?? throw new HttpException(404);
		$u = $this->presentUser($u);
		$rows = Database::get()->all('SELECT class_id FROM class_students WHERE student_id = :id', ['id' => $u['id']]);
		$u['class_ids'] = array_map('intval', array_column($rows, 'class_id'));
		return Response::json(['data' => $u]);
	}

	public function createUser(Request $request): Response
	{
		$id = (new UserService(Auth::institutionId()))->create($request->input());
		return Response::json(['data' => ['id' => $id]], 201);
	}

	public function updateUser(Request $request): Response
	{
		(new UserService(Auth::institutionId()))->update($request->intParam('id'), $request->input(), Auth::id());
		return Response::json(['ok' => true]);
	}

	public function importUsers(Request $request): Response
	{
		$files = $request->uploadedFiles('file');
		if (count($files) !== 1 || $files[0]['error'] !== UPLOAD_ERR_OK) {
			throw HttpException::validation(['file' => 'Choose one CSV file.']);
		}
		if (strtolower(pathinfo($files[0]['name'], PATHINFO_EXTENSION)) !== 'csv' || $files[0]['size'] > 2 * 1024 * 1024) {
			throw HttpException::validation(['file' => 'Upload a .csv file up to 2 MB.']);
		}
		$result = (new UserService(Auth::institutionId()))->importCsv($files[0]['tmp_name']);
		return Response::json($result, $result['errors'] ? 422 : 200);
	}

	private function presentUser(array $u): array
	{
		unset($u['password_hash']);
		$u['id'] = (int) $u['id'];
		$u['is_active'] = (bool) $u['is_active'];
		$u['last_login_at'] = iso_utc($u['last_login_at'] ?? null);
		$u['created_at'] = iso_utc($u['created_at'] ?? null);
		return $u;
	}

	// --- Academic structure ---------------------------------------------------

	public function sessions(Request $request): Response
	{
		return Response::json(['data' => $this->academic()->sessions()]);
	}

	public function saveSession(Request $request): Response
	{
		$id = $this->academic()->saveSession($this->optionalId($request), $request->input());
		return Response::json(['data' => ['id' => $id]], $this->optionalId($request) ? 200 : 201);
	}

	public function departments(Request $request): Response
	{
		return Response::json(['data' => $this->academic()->departments()]);
	}

	public function saveDepartment(Request $request): Response
	{
		$id = $this->academic()->saveDepartment($this->optionalId($request), $request->input());
		return Response::json(['data' => ['id' => $id]], $this->optionalId($request) ? 200 : 201);
	}

	public function subjects(Request $request): Response
	{
		return Response::json(['data' => $this->academic()->subjects()]);
	}

	public function saveSubject(Request $request): Response
	{
		$id = $this->academic()->saveSubject($this->optionalId($request), $request->input());
		return Response::json(['data' => ['id' => $id]], $this->optionalId($request) ? 200 : 201);
	}

	public function classes(Request $request): Response
	{
		$session = filter_var($request->query['session_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
		return Response::json(['data' => $this->academic()->classes($session)]);
	}

	public function classDetail(Request $request): Response
	{
		$id = $request->intParam('id');
		$class = array_values(array_filter($this->academic()->classes(), fn($c) => (int) $c['id'] === $id))[0] ?? throw new HttpException(404);
		return Response::json(['data' => [
			'class' => $class,
			'students' => $this->academic()->classStudents($id),
			'teaching' => $this->academic()->teaching($id),
		]]);
	}

	public function saveClass(Request $request): Response
	{
		$id = $this->academic()->saveClass($this->optionalId($request), $request->input());
		return Response::json(['data' => ['id' => $id]], $this->optionalId($request) ? 200 : 201);
	}

	public function enroll(Request $request): Response
	{
		$d = Validator::make($request->input(), ['student_ids' => 'required|ids']);
		$n = $this->academic()->enroll([$request->intParam('id')], $d['student_ids']);
		return Response::json(['ok' => true, 'enrolled' => $n]);
	}

	public function unenroll(Request $request): Response
	{
		$this->academic()->unenroll($request->intParam('id'), $request->intParam('studentId'));
		return Response::json(['ok' => true]);
	}

	public function teaching(Request $request): Response
	{
		$teacher = filter_var($request->query['teacher_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
		return Response::json(['data' => $this->academic()->teaching(null, $teacher)]);
	}

	public function assignTeacher(Request $request): Response
	{
		$id = $this->academic()->assignTeacher($request->input());
		return Response::json(['data' => ['id' => $id]], 201);
	}

	public function unassignTeacher(Request $request): Response
	{
		$this->academic()->unassignTeacher($request->intParam('id'));
		return Response::json(['ok' => true]);
	}

	// --- Institution settings -------------------------------------------------

	public function settings(Request $request): Response
	{
		$row = Database::get()->one('SELECT name, code, kind, timezone, settings FROM institutions WHERE id = :i', ['i' => Auth::institutionId()]);
		$row['settings'] = $row['settings'] ? json_decode($row['settings'], true) : [];
		$row['timezones'] = \DateTimeZone::listIdentifiers();
		return Response::json(['data' => $row]);
	}

	public function saveSettings(Request $request): Response
	{
		$d = Validator::make($request->input(), [
			'name' => 'string|min:3|max:190',
			'kind' => 'in:school,college',
			'timezone' => 'string|max:64',
			'due_soon_hours' => 'int|min:1|max:168',
		]);
		if (isset($d['timezone']) && !in_array($d['timezone'], \DateTimeZone::listIdentifiers(), true)) {
			throw HttpException::validation(['timezone' => 'Choose a valid time zone.']);
		}
		$db = Database::get();
		$fields = array_intersect_key($d, array_flip(['name', 'kind', 'timezone']));
		if (array_key_exists('due_soon_hours', $d)) {
			$current = json_decode((string) $db->value('SELECT settings FROM institutions WHERE id = :i', ['i' => Auth::institutionId()]), true) ?: [];
			$fields['settings'] = json_encode(['due_soon_hours' => $d['due_soon_hours']] + $current);
		}
		if ($fields) {
			$db->update('institutions', $fields, 'id = :i', ['i' => Auth::institutionId()]);
			AuditLog::record('settings.updated', 'institution', Auth::institutionId(), $d);
		}
		return Response::json(['ok' => true]);
	}

	// --- Audit log ------------------------------------------------------------

	public function auditLogs(Request $request): Response
	{
		$f = Validator::make($request->query, ['action' => 'string|max:60', 'user_id' => 'int|min:1']);
		$where = ['a.institution_id = :i'];
		$params = ['i' => Auth::institutionId()];
		if (!empty($f['action'])) {
			$where[] = 'a.action LIKE :a';
			$params['a'] = Paginator::like($f['action']);
		}
		if (!empty($f['user_id'])) {
			$where[] = 'a.user_id = :u';
			$params['u'] = $f['user_id'];
		}
		$result = (new Paginator($request, 50))->run(
			'SELECT a.id, a.action, a.entity_type, a.entity_id, a.details, a.ip_address, a.created_at, u.full_name AS user_name, u.email AS user_email',
			'FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE ' . implode(' AND ', $where),
			$params,
			'a.id DESC',
		);
		foreach ($result['data'] as &$row) {
			$row['created_at'] = iso_utc($row['created_at']);
			$row['details'] = $row['details'] ? json_decode($row['details'], true) : null;
		}
		return Response::json($result);
	}

	private function optionalId(Request $request): ?int
	{
		return isset($request->params['id']) ? $request->intParam('id') : null;
	}
}
