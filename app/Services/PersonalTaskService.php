<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Paginator;
use App\Core\Validator;
use PDOException;

/**
 * Private personal tasks. Every query is filtered by the owner's user id, so a
 * task id belonging to someone else behaves exactly like a missing one (404).
 * Personal tasks never touch assignment or submission records.
 */
final class PersonalTaskService
{
	private Database $db;

	public function __construct(private readonly int $userId)
	{
		$this->db = Database::get();
	}

	public static function rules(bool $creating): array
	{
		$r = $creating ? 'required|' : '';
		return [
			'title' => $r . 'string|min:1|max:200',
			'description' => 'nullable|string|max:5000',
			'category_id' => 'nullable|int|min:1',
			'priority' => 'in:low,medium,high',
			'status' => 'in:pending,in_progress,completed,cancelled',
			'starts_at' => 'nullable|datetime',
			'due_at' => 'nullable|datetime',
			'duration_min' => 'int|min:5|max:1440',
			'context' => 'nullable|string|max:100',
			'raw_input' => 'nullable|string|max:500',
			'remind_at' => 'nullable|datetime',
		];
	}

	public function find(int $id): array
	{
		$t = $this->db->one(
			'SELECT t.*, c.name AS category_name, c.color AS category_color,
				(SELECT r.remind_at FROM task_reminders r WHERE r.task_id = t.id AND r.sent_at IS NULL ORDER BY r.remind_at LIMIT 1) AS remind_at
			 FROM personal_tasks t LEFT JOIN task_categories c ON c.id = t.category_id
			 WHERE t.id = :id AND t.user_id = :u AND t.deleted_at IS NULL',
			['id' => $id, 'u' => $this->userId],
		);
		return $t ?? throw new HttpException(404, 'Task not found.');
	}

	public function create(array $input): int
	{
		$d = Validator::make($input, self::rules(true));
		$this->checkCategory($d['category_id'] ?? null);
		$status = $d['status'] ?? 'pending';
		return $this->db->transaction(function (Database $db) use ($d, $status) {
			$id = $db->insert('personal_tasks', [
				'user_id' => $this->userId,
				'category_id' => $d['category_id'] ?? null,
				'title' => $d['title'],
				'description' => $d['description'] ?? null,
				'priority' => $d['priority'] ?? 'medium',
				'status' => $status,
				'starts_at' => $d['starts_at'] ?? null,
				'due_at' => $d['due_at'] ?? null,
				'duration_min' => $d['duration_min'] ?? 45,
				'context' => $d['context'] ?? null,
				'raw_input' => $d['raw_input'] ?? null,
				'completed_at' => $status === 'completed' ? now_utc() : null,
			]);
			$this->setReminder($id, $d['remind_at'] ?? null);
			return $id;
		});
	}

	public function update(int $id, array $input): array
	{
		$task = $this->find($id);
		$d = Validator::make($input, self::rules(false));
		if (array_key_exists('category_id', $d)) {
			$this->checkCategory($d['category_id']);
		}
		$fields = array_intersect_key($d, array_flip(['title', 'description', 'category_id', 'priority', 'status', 'starts_at', 'due_at', 'duration_min', 'context']));
		if (isset($d['status']) && $d['status'] !== $task['status']) {
			// completed_at records when work was finished; reopening clears it.
			$fields['completed_at'] = $d['status'] === 'completed' ? now_utc() : null;
		}
		$this->db->transaction(function (Database $db) use ($id, $fields, $d) {
			if ($fields) {
				$db->update('personal_tasks', $fields, 'id = :id AND user_id = :u', ['id' => $id, 'u' => $this->userId]);
			}
			if (array_key_exists('remind_at', $d)) {
				$this->setReminder($id, $d['remind_at']);
			}
		});
		return $this->find($id);
	}

	public function delete(int $id): void
	{
		$this->find($id);
		$this->db->update('personal_tasks', ['deleted_at' => now_utc()], 'id = :id AND user_id = :u', ['id' => $id, 'u' => $this->userId]);
		$this->db->run('DELETE FROM task_reminders WHERE task_id = :t AND sent_at IS NULL', ['t' => $id]);
	}

	private function setReminder(int $taskId, ?string $at): void
	{
		$this->db->run('DELETE FROM task_reminders WHERE task_id = :t AND sent_at IS NULL', ['t' => $taskId]);
		if ($at !== null) {
			if ($at <= now_utc()) {
				throw HttpException::validation(['remind_at' => 'Pick a reminder time in the future.']);
			}
			$this->db->run('INSERT IGNORE INTO task_reminders (task_id, remind_at) VALUES (:t, :r)', ['t' => $taskId, 'r' => $at]);
		}
	}

