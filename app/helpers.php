<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\Env;

/** Read a dotted config key, e.g. config('db.host'). */
function config(string $key, mixed $default = null): mixed
{
	static $config = null;
	if ($config === null) {
		$config = require BASE_PATH . '/config/app.php';
	}
	$value = $config;
	foreach (explode('.', $key) as $part) {
		if (!is_array($value) || !array_key_exists($part, $value)) {
			return $default;
		}
		$value = $value[$part];
	}
	return $value;
}

function env(string $key, mixed $default = null): mixed
{
	return Env::get($key, $default);
}

/** HTML-escape for output in templates. */
function e(mixed $value): string
{
	return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
	return Csrf::token();
}

function csrf_field(): string
{
	return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
}

/** Current UTC time as a MySQL DATETIME string. */
function now_utc(): string
{
	return gmdate('Y-m-d H:i:s');
}

/** MySQL UTC DATETIME -> ISO 8601 with Z, for the browser. */
function iso_utc(?string $datetime): ?string
{
	if ($datetime === null || $datetime === '') {
		return null;
	}
	return str_replace(' ', 'T', $datetime) . 'Z';
}

function asset(string $path): string
{
	$file = BASE_PATH . '/public/assets/' . $path;
	$version = is_file($file) ? (string) filemtime($file) : '1';
	return '/assets/' . $path . '?v=' . $version;
}
