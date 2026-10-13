<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\FileStorage;
use App\Services\Policy;

/** Authorized downloads. Files live outside the web root; ids are checked against the user every time. */
final class FileController
{
	public function attachment(Request $request): Response
	{
		$f = Database::get()->one('SELECT * FROM assignment_attachments WHERE id = :id', ['id' => $request->intParam('id')]) ?? throw new HttpException(404);
		$user = Auth::user();
		match ($user['role']) {
			'teacher' => Policy::assignmentForTeacher((int) $f['assignment_id'], (int) $user['id']),
			'student' => Policy::assignmentForStudent((int) $f['assignment_id'], (int) $user['id']),
			default => throw new HttpException(404),
		};
		return $this->send($f);
	}

	public function submissionFile(Request $request): Response
	{
		$f = Database::get()->one('SELECT * FROM submission_files WHERE id = :id', ['id' => $request->intParam('id')]) ?? throw new HttpException(404);
		Policy::submissionFor((int) $f['submission_id'], Auth::user());
		return $this->send($f);
	}

	private function send(array $f): Response
	{
		$path = FileStorage::path($f['stored_name']);
		if (!is_file($path)) {
			throw new HttpException(404, 'The file is no longer available.');
		}
		// Always a download, never rendered inline, so an uploaded file cannot run in our origin.
		return Response::download($path, $f['original_name'], 'application/octet-stream');
	}
}