	private function checkCategory(?int $categoryId): void
	{
		if ($categoryId !== null && !$this->db->value('SELECT 1 FROM task_categories WHERE id = :c AND user_id = :u', ['c' => $categoryId, 'u' => $this->userId])) {
			throw HttpException::validation(['category_id' => 'Unknown category.']);
		}
	}

	public function list(Paginator $p, array $f, string $orderBy): array
	{
		$where = ['t.user_id = :u', 't.deleted_at IS NULL'];
		$params = ['u' => $this->userId];
		$now = now_utc();
		$status = $f['status'] ?? '';
		if ($status === 'overdue') {
			$where[] = "t.status IN ('pending', 'in_progress') AND t.due_at < :now";
			$params['now'] = $now;
		} elseif ($status === 'open') {
			$where[] = "t.status IN ('pending', 'in_progress')";
		} elseif ($status !== '') {
			$where[] = 't.status = :st';
			$params['st'] = $status;
		} else {
			$where[] = "t.status <> 'cancelled'";
		}
		foreach (['priority' => 't.priority', 'category_id' => 't.category_id'] as $k => $col) {
			if (!empty($f[$k])) {
				$where[] = "$col = :$k";
				$params[$k] = $f[$k];
			}
		}
		if (!empty($f['q'])) {
			$where[] = '(t.title LIKE :q1 OR t.description LIKE :q2)';
			$params += ['q1' => Paginator::like($f['q']), 'q2' => Paginator::like($f['q'])];
		}
		if (!empty($f['from'])) {
			$where[] = 't.due_at >= :from';
			$params['from'] = $f['from'];
		}
		if (!empty($f['to'])) {
			$where[] = 't.due_at < :to';
			$params['to'] = $f['to'];
		}
		$result = $p->run(
			'SELECT t.id, t.title, t.description, t.priority, t.status, t.starts_at, t.due_at, t.duration_min, t.context, t.completed_at, t.created_at,
				t.category_id, c.name AS category_name, c.color AS category_color,
				(SELECT r.remind_at FROM task_reminders r WHERE r.task_id = t.id AND r.sent_at IS NULL ORDER BY r.remind_at LIMIT 1) AS remind_at',
			'FROM personal_tasks t LEFT JOIN task_categories c ON c.id = t.category_id WHERE ' . implode(' AND ', $where),
			$params,
			$orderBy,
		);
		$result['data'] = array_map([self::class, 'present'], $result['data']);
		return $result;
	}

	public static function present(array $t): array
	{
		foreach (['starts_at', 'due_at', 'completed_at', 'created_at', 'updated_at', 'remind_at'] as $k) {
			if (array_key_exists($k, $t)) {
				$t[$k] = iso_utc($t[$k]);
			}
		}
		$t['is_overdue'] = in_array($t['status'], ['pending', 'in_progress'], true) && $t['due_at'] !== null && $t['due_at'] < iso_utc(now_utc());
		unset($t['user_id'], $t['deleted_at'], $t['raw_input']);
		return $t;
	}

	// --- Categories -------------------------------------------------------------

	public function categories(): array
	{
		return $this->db->all(
			"SELECT c.id, c.name, c.color, (SELECT COUNT(*) FROM personal_tasks t WHERE t.category_id = c.id AND t.deleted_at IS NULL AND t.status IN ('pending', 'in_progress')) AS open_count
			 FROM task_categories c WHERE c.user_id = :u ORDER BY c.name",
			['u' => $this->userId],
		);
	}

	public function saveCategory(?int $id, array $input): int
	{
		$d = Validator::make($input, [
			'name' => ($id ? '' : 'required|') . 'string|min:1|max:60',
			'color' => 'string|regex:/^#[0-9a-fA-F]{6}$/',
		]);
		try {
			if ($id) {
				if (!$this->db->value('SELECT 1 FROM task_categories WHERE id = :c AND user_id = :u', ['c' => $id, 'u' => $this->userId])) {
					throw new HttpException(404);
				}
				if ($d) {
					$this->db->update('task_categories', $d, 'id = :id AND user_id = :u', ['id' => $id, 'u' => $this->userId]);
				}
				return $id;
			}
			if ((int) $this->db->value('SELECT COUNT(*) FROM task_categories WHERE user_id = :u', ['u' => $this->userId]) >= 30) {
				throw HttpException::validation(['name' => 'You can have up to 30 categories.']);
			}
			return $this->db->insert('task_categories', ['user_id' => $this->userId] + $d);
		} catch (PDOException $e) {
			if (($e->errorInfo[1] ?? null) === 1062) {
				throw HttpException::validation(['name' => 'You already have a category with this name.']);
			}
			throw $e;
		}
	}

	public function deleteCategory(int $id): void
	{
		$n = $this->db->run('DELETE FROM task_categories WHERE id = :c AND user_id = :u', ['c' => $id, 'u' => $this->userId])->rowCount();
		if (!$n) {
			throw new HttpException(404);
		}
	}
}
