<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Validator;
use App\Repositories\UserRepository;
use App\Validators\PasswordPolicy;

/** Admin-side user management. Every method is scoped to one institution. */
final class UserService
{
	private Database $db;
	private UserRepository $users;

	public function __construct(private readonly int $institutionId)
	{
		$this->db = Database::get();
		$this->users = new UserRepository();
	}

	public static function rules(bool $creating): array
	{
		$req = $creating ? 'required|' : '';
		return [
			'role' => $req . 'in:admin,teacher,student',
			'full_name' => $req . 'string|min:2|max:150',
			'email' => $req . 'email',
			'phone' => 'nullable|string|max:30|regex:/^[0-9+()\- ]*$/',
			'password' => ($creating ? 'required|' : 'nullable|') . 'string',
			'is_active' => 'bool',
			'admission_no' => 'nullable|string|max:40',
			'roll_no' => 'nullable|string|max:20',
			'guardian_name' => 'nullable|string|max:150',
			'employee_no' => 'nullable|string|max:40',
			'designation' => 'nullable|string|max:100',
			'department_id' => 'nullable|int|min:1',
			'class_ids' => 'ids',
		];
	}

	public function create(array $input): int
	{
		$d = Validator::make($input, self::rules(true));
		if ($this->users->emailTaken($d['email'])) {
			throw HttpException::validation(['email' => 'An account with this email already exists.']);
		}
		PasswordPolicy::enforce($d['password'], $d['email']);
		$this->checkProfileFields($d['role'], $d);

		return $this->db->transaction(function (Database $db) use ($d) {
			$id = $db->insert('users', [
				'institution_id' => $this->institutionId,
				'role' => $d['role'],
				'email' => $d['email'],
				'password_hash' => PasswordPolicy::hash($d['password']),
				'full_name' => $d['full_name'],
				'phone' => $d['phone'] ?? null,
				'is_active' => (int) ($d['is_active'] ?? true),
			]);
			$this->saveProfile($id, $d['role'], $d, true);
			if ($d['role'] === 'student' && !empty($d['class_ids'])) {
				(new AcademicService($this->institutionId))->enroll($d['class_ids'], [$id]);
			}
			AuditLog::record('user.created', 'user', $id, ['role' => $d['role'], 'email' => $d['email']]);
			return $id;
		});
	}

	public function update(int $id, array $input, int $actingUserId): void
	{
		$user = $this->users->find($this->institutionId, $id) ?? throw new HttpException(404);
		$d = Validator::make($input, self::rules(false));
		unset($d['role'], $d['class_ids']); // role changes are not supported; enrolment has its own endpoint

		if (isset($d['email']) && $this->users->emailTaken($d['email'], $id)) {
			throw HttpException::validation(['email' => 'An account with this email already exists.']);
		}
		if (array_key_exists('is_active', $d) && !$d['is_active'] && $id === $actingUserId) {
			throw HttpException::validation(['is_active' => 'You cannot deactivate your own account.']);
		}
		if (!empty($d['password'])) {
			PasswordPolicy::enforce($d['password'], $d['email'] ?? $user['email']);
		}
		$this->checkProfileFields($user['role'], $d + $user, $id);

		$this->db->transaction(function (Database $db) use ($id, $d, $user) {
			$fields = array_intersect_key($d, array_flip(['full_name', 'email', 'phone']));
			if (array_key_exists('is_active', $d)) {
				$fields['is_active'] = (int) $d['is_active'];
			}
			if (!empty($d['password'])) {
				$fields['password_hash'] = PasswordPolicy::hash($d['password']);
			}
			if ($fields) {
				$db->update('users', $fields, 'id = :id AND institution_id = :inst', ['id' => $id, 'inst' => $this->institutionId]);
			}
			$this->saveProfile($id, $user['role'], $d, false);
			$changed = array_keys($d);
			if (!empty($d['password'])) {
				$changed = array_diff($changed, ['password']);
				$changed[] = 'password (reset by admin)';
			}
			$action = array_key_exists('is_active', $d) && (int) $d['is_active'] !== (int) $user['is_active']
				? ($d['is_active'] ? 'user.activated' : 'user.deactivated')
				: 'user.updated';
			AuditLog::record($action, 'user', $id, ['fields' => array_values($changed)]);
		});
	}

