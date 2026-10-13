<?php

declare(strict_types=1);

namespace App\Core;

/** Reads ?page=&per_page= safely and shapes paginated API responses. */
final class Paginator
{
	public readonly int $page;
	public readonly int $perPage;

	public function __construct(Request $request, int $defaultPerPage = 20)
	{
		$page = filter_var($request->query['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
		$per = filter_var($request->query['per_page'] ?? $defaultPerPage, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
		$this->page = $page ?: 1;
		$this->perPage = $per ?: $defaultPerPage;
	}

	public function offset(): int
	{
		return ($this->page - 1) * $this->perPage;
	}

	/** Appends LIMIT/OFFSET to $sql and runs a COUNT over the same FROM/WHERE. */
	public function run(string $select, string $fromWhere, array $params, string $orderBy): array
	{
		$db = Database::get();
		$total = (int) $db->value("SELECT COUNT(*) $fromWhere", $params);
		$rows = $db->all(
			"$select $fromWhere ORDER BY $orderBy LIMIT {$this->perPage} OFFSET {$this->offset()}",
			$params,
		);
		return [
			'data' => $rows,
			'meta' => [
				'page' => $this->page,
				'per_page' => $this->perPage,
				'total' => $total,
				'pages' => (int) max(1, ceil($total / $this->perPage)),
			],
		];
	}

	/** Whitelisted "sort" query param, e.g. ?sort=-due_at. */
	public static function order(Request $request, array $allowed, string $default): string
	{
		$sort = (string) ($request->query['sort'] ?? '');
		$desc = str_starts_with($sort, '-');
		$key = ltrim($sort, '-');
		if (!isset($allowed[$key])) {
			return $default;
		}
		return $allowed[$key] . ($desc ? ' DESC' : ' ASC');
	}

	/** Escape a user search term for LIKE. */
	public static function like(string $term): string
	{
		return '%' . addcslashes($term, '%_\\') . '%';
	}
}
