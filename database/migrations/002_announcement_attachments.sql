-- Files attached to announcements (stored privately like assignment attachments).
CREATE TABLE announcement_attachments (
	id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	announcement_id BIGINT UNSIGNED NOT NULL,
	original_name VARCHAR(255) NOT NULL,
	stored_name CHAR(40) NOT NULL,
	mime_type VARCHAR(120) NOT NULL,
	size_bytes INT UNSIGNED NOT NULL,
	created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	UNIQUE KEY uq_announcement_attachments_stored (stored_name),
	CONSTRAINT fk_anatt_announcement FOREIGN KEY (announcement_id) REFERENCES announcements (id) ON DELETE CASCADE
) ENGINE=InnoDB;
