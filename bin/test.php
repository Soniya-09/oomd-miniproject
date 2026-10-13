<?php

declare(strict_types=1);

/*
 * Test runner (no Composer needed).  Uses DB_TEST_DATABASE, which is wiped and
 * migrated at the start of every run.
 *
 *   php bin/test.php                 # all tests
 *   php bin/test.php Auth            # only classes whose name contains "Auth"
 */

// Must be set before bootstrap: config is read once.
putenv('MAIL_DRIVER=none');
putenv('APP_ENV=testing');
putenv('UPLOAD_PATH=' . sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'eduflow_test_uploads');

require dirname(__DIR__) . '/app/bootstrap.php';
require BASE_PATH . '/database/seeders/DemoSeeder.php';
require BASE_PATH . '/tests/TestCase.php';

use App\Core\Database;
use App\Core\Migrator;
use App\Services\Mailer;

$testDb = (string) env('DB_TEST_DATABASE', '');
if ($testDb === '' || $testDb === env('DB_DATABASE')) {
	fwrite(STDERR, "Set DB_TEST_DATABASE in .env to a separate database.\n");
	exit(1);
}
@mkdir(config('uploads.path'), 0770, true);
Database::reset(['name' => $testDb] + config('db'));
$migrator = new Migrator(Database::get());
$migrator->wipe();
$migrator->migrate();

$filter = $argv[1] ?? '';
$files = glob(BASE_PATH . '/tests/*Test.php');
sort($files);
$passed = $failed = $assertions = 0;
$failures = [];
$start = microtime(true);

foreach ($files as $file) {
	require_once $file;
	$class = 'Tests\\' . basename($file, '.php');
	if ($filter !== '' && stripos($class, $filter) === false) {
		continue;
	}
	echo basename($file, '.php') . PHP_EOL;
	foreach (get_class_methods($class) as $method) {
		if (!str_starts_with($method, 'test')) {
			continue;
		}
		$t = new $class();
		Mailer::$sent = [];
		try {
			$t->setUp();
			$t->$method();
			$passed++;
			echo "  \u{2713} $method\n";
		} catch (Throwable $e) {
			$failed++;
			$where = $e instanceof Tests\AssertionFailed ? '' : sprintf(' [%s at %s:%d]', get_class($e), basename($e->getFile()), $e->getLine());
			$failures[] = "$class::$method — {$e->getMessage()}$where";
			echo "  \u{2717} $method\n";
		} finally {
			$t->tearDown();
			$assertions += $t->assertions;
		}
	}
}

printf("\n%d passed, %d failed, %d assertions in %.1fs\n", $passed, $failed, $assertions, microtime(true) - $start);
foreach ($failures as $f) {
	echo "\nFAIL $f\n";
}
exit($failed ? 1 : 0);
