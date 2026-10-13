<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\AnnouncementService;
use App\Services\CalendarService;
use App\Services\FileStorage;
use App\Services\ScheduleService;

/** Endpoints shared by several roles; each service applies the caller's scope. */
final class SharedController
{
	// --- Calendar -------------------------------------------------------------

	public function calendar(Request $request): Response
	{
		if (Auth::is('admin')) {
			throw new HttpException(403);
		}
		$f = Validator::make($request->query, ['from' => 'required|datetime', 'to' => 'required|datetime']);
		return Response::json(['data' => (new CalendarService(Auth::user()))->events($f['from'], $f['to'])]);
	}

	// --- Announcements --------------------------------------------------------

	public function announcements(Request $request): Response
	{
		$f = Validator::make($request->query, ['q' => 'string|max:100', 'include_inactive' => 'in:0,1']);
		return Response::json((new AnnouncementService(Auth::user()))->list(new Paginator($request, 10), $f));
	}

	public function createAnnouncement(Request $request): Response
	{
		if (!Auth::is('admin', 'teacher')) {
			throw new HttpException(403);
		}
		$input = $request->input();
		if (isset($input['class_ids']) && is_string($input['class_ids'])) {
			$input['class_ids'] = array_filter(explode(',', $input['class_ids']));
		}
		$id = (new AnnouncementService(Auth::user()))->create($input, $request->uploadedFiles('files'));
		return Response::json(['data' => ['id' => $id]], 201);
	}

	public function readAnnouncement(Request $request): Response
	{
		(new AnnouncementService(Auth::user()))->markRead($request->intParam('id'));
		return Response::json(['ok' => true]);
	}

	public function deleteAnnouncement(Request $request): Response
	{
		(new AnnouncementService(Auth::user()))->delete($request->intParam('id'));
		return Response::json(['ok' => true]);
	}

	public function announcementFile(Request $request): Response
	{
		$f = (new AnnouncementService(Auth::user()))->attachment($request->intParam('id'));
		$path = FileStorage::path($f['stored_name']);
		if (!is_file($path)) {
			throw new HttpException(404);
		}
		return Response::download($path, $f['original_name'], 'application/octet-stream');
	}

	// --- Exams & timetable ----------------------------------------------------

	public function exams(Request $request): Response
	{
		$this->staffOnly();
		$class = filter_var($request->query['class_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
		return Response::json(['data' => (new ScheduleService(Auth::user()))->exams($class)]);
	}

	public function createExam(Request $request): Response
	{
		$this->staffOnly();
		return Response::json(['data' => ['id' => (new ScheduleService(Auth::user()))->createExam($request->input())]], 201);
	}

	public function deleteExam(Request $request): Response
	{
		$this->staffOnly();
		(new ScheduleService(Auth::user()))->deleteExam($request->intParam('id'));
		return Response::json(['ok' => true]);
	}

	public function timetable(Request $request): Response
	{
		return Response::json(['data' => (new ScheduleService(Auth::user()))->timetable($request->intParam('id'))]);
	}

	public function addTimetable(Request $request): Response
	{
		return Response::json(['data' => ['id' => (new ScheduleService(Auth::user()))->addTimetableEntry($request->intParam('id'), $request->input())]], 201);
	}

	public function deleteTimetable(Request $request): Response
	{
		(new ScheduleService(Auth::user()))->deleteTimetableEntry($request->intParam('id'));
		return Response::json(['ok' => true]);
	}

	private function staffOnly(): void
	{
		if (!Auth::is('admin', 'teacher')) {
			throw new HttpException(403);
		}
	}
}
