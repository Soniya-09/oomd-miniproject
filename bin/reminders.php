<?php

declare(strict_types=1);

/*
 * Scheduled job: deadline reminders, overdue notices, personal-task reminders and
 * scheduled announcements. Safe to run repeatedly (every notification has a dedupe key).
 *
 *   Cron (every 15 min):   php /path/to/eduflow/bin/reminders.php
 *   Windows Task Scheduler: php.exe D:\path\to\eduflow\bin\reminders.php
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\ReminderService;

$counts = (new ReminderService())->run();
printf("[%s] due-soon: %d, overdue: %d, task reminders: %d, announcements: %d\n", gmdate('c'), ...array_values($counts));
