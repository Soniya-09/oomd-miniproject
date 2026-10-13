-- EduFlow initial schema (MySQL 8, InnoDB, utf8mb4).
-- All DATETIME columns hold UTC. The app sets the connection time zone to +00:00.

-- ---------------------------------------------------------------------------
-- Institution & identity
-- ---------------------------------------------------------------------------

CREATE TABLE institutions (
	id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	name VARCHAR(190) NOT NULL,
	code VARCHAR(40) NOT NULL,
	kind ENUM('school', 'college') NOT NULL DEFAULT 'college',
	timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Kolkata',
	settings JSON NULL,
	created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	UNIQUE KEY uq_institutions_code (code)
) ENGINE=InnoDB;

CREATE TABLE users (
	id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	institution_id BIGINT UNSIGNED NOT NULL,
	role ENUM('admin', 'teacher', 'student') NOT NULL,
	email VARCHAR(190) NOT NULL,
	password_hash VARCHAR(255) NOT NULL,
	full_name VARCHAR(150) NOT NULL,
	phone VARCHAR(30) NULL,
	is_active TINYINT(1) NOT NULL DEFAULT 1,
	last_login_at DATETIME NULL,
	created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	deleted_at DATETIME NULL,
	UNIQUE KEY uq_users_email (email),
	KEY ix_users_institution_role (institution_id, role, is_active),
	CONSTRAINT fk_users_institution FOREIGN KEY (institution_id) REFERENCES institutions (id)
) ENGINE=InnoDB;

CREATE TABLE departments (
	id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	institution_id BIGINT UNSIGNED NOT NULL,
	name VARCHAR(150) NOT NULL,
	code VARCHAR(30) NOT NULL,
	created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	UNIQUE KEY uq_departments_code (institution_id, code),
	CONSTRAINT fk_departments_institution FOREIGN KEY (institution_id) REFERENCES institutions (id)
) ENGINE=InnoDB;

CREATE TABLE students (
	user_id BIGINT UNSIGNED PRIMARY KEY,
	institution_id BIGINT UNSIGNED NOT NULL,
	admission_no VARCHAR(40) NOT NULL,
	roll_no VARCHAR(20) NULL,
	guardian_name VARCHAR(150) NULL,
	UNIQUE KEY uq_students_admission (institution_id, admission_no),
	CONSTRAINT fk_students_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
	CONSTRAINT fk_students_institution FOREIGN KEY (institution_id) REFERENCES institutions (id)
) ENGINE=InnoDB;

