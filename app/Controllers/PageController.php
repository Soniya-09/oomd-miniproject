<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

/**
 * Renders page shells. Data is loaded by page scripts from the JSON API,
 * which performs its own authorization on every call.
 */
final class PageController
{
	/** route path => [view, title, allowed roles] */
	public const PAGES = [
		'/admin' => ['admin.overview', 'Overview', ['admin']],
		'/admin/users' => ['admin.users', 'Users', ['admin']],
		'/admin/students' => ['admin.users', 'Students', ['admin']],
		'/admin/teachers' => ['admin.users', 'Teachers', ['admin']],
		'/admin/classes' => ['admin.classes', 'Classes', ['admin']],
		'/admin/subjects' => ['admin.subjects', 'Subjects', ['admin']],
		'/admin/sessions' => ['admin.sessions', 'Academic Sessions', ['admin']],
		'/admin/announcements' => ['shared.announcements', 'Announcements', ['admin']],
		'/admin/reports' => ['admin.reports', 'Reports', ['admin']],
		'/admin/settings' => ['admin.settings', 'Settings', ['admin']],
		'/admin/audit-logs' => ['admin.audit', 'Audit Logs', ['admin']],

		'/teacher' => ['teacher.dashboard', 'Dashboard', ['teacher']],
		'/teacher/classes' => ['teacher.classes', 'My Classes', ['teacher']],
		'/teacher/assignments' => ['teacher.assignments', 'Assignments', ['teacher']],
		'/teacher/submissions' => ['teacher.submissions', 'Submissions', ['teacher']],
		'/teacher/announcements' => ['shared.announcements', 'Announcements', ['teacher']],
		'/teacher/analytics' => ['teacher.analytics', 'Analytics', ['teacher']],

		'/student' => ['student.dashboard', 'Dashboard', ['student']],
		'/student/assignments' => ['student.assignments', 'My Assignments', ['student']],
		'/student/tasks' => ['student.tasks', 'Personal Tasks', ['student']],
		'/student/calendar' => ['shared.calendar', 'Calendar', ['student']],
		'/student/progress' => ['student.progress', 'My Progress', ['student']],

		'/notifications' => ['shared.notifications', 'Notifications', ['teacher', 'student']],
		'/announcements' => ['shared.announcements', 'Announcements', ['admin', 'teacher', 'student']],
		'/profile' => ['profile', 'Profile', ['admin', 'teacher', 'student']],
	];

	/** Detail pages: pattern => [view, title, roles, highlighted nav path] */
	public const DETAIL_PAGES = [
		'/admin/classes/{id}' => ['admin.class', 'Class', ['admin'], '/admin/classes'],
		'/teacher/assignments/{id}' => ['teacher.assignment', 'Assignment', ['teacher'], '/teacher/assignments'],
		'/student/assignments/{id}' => ['student.assignment', 'Assignment', ['student'], '/student/assignments'],
	];

	public function home(Request $request): Response
	{
		return Response::redirect(Auth::check() ? Auth::homeFor(Auth::role()) : '/login');
	}

	public function show(Request $request): Response
	{
		[$view, $title, $roles] = self::PAGES[$request->path] ?? throw new HttpException(404);
		if (!Auth::is(...$roles)) {
			throw new HttpException(403);
		}
		$data = ['title' => $title, 'user' => Auth::user(), 'path' => $request->path];
		if ($view === 'admin.users') {
			$data['roleFilter'] = match ($request->path) {
				'/admin/students' => 'student',
				'/admin/teachers' => 'teacher',
				default => '',
			};
		}
		return View::page($view, $data);
	}

	public function detail(Request $request): Response
	{
		$pattern = preg_replace('#/\d+$#', '/{id}', $request->path);
		[$view, $title, $roles, $nav] = self::DETAIL_PAGES[$pattern] ?? throw new HttpException(404);
		if (!Auth::is(...$roles)) {
			throw new HttpException(403);
		}
		return View::page($view, ['title' => $title, 'user' => Auth::user(), 'path' => $nav, 'id' => $request->intParam('id')]);
	}
}
