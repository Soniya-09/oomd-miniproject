<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\AnalyticsService;
use App\Services\PersonalTaskService;
use App\Services\StudentWork;

/** Student personal tasks, unified task list and dashboard. Everything is scoped to Auth::id(). */
final class StudentWorkController
{
	private function tasks(): PersonalTaskService
	{
		return new PersonalTaskService(Auth::id());
	}

	// --- Personal tasks -------------------------------------------------------

	public function index(Request $request): Response
	{
		$f = Validator::make($request->query, [
			'status' => 'in:pending,in_progress,completed,cancelled,overdue,open',
			'priority' => 'in:low,medium,high',
			'category_id' => 'int|min:1',
			'q' => 'string|max:100',
			'from' => 'datetime',
			'to' => 'datetime',
		]);
		$order = Paginator::order($request, [
			'due' => 't.due_at IS NULL, t.due_at',
			'created' => 't.created_at',
			'priority' => "FIELD(t.priority, 'high', 'medium', 'low')",
			'completed' => 't.completed_at',
		], 't.due_at IS NULL, t.due_at ASC');
		return Response::json($this->tasks()->list(new Paginator($request, 25), $f, $order));
	}

	public function show(Request $request): Response
	{
		return Response::json(['data' => PersonalTaskService::present($this->tasks()->find($request->intParam('id')))]);
	}

	public function store(Request $request): Response
	{
		$id = $this->tasks()->create($request->input());
		return Response::json(['data' => PersonalTaskService::present($this->tasks()->find($id))], 201);
	}

	public function update(Request $request): Response
	{
		return Response::json(['data' => PersonalTaskService::present($this->tasks()->update($request->intParam('id'), $request->input()))]);
	}

	public function destroy(Request $request): Response
	{
		$this->tasks()->delete($request->intParam('id'));
		return Response::json(['ok' => true]);
	}

	public function categories(Request $request): Response
	{
		return Response::json(['data' => $this->tasks()->categories()]);
	}

	public function saveCategory(Request $request): Response
	{
		$id = isset($request->params['id']) ? $request->intParam('id') : null;
		return Response::json(['data' => ['id' => $this->tasks()->saveCategory($id, $request->input())]], $id ? 200 : 201);
	}

	public function deleteCategory(Request $request): Response
	{
		$this->tasks()->deleteCategory($request->intParam('id'));
		return Response::json(['ok' => true]);
	}

	// --- Unified list ---------------------------------------------------------

	public function items(Request $request): Response
	{
		$f = Validator::make($request->query, [
			'kind' => 'in:academic,personal',
			'status' => 'in:pending,in_progress,completed,overdue,open,due_soon',
			'priority' => 'in:low,medium,high',
			'subject_id' => 'int|min:1',
			'q' => 'string|max:100',
			'from' => 'datetime',
			'to' => 'datetime',
		]);
		$now = now_utc();
		$soon = gmdate('Y-m-d H:i:s', time() + 72 * 3600);
		$items = array_filter((new StudentWork(Auth::id()))->items(), function ($i) use ($f, $now, $soon) {
			if ($i['status'] === 'info' && !empty($f['status'])) {
				return false;
			}
			if (!empty($f['kind']) && $i['kind'] !== $f['kind']) {
				return false;
			}
			if (!empty($f['status'])) {
				$ok = match ($f['status']) {
					'open' => StudentWork::isOpen($i),
					'due_soon' => $i['kind'] === 'academic' && StudentWork::isOpen($i) && $i['due_at'] !== null && $i['due_at'] >= $now && $i['due_at'] <= $soon,
					default => $i['status'] === $f['status'],
				};
				if (!$ok) {
					return false;
				}
			}
			if (!empty($f['priority']) && $i['priority'] !== $f['priority']) {
				return false;
			}
			if (!empty($f['subject_id']) && $i['subject_id'] !== $f['subject_id']) {
				return false;
			}
			if (!empty($f['q']) && mb_stripos($i['title'], $f['q']) === false) {
				return false;
			}
			if (!empty($f['from']) && ($i['due_at'] === null || $i['due_at'] < $f['from'])) {
				return false;
			}
			if (!empty($f['to']) && ($i['due_at'] === null || $i['due_at'] >= $f['to'])) {
				return false;
			}
			return true;
		});
		$items = array_values($items);
		usort($items, [StudentWork::class, 'byUrgency']);
		// Completed work is most useful newest-first.
		if (($f['status'] ?? '') === 'completed') {
			usort($items, fn($a, $b) => strcmp((string) $b['completed_at'], (string) $a['completed_at']));
		}
		$p = new Paginator($request, 25);
		$total = count($items);
		return Response::json([
			'data' => array_map([StudentWork::class, 'present'], array_slice($items, $p->offset(), $p->perPage)),
			'meta' => ['page' => $p->page, 'per_page' => $p->perPage, 'total' => $total, 'pages' => (int) max(1, ceil($total / $p->perPage))],
		]);
	}

	// --- Dashboard ------------------------------------------------------------

	public function dashboard(Request $request): Response
	{
		return Response::json(['data' => (new AnalyticsService())->studentDashboard(Auth::id(), Auth::user()['timezone'])]);
	}

	public function progress(Request $request): Response
	{
		$f = Validator::make($request->query, [
			'period' => 'in:today,week,month,custom',
			'from' => 'date',
			'to' => 'date',
			'kind' => 'in:all,academic,personal',
			'granularity' => 'in:day,week,month',
		]);
		return Response::json(['data' => (new AnalyticsService())->studentProgress(Auth::id(), Auth::user()['timezone'], $f)]);
	}
}
