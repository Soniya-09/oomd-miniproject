<?php

declare(strict_types=1);

/* Apply pending SQL migrations.  Usage: php bin/migrate.php [--fresh] */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Core\Migrator;

$migrator = new Migrator(Database::get());
if (in_array('--fresh', $argv, true)) {
	if (config('app.env') === 'production') {
		fwrite(STDERR, "Refusing --fresh in production.\n");
		exit(1);
	}
	$migrator->wipe();
	echo "Dropped all tables.\n";
}
$applied = $migrator->migrate();
echo $applied ? 'Applied: ' . implode(', ', $applied) . PHP_EOL : "Nothing to migrate.\n";
