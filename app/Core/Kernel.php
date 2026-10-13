<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/** Builds the router once and turns a Request into a Response, including errors. */
final class Kernel
{
	private Router $router;

	public function __construct()
	{
		$this->router = new Router();
		$router = $this->router;
		require BASE_PATH . '/routes/web.php';
		require BASE_PATH . '/routes/api.php';
	}

	public function handle(Request $request): Response
	{
		try {
			return $this->router->dispatch($request);
		} catch (HttpException $e) {
			return $this->renderError($request, $e->status, $e->getMessage(), $e->errors);
		} catch (Throwable $e) {
			error_log(sprintf('[%s] %s in %s:%d', gmdate('c'), $e->getMessage(), $e->getFile(), $e->getLine()));
			$message = config('app.debug') ? $e->getMessage() : HttpException::defaultMessage(500);
			return $this->renderError($request, 500, $message);
		}
	}

	private function renderError(Request $request, int $status, string $message, array $errors = []): Response
	{
		if ($request->isApi()) {
			$body = ['error' => $message];
			if ($errors) {
				$body['errors'] = $errors;
			}
			return Response::json($body, $status);
		}
		if ($status === 401) {
			return Response::redirect('/login');
		}
		if ($status === 419) {
			Session::flash('error', $message);
			return Response::redirect($request->path);
		}
		return View::page('errors.error', ['status' => $status, 'message' => $message, 'title' => "Error $status"], $status);
	}
}
