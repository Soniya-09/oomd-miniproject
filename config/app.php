<?php

declare(strict_types=1);

return [
	'app' => [
		'name' => env('APP_NAME', 'EduFlow'),
		'env' => env('APP_ENV', 'local'),
		'debug' => (bool) env('APP_DEBUG', false),
		'url' => rtrim((string) env('APP_URL', 'http://localhost:8000'), '/'),
	],
	'db' => [
		'host' => env('DB_HOST', '127.0.0.1'),
		'port' => (int) env('DB_PORT', 3306),
		'name' => env('DB_DATABASE', 'eduflow'),
		'user' => env('DB_USERNAME', 'eduflow'),
		'pass' => (string) env('DB_PASSWORD', ''),
	],
	'session' => [
		'name' => 'eduflow_session',
		'lifetime' => (int) env('SESSION_LIFETIME', 7200),
		// Secure cookies whenever the app is served over HTTPS.
		'secure' => (bool) env('SESSION_SECURE', str_starts_with((string) env('APP_URL', ''), 'https://')),
	],
	'uploads' => [
		'path' => env('UPLOAD_PATH', BASE_PATH . '/storage/private_uploads'),
		'max_mb' => (int) env('UPLOAD_MAX_MB', 20),
		// Extensions teachers may allow. Executables and scripts are never accepted.
		'allowed' => [
			'pdf' => ['application/pdf'],
			'doc' => ['application/msword', 'application/x-ole-storage', 'application/CDFV2'],
			'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
			'ppt' => ['application/vnd.ms-powerpoint', 'application/x-ole-storage', 'application/CDFV2'],
			'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
			'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/CDFV2'],
			'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
			'txt' => ['text/plain'],
			'csv' => ['text/plain', 'text/csv'],
			'png' => ['image/png'],
			'jpg' => ['image/jpeg'],
			'jpeg' => ['image/jpeg'],
			'zip' => ['application/zip'],
		],
	],
	'mail' => [
		// "log" writes mails to storage/logs/mail.log (development).
		'driver' => env('MAIL_DRIVER', 'log'),
		'from' => env('MAIL_FROM', 'no-reply@eduflow.local'),
	],
	'rate_limits' => [
		'login' => [5, 15 * 60],          // attempts per window (seconds), per email+IP
		'login_ip' => [30, 15 * 60],      // per IP across all emails
		'password_reset' => [3, 60 * 60],
		'upload' => [30, 60 * 60],
	],
];
