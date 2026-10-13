<?php

declare(strict_types=1);

/*
 * Load the fictional demo institution.
 *   php bin/seed.php            # people, classes, subjects + realistic activity
 *   php bin/seed.php --bare     # people, classes, subjects only
 */

require dirname(__DIR__) . '/app/bootstrap.php';
require BASE_PATH . '/database/seeders/DemoSeeder.php';
require BASE_PATH . '/database/seeders/DemoActivitySeeder.php';

use App\Core\Database;
use Database\Seeders\DemoActivitySeeder;
use Database\Seeders\DemoSeeder;

if (Database::get()->value("SELECT 1 FROM institutions WHERE code = 'RIVERSIDE'")) {
	echo "Demo data already present. Run `php bin/migrate.php --fresh` first to start over.\n";
	exit(0);
}

$ids = (new DemoSeeder())->run();
if (!in_array('--bare', $argv, true)) {
	(new DemoActivitySeeder($ids))->run();
}

echo "Seeded demo institution \"Riverside College of Arts & Science\" (fictional).\n";
echo "Password for every demo account: " . DemoSeeder::PASSWORD . "\n";
echo "  admin@eduflow.test            admin\n";
echo "  meera.teacher@eduflow.test    teacher (CS101, CS102, CS103)\n";
echo "  rahul.teacher@eduflow.test    teacher (MA101)\n";
echo "  priya.student@eduflow.test    student (FY BSc CS A)\n";
echo "  neha.student@eduflow.test     student (FY BSc CS B)\n";
