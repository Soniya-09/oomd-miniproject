<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Csrf;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

/** Every state-changing request must carry the session's CSRF token. */
final class VerifyCsrf
{
	public function handle(Request $request, callable $next): Response
	{
		if (!in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
			$token = $request->header('X-CSRF-Token') ?? ($request->post['_csrf'] ?? null);
			if (!Csrf::valid($token)) {
				throw new HttpException(419);
			}
		}
		return $next($request);
	}
}
