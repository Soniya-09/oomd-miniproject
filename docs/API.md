# API reference

JSON over HTTPS, same-origin, session-cookie auth. Routes live in `routes/api.php`.

* **Writes** (POST/PATCH/DELETE) need header `X-CSRF-Token: <token>` (the page's
  `<meta name="csrf-token">`). Missing/invalid → `419`.
* **Errors**: `{"error": "message", "errors": {"field": "message"}}` with 401 (not logged in),
  403 (wrong role), 404 (not found *or not yours*), 409 (invalid state change), 422 (validation),
  429 (rate limited).
* **Lists** are paginated: `?page=1&per_page=20` (max 100) → `{"data": [...], "meta": {"page", "per_page", "total", "pages"}}`.
  Many accept `?sort=field` or `?sort=-field`.
* **Datetimes** are ISO 8601 UTC (`2026-10-12T13:00:00Z`) in both directions.

Login, logout and password reset are HTML form posts (`/login`, `/logout`, `/forgot-password`,
`/reset-password`), not JSON.

## Any logged-in user

| Method | Path | Notes |
|---|---|---|
| GET | `/api/me` | Profile, role, institution, time zone |
| PATCH | `/api/me` | `full_name`, `phone` |
| POST | `/api/me/password` | `current_password`, `new_password` |
| GET | `/api/notifications` | `?unread=1`, `?type=` |
| GET | `/api/notifications/unread-count` | `{count}` |
| POST | `/api/notifications/{id}/read` · `/api/notifications/read-all` | |
| GET | `/api/announcements` | Visible to caller; `?q=` |
| POST | `/api/announcements` | admin/teacher; multipart: `title`, `body`, `audience` (`institution`\|`classes`), `class_ids` (comma list), `published_at`, `expires_at`, `files[]` |
| POST | `/api/announcements/{id}/read` · DELETE `/api/announcements/{id}` | delete: author or admin |
| GET | `/api/calendar?from=&to=` | teacher/student; max ~3 months; events: `assignment`, `task`, `exam`, `class`, `announcement` |
| GET | `/api/exams?class_id=` · POST `/api/exams` · DELETE `/api/exams/{id}` | admin/teacher (teacher: own class+subject) |
| GET | `/api/files/attachments/{id}` · `/api/files/submissions/{id}` · `/api/files/announcements/{id}` | Authorized downloads |

## Student

| Method | Path | Notes |
|---|---|---|
| GET | `/api/student/dashboard` | Summary cards, upcoming, suggestion, insights |
| GET | `/api/student/progress` | `period=today\|week\|month\|custom`, `from`/`to` (Y-m-d), `kind=all\|academic\|personal`, `granularity=day\|week\|month` |
| GET | `/api/student/items` | Unified academic + personal list: `kind`, `status` (`pending`, `in_progress`, `completed`, `overdue`, `open`, `due_soon`), `priority`, `subject_id`, `q`, `from`, `to` |
| GET | `/api/student/subjects` | Subjects of the student's classes |
| GET | `/api/student/assignments` | `status` (`pending`, `missing`, `open`, `done`, `submitted`, `under_review`, `graded`, `returned`, `resubmitted`), `subject_id`, `q`, `sort=due\|title\|priority` |
| GET | `/api/student/assignments/{id}` | Detail + own submission, released marks and feedback |
| POST | `/api/student/assignments/{id}/submission` | multipart `files[]`, `note`. Creates, replaces (before review) or resubmits (after return) |
| POST | `/api/student/assignments/{id}/resubmission-request` | `reason` |
| GET/POST | `/api/student/tasks` | Personal tasks. Filters: `status` (+`overdue`, `open`), `priority`, `category_id`, `q`, `from`, `to`; `sort=due\|created\|priority\|completed` |
| GET/PATCH/DELETE | `/api/student/tasks/{id}` | Fields: `title`, `description`, `category_id`, `priority`, `status`, `starts_at`, `due_at`, `duration_min`, `context`, `remind_at` |
| GET/POST | `/api/student/categories` · PATCH/DELETE `/api/student/categories/{id}` | `name`, `color` (#rrggbb) |

## Teacher

| Method | Path | Notes |
|---|---|---|
| GET | `/api/teacher/dashboard` · `/api/teacher/analytics?class_id=` | Cards, upcoming; class analytics |
| GET | `/api/teacher/classes` · `/api/teacher/classes/{id}/students` | Own teaching scope |
| GET/POST | `/api/teacher/assignments` | Filters `class_id`, `subject_id`, `status`, `q`. Create fields: `title`, `description`, `class_id`, `subject_id`, `priority`, `due_at`, `max_marks`, `requires_submission`, `allowed_extensions`, `max_upload_mb`, `allow_late`, `allow_resubmission`, `target_all`, `student_ids`, `publish` |
| GET/PATCH | `/api/teacher/assignments/{id}` | Detail includes roster |
| POST | `/api/teacher/assignments/{id}/{publish\|close\|reopen\|cancel}` | State transitions (409 if not allowed) |
| POST/DELETE | `/api/teacher/assignments/{id}/attachments[/{attachmentId}]` | multipart `files[]` |
| GET | `/api/teacher/submissions` | Review queue: `status` (`to_review` default), `assignment_id`, `class_id`, `q` |
| GET | `/api/teacher/submissions/{id}` | Files, feedback, history |
| POST | `/api/teacher/submissions/{id}/{review\|grade\|return\|release}` | grade: `marks`, `feedback`, `release`; return: `feedback` (required) |

## Admin

| Method | Path | Notes |
|---|---|---|
| GET | `/api/admin/overview` · `/api/admin/analytics` | Cards; class/subject/trend/enrolment charts |
| GET/POST | `/api/admin/users` | Filters `role`, `active`, `class_id`, `q`; create: `role`, `full_name`, `email`, `password`, `phone`, student: `admission_no`, `roll_no`, `guardian_name`, `class_ids`; teacher: `employee_no`, `designation`, `department_id` |
| GET/PATCH | `/api/admin/users/{id}` | Includes `is_active`, optional `password` reset |
| POST | `/api/admin/users/import` | multipart `file` (CSV, ≤ 2 MB, ≤ 2000 rows). All-or-nothing |
| GET/POST, PATCH `{id}` | `/api/admin/sessions`, `/api/admin/departments`, `/api/admin/subjects`, `/api/admin/classes` | |
| GET | `/api/admin/classes/{id}` | Class + students + teaching |
| POST / DELETE | `/api/admin/classes/{id}/students` (`student_ids`) / `.../students/{studentId}` | Enrolment |
| GET/POST | `/api/admin/classes/{id}/timetable` · DELETE `/api/admin/timetable/{id}` | `weekday` 1–7, `start_time`, `end_time`, `subject_id`, `teacher_id`, `room` |
| GET/POST, DELETE `{id}` | `/api/admin/teaching` | `class_id`, `subject_id`, `teacher_id` |
| GET/PATCH | `/api/admin/settings` | `name`, `kind`, `timezone`, `due_soon_hours` |
| GET | `/api/admin/audit-logs` | `action`, `user_id` |

## Reports (admin, teacher)

`GET /api/reports/{submissions|missing|classes|grades}.csv?class_id=&assignment_id=` — scoped to
the institution (admin) or the teacher's classes+subjects.

## Scheduled job

`php bin/reminders.php` — due-soon and overdue reminders, personal-task reminders, scheduled
announcements. Idempotent; run every 15 minutes.
