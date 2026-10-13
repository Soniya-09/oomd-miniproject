<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Kernel;
use App\Core\Request;
use App\Core\Session;

// Let the PHP built-in server serve real static files directly.
if (PHP_SAPI === 'cli-server') {
	$file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
	if ($file !== __DIR__ . '/' && is_file($file) && !str_ends_with($file, '.php')) {
		return false;
	}
}

Session::start();
(new Kernel())->handle(Request::capture())->send();
