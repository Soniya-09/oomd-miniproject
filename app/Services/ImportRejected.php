<?php

declare(strict_types=1);

namespace App\Services;

/** Internal signal used to roll back a CSV import that had row errors. */
final class ImportRejected extends \RuntimeException
{
}
