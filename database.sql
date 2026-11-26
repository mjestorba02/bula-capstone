-- Payment System database (schema + starter data)
-- Import with phpMyAdmin (Import tab) or: mysql -u root < database.sql
-- Safe to re-run: existing tables and rows are kept.
CREATE DATABASE IF NOT EXISTS bestlink_student_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE bestlink_student_system;
SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS `ai_knowledge` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `question` varchar(180) NOT NULL,
  `answer` text NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `action` varchar(255) NOT NULL,
  `category` varchar(20) NOT NULL DEFAULT 'activity',
  `ip_address` varchar(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `fee_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `percentage` decimal(6,3) NOT NULL DEFAULT 0.000,
  `fixed_amount` decimal(12,2) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fee_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(190) NOT NULL,
  `ip_address` varchar(64) NOT NULL,
  `failed_count` int(10) unsigned NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `last_failed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_login_attempt` (`username`,`ip_address`),
  KEY `idx_login_lock` (`locked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `login_otps` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `code_hash` varchar(255) NOT NULL,
  `sent_to` varchar(190) DEFAULT NULL,
  `sent_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `attempts` int(10) unsigned NOT NULL DEFAULT 0,
  `consumed_at` datetime DEFAULT NULL,
  `ip_address` varchar(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_otp_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `payment_installments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(40) NOT NULL,
  `percentage` decimal(6,3) NOT NULL DEFAULT 0.000,
  `due_date` date DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `payment_settings` (
  `setting_key` varchar(80) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `payments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `fee_item_id` int(10) unsigned DEFAULT NULL,
  `installment_id` int(10) unsigned DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL,
  `payment_method` varchar(100) NOT NULL,
  `payment_channel` varchar(30) NOT NULL DEFAULT 'Online',
  `cashier_id` int(10) unsigned DEFAULT NULL,
  `processed_at` datetime DEFAULT NULL,
  `payment_date` date DEFAULT NULL,
  `reference_number` varchar(100) NOT NULL,
  `proof_file_name` varchar(255) DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'Pending Verification',
  `verified_by` int(10) unsigned DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `provider_payment_id` varchar(120) DEFAULT NULL,
  `provider_status` varchar(60) DEFAULT NULL,
  `payment_expires_at` datetime DEFAULT NULL,
  `receipt_number` varchar(120) DEFAULT NULL,
  `receipt_file_name` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pay_user` (`user_id`),
  KEY `idx_pay_status` (`status`),
  KEY `idx_pay_reference` (`reference_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `receivable_documents` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `original_name` varchar(255) NOT NULL,
  `stored_name` varchar(255) NOT NULL,
  `mime_type` varchar(100) NOT NULL DEFAULT 'application/pdf',
  `file_size` int(10) unsigned NOT NULL DEFAULT 0,
  `uploaded_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_receivable_docs_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `scholarship_applications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `request_type` varchar(80) NOT NULL DEFAULT 'Discount Request',
  `discount_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `reason` varchar(255) NOT NULL,
  `student_message` varchar(1000) DEFAULT NULL,
  `proof_file_name` varchar(255) DEFAULT NULL,
  `proof_uploaded_at` datetime DEFAULT NULL,
  `status` enum('For Review','Approved','Rejected') NOT NULL DEFAULT 'For Review',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reviewed_by` int(10) unsigned DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_scholar_user` (`user_id`),
  KEY `idx_scholar_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `email` varchar(190) DEFAULT NULL,
  `student_id` varchar(50) DEFAULT NULL,
  `student_level` varchar(20) DEFAULT NULL,
  `year_level` varchar(30) DEFAULT NULL,
  `employee_id` varchar(60) DEFAULT NULL,
  `role` enum('admin','user','cashier','staff','mis') NOT NULL DEFAULT 'user',
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



-- Starter data (demo accounts have no email; add one per account in User Accounts)


INSERT  IGNORE INTO `users` (`id`, `username`, `password_hash`, `full_name`, `email`, `student_id`, `student_level`, `year_level`, `employee_id`, `role`, `status`, `created_at`) VALUES (1,'admin','$2y$10$BnoeIu0ri6YdUu76LCaZuODvWRxiS5hIXINjhEglbnC.ye/ptEYWa','System Administrator',NULL,NULL,NULL,NULL,'ADM-001','admin','Active','2026-11-14 08:44:05');
INSERT  IGNORE INTO `users` (`id`, `username`, `password_hash`, `full_name`, `email`, `student_id`, `student_level`, `year_level`, `employee_id`, `role`, `status`, `created_at`) VALUES (2,'mis','$2y$10$eP4y3USrMfjYmahcZGGEE.hWhxtOEFg1Eivj7oxtHkBerxpjLC5qW','MIS Officer',NULL,NULL,NULL,NULL,'MIS-001','mis','Active','2026-11-14 08:44:05');
INSERT  IGNORE INTO `users` (`id`, `username`, `password_hash`, `full_name`, `email`, `student_id`, `student_level`, `year_level`, `employee_id`, `role`, `status`, `created_at`) VALUES (3,'staff','$2y$10$0mHVyNpJqQAekZdk4lCnCeRtINh57JWXPeVXKEsGlaSh09Y8wpR4G','Finance Staff',NULL,NULL,NULL,NULL,'STF-001','staff','Active','2026-11-14 08:44:05');
INSERT  IGNORE INTO `users` (`id`, `username`, `password_hash`, `full_name`, `email`, `student_id`, `student_level`, `year_level`, `employee_id`, `role`, `status`, `created_at`) VALUES (4,'student','$2y$10$IZhpybEY51cd6s4jgxGQtub0YXukypPUIWWEMMAASYEHboxNT5aNq','Student User',NULL,'BLCP-2026-0001','College','1st Year',NULL,'user','Active','2026-11-14 08:44:05');
INSERT  IGNORE INTO `users` (`id`, `username`, `password_hash`, `full_name`, `email`, `student_id`, `student_level`, `year_level`, `employee_id`, `role`, `status`, `created_at`) VALUES (5,'shsstudent','$2y$10$d5TxUGbHgAy9ahzNiIanb.pqn9iiwNuVfpne/jsoRKd5UclACgnqO','SHS Student User',NULL,'BLCP-SHS-2026-0001','SHS','Grade 11',NULL,'user','Active','2026-11-14 08:44:05');

INSERT  IGNORE INTO `payment_settings` (`setting_key`, `setting_value`, `updated_by`, `updated_at`) VALUES ('default_assessment','6175',NULL,'2026-11-14 08:44:05');
INSERT  IGNORE INTO `payment_settings` (`setting_key`, `setting_value`, `updated_by`, `updated_at`) VALUES ('due_date','',NULL,'2026-11-14 08:44:05');
INSERT  IGNORE INTO `payment_settings` (`setting_key`, `setting_value`, `updated_by`, `updated_at`) VALUES ('hellomoney_merchant_name','Bestlink College of the Philippines',NULL,'2026-11-14 08:44:05');
INSERT  IGNORE INTO `payment_settings` (`setting_key`, `setting_value`, `updated_by`, `updated_at`) VALUES ('hellomoney_mode','demo',NULL,'2026-11-14 08:44:05');
INSERT  IGNORE INTO `payment_settings` (`setting_key`, `setting_value`, `updated_by`, `updated_at`) VALUES ('hellomoney_qr_payload','',NULL,'2026-11-14 08:44:05');
INSERT  IGNORE INTO `payment_settings` (`setting_key`, `setting_value`, `updated_by`, `updated_at`) VALUES ('mode_hma_enabled','1',NULL,'2026-11-14 08:44:05');
INSERT  IGNORE INTO `payment_settings` (`setting_key`, `setting_value`, `updated_by`, `updated_at`) VALUES ('mode_walkin_enabled','1',NULL,'2026-11-14 08:44:05');
INSERT  IGNORE INTO `payment_settings` (`setting_key`, `setting_value`, `updated_by`, `updated_at`) VALUES ('penalty_percent','0',NULL,'2026-11-14 08:44:05');

INSERT  IGNORE INTO `fee_items` (`id`, `name`, `percentage`, `fixed_amount`, `active`, `created_at`) VALUES (1,'Miscellaneous Fees',0.000,4975.00,1,'2026-11-14 08:44:05');
INSERT  IGNORE INTO `fee_items` (`id`, `name`, `percentage`, `fixed_amount`, `active`, `created_at`) VALUES (2,'School RFID & Lace',0.000,350.00,1,'2026-11-14 08:44:05');
INSERT  IGNORE INTO `fee_items` (`id`, `name`, `percentage`, `fixed_amount`, `active`, `created_at`) VALUES (3,'Medical & Dental',0.000,400.00,1,'2026-11-14 08:44:05');
INSERT  IGNORE INTO `fee_items` (`id`, `name`, `percentage`, `fixed_amount`, `active`, `created_at`) VALUES (4,'Research Forum 2026',0.000,200.00,1,'2026-11-14 08:44:05');
INSERT  IGNORE INTO `fee_items` (`id`, `name`, `percentage`, `fixed_amount`, `active`, `created_at`) VALUES (5,'Student Handbook',0.000,250.00,1,'2026-11-14 08:44:05');

INSERT  IGNORE INTO `payment_installments` (`id`, `name`, `percentage`, `due_date`, `active`, `sort_order`, `created_at`, `updated_at`) VALUES (1,'Prelim',30.000,'2026-09-30',1,1,'2026-11-14 08:44:05','2026-11-14 08:44:05');
INSERT  IGNORE INTO `payment_installments` (`id`, `name`, `percentage`, `due_date`, `active`, `sort_order`, `created_at`, `updated_at`) VALUES (2,'Midterm',30.000,'2026-11-15',1,2,'2026-11-14 08:44:05','2026-11-14 08:44:05');
INSERT  IGNORE INTO `payment_installments` (`id`, `name`, `percentage`, `due_date`, `active`, `sort_order`, `created_at`, `updated_at`) VALUES (3,'Finals',40.000,'2027-01-15',1,3,'2026-11-14 08:44:05','2026-11-14 08:44:05');



SET FOREIGN_KEY_CHECKS=1;
