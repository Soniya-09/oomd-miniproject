<?php

declare(strict_types=1);

/*
 * One-time database setup using a MySQL admin account (e.g. root).
 * Creates the app database, a test database and a least-privilege app user,
 * then writes the app user's credentials into .env.
 *
 *   php bin/setup-db.php            # prompts for the admin password
 *   MYSQL_ADMIN_PASSWORD=... php bin/setup-db.php
 *
 * The admin password is used only for this connection and is never saved.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

$adminUser = getenv('MYSQL_ADMIN_USER') ?: 'root';
$adminPass = getenv('MYSQL_ADMIN_PASSWORD');
if ($adminPass === false) {
	fwrite(STDOUT, "MySQL password for '$adminUser': ");
	if (DIRECTORY_SEPARATOR === '/') {
		system('stty -echo');
	}
	$adminPass = rtrim((string) fgets(STDIN), "\r\n");
	if (DIRECTORY_SEPARATOR === '/') {
		system('stty echo');
	}
	fwrite(STDOUT, PHP_EOL);
}

$host = (string) env('DB_HOST', '127.0.0.1');
$port = (int) env('DB_PORT', 3306);
$db = (string) env('DB_DATABASE', 'eduflow');
$testDb = (string) env('DB_TEST_DATABASE', $db . '_test');
$appUser = (string) env('DB_USERNAME', 'eduflow');
$appPass = (string) env('DB_PASSWORD', '');
if ($appPass === '') {
	$appPass = rtrim(strtr(base64_encode(random_bytes(18)), '+/', 'Aa'), '=');
}

foreach ([$db, $testDb, $appUser] as $name) {
	if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
		fwrite(STDERR, "Invalid name: $name\n");
		exit(1);
	}
}

try {
	$pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $adminUser, $adminPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
	fwrite(STDERR, 'Could not connect as admin: ' . $e->getMessage() . PHP_EOL);
	exit(1);
}

$quotedPass = $pdo->quote($appPass);
foreach ([$db, $testDb] as $name) {
	$pdo->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
}
foreach (['localhost', '127.0.0.1'] as $h) {
	$pdo->exec("CREATE USER IF NOT EXISTS '$appUser'@'$h' IDENTIFIED BY $quotedPass");
	$pdo->exec("ALTER USER '$appUser'@'$h' IDENTIFIED BY $quotedPass");
	// Least privilege: data access plus DDL for migrations, only on our databases.
	foreach ([$db, $testDb] as $name) {
		$pdo->exec("GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES ON `$name`.* TO '$appUser'@'$h'");
	}
}
$pdo->exec('FLUSH PRIVILEGES');

// Write/refresh .env with the app user's credentials.
$envFile = BASE_PATH . '/.env';
$env = is_file($envFile) ? file_get_contents($envFile) : file_get_contents(BASE_PATH . '/.env.example');
$set = function (string $env, string $key, string $value): string {
	$line = $key . '=' . $value;
	return preg_match("/^$key=.*$/m", $env) ? preg_replace("/^$key=.*$/m", addcslashes($line, '\\$'), $env) : rtrim($env) . PHP_EOL . $line . PHP_EOL;
};
foreach (['DB_HOST' => $host, 'DB_PORT' => (string) $port, 'DB_DATABASE' => $db, 'DB_TEST_DATABASE' => $testDb, 'DB_USERNAME' => $appUser, 'DB_PASSWORD' => $appPass] as $k => $v) {
	$env = $set($env, $k, $v);
}
file_put_contents($envFile, $env);

echo "Created databases `$db` and `$testDb` and user '$appUser'. Credentials written to .env\n";
echo "Next: php bin/migrate.php && php bin/seed.php\n";
