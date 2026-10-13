<?php

declare(strict_types=1);

/*
 * Shared bootstrap for the web front controller, CLI scripts and tests.
 * Sets up autoloading, configuration, UTC time and error handling.
 */

define('BASE_PATH', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
	$prefix = 'App\\';
	if (!str_starts_with($class, $prefix)) {
		return;
	}
	$file = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
	if (is_file($file)) {
		require $file;
	}
});

require BASE_PATH . '/app/helpers.php';

App\Core\Env::load(BASE_PATH . '/.env');

// Everything is stored and computed in UTC; the UI converts to the institution's zone.
date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

$debug = (bool) config('app.debug');
error_reporting(E_ALL);
ini_set('display_errors', $debug && PHP_SAPI === 'cli' ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', BASE_PATH . '/storage/logs/php-error.log');
