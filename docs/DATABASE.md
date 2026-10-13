# Database

MySQL 8, InnoDB, `utf8mb4`. Schema: `database/migrations/*.sql`, applied in order by
`php bin/migrate.php` and tracked in `schema_migrations`. All `DATETIME` columns are UTC
(the connection runs `SET time_zone = '+00:00'`).

## Relationships

```
institutions ─┬─< users ─┬─ students (1:1, user_id PK)
              │          └─ teachers (1:1, user_id PK) >─ departments
              ├─< departments
              ├─< academic_sessions ─< classes >─ departments
              ├─< subjects >─ departments
              ├─< assignments
              ├─< announcements
              ├─< exams
              └─< audit_logs

classes ─< class_students >─ students                (enrolment, M:N)
classes ─< teacher_assignments >─ subjects, teachers  (who teaches what to whom)
classes ─< timetable_entries >─ subjects, teachers
classes ─< attendance_records >─ students             (table ready; feature not built yet)

assignments >─ classes, subjects, teachers
assignments ─< assignment_targets >─ students   (only when target_all = 0)
assignments ─< assignment_attachments
assignments ─< submissions >─ students           (one row per student, UNIQUE)
submissions ─< submission_files                  (per attempt, history kept)
submissions ─< submission_feedback
submissions ─< grading_history

users ─< personal_tasks >─ task_categories (owned by the same user)
personal_tasks ─< task_reminders
users ─< notifications                           (UNIQUE user_id + dedupe_key)
announcements ─< announcement_targets >─ classes
announcements ─< announcement_reads >─ users
announcements ─< announcement_attachments
users ─< password_reset_tokens
rate_limits                                      (throttling counters)
```

## Design notes

* **One identity table, role profiles.** `users` holds login data and role; `students` and
  `teachers` hold role-specific fields with `user_id` as primary key.
* **No comma-separated ids.** Targets, enrolments and teaching assignments are mapping tables.
  (`assignments.allowed_extensions` is a JSON list of file extensions, not ids.)
* **Assignment recipients** are defined once in `app/Repositories/Recipients.php`: students
  enrolled in the class, narrowed by `assignment_targets` when `target_all = 0`. Visibility,
  notifications, rosters and analytics all join that one definition.
* **Publication vs submission state** are separate: `assignments.status`
  (draft → published → closed, or cancelled) and `submissions.status`
  (submitted → under_review → graded / returned → resubmitted …). "Not submitted" and "missing" are
  the absence of a submission row.
* **Soft deletion** for `users` (`deleted_at`, plus `is_active` for deactivation) and
  `personal_tasks`. Academic records are never hard-deleted by the UI; assignments are cancelled.
* **Idempotent notifications** via `UNIQUE (user_id, dedupe_key)` and `INSERT IGNORE`.
* **Transactions** wrap every multi-step write (user + profile + enrolment, assignment + targets,
  submission + files + history, CSV import). Nested calls use savepoints.

## Indexes worth knowing

| Table | Index | Used by |
|---|---|---|
| `assignments` | `(class_id, status, due_at)` | student lists, recipients, reminders |
| `submissions` | `UNIQUE (assignment_id, student_id)`, `(student_id, status)` | rosters, dashboards |
| `class_students` | PK `(class_id, student_id)`, `(student_id)` | recipients, scope |
| `teacher_assignments` | `UNIQUE (class_id, subject_id, teacher_id)`, `(teacher_id)` | teacher scope |
| `personal_tasks` | `(user_id, status, due_at)`, `(user_id, completed_at)` | task list, trends |
| `notifications` | `(user_id, read_at, created_at)` | unread badge, list |

## Seed data

`php bin/seed.php` loads a fictional college ("Riverside College of Arts & Science") with one
admin, two teachers, seven students in two classes, four subjects, a timetable, exams,
announcements, assignments with on-time / late / missing / graded / returned submissions, and
personal tasks completed over the past two weeks. Every account's password is `Demo@1234`.
All names and emails are made up.