	private function checkProfileFields(string $role, array $d, ?int $exceptId = null): void
	{
		if ($role === 'student') {
			if (empty($d['admission_no'])) {
				throw HttpException::validation(['admission_no' => 'Admission number is required for students.']);
			}
			$dup = $this->db->value(
				'SELECT 1 FROM students WHERE institution_id = :i AND admission_no = :a AND user_id <> :id',
				['i' => $this->institutionId, 'a' => $d['admission_no'], 'id' => $exceptId ?? 0],
			);
			if ($dup) {
				throw HttpException::validation(['admission_no' => 'This admission number is already used.']);
			}
		}
		if ($role === 'teacher') {
			if (empty($d['employee_no'])) {
				throw HttpException::validation(['employee_no' => 'Employee number is required for teachers.']);
			}
			$dup = $this->db->value(
				'SELECT 1 FROM teachers WHERE institution_id = :i AND employee_no = :e AND user_id <> :id',
				['i' => $this->institutionId, 'e' => $d['employee_no'], 'id' => $exceptId ?? 0],
			);
			if ($dup) {
				throw HttpException::validation(['employee_no' => 'This employee number is already used.']);
			}
			if (!empty($d['department_id']) && !$this->db->value(
				'SELECT 1 FROM departments WHERE id = :d AND institution_id = :i',
				['d' => $d['department_id'], 'i' => $this->institutionId],
			)) {
				throw HttpException::validation(['department_id' => 'Unknown department.']);
			}
		}
	}

	private function saveProfile(int $id, string $role, array $d, bool $creating): void
	{
		if ($role === 'student') {
			$fields = array_intersect_key($d, array_flip(['admission_no', 'roll_no', 'guardian_name']));
			if ($creating) {
				$this->db->insert('students', ['user_id' => $id, 'institution_id' => $this->institutionId] + $fields);
			} elseif ($fields) {
				$this->db->update('students', $fields, 'user_id = :id', ['id' => $id]);
			}
		} elseif ($role === 'teacher') {
			$fields = array_intersect_key($d, array_flip(['employee_no', 'designation', 'department_id']));
			if ($creating) {
				$this->db->insert('teachers', ['user_id' => $id, 'institution_id' => $this->institutionId] + $fields);
			} elseif ($fields) {
				$this->db->update('teachers', $fields, 'user_id = :id', ['id' => $id]);
			}
		}
	}

	/**
	 * CSV columns: role,full_name,email,password,admission_no,roll_no,employee_no,designation,class
	 * ("class" = "Name Division" of an existing class, students only).
	 * Validates every row first; imports nothing if any row is invalid.
	 */
	public function importCsv(string $path): array
	{
		$fh = fopen($path, 'r');
		if (!$fh) {
			throw new HttpException(400, 'Could not read the uploaded file.');
		}
		$header = fgetcsv($fh, 0, ',', '"', '');
		if (!$header) {
			throw HttpException::validation(['file' => 'The CSV file is empty.']);
		}
		$header = array_map(fn($h) => strtolower(trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $header);
		foreach (['role', 'full_name', 'email', 'password'] as $required) {
			if (!in_array($required, $header, true)) {
				throw HttpException::validation(['file' => "Missing required column: $required"]);
			}
		}
		$classes = [];
		foreach ($this->db->all('SELECT id, name, division FROM classes WHERE institution_id = :i AND is_active = 1', ['i' => $this->institutionId]) as $c) {
			$classes[mb_strtolower(trim($c['name'] . ' ' . $c['division']))] = (int) $c['id'];
		}

		$rows = [];
		$errors = [];
		$seenEmails = [];
		$line = 1;
		while (($cols = fgetcsv($fh, 0, ',', '"', '')) !== false) {
			$line++;
			if ($cols === [null] || count(array_filter($cols, fn($v) => trim((string) $v) !== '')) === 0) {
				continue;
			}
			if ($line > 2001) {
				$errors[] = 'Import at most 2000 rows at a time.';
				break;
			}
			$row = array_combine($header, array_pad(array_slice($cols, 0, count($header)), count($header), ''));
			if (!empty($row['class'])) {
				$key = mb_strtolower(trim($row['class']));
				if (!isset($classes[$key])) {
					$errors[] = "Row $line: unknown class \"{$row['class']}\".";
					continue;
				}
				$row['class_ids'] = [$classes[$key]];
			}
			unset($row['class']);
			$email = mb_strtolower(trim((string) $row['email']));
			if (isset($seenEmails[$email])) {
				$errors[] = "Row $line: duplicate email in file.";
				continue;
			}
			$seenEmails[$email] = true;
			$rows[$line] = $row;
		}
		fclose($fh);

		// Create every row in one transaction; any row error rolls the whole import back.
		$created = 0;
		try {
			$this->db->transaction(function () use ($rows, &$errors, &$created) {
				foreach ($rows as $l => $row) {
					try {
						$this->db->transaction(fn() => $this->create(array_filter($row, fn($v) => $v !== '' && $v !== null)));
						$created++;
					} catch (HttpException $e) {
						$msg = $e->errors ? implode(' ', array_map(fn($k, $v) => "$k: $v", array_keys($e->errors), $e->errors)) : $e->getMessage();
						$errors[] = "Row $l: $msg";
					}
				}
				if ($errors) {
					throw new ImportRejected();
				}
				AuditLog::record('user.imported', 'user', null, ['count' => $created]);
			});
		} catch (ImportRejected) {
			return ['created' => 0, 'errors' => $errors];
		}
		return ['created' => $created, 'errors' => []];
	}
}
