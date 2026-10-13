<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Paginator;

final class UserRepository
{
	private Database $db;

	public function __construct()
	{
		$this->db = Database::get();
	}

	public function findByEmail(string $email): ?array
	{
		return $this->db->one('SELECT * FROM users WHERE email = :e AND deleted_at IS NULL', ['e' => mb_strtolower($email)]);
	}

	public function find(int $institutionId, int $id): ?array
	{
		return $this->db->one(
			'SELECT u.id, u.role, u.email, u.full_name, u.phone, u.is_active, u.last_login_at, u.created_at,
				s.admission_no, s.roll_no, s.guardian_name,
				t.employee_no, t.designation, t.department_id
			 FROM users u
			 LEFT JOIN students s ON s.user_id = u.id
			 LEFT JOIN teachers t ON t.user_id = u.id
			 WHERE u.id = :id AND u.institution_id = :inst AND u.deleted_at IS NULL',
			['id' => $id, 'inst' => $institutionId],
		);
	}

	public function emailTaken(string $email, ?int $exceptId = null): bool
	{
		return (bool) $this->db->value(
			'SELECT 1 FROM users WHERE email = :e AND id <> :id',
			['e' => mb_strtolower($email), 'id' => $exceptId ?? 0],
		);
	}

	public function paginate(int $institutionId, Paginator $p, array $filters, string $orderBy): array
	{
		$where = ['u.institution_id = :inst', 'u.deleted_at IS NULL'];
		$params = ['inst' => $institutionId];
		if (!empty($filters['role'])) {
			$where[] = 'u.role = :role';
			$params['role'] = $filters['role'];
		}
		if (isset($filters['active']) && $filters['active'] !== '') {
			$where[] = 'u.is_active = :active';
			$params['active'] = (int) $filters['active'];
		}
		if (!empty($filters['class_id'])) {
			$where[] = 'u.id IN (SELECT student_id FROM class_students WHERE class_id = :class_id)';
			$params['class_id'] = (int) $filters['class_id'];
		}
		if (!empty($filters['q'])) {
			$where[] = '(u.full_name LIKE :q1 OR u.email LIKE :q2 OR s.admission_no LIKE :q3 OR t.employee_no LIKE :q4)';
			$like = Paginator::like($filters['q']);
			$params += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like];
		}
		$from = 'FROM users u LEFT JOIN students s ON s.user_id = u.id LEFT JOIN teachers t ON t.user_id = u.id
			WHERE ' . implode(' AND ', $where);
		return $p->run(
			'SELECT u.id, u.role, u.email, u.full_name, u.phone, u.is_active, u.last_login_at, u.created_at,
				s.admission_no, s.roll_no, t.employee_no, t.designation,
				(SELECT GROUP_CONCAT(CONCAT(c.name, IF(c.division = "", "", CONCAT(" ", c.division))) ORDER BY c.name SEPARATOR ", ")
					FROM class_students cs JOIN classes c ON c.id = cs.class_id WHERE cs.student_id = u.id) AS classes',
			$from,
			$params,
			$orderBy,
		);
	}
}
