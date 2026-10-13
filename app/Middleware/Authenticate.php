<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

final class Authenticate
{
	public function handle(Request $request, callable $next): Response
	{
		if (!Auth::check()) {
			if ($request->isApi()) {
				throw new HttpException(401);
			}
			$target = $request->method === 'GET' ? '?next=' . rawurlencode($request->path) : '';
			return Response::redirect('/login' . $target);
		}
		return $next($request);
	}
}
