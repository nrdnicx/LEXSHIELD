-- LEXSHIELD database schema and seed data
-- InfinityFree version: import this after selecting your InfinityFree database in phpMyAdmin.


SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `notifications`;
DROP TABLE IF EXISTS `sessions`;
DROP TABLE IF EXISTS `email_otps`;
DROP TABLE IF EXISTS `password_resets`;
DROP TABLE IF EXISTS `quick_inquiries`;
DROP TABLE IF EXISTS `phishing_scans`;
DROP TABLE IF EXISTS `rate_limits`;
DROP TABLE IF EXISTS `audit_logs`;
DROP TABLE IF EXISTS `messages`;
DROP TABLE IF EXISTS `manual_payments`;
DROP TABLE IF EXISTS `appointments`;
DROP TABLE IF EXISTS `compliance_checks`;
DROP TABLE IF EXISTS `documents`;
DROP TABLE IF EXISTS `case_file_documents`;
DROP TABLE IF EXISTS `case_file_folders`;
DROP TABLE IF EXISTS `cases`;
DROP TABLE IF EXISTS `case_files`;
DROP TABLE IF EXISTS `risk_assessments`;
DROP TABLE IF EXISTS `site_settings`;
DROP TABLE IF EXISTS `case_notes`;
DROP TABLE IF EXISTS `clients`;
DROP TABLE IF EXISTS `lawyers`;
DROP TABLE IF EXISTS `users`;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `full_name` VARCHAR(150) NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('admin','lawyer','client') NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `failed_login_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `locked_until` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login` DATETIME DEFAULT NULL,
  `avatar_stored_name` VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_role_active` (`role`, `is_active`)
) ENGINE=InnoDB;

CREATE TABLE `lawyers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `bar_number` VARCHAR(60) NOT NULL,
  `specialization` VARCHAR(120) NOT NULL,
  `status` ENUM('active','busy','suspended') NOT NULL DEFAULT 'active',
  `bio` TEXT,
  `background` TEXT,
  `contact_number` VARCHAR(40) DEFAULT NULL,
  `gcash_account_name` VARCHAR(150) DEFAULT NULL,
  `gcash_number` VARCHAR(40) DEFAULT NULL,
  `gcash_qr_stored_name` VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lawyers_user` (`user_id`),
  UNIQUE KEY `uq_lawyers_bar` (`bar_number`),
  KEY `idx_lawyers_status_specialization` (`status`, `specialization`),
  CONSTRAINT `fk_lawyers_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `clients` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `contact_number` VARCHAR(40) NOT NULL,
  `address` TEXT,
  `risk_level` ENUM('low','medium','high') NOT NULL DEFAULT 'low',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_clients_user` (`user_id`),
  KEY `idx_clients_risk` (`risk_level`),
  CONSTRAINT `fk_clients_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `cases` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `case_number` VARCHAR(50) NOT NULL,
  `title` VARCHAR(180) NOT NULL,
  `description` TEXT NOT NULL,
  `lawyer_id` INT UNSIGNED NOT NULL,
  `client_id` INT UNSIGNED NOT NULL,
  `status` ENUM('open','ongoing','closed','archived') NOT NULL DEFAULT 'open',
  `priority` ENUM('low','normal','urgent') NOT NULL DEFAULT 'normal',
  `filed_date` DATE NOT NULL,
  `closed_date` DATE DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cases_number` (`case_number`),
  KEY `idx_cases_lawyer_status` (`lawyer_id`, `status`),
  KEY `idx_cases_client_status` (`client_id`, `status`),
  CONSTRAINT `fk_cases_lawyer` FOREIGN KEY (`lawyer_id`) REFERENCES `lawyers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_cases_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE `case_files` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `full_name` VARCHAR(150) NOT NULL,
  `case_identifier` VARCHAR(80) NOT NULL,
  `case_file_title` VARCHAR(180) NOT NULL,
  `description` TEXT NULL,
  `date_created` DATE NOT NULL,
  `client_user_id` INT UNSIGNED NOT NULL,
  `assigned_lawyer_user_id` INT UNSIGNED DEFAULT NULL,
  `status` ENUM('open','ongoing','closed') NOT NULL DEFAULT 'open',
  `folder_name` VARCHAR(180) NOT NULL,
  `attachments_json` LONGTEXT NULL,
  `created_by_user_id` INT UNSIGNED NOT NULL,
  `updated_by_user_id` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_case_files_identifier` (`case_identifier`),
  UNIQUE KEY `uq_case_files_folder` (`folder_name`),
  KEY `idx_case_files_fullname` (`full_name`),
  KEY `idx_case_files_title` (`case_file_title`),
  KEY `idx_case_files_client` (`client_user_id`),
  KEY `idx_case_files_lawyer` (`assigned_lawyer_user_id`),
  CONSTRAINT `fk_case_files_client_user` FOREIGN KEY (`client_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_case_files_assigned_lawyer_user` FOREIGN KEY (`assigned_lawyer_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_case_files_created_by_user` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_case_files_updated_by_user` FOREIGN KEY (`updated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `case_file_folders` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `case_file_id` INT UNSIGNED NOT NULL,
  `parent_folder_id` INT UNSIGNED DEFAULT NULL,
  `name` VARCHAR(150) NOT NULL,
  `slug` VARCHAR(170) NOT NULL,
  `created_by_user_id` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_case_file_folder_slug` (`case_file_id`, `slug`),
  KEY `idx_case_file_folders_case` (`case_file_id`),
  KEY `idx_case_file_folders_parent` (`parent_folder_id`),
  CONSTRAINT `fk_case_file_folders_case` FOREIGN KEY (`case_file_id`) REFERENCES `case_files` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_case_file_folders_parent` FOREIGN KEY (`parent_folder_id`) REFERENCES `case_file_folders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_case_file_folders_user` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE `case_file_documents` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `case_file_id` INT UNSIGNED NOT NULL,
  `folder_id` INT UNSIGNED NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `stored_name` VARCHAR(255) NOT NULL,
  `storage_provider` VARCHAR(30) NOT NULL DEFAULT 'local',
  `storage_path` VARCHAR(500) DEFAULT NULL,
  `mime_type` VARCHAR(120) DEFAULT NULL,
  `file_size` INT UNSIGNED DEFAULT NULL,
  `file_hash` CHAR(64) DEFAULT NULL,
  `encryption_algorithm` VARCHAR(40) DEFAULT NULL,
  `encryption_iv` VARCHAR(64) DEFAULT NULL,
  `encryption_tag` VARCHAR(64) DEFAULT NULL,
  `encrypted_at` DATETIME DEFAULT NULL,
  `upload_status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved',
  `uploaded_by_user_id` INT UNSIGNED NOT NULL,
  `approved_by_user_id` INT UNSIGNED DEFAULT NULL,
  `approved_at` DATETIME DEFAULT NULL,
  `rejection_reason` TEXT DEFAULT NULL,
  `is_confidential` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_case_file_documents_stored` (`case_file_id`, `stored_name`),
  KEY `idx_case_file_documents_case` (`case_file_id`, `upload_status`),
  KEY `idx_case_file_documents_folder` (`folder_id`),
  KEY `idx_case_file_documents_uploaded_by` (`uploaded_by_user_id`),
  KEY `idx_case_file_documents_storage` (`storage_provider`, `storage_path`),
  CONSTRAINT `fk_case_file_documents_case` FOREIGN KEY (`case_file_id`) REFERENCES `case_files` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_case_file_documents_folder` FOREIGN KEY (`folder_id`) REFERENCES `case_file_folders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_case_file_documents_uploaded_by` FOREIGN KEY (`uploaded_by_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_case_file_documents_approved_by` FOREIGN KEY (`approved_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `appointments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `case_id` INT UNSIGNED DEFAULT NULL,
  `client_id` INT UNSIGNED NOT NULL,
  `lawyer_id` INT UNSIGNED NOT NULL,
  `scheduled_at` DATETIME NOT NULL,
  `appointment_type` VARCHAR(120) NOT NULL DEFAULT 'Client Intake Consultation',
  `status` ENUM('pending','confirmed','cancelled','deleted') NOT NULL DEFAULT 'pending',
  `notes` TEXT,
  `meeting_provider` VARCHAR(30) DEFAULT NULL,
  `meeting_room` VARCHAR(180) DEFAULT NULL,
  `meeting_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `meeting_created_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_appointments_lawyer_date` (`lawyer_id`, `scheduled_at`),
  KEY `idx_appointments_client_date` (`client_id`, `scheduled_at`),
  UNIQUE KEY `idx_appointments_meeting_room` (`meeting_room`),
  CONSTRAINT `fk_appointments_case` FOREIGN KEY (`case_id`) REFERENCES `cases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_appointments_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_appointments_lawyer` FOREIGN KEY (`lawyer_id`) REFERENCES `lawyers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `manual_payments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id` INT UNSIGNED NOT NULL,
  `lawyer_id` INT UNSIGNED DEFAULT NULL,
  `payment_channel` ENUM('gcash') NOT NULL DEFAULT 'gcash',
  `payment_for` VARCHAR(180) NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'PHP',
  `payer_name` VARCHAR(150) NOT NULL,
  `payer_contact` VARCHAR(40) DEFAULT NULL,
  `reference_number` VARCHAR(120) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `proof_original_name` VARCHAR(255) NOT NULL,
  `proof_stored_name` VARCHAR(255) NOT NULL,
  `proof_mime_type` VARCHAR(120) DEFAULT NULL,
  `proof_size` INT UNSIGNED DEFAULT NULL,
  `status` ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  `admin_notes` TEXT DEFAULT NULL,
  `reviewed_by_user_id` INT UNSIGNED DEFAULT NULL,
  `reviewed_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_manual_payments_client` (`client_id`, `status`, `created_at`),
  KEY `idx_manual_payments_lawyer` (`lawyer_id`, `status`, `created_at`),
  KEY `idx_manual_payments_status` (`status`, `created_at`),
  KEY `idx_manual_payments_reviewer` (`reviewed_by_user_id`),
  CONSTRAINT `fk_manual_payments_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_manual_payments_lawyer` FOREIGN KEY (`lawyer_id`) REFERENCES `lawyers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_manual_payments_reviewer` FOREIGN KEY (`reviewed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `lawyer_reviews` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `lawyer_id` INT UNSIGNED NOT NULL,
  `client_id` INT UNSIGNED NOT NULL,
  `rating` TINYINT UNSIGNED NOT NULL,
  `comment` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lawyer_reviews_pair` (`lawyer_id`, `client_id`),
  KEY `idx_lawyer_reviews_lawyer` (`lawyer_id`),
  KEY `idx_lawyer_reviews_client` (`client_id`),
  CONSTRAINT `fk_lawyer_reviews_lawyer` FOREIGN KEY (`lawyer_id`) REFERENCES `lawyers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lawyer_reviews_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `messages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sender_id` INT UNSIGNED NOT NULL,
  `receiver_id` INT UNSIGNED NOT NULL,
  `case_id` INT UNSIGNED DEFAULT NULL,
  `message_text` TEXT NOT NULL,
  `attachment_original_name` VARCHAR(255) DEFAULT NULL,
  `attachment_stored_name` VARCHAR(255) DEFAULT NULL,
  `attachment_mime_type` VARCHAR(120) DEFAULT NULL,
  `attachment_size` INT UNSIGNED DEFAULT NULL,
  `is_encrypted` TINYINT(1) NOT NULL DEFAULT 1,
  `sent_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_messages_case_sent` (`case_id`, `sent_at`),
  KEY `idx_messages_receiver_read` (`receiver_id`, `is_read`),
  CONSTRAINT `fk_messages_sender` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_messages_receiver` FOREIGN KEY (`receiver_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_messages_case` FOREIGN KEY (`case_id`) REFERENCES `cases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `audit_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `action` VARCHAR(120) NOT NULL,
  `target_table` VARCHAR(120) NOT NULL,
  `target_id` VARCHAR(64) DEFAULT NULL,
  `ip_address` VARCHAR(45) NOT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `performed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_user_action_date` (`user_id`, `action`, `performed_at`),
  KEY `idx_audit_target` (`target_table`, `target_id`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `sessions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `session_token` VARCHAR(128) NOT NULL,
  `ip_address` VARCHAR(45) NOT NULL,
  `device_info` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sessions_token` (`session_token`),
  KEY `idx_sessions_user_expiry` (`user_id`, `expires_at`),
  CONSTRAINT `fk_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `email_otps` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(190) NOT NULL,
  `otp_hash` VARCHAR(255) NOT NULL,
  `purpose` VARCHAR(50) NOT NULL DEFAULT 'client_registration',
  `expires_at` DATETIME NOT NULL,
  `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `is_used` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_email_otps_email_purpose` (`email`, `purpose`, `is_used`),
  KEY `idx_email_otps_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `password_resets` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `is_used` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `used_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_password_resets_token` (`token_hash`),
  KEY `idx_password_resets_user` (`user_id`, `is_used`),
  KEY `idx_password_resets_expires` (`expires_at`),
  CONSTRAINT `fk_password_resets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `quick_inquiries` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `full_name` VARCHAR(150) NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `phone` VARCHAR(40) DEFAULT NULL,
  `topic` VARCHAR(120) NOT NULL,
  `message` TEXT NOT NULL,
  `status` ENUM('new','read','replied','closed') NOT NULL DEFAULT 'new',
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_quick_inquiries_status_date` (`status`, `created_at`),
  KEY `idx_quick_inquiries_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `rate_limits` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `action` VARCHAR(64) NOT NULL,
  `identifier_hash` CHAR(64) NOT NULL,
  `identifier_label` VARCHAR(190) DEFAULT NULL,
  `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
  `window_started_at` DATETIME NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `blocked_until` DATETIME DEFAULT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rate_limits_action_identifier` (`action`, `identifier_hash`),
  KEY `idx_rate_limits_expires` (`expires_at`),
  KEY `idx_rate_limits_blocked_until` (`blocked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `phishing_scans` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `submitted_url` TEXT NOT NULL,
  `final_url` TEXT DEFAULT NULL,
  `status` ENUM('suspicious','phishing') NOT NULL,
  `score` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `findings_json` JSON DEFAULT NULL,
  `redirect_count` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `ip_address` VARCHAR(45) NOT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_phishing_scans_status_date` (`status`, `created_at`),
  KEY `idx_phishing_scans_user_date` (`user_id`, `created_at`),
  CONSTRAINT `fk_phishing_scans_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `notifications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `type` VARCHAR(60) NOT NULL,
  `message` VARCHAR(255) NOT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notifications_user_read` (`user_id`, `is_read`),
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `risk_assessments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id` INT UNSIGNED NOT NULL,
  `score` INT NOT NULL,
  `level` ENUM('low','medium','high','critical') NOT NULL DEFAULT 'low',
  `assessed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `notes` TEXT,
  PRIMARY KEY (`id`),
  KEY `idx_risk_client_level` (`client_id`, `level`),
  CONSTRAINT `fk_risk_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `case_notes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `case_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `note_text` TEXT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_case_notes_case_date` (`case_id`, `created_at`),
  CONSTRAINT `fk_case_notes_case` FOREIGN KEY (`case_id`) REFERENCES `cases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_case_notes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `documents` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `case_id` INT UNSIGNED NOT NULL,
  `uploaded_by` INT UNSIGNED NOT NULL,
  `filename` VARCHAR(255) NOT NULL,
  `filepath` VARCHAR(255) NOT NULL,
  `file_hash` CHAR(64) NOT NULL,
  `encryption_key_ref` VARCHAR(255) DEFAULT NULL,
  `is_confidential` TINYINT(1) NOT NULL DEFAULT 1,
  `uploaded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_documents_case` (`case_id`),
  KEY `idx_documents_uploaded_by` (`uploaded_by`),
  CONSTRAINT `fk_documents_case` FOREIGN KEY (`case_id`) REFERENCES `cases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_documents_uploaded_by` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE `compliance_checks` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `case_id` INT UNSIGNED NOT NULL,
  `checked_by` INT UNSIGNED NOT NULL,
  `checklist` JSON NOT NULL,
  `passed` TINYINT(1) NOT NULL DEFAULT 0,
  `notes` TEXT,
  `checked_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_compliance_case` (`case_id`),
  KEY `idx_compliance_checked_by` (`checked_by`),
  CONSTRAINT `fk_compliance_case` FOREIGN KEY (`case_id`) REFERENCES `cases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_compliance_checked_by` FOREIGN KEY (`checked_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE `site_settings` (
  `setting_key` VARCHAR(120) NOT NULL,
  `setting_value` TEXT NOT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB;

INSERT INTO `users` (`id`, `full_name`, `email`, `password_hash`, `role`, `is_active`, `failed_login_attempts`, `locked_until`, `last_login`) VALUES
(1, 'LEXSHIELD ADMIN', 'lexshield000@gmail.com', '$2y$12$OgMnY80/P9pXGDRvxlsA4.193UhJ6Dy5J3i7stPoZrAbi0MoZa/pm', 'admin', 1, 0, NULL, NOW());

INSERT INTO `audit_logs` (`user_id`, `action`, `target_table`, `target_id`, `ip_address`, `user_agent`, `performed_at`) VALUES
(1, 'seed_create', 'users', '1', '127.0.0.1', 'Seeder', NOW());

INSERT INTO `sessions` (`user_id`, `session_token`, `ip_address`, `device_info`, `expires_at`) VALUES
(1, SHA2(UUID(), 256), '127.0.0.1', 'Seeder', DATE_ADD(NOW(), INTERVAL 8 HOUR));

INSERT INTO `notifications` (`user_id`, `type`, `message`, `is_read`, `created_at`) VALUES
(1, 'security', 'New audit event logged for the dashboard.', 0, NOW());

INSERT INTO `site_settings` (`setting_key`, `setting_value`) VALUES
('site_name', 'LEXSHIELD'),
('smtp_host', ''),
('smtp_port', '587'),
('smtp_encryption', 'tls'),
('smtp_user', ''),
('smtp_pass', ''),
('smtp_from_email', ''),
('smtp_from_name', ''),
('login_otp_enabled', 'false'),
('admin_otp_enabled', 'false'),
('client_registration_otp_enabled', 'false'),
('session_timeout', '1800'),
('gcash_account_name', ''),
('gcash_number', ''),
('gcash_instructions', ''),
('gcash_qr_stored_name', ''),
('default_currency', 'USD');
