# Roles and permissions

Authorization happens on the server for every request. The UI only hides what a role cannot use;
it is never the thing that enforces access.

## Layers

1. **Authentication** (`app/Middleware/Authenticate.php`) — session user is re-loaded from the
   database on each request. Deactivated or deleted accounts lose access immediately.
2. **CSRF** (`app/Middleware/VerifyCsrf.php`) — every non-GET request needs the session token
   (`X-CSRF-Token` header or `_csrf` form field).
3. **Role** (`app/Middleware/RequireRole.php`) — route groups for `admin`, `teacher`, `student`.
4. **Scope / ownership** (`app/Services/Policy.php` and each service) — rows are always fetched
   with the caller's institution, teaching assignment, enrolment or ownership in the SQL `WHERE`
   clause. A record outside the caller's scope returns **404**, so ids cannot be probed.

## Matrix

| Capability | Admin | Teacher | Student |
|---|:-:|:-:|:-:|
| Manage users, CSV import, (de)activate accounts | ✅ own institution | — | — |
| Manage sessions, departments, classes, subjects | ✅ | — | — |
| Enroll students, assign teachers to class+subject | ✅ | — | — |
| Weekly timetable | ✅ | — | — |
| Institution settings, audit log | ✅ | — | — |
| Institution analytics, CSV reports | ✅ all classes | ✅ own classes+subjects | — |
| Create / edit / publish / close / cancel assignments | — | ✅ only for class+subject they teach | — |
| See assignments | — | ✅ for class+subject they teach (incl. drafts) | ✅ published/closed, addressed to them |
| Upload learning materials | — | ✅ own scope | — |
| Download learning materials | — | ✅ own scope | ✅ if they can see the assignment |
| Submit / resubmit work | — | — | ✅ own, per assignment policy |
| Download submission files | — | ✅ own scope | ✅ only their own |
| Grade, comment, return, release grades | — | ✅ own scope | — |
| See marks & feedback | — | ✅ always | ✅ own, once released |
| Schedule exams | ✅ any class | ✅ own class+subject | — |
| Announcements: post | ✅ institution or any class | ✅ classes they teach | — |
| Announcements: read | ✅ all | ✅ institution-wide + their classes + own | ✅ institution-wide + their classes |
| Personal tasks, categories, reminders | — | — | ✅ only their own |
| Calendar | — | ✅ own scope | ✅ own scope + own tasks |
| Notifications | own | own | own |
| Profile: name, phone, password | ✅ | ✅ | ✅ |

## Multi-institution isolation

Every institution-owned table carries `institution_id` (or reaches it through a parent row), and
every admin query filters by `Auth::institutionId()`. Tests in `tests/AdminTest.php`
(`testTenantIsolation`) create a second institution and check that none of its users, classes or
sessions can be read or referenced.

## Other safeguards

* Passwords: `password_hash` / `password_verify` (bcrypt), policy: ≥ 8 characters, a letter and a
  number, not containing the email name. Hashes are upgraded on login when PHP's default changes.
* Login throttling: 5 failed attempts per email+IP and 30 per IP per 15 minutes. Password-reset
  requests: 3 per IP per hour. Uploads: 30 per user per hour.
* Session id is regenerated at login; cookies are `HttpOnly`, `SameSite=Lax`, and `Secure` when
  `APP_URL` is HTTPS. Idle sessions expire after `SESSION_LIFETIME` seconds.
* Password reset tokens are random 256-bit values stored only as SHA-256 hashes, valid for one
  hour, single use, and only the newest one works. The reset request never reveals whether an
  email exists.
* Uploads: extension allow-list ∩ assignment's allowed types, real MIME check with `fileinfo`,
  rejection of executables / scripts / double extensions, size limits, random 40-hex stored names
  in `storage/private_uploads` (outside `public/`), always served as `application/octet-stream`
  attachments after an authorization check.
* All SQL uses prepared statements. All HTML output goes through `e()` (PHP) or the escaping
  `EF.html` template tag (JS). A strict Content-Security-Policy blocks inline scripts.
* CSV exports neutralise spreadsheet formulas (`=`, `+`, `-`, `@`).
* Errors never expose stack traces unless `APP_DEBUG=true`.
