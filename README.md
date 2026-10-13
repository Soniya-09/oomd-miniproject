# EduFlow — Academic Management & Productivity Portal

EduFlow brings school/college administration, assignment distribution, submissions, grading,
announcements and a student's private to-do list into one place. Students get a single dashboard
for academic work and personal tasks, with charts built from their real activity.

* **Admins** manage users (incl. CSV import), sessions, departments, classes, subjects,
  enrolment, teacher assignments, timetables, institution settings, reports and the audit log.
* **Teachers** publish assignments to a class or selected students, attach materials, review a
  queue of submissions, grade, give feedback, return work for revision, schedule exams, post
  announcements and see class analytics.
* **Students** see assignments addressed to them, submit and resubmit files, read released
  feedback, manage private tasks (with a natural-language "quick add"), use a calendar, receive
  notifications and track progress.

Stack: PHP 8.2+ (no framework, no Composer), MySQL 8 via PDO, Bootstrap 5, Chart.js 4, vanilla JS.

## Requirements

* PHP **8.2 or newer** with `pdo_mysql`, `mbstring`, `fileinfo`, `openssl` (developed on 8.4)
* MySQL **8.0** (or MariaDB 10.6+, untested)
* A modern browser. Bootstrap, Bootstrap Icons and Chart.js load from `cdn.jsdelivr.net`.

On XAMPP enable `extension=fileinfo` in `php.ini` if it is commented out.

## Setup

```bash
git clone https://github.com/Soniya-09/oomd-miniproject.git eduflow
cd eduflow
cp .env.example .env

# 1. Create the databases and a least-privilege app user (asks for the MySQL root password,
#    which is not stored). Writes the app user's credentials into .env.
php bin/setup-db.php

# 2. Create tables and load the demo institution
php bin/migrate.php
php bin/seed.php

# 3. Run
php -S localhost:8000 -t public public/index.php
```

Open <http://localhost:8000> and log in with a demo account (password `Demo@1234`):

| Role | Email |
|---|---|
| Admin | `admin@eduflow.test` |
| Teacher | `meera.teacher@eduflow.test` (CS subjects), `rahul.teacher@eduflow.test` (Maths) |
| Student | `priya.student@eduflow.test` (class A), `neha.student@eduflow.test` (class B) |

All demo people and emails are fictional.

**Prefer phpMyAdmin/Workbench?** Create a database `eduflow` (and `eduflow_test` for tests), a user
with `SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES` on them, put the
credentials in `.env`, then run steps 2–3.

**Apache / XAMPP:** point the virtual host's `DocumentRoot` at the `public/` folder (never the
project root, so `storage/`, `.env` and source code are not web-accessible). `public/.htaccess`
routes requests to `index.php`; `mod_rewrite` must be on.

### Scheduled reminders

`bin/reminders.php` sends due-soon and overdue reminders, personal-task reminders and announcements
scheduled for later. It is safe to run repeatedly.

```bash
# Linux cron, every 15 minutes
*/15 * * * * php /var/www/eduflow/bin/reminders.php >> /var/www/eduflow/storage/logs/cron.log 2>&1
```

On Windows use Task Scheduler with `php.exe D:\path\to\eduflow\bin\reminders.php`.

### Email

With `MAIL_DRIVER=log` (default) emails such as password-reset links are written to
`storage/logs/mail.log`. Set `MAIL_DRIVER=mail` to use PHP's `mail()`.

## Tests

```bash
php bin/test.php            # all tests (uses DB_TEST_DATABASE, wiped on each run)
php bin/test.php Assignment # only test classes whose name contains "Assignment"
```

The suite (48 tests) runs requests through the real router, middleware and controllers
against MySQL. It covers authentication, CSRF, rate limiting, role and ownership checks, tenant
isolation, the full assignment → submission → grading → resubmission workflow, upload
validation, notification de-duplication, reminders, calendar scope, announcement audiences,
dashboard counts vs. database records, and chart total consistency.

## Project layout

```
app/
  Core/          Router, Request/Response, Database (PDO), Session, Csrf, Auth, Validator,
                 RateLimiter, View, Paginator, Migrator, Kernel
  Middleware/    Authenticate, RequireRole, VerifyCsrf, RedirectIfAuthenticated
  Controllers/   Page shells + Api/* JSON controllers
  Services/      Business rules: assignments, submissions, personal tasks, analytics,
                 notifications, announcements, calendar, schedule, files, policy, audit
  Repositories/  User queries, the shared "assignment recipients" definition
  Validators/    Password policy
bin/             setup-db, migrate, seed, reminders, test
config/app.php   Configuration (reads .env)
database/        migrations/*.sql, seeders/
docs/            API, permissions, database, metrics
public/          index.php (front controller), assets/css, assets/js
resources/views  layouts, components, auth, admin, teacher, student, shared
routes/          web.php (pages), api.php (JSON)
storage/         private_uploads (outside web root), logs
tests/           TestCase + feature tests
```

## Documentation

* [docs/API.md](docs/API.md) — every endpoint
* [docs/PERMISSIONS.md](docs/PERMISSIONS.md) — role matrix and security measures
* [docs/DATABASE.md](docs/DATABASE.md) — tables and relationships
* [docs/METRICS.md](docs/METRICS.md) — how each dashboard number is calculated
* [docs/uml/](docs/uml/README.md) — UML: use case, class, sequence, activity, state machine,
  component and deployment diagrams

## Production checklist

* `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://…` (turns on `Secure` cookies)
* Serve over HTTPS only; `DocumentRoot` = `public/`
* Database user limited to the app database (as `bin/setup-db.php` creates)
* Back up the database **and** `storage/private_uploads/`
* Schedule `bin/reminders.php`; configure real mail delivery

## Status

Built: phases 1–4 of the brief (foundation, assignments & submissions, personal productivity,
analytics) and, from phase 5, announcements, exams and the weekly timetable.

Not built yet:
* Attendance (the `attendance_records` table exists; no screens or endpoints)
* Parent/guardian access
* Assignment discussion threads, recurring tasks, browser push / email reminders
* Admin-configurable notification rules beyond the "due soon" window