CREATE TABLE teachers (
	user_id BIGINT UNSIGNED PRIMARY KEY,
	institution_id BIGINT UNSIGNED NOT NULL,
	employee_no VARCHAR(40) NOT NULL,
	designation VARCHAR(100) NULL,
	department_id BIGINT UNSIGNED NULL,
	UNIQUE KEY uq_teachers_employee (institution_id, employee_no),
	CONSTRAINT fk_teachers_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
	CONSTRAINT fk_teachers_institution FOREIGN KEY (institution_id) REFERENCES institutions (id),
	CONSTRAINT fk_teachers_department FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE academic_sessions (
	id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	institution_id BIGINT UNSIGNED NOT NULL,
	name VARCHAR(60) NOT NULL,
	starts_on DATE NOT NULL,
	ends_on DATE NOT NULL,
	is_current TINYINT(1) NOT NULL DEFAULT 0,
	created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	UNIQUE KEY uq_sessions_name (institution_id, name),
	CONSTRAINT fk_sessions_institution FOREIGN KEY (institution_id) REFERENCES institutions (id),
	CONSTRAINT ck_sessions_dates CHECK (ends_on > starts_on)
) ENGINE=InnoDB;

CREATE TABLE classes (
	id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	institution_id BIGINT UNSIGNED NOT NULL,
	academic_session_id BIGINT UNSIGNED NOT NULL,
	department_id BIGINT UNSIGNED NULL,
	name VARCHAR(100) NOT NULL,
	division VARCHAR(20) NOT NULL DEFAULT '',
	is_active TINYINT(1) NOT NULL DEFAULT 1,
	created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	UNIQUE KEY uq_classes_name (institution_id, academic_session_id, name, division),
	CONSTRAINT fk_classes_institution FOREIGN KEY (institution_id) REFERENCES institutions (id),
	CONSTRAINT fk_classes_session FOREIGN KEY (academic_session_id) REFERENCES academic_sessions (id),
	CONSTRAINT fk_classes_department FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE subjects (
	id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	institution_id BIGINT UNSIGNED NOT NULL,
	department_id BIGINT UNSIGNED NULL,
	name VARCHAR(150) NOT NULL,
	code VARCHAR(30) NOT NULL,
	is_active TINYINT(1) NOT NULL DEFAULT 1,
	created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	UNIQUE KEY uq_subjects_code (institution_id, code),
	CONSTRAINT fk_subjects_institution FOREIGN KEY (institution_id) REFERENCES institutions (id),
	CONSTRAINT fk_subjects_department FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE class_students (
	class_id BIGINT UNSIGNED NOT NULL,
	student_id BIGINT UNSIGNED NOT NULL,
	enrolled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (class_id, student_id),
	KEY ix_class_students_student (student_id),
	CONSTRAINT fk_class_students_class FOREIGN KEY (class_id) REFERENCES classes (id) ON DELETE CASCADE,
	CONSTRAINT fk_class_students_student FOREIGN KEY (student_id) REFERENCES students (user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Which teacher teaches which subject to which class. Defines a teacher's scope.
CREATE TABLE teacher_assignments (
	id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	class_id BIGINT UNSIGNED NOT NULL,
	subject_id BIGINT UNSIGNED NOT NULL,
	teacher_id BIGINT UNSIGNED NOT NULL,
	created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	UNIQUE KEY uq_teacher_assignments (class_id, subject_id, teacher_id),
	KEY ix_teacher_assignments_teacher (teacher_id),
	CONSTRAINT fk_ta_class FOREIGN KEY (class_id) REFERENCES classes (id) ON DELETE CASCADE,
	CONSTRAINT fk_ta_subject FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE CASCADE,
	CONSTRAINT fk_ta_teacher FOREIGN KEY (teacher_id) REFERENCES teachers (user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Assignments & submissions
-- ---------------------------------------------------------------------------

CREATE TABLE assignments (
	id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	institution_id BIGINT UNSIGNED NOT NULL,
	class_id BIGINT UNSIGNED NOT NULL,
	subject_id BIGINT UNSIGNED NOT NULL,
	teacher_id BIGINT UNSIGNED NOT NULL,
	title VARCHAR(200) NOT NULL,
	description TEXT NOT NULL,
	priority ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'medium',
	max_marks DECIMAL(6,2) NULL,
	requires_submission TINYINT(1) NOT NULL DEFAULT 1,
	allowed_extensions JSON NOT NULL,
	max_upload_mb SMALLINT UNSIGNED NOT NULL DEFAULT 10,
	allow_late TINYINT(1) NOT NULL DEFAULT 1,
	allow_resubmission TINYINT(1) NOT NULL DEFAULT 1,
	target_all TINYINT(1) NOT NULL DEFAULT 1,
	status ENUM('draft', 'published', 'closed', 'cancelled') NOT NULL DEFAULT 'draft',
	due_at DATETIME NULL,
	published_at DATETIME NULL,
	closed_at DATETIME NULL,
	created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	KEY ix_assignments_class_status (class_id, status, due_at),
	KEY ix_assignments_teacher (teacher_id, status),
	KEY ix_assignments_institution (institution_id, status),
	CONSTRAINT fk_assignments_institution FOREIGN KEY (institution_id) REFERENCES institutions (id),
	CONSTRAINT fk_assignments_class FOREIGN KEY (class_id) REFERENCES classes (id),
	CONSTRAINT fk_assignments_subject FOREIGN KEY (subject_id) REFERENCES subjects (id),
	CONSTRAINT fk_assignments_teacher FOREIGN KEY (teacher_id) REFERENCES teachers (user_id)
) ENGINE=InnoDB;

-- Only used when target_all = 0.
CREATE TABLE assignment_targets (
	assignment_id BIGINT UNSIGNED NOT NULL,
	student_id BIGINT UNSIGNED NOT NULL,
	PRIMARY KEY (assignment_id, student_id),
	KEY ix_assignment_targets_student (student_id),
	CONSTRAINT fk_at_assignment FOREIGN KEY (assignment_id) REFERENCES assignments (id) ON DELETE CASCADE,
	CONSTRAINT fk_at_student FOREIGN KEY (student_id) REFERENCES students (user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE assignment_attachments (
	id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	assignment_id BIGINT UNSIGNED NOT NULL,
	uploaded_by BIGINT UNSIGNED NOT NULL,
	original_name VARCHAR(255) NOT NULL,
	stored_name CHAR(40) NOT NULL,
	mime_type VARCHAR(120) NOT NULL,
	size_bytes INT UNSIGNED NOT NULL,
	created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	UNIQUE KEY uq_assignment_attachments_stored (stored_name),
	CONSTRAINT fk_aa_assignment FOREIGN KEY (assignment_id) REFERENCES assignments (id) ON DELETE CASCADE,
	CONSTRAINT fk_aa_user FOREIGN KEY (uploaded_by) REFERENCES users (id)
) ENGINE=InnoDB;

-- One row per (assignment, student) once the student first submits.
-- "Not submitted" / "missing" are derived from the absence of a row.
CREATE TABLE submissions (
	id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	assignment_id BIGINT UNSIGNED NOT NULL,
	student_id BIGINT UNSIGNED NOT NULL,
	status ENUM('submitted', 'under_review', 'graded', 'returned', 'resubmitted') NOT NULL DEFAULT 'submitted',
	attempt SMALLINT UNSIGNED NOT NULL DEFAULT 1,
	first_submitted_at DATETIME NOT NULL,
	submitted_at DATETIME NOT NULL,
	is_late TINYINT(1) NOT NULL DEFAULT 0,
	student_note TEXT NULL,
	marks DECIMAL(6,2) NULL,
	grade_released TINYINT(1) NOT NULL DEFAULT 0,
	resubmission_requested TINYINT(1) NOT NULL DEFAULT 0,
	graded_by BIGINT UNSIGNED NULL,
	graded_at DATETIME NULL,
	created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	UNIQUE KEY uq_submissions (assignment_id, student_id),
	KEY ix_submissions_student (student_id, status),
	KEY ix_submissions_assignment_status (assignment_id, status),
	CONSTRAINT fk_submissions_assignment FOREIGN KEY (assignment_id) REFERENCES assignments (id) ON DELETE CASCADE,
	CONSTRAINT fk_submissions_student FOREIGN KEY (student_id) REFERENCES students (user_id) ON DELETE CASCADE,
	CONSTRAINT fk_submissions_grader FOREIGN KEY (graded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE submission_files (
	id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	submission_id BIGINT UNSIGNED NOT NULL,
	attempt SMALLINT UNSIGNED NOT NULL,
	original_name VARCHAR(255) NOT NULL,
	stored_name CHAR(40) NOT NULL,
	mime_type VARCHAR(120) NOT NULL,
	size_bytes INT UNSIGNED NOT NULL,
	created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	UNIQUE KEY uq_submission_files_stored (stored_name),
	KEY ix_submission_files_submission (submission_id, attempt),
	CONSTRAINT fk_sf_submission FOREIGN KEY (submission_id) REFERENCES submissions (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE submission_feedback (
	id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	submission_id BIGINT UNSIGNED NOT NULL,
	author_id BIGINT UNSIGNED NOT NULL,
	attempt SMALLINT UNSIGNED NOT NULL,
	comment TEXT NOT NULL,
	is_released TINYINT(1) NOT NULL DEFAULT 0,
	created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	KEY ix_feedback_submission (submission_id),
	CONSTRAINT fk_fb_submission FOREIGN KEY (submission_id) REFERENCES submissions (id) ON DELETE CASCADE,
	CONSTRAINT fk_fb_author FOREIGN KEY (author_id) REFERENCES users (id)
) ENGINE=InnoDB;

CREATE TABLE grading_history (
	id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	submission_id BIGINT UNSIGNED NOT NULL,
	changed_by BIGINT UNSIGNED NOT NULL,
	old_status VARCHAR(20) NULL,
	new_status VARCHAR(20) NOT NULL,
	old_marks DECIMAL(6,2) NULL,
	new_marks DECIMAL(6,2) NULL,
	note VARCHAR(255) NULL,
	created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	KEY ix_grading_history_submission (submission_id),
	CONSTRAINT fk_gh_submission FOREIGN KEY (submission_id) REFERENCES submissions (id) ON DELETE CASCADE,
	CONSTRAINT fk_gh_user FOREIGN KEY (changed_by) REFERENCES users (id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Personal productivity (private to owner)
-- ---------------------------------------------------------------------------

CREATE TABLE task_categories (
	id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	user_id BIGINT UNSIGNED NOT NULL,
	name VARCHAR(60) NOT NULL,
	color CHAR(7) NOT NULL DEFAULT '#0d6efd',
	created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	UNIQUE KEY uq_task_categories (user_id, name),
	CONSTRAINT fk_tc_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE personal_tasks (
	id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	user_id BIGINT UNSIGNED NOT NULL,
	category_id BIGINT UNSIGNED NULL,
	title VARCHAR(200) NOT NULL,
	description TEXT NULL,
	priority ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'medium',
	status ENUM('pending', 'in_progress', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
	starts_at DATETIME NULL,
	due_at DATETIME NULL,
	duration_min SMALLINT UNSIGNED NOT NULL DEFAULT 45,
	context VARCHAR(100) NULL,
	raw_input VARCHAR(500) NULL,
	completed_at DATETIME NULL,
	created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	deleted_at DATETIME NULL,
	KEY ix_personal_tasks_user_status (user_id, status, due_at),
	KEY ix_personal_tasks_completed (user_id, completed_at),
	CONSTRAINT fk_pt_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
	CONSTRAINT fk_pt_category FOREIGN KEY (category_id) REFERENCES task_categories (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE task_reminders (
	id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	task_id BIGINT UNSIGNED NOT NULL,
	remind_at DATETIME NOT NULL,
	sent_at DATETIME NULL,
	created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	UNIQUE KEY uq_task_reminders (task_id, remind_at),
	KEY ix_task_reminders_due (sent_at, remind_at),
	CONSTRAINT fk_tr_task FOREIGN KEY (task_id) REFERENCES personal_tasks (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Notifications & communication
-- ---------------------------------------------------------------------------

CREATE TABLE notifications (
	id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	user_id BIGINT UNSIGNED NOT NULL,
	type VARCHAR(40) NOT NULL,
	title VARCHAR(200) NOT NULL,
	message VARCHAR(1000) NOT NULL,
	related_type VARCHAR(40) NULL,
	related_id BIGINT UNSIGNED NULL,
	action_url VARCHAR(255) NULL,
	-- Same key for the same event = no duplicate on edits or cron retries.
	dedupe_key VARCHAR(120) NULL,
	read_at DATETIME NULL,
	created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	UNIQUE KEY uq_notifications_dedupe (user_id, dedupe_key),
	KEY ix_notifications_user_read (user_id, read_at, created_at),
	CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE announcements (
	id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	institution_id BIGINT UNSIGNED NOT NULL,
	author_id BIGINT UNSIGNED NOT NULL,
	title VARCHAR(200) NOT NULL,
	body TEXT NOT NULL,
	audience ENUM('institution', 'classes') NOT NULL DEFAULT 'institution',
	published_at DATETIME NOT NULL,
	expires_at DATETIME NULL,
	created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	KEY ix_announcements_institution (institution_id, published_at),
	CONSTRAINT fk_announcements_institution FOREIGN KEY (institution_id) REFERENCES institutions (id),
	CONSTRAINT fk_announcements_author FOREIGN KEY (author_id) REFERENCES users (id)
) ENGINE=InnoDB;

CREATE TABLE announcement_targets (
	announcement_id BIGINT UNSIGNED NOT NULL,
	class_id BIGINT UNSIGNED NOT NULL,
	PRIMARY KEY (announcement_id, class_id),
	KEY ix_announcement_targets_class (class_id),
	CONSTRAINT fk_ant_announcement FOREIGN KEY (announcement_id) REFERENCES announcements (id) ON DELETE CASCADE,
	CONSTRAINT fk_ant_class FOREIGN KEY (class_id) REFERENCES classes (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE announcement_reads (
	announcement_id BIGINT UNSIGNED NOT NULL,
	user_id BIGINT UNSIGNED NOT NULL,
	read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (announcement_id, user_id),
	CONSTRAINT fk_anr_announcement FOREIGN KEY (announcement_id) REFERENCES announcements (id) ON DELETE CASCADE,
	CONSTRAINT fk_anr_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Academic calendar
-- ---------------------------------------------------------------------------

CREATE TABLE exams (
	id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	institution_id BIGINT UNSIGNED NOT NULL,
	class_id BIGINT UNSIGNED NOT NULL,
	subject_id BIGINT UNSIGNED NOT NULL,
	title VARCHAR(200) NOT NULL,
	starts_at DATETIME NOT NULL,
	ends_at DATETIME NOT NULL,
	room VARCHAR(60) NULL,
	created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	KEY ix_exams_class (class_id, starts_at),
	CONSTRAINT fk_exams_institution FOREIGN KEY (institution_id) REFERENCES institutions (id),
	CONSTRAINT fk_exams_class FOREIGN KEY (class_id) REFERENCES classes (id) ON DELETE CASCADE,
	CONSTRAINT fk_exams_subject FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE CASCADE,
	CONSTRAINT ck_exams_time CHECK (ends_at > starts_at)
) ENGINE=InnoDB;

CREATE TABLE timetable_entries (
	id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	class_id BIGINT UNSIGNED NOT NULL,
	subject_id BIGINT UNSIGNED NOT NULL,
	teacher_id BIGINT UNSIGNED NULL,
	weekday TINYINT UNSIGNED NOT NULL,
	start_time TIME NOT NULL,
	end_time TIME NOT NULL,
	room VARCHAR(60) NULL,
	KEY ix_timetable_class (class_id, weekday),
	CONSTRAINT fk_tt_class FOREIGN KEY (class_id) REFERENCES classes (id) ON DELETE CASCADE,
	CONSTRAINT fk_tt_subject FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE CASCADE,
	CONSTRAINT fk_tt_teacher FOREIGN KEY (teacher_id) REFERENCES teachers (user_id) ON DELETE SET NULL,
	CONSTRAINT ck_tt_weekday CHECK (weekday BETWEEN 1 AND 7),
	CONSTRAINT ck_tt_time CHECK (end_time > start_time)
) ENGINE=InnoDB;

CREATE TABLE attendance_records (
	id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	class_id BIGINT UNSIGNED NOT NULL,
	student_id BIGINT UNSIGNED NOT NULL,
	attended_on DATE NOT NULL,
	status ENUM('present', 'absent', 'late', 'excused') NOT NULL,
	marked_by BIGINT UNSIGNED NOT NULL,
	created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	UNIQUE KEY uq_attendance (class_id, student_id, attended_on),
	CONSTRAINT fk_att_class FOREIGN KEY (class_id) REFERENCES classes (id) ON DELETE CASCADE,
	CONSTRAINT fk_att_student FOREIGN KEY (student_id) REFERENCES students (user_id) ON DELETE CASCADE,
	CONSTRAINT fk_att_marker FOREIGN KEY (marked_by) REFERENCES users (id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Security & operations
-- ---------------------------------------------------------------------------

CREATE TABLE audit_logs (
	id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	institution_id BIGINT UNSIGNED NULL,
	user_id BIGINT UNSIGNED NULL,
	action VARCHAR(60) NOT NULL,
	entity_type VARCHAR(40) NULL,
	entity_id BIGINT UNSIGNED NULL,
	details JSON NULL,
	ip_address VARCHAR(45) NULL,
	created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	KEY ix_audit_institution (institution_id, created_at),
	CONSTRAINT fk_audit_institution FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE SET NULL,
	CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE password_reset_tokens (
	id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	user_id BIGINT UNSIGNED NOT NULL,
	token_hash CHAR(64) NOT NULL,
	expires_at DATETIME NOT NULL,
	used_at DATETIME NULL,
	created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	UNIQUE KEY uq_reset_token (token_hash),
	CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Fixed-window counters for login / reset / upload throttling.
CREATE TABLE rate_limits (
	bucket_key VARCHAR(190) PRIMARY KEY,
	hits INT UNSIGNED NOT NULL,
	expires_at DATETIME NOT NULL,
	KEY ix_rate_limits_expiry (expires_at)
) ENGINE=InnoDB;
