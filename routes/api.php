<?php

declare(strict_types=1);

/** @var App\Core\Router $router */

use App\Controllers\Api\AdminController;
use App\Controllers\Api\FileController;
use App\Controllers\Api\NotificationController;
use App\Controllers\Api\ProfileController;
use App\Controllers\Api\ReportController;
use App\Controllers\Api\SharedController;
use App\Controllers\Api\StudentController;
use App\Controllers\Api\StudentWorkController;
use App\Controllers\Api\TeacherController;

// Every API route: logged in + CSRF on writes. Role/ownership checks happen per route and in services.
$router->group(['auth', 'csrf'], function ($router) {
	$router->get('/api/me', [ProfileController::class, 'me']);
	$router->patch('/api/me', [ProfileController::class, 'update']);
	$router->post('/api/me/password', [ProfileController::class, 'changePassword']);

	// Notifications: always the caller's own.
	$router->get('/api/notifications', [NotificationController::class, 'index']);
	$router->get('/api/notifications/unread-count', [NotificationController::class, 'unreadCount']);
	$router->post('/api/notifications/read-all', [NotificationController::class, 'markAllRead']);
	$router->post('/api/notifications/{id}/read', [NotificationController::class, 'markRead']);

	// Shared: services apply the caller's scope.
	$router->get('/api/calendar', [SharedController::class, 'calendar']);
	$router->get('/api/announcements', [SharedController::class, 'announcements']);
	$router->post('/api/announcements', [SharedController::class, 'createAnnouncement']);
	$router->post('/api/announcements/{id}/read', [SharedController::class, 'readAnnouncement']);
	$router->delete('/api/announcements/{id}', [SharedController::class, 'deleteAnnouncement']);
	$router->get('/api/files/announcements/{id}', [SharedController::class, 'announcementFile']);
	$router->get('/api/exams', [SharedController::class, 'exams']);
	$router->post('/api/exams', [SharedController::class, 'createExam']);
	$router->delete('/api/exams/{id}', [SharedController::class, 'deleteExam']);

	// Downloads: authorization is checked per file against the assignment/submission.
	$router->get('/api/files/attachments/{id}', [FileController::class, 'attachment']);
	$router->get('/api/files/submissions/{id}', [FileController::class, 'submissionFile']);

	$router->group(['role:admin,teacher'], function ($router) {
		$router->get('/api/reports/{type}.csv', [ReportController::class, 'export']);
	});

	$router->group(['role:teacher'], function ($router) {
		$router->get('/api/teacher/dashboard', [ReportController::class, 'teacherDashboard']);
		$router->get('/api/teacher/analytics', [ReportController::class, 'teacherAnalytics']);
		$router->get('/api/teacher/classes', [TeacherController::class, 'classes']);
		$router->get('/api/teacher/classes/{id}/students', [TeacherController::class, 'classStudents']);
		$router->get('/api/teacher/assignments', [TeacherController::class, 'index']);
		$router->post('/api/teacher/assignments', [TeacherController::class, 'store']);
		$router->get('/api/teacher/assignments/{id}', [TeacherController::class, 'show']);
		$router->patch('/api/teacher/assignments/{id}', [TeacherController::class, 'update']);
		$router->post('/api/teacher/assignments/{id}/attachments', [TeacherController::class, 'uploadAttachments']);
		$router->delete('/api/teacher/assignments/{id}/attachments/{attachmentId}', [TeacherController::class, 'deleteAttachment']);
		$router->post('/api/teacher/assignments/{id}/{action}', [TeacherController::class, 'transition']);
		$router->get('/api/teacher/submissions', [TeacherController::class, 'queue']);
		$router->get('/api/teacher/submissions/{id}', [TeacherController::class, 'submission']);
		$router->post('/api/teacher/submissions/{id}/{action}', [TeacherController::class, 'submissionAction']);
	});

	$router->group(['role:student'], function ($router) {
		$router->get('/api/student/dashboard', [StudentWorkController::class, 'dashboard']);
		$router->get('/api/student/progress', [StudentWorkController::class, 'progress']);
		$router->get('/api/student/items', [StudentWorkController::class, 'items']);
		$router->get('/api/student/subjects', [StudentController::class, 'subjects']);
		$router->get('/api/student/assignments', [StudentController::class, 'assignments']);
		$router->get('/api/student/assignments/{id}', [StudentController::class, 'assignment']);
		$router->post('/api/student/assignments/{id}/submission', [StudentController::class, 'submit']);
		$router->post('/api/student/assignments/{id}/resubmission-request', [StudentController::class, 'requestResubmission']);

		$router->get('/api/student/tasks', [StudentWorkController::class, 'index']);
		$router->post('/api/student/tasks', [StudentWorkController::class, 'store']);
		$router->get('/api/student/tasks/{id}', [StudentWorkController::class, 'show']);
		$router->patch('/api/student/tasks/{id}', [StudentWorkController::class, 'update']);
		$router->delete('/api/student/tasks/{id}', [StudentWorkController::class, 'destroy']);
		$router->get('/api/student/categories', [StudentWorkController::class, 'categories']);
		$router->post('/api/student/categories', [StudentWorkController::class, 'saveCategory']);
		$router->patch('/api/student/categories/{id}', [StudentWorkController::class, 'saveCategory']);
		$router->delete('/api/student/categories/{id}', [StudentWorkController::class, 'deleteCategory']);
	});

	$router->group(['role:admin'], function ($router) {
		$router->get('/api/admin/overview', [AdminController::class, 'overview']);
		$router->get('/api/admin/analytics', [ReportController::class, 'adminAnalytics']);
		$router->get('/api/admin/users', [AdminController::class, 'users']);
		$router->post('/api/admin/users', [AdminController::class, 'createUser']);
		$router->post('/api/admin/users/import', [AdminController::class, 'importUsers']);
		$router->get('/api/admin/users/{id}', [AdminController::class, 'user']);
		$router->patch('/api/admin/users/{id}', [AdminController::class, 'updateUser']);

		$router->get('/api/admin/sessions', [AdminController::class, 'sessions']);
		$router->post('/api/admin/sessions', [AdminController::class, 'saveSession']);
		$router->patch('/api/admin/sessions/{id}', [AdminController::class, 'saveSession']);

		$router->get('/api/admin/departments', [AdminController::class, 'departments']);
		$router->post('/api/admin/departments', [AdminController::class, 'saveDepartment']);
		$router->patch('/api/admin/departments/{id}', [AdminController::class, 'saveDepartment']);

		$router->get('/api/admin/subjects', [AdminController::class, 'subjects']);
		$router->post('/api/admin/subjects', [AdminController::class, 'saveSubject']);
		$router->patch('/api/admin/subjects/{id}', [AdminController::class, 'saveSubject']);

		$router->get('/api/admin/classes', [AdminController::class, 'classes']);
		$router->post('/api/admin/classes', [AdminController::class, 'saveClass']);
		$router->get('/api/admin/classes/{id}', [AdminController::class, 'classDetail']);
		$router->patch('/api/admin/classes/{id}', [AdminController::class, 'saveClass']);
		$router->post('/api/admin/classes/{id}/students', [AdminController::class, 'enroll']);
		$router->delete('/api/admin/classes/{id}/students/{studentId}', [AdminController::class, 'unenroll']);
		$router->get('/api/admin/classes/{id}/timetable', [SharedController::class, 'timetable']);
		$router->post('/api/admin/classes/{id}/timetable', [SharedController::class, 'addTimetable']);
		$router->delete('/api/admin/timetable/{id}', [SharedController::class, 'deleteTimetable']);

		$router->get('/api/admin/teaching', [AdminController::class, 'teaching']);
		$router->post('/api/admin/teaching', [AdminController::class, 'assignTeacher']);
		$router->delete('/api/admin/teaching/{id}', [AdminController::class, 'unassignTeacher']);

		$router->get('/api/admin/settings', [AdminController::class, 'settings']);
		$router->patch('/api/admin/settings', [AdminController::class, 'saveSettings']);
		$router->get('/api/admin/audit-logs', [AdminController::class, 'auditLogs']);
	});
});
