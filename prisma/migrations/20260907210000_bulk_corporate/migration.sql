ALTER TABLE `bookings`
  ADD COLUMN IF NOT EXISTS `corporate_account_id` BIGINT NULL;

SET @idx := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'bookings' AND index_name = 'bookings_corporate_account_id_idx'
);
SET @sql := IF(@idx = 0, 'CREATE INDEX `bookings_corporate_account_id_idx` ON `bookings` (`corporate_account_id`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `bulk_rate_rules` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `event_key` VARCHAR(40) NOT NULL,
  `category` ENUM('BIKE','AUTO','E_RICKSHAW','MINI','SEDAN','SUV','TRAVELLER') NOT NULL,
  `per_vehicle_paise` INT NOT NULL,
  `gst_percent` INT NOT NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `bulk_rate_rules_event_key_category_key` (`event_key`, `category`),
  KEY `bulk_rate_rules_event_key_category_active_idx` (`event_key`, `category`, `active`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `corporate_accounts` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `owner_user_id` BIGINT NOT NULL,
  `company_name` VARCHAR(160) NOT NULL,
  `gstin` VARCHAR(15) NULL,
  `contact_name` VARCHAR(120) NOT NULL,
  `contact_phone` VARCHAR(20) NOT NULL,
  `contact_email` VARCHAR(180) NULL,
  `district_id` INT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'active',
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `corporate_accounts_owner_user_id_key` (`owner_user_id`),
  KEY `corporate_accounts_status_created_at_idx` (`status`, `created_at`),
  CONSTRAINT `corporate_accounts_owner_user_id_fkey` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `corporate_employees` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `account_id` BIGINT NOT NULL,
  `user_id` BIGINT NULL,
  `name` VARCHAR(120) NOT NULL,
  `phone` VARCHAR(20) NOT NULL,
  `email` VARCHAR(180) NULL,
  `department` VARCHAR(80) NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `corporate_employees_account_id_phone_key` (`account_id`, `phone`),
  KEY `corporate_employees_account_id_active_idx` (`account_id`, `active`),
  CONSTRAINT `corporate_employees_account_id_fkey` FOREIGN KEY (`account_id`) REFERENCES `corporate_accounts` (`id`),
  CONSTRAINT `corporate_employees_user_id_fkey` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `corporate_invoices` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `public_ref` VARCHAR(24) NOT NULL,
  `account_id` BIGINT NOT NULL,
  `period_start` DATE NOT NULL,
  `period_end` DATE NOT NULL,
  `subtotal_paise` BIGINT NOT NULL,
  `gst_paise` BIGINT NOT NULL,
  `total_paise` BIGINT NOT NULL,
  `gstin` VARCHAR(15) NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'issued',
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `corporate_invoices_public_ref_key` (`public_ref`),
  KEY `corporate_invoices_account_id_period_start_idx` (`account_id`, `period_start`),
  CONSTRAINT `corporate_invoices_account_id_fkey` FOREIGN KEY (`account_id`) REFERENCES `corporate_accounts` (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bulk_bookings` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `public_ref` VARCHAR(24) NOT NULL,
  `customer_id` BIGINT NOT NULL,
  `corporate_account_id` BIGINT NULL,
  `event_key` VARCHAR(40) NOT NULL,
  `vehicle_count` INT NOT NULL,
  `category` ENUM('BIKE','AUTO','E_RICKSHAW','MINI','SEDAN','SUV','TRAVELLER') NOT NULL,
  `pickup_text` VARCHAR(255) NOT NULL,
  `drop_text` VARCHAR(255) NOT NULL,
  `pickup_lat` DECIMAL(10,7) NULL,
  `pickup_lng` DECIMAL(10,7) NULL,
  `drop_lat` DECIMAL(10,7) NULL,
  `drop_lng` DECIMAL(10,7) NULL,
  `event_date` DATE NOT NULL,
  `event_time` VARCHAR(8) NOT NULL,
  `passengers` INT NOT NULL,
  `requirements` VARCHAR(255) NULL,
  `quote_paise` BIGINT NULL,
  `quote_snapshot` JSON NULL,
  `advance_paise` BIGINT NULL,
  `invoice_paise` BIGINT NULL,
  `assignment_notes` VARCHAR(255) NULL,
  `payment_status` VARCHAR(20) NOT NULL DEFAULT 'unpaid',
  `payment_method` VARCHAR(20) NULL,
  `status` VARCHAR(24) NOT NULL DEFAULT 'requested',
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `bulk_bookings_public_ref_key` (`public_ref`),
  KEY `bulk_bookings_customer_id_created_at_idx` (`customer_id`, `created_at`),
  KEY `bulk_bookings_status_created_at_idx` (`status`, `created_at`),
  KEY `bulk_bookings_corporate_account_id_idx` (`corporate_account_id`),
  CONSTRAINT `bulk_bookings_customer_id_fkey` FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`),
  CONSTRAINT `bulk_bookings_corporate_account_id_fkey` FOREIGN KEY (`corporate_account_id`) REFERENCES `corporate_accounts` (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `payments`
  ADD COLUMN IF NOT EXISTS `bulk_booking_id` BIGINT NULL,
  ADD COLUMN IF NOT EXISTS `corporate_invoice_id` BIGINT NULL;

SET @idx := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'payments' AND index_name = 'payments_bulk_booking_id_status_idx'
);
SET @sql := IF(@idx = 0, 'CREATE INDEX `payments_bulk_booking_id_status_idx` ON `payments` (`bulk_booking_id`, `status`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'payments' AND index_name = 'payments_corporate_invoice_id_status_idx'
);
SET @sql := IF(@idx = 0, 'CREATE INDEX `payments_corporate_invoice_id_status_idx` ON `payments` (`corporate_invoice_id`, `status`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO `system_settings` (`key`, `value`, `created_at`, `updated_at`)
SELECT 'bulk_event_types', '[{"key":"WEDDING","label":"Wedding"},{"key":"CORPORATE","label":"Corporate"},{"key":"SCHOOL","label":"School"},{"key":"COLLEGE","label":"College"},{"key":"EVENTS","label":"Events"},{"key":"GROUP_TOURS","label":"Group Tours"}]', CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3)
WHERE NOT EXISTS (SELECT 1 FROM `system_settings` WHERE `key` = 'bulk_event_types');

INSERT INTO `system_settings` (`key`, `value`, `created_at`, `updated_at`)
SELECT 'bulk_advance_percent', '30', CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3)
WHERE NOT EXISTS (SELECT 1 FROM `system_settings` WHERE `key` = 'bulk_advance_percent');

INSERT INTO `bulk_rate_rules`
  (`event_key`, `category`, `per_vehicle_paise`, `gst_percent`, `active`, `created_at`, `updated_at`)
SELECT v.`event_key`, v.`category`, v.`per_vehicle`, 5, 1, CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3)
FROM (
  SELECT 'WEDDING' AS `event_key`, 'SEDAN' AS `category`, 450000 AS `per_vehicle`
  UNION ALL SELECT 'WEDDING', 'SUV', 650000
  UNION ALL SELECT 'WEDDING', 'TRAVELLER', 950000
  UNION ALL SELECT 'CORPORATE', 'SEDAN', 380000
  UNION ALL SELECT 'CORPORATE', 'SUV', 520000
  UNION ALL SELECT 'CORPORATE', 'TRAVELLER', 820000
  UNION ALL SELECT 'SCHOOL', 'SEDAN', 280000
  UNION ALL SELECT 'SCHOOL', 'SUV', 360000
  UNION ALL SELECT 'SCHOOL', 'TRAVELLER', 620000
  UNION ALL SELECT 'COLLEGE', 'SEDAN', 300000
  UNION ALL SELECT 'COLLEGE', 'SUV', 390000
  UNION ALL SELECT 'COLLEGE', 'TRAVELLER', 680000
  UNION ALL SELECT 'EVENTS', 'SEDAN', 400000
  UNION ALL SELECT 'EVENTS', 'SUV', 550000
  UNION ALL SELECT 'EVENTS', 'TRAVELLER', 880000
  UNION ALL SELECT 'GROUP_TOURS', 'SEDAN', 420000
  UNION ALL SELECT 'GROUP_TOURS', 'SUV', 580000
  UNION ALL SELECT 'GROUP_TOURS', 'TRAVELLER', 920000
) v
WHERE NOT EXISTS (
  SELECT 1 FROM `bulk_rate_rules` x WHERE x.`event_key` = v.`event_key` AND x.`category` = v.`category`
);
