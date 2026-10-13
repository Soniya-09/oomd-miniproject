<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Database;
use App\Validators\PasswordPolicy;

/**
 * Fictional demo institution. All people and emails are made up.
 * Password for every demo account: Demo@1234
 */
final class DemoSeeder
{
	public const PASSWORD = 'Demo@1234';

	private Database $db;
	private string $hash;
	public array $ids = [];

	public function __construct()
	{
		$this->db = Database::get();
		$this->hash = PasswordPolicy::hash(self::PASSWORD);
	}

	public function run(): array
	{
		return $this->db->transaction(function () {
			$inst = $this->db->insert('institutions', [
				'name' => 'Riverside College of Arts & Science',
				'code' => 'RIVERSIDE',
				'kind' => 'college',
				'timezone' => 'Asia/Kolkata',
			]);
			$this->ids['institution'] = $inst;

			$this->ids['admin'] = $this->user($inst, 'admin', 'admin@eduflow.test', 'Asha Admin');

			$session = $this->db->insert('academic_sessions', [
				'institution_id' => $inst, 'name' => '2026-27', 'starts_on' => '2026-06-15', 'ends_on' => '2027-04-30', 'is_current' => 1,
			]);
			$cs = $this->db->insert('departments', ['institution_id' => $inst, 'name' => 'Computer Science', 'code' => 'CS']);
			$math = $this->db->insert('departments', ['institution_id' => $inst, 'name' => 'Mathematics', 'code' => 'MATH']);

			$subjects = [];
			foreach ([
				['CS101', 'Programming Fundamentals', $cs],
				['CS102', 'Database Systems', $cs],
				['CS103', 'Web Technologies', $cs],
				['MA101', 'Discrete Mathematics', $math],
			] as [$code, $name, $dep]) {
				$subjects[$code] = $this->db->insert('subjects', ['institution_id' => $inst, 'code' => $code, 'name' => $name, 'department_id' => $dep]);
			}
			$this->ids['subjects'] = $subjects;

			$fyA = $this->db->insert('classes', ['institution_id' => $inst, 'academic_session_id' => $session, 'department_id' => $cs, 'name' => 'FY BSc CS', 'division' => 'A']);
			$fyB = $this->db->insert('classes', ['institution_id' => $inst, 'academic_session_id' => $session, 'department_id' => $cs, 'name' => 'FY BSc CS', 'division' => 'B']);
			$this->ids['classes'] = ['FY-A' => $fyA, 'FY-B' => $fyB];

			$t1 = $this->teacher($inst, 'meera.teacher@eduflow.test', 'Meera Kulkarni', 'T-001', 'Assistant Professor', $cs);
			$t2 = $this->teacher($inst, 'rahul.teacher@eduflow.test', 'Rahul Deshmukh', 'T-002', 'Associate Professor', $math);
			$this->ids['teachers'] = ['meera' => $t1, 'rahul' => $t2];

			foreach ([
				[$fyA, 'CS101', $t1], [$fyA, 'CS102', $t1], [$fyA, 'MA101', $t2],
				[$fyB, 'CS101', $t1], [$fyB, 'CS103', $t1], [$fyB, 'MA101', $t2],
			] as [$class, $code, $teacher]) {
				$this->db->insert('teacher_assignments', ['class_id' => $class, 'subject_id' => $subjects[$code], 'teacher_id' => $teacher]);
			}

			$students = [];
			$names = [
				['priya', 'Priya Sharma', $fyA], ['arjun', 'Arjun Mehta', $fyA], ['sara', 'Sara Khan', $fyA], ['dev', 'Dev Patil', $fyA],
				['neha', 'Neha Joshi', $fyB], ['kabir', 'Kabir Singh', $fyB], ['isha', 'Isha Nair', $fyB],
			];
			foreach ($names as $i => [$slug, $name, $class]) {
				$id = $this->user($inst, 'student', "$slug.student@eduflow.test", $name);
				$this->db->insert('students', [
					'user_id' => $id, 'institution_id' => $inst, 'admission_no' => sprintf('RC26%03d', $i + 1), 'roll_no' => (string) ($i + 1),
				]);
				$this->db->insert('class_students', ['class_id' => $class, 'student_id' => $id]);
				$students[$slug] = $id;
			}
			$this->ids['students'] = $students;

			return $this->ids;
		});
	}

	private function user(int $inst, string $role, string $email, string $name): int
	{
		return $this->db->insert('users', [
			'institution_id' => $inst, 'role' => $role, 'email' => $email, 'password_hash' => $this->hash, 'full_name' => $name,
		]);
	}

	private function teacher(int $inst, string $email, string $name, string $emp, string $designation, int $dep): int
	{
		$id = $this->user($inst, 'teacher', $email, $name);
		$this->db->insert('teachers', ['user_id' => $id, 'institution_id' => $inst, 'employee_no' => $emp, 'designation' => $designation, 'department_id' => $dep]);
		return $id;
	}
}
