<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

final class RequireRole
{
	public function __construct(private readonly array $roles)
	{
	}

	public function handle(Request $request, callable $next): Response
	{
		if (!Auth::check()) {
			throw new HttpException(401);
		}
		if (!Auth::is(...$this->roles)) {
			throw new HttpException(403);
		}
		return $next($request);
	}
}
