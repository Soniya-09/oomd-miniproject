<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Single definition of "who an assignment is for": students currently enrolled
 * in the assignment's class, narrowed to assignment_targets when target_all = 0.
 * Used by visibility checks, notifications and every analytics query.
 */
final class Recipients
{
	/** Derived table with columns (assignment_id, student_id). Alias it in the caller. */
	public const SQL = '(SELECT a.id AS assignment_id, cs.student_id
		FROM assignments a
		JOIN class_students cs ON cs.class_id = a.class_id
		LEFT JOIN assignment_targets t ON t.assignment_id = a.id AND t.student_id = cs.student_id
		WHERE a.target_all = 1 OR t.student_id IS NOT NULL)';
}
