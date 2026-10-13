<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Small router: "/api/assignments/{id}" style patterns, per-route middleware.
 * A handler is [ControllerClass::class, 'method'] or a closure taking Request.
 */
final class Router
{
	private array $routes = [];
	private array $groupMiddleware = [];

	public function get(string $path, array|\Closure $handler, array $middleware = []): void
	{
		$this->add('GET', $path, $handler, $middleware);
	}

	public function post(string $path, array|\Closure $handler, array $middleware = []): void
	{
		$this->add('POST', $path, $handler, $middleware);
	}

	public function put(string $path, array|\Closure $handler, array $middleware = []): void
	{
		$this->add('PUT', $path, $handler, $middleware);
	}

	public function patch(string $path, array|\Closure $handler, array $middleware = []): void
	{
		$this->add('PATCH', $path, $handler, $middleware);
	}

	public function delete(string $path, array|\Closure $handler, array $middleware = []): void
	{
		$this->add('DELETE', $path, $handler, $middleware);
	}

	/** Apply $middleware to every route registered inside $fn. */
	public function group(array $middleware, callable $fn): void
	{
		$previous = $this->groupMiddleware;
		$this->groupMiddleware = array_merge($previous, $middleware);
		$fn($this);
		$this->groupMiddleware = $previous;
	}

	private function add(string $method, string $path, array|\Closure $handler, array $middleware): void
	{
		$regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', rtrim($path, '/') ?: '/') . '$#';
		$this->routes[] = [$method, $regex, $handler, array_merge($this->groupMiddleware, $middleware)];
	}

	public function dispatch(Request $request): Response
	{
		$allowed = [];
		foreach ($this->routes as [$method, $regex, $handler, $middleware]) {
			if (!preg_match($regex, $request->path, $m)) {
				continue;
			}
			if ($method !== $request->method) {
				$allowed[] = $method;
				continue;
			}
			$request->params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
			$core = function (Request $req) use ($handler): Response {
				if ($handler instanceof \Closure) {
					return $handler($req);
				}
				[$class, $action] = $handler;
				return (new $class())->$action($req);
			};
			// Build the middleware onion: first listed runs first.
			$next = $core;
			foreach (array_reverse($middleware) as $mw) {
				$next = static fn(Request $req) => self::resolve($mw)->handle($req, $next);
			}
			return $next($request);
		}
		throw new HttpException($allowed ? 405 : 404);
	}

	/** "role:admin,teacher" -> new RoleMiddleware(['admin','teacher']) */
	private static function resolve(string|object $mw): object
	{
		if (is_object($mw)) {
			return $mw;
		}
		[$name, $args] = array_pad(explode(':', $mw, 2), 2, null);
		$class = match ($name) {
			'auth' => \App\Middleware\Authenticate::class,
			'guest' => \App\Middleware\RedirectIfAuthenticated::class,
			'role' => \App\Middleware\RequireRole::class,
			'csrf' => \App\Middleware\VerifyCsrf::class,
			default => $name,
		};
		return $args === null ? new $class() : new $class(explode(',', $args));
	}
}
