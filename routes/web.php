<?php

declare(strict_types=1);

/** @var App\Core\Router $router */

use App\Controllers\AuthController;
use App\Controllers\PageController;

$router->group(['csrf'], function ($router) {
	$router->get('/', [PageController::class, 'home']);

	$router->group(['guest'], function ($router) {
		$router->get('/login', [AuthController::class, 'showLogin']);
		$router->post('/login', [AuthController::class, 'login']);
		$router->get('/forgot-password', [AuthController::class, 'showForgot']);
		$router->post('/forgot-password', [AuthController::class, 'forgot']);
		$router->get('/reset-password', [AuthController::class, 'showReset']);
		$router->post('/reset-password', [AuthController::class, 'reset']);
	});

	$router->post('/logout', [AuthController::class, 'logout']);

	// Page shells. PageController checks the role for each page; the API checks everything else.
	$router->group(['auth'], function ($router) {
		foreach (array_keys(PageController::PAGES) as $path) {
			$router->get($path, [PageController::class, 'show']);
		}
		foreach (array_keys(PageController::DETAIL_PAGES) as $pattern) {
			$router->get($pattern, [PageController::class, 'detail']);
		}
	});
});
