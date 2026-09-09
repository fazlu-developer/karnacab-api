-- Phase 20 franchise hierarchy. Additive. Drops unique(district_id) so history can exist;
-- exclusive seat remains unique(active_district_key) set only while ACTIVE.

ALTER TABLE `franchises`
  MODIFY `status` ENUM('APPLIED','UNDER_REVIEW','APPROVED','ACTIVE','SUSPENDED','EXPIRED','TERMINATED') NOT NULL DEFAULT 'APPLIED';

ALTER TABLE `franchises` DROP FOREIGN KEY `franchises_district_id_fkey`;
ALTER TABLE `franchises` DROP INDEX `franchises_district_id_key`;
ALTER TABLE `franchises` ADD INDEX `franchises_district_id_idx` (`district_id`);
ALTER TABLE `franchises`
  ADD CONSTRAINT `franchises_district_id_fkey` FOREIGN KEY (`district_id`) REFERENCES `districts` (`id`) ON UPDATE CASCADE;

ALTER TABLE `franchises`
  ADD COLUMN `state_id` INT NOT NULL AFTER `district_id`,
  ADD COLUMN `parent_user_id` BIGINT NULL AFTER `owner_user_id`,
  ADD COLUMN `kind` ENUM('DISTRICT_HEAD','EXCLUSIVE_FRANCHISE') NOT NULL DEFAULT 'EXCLUSIVE_FRANCHISE' AFTER `parent_user_id`,
  ADD COLUMN `trade_name` VARCHAR(160) NOT NULL DEFAULT '' AFTER `active_district_key`,
  ADD COLUMN `gstin` VARCHAR(20) NULL AFTER `trade_name`,
  ADD COLUMN `pan` VARCHAR(20) NULL AFTER `gstin`,
  ADD COLUMN `contact_phone` VARCHAR(20) NULL AFTER `pan`,
  ADD COLUMN `kyc_status` VARCHAR(32) NOT NULL DEFAULT 'pending' AFTER `contact_phone`,
  ADD COLUMN `agreement_status` VARCHAR(32) NOT NULL DEFAULT 'unsigned' AFTER `kyc_status`,
  ADD COLUMN `fee_paid_at` DATETIME(3) NULL AFTER `fee_amount_paise`,
  ADD COLUMN `commission_percent` DECIMAL(5, 2) NOT NULL DEFAULT 0 AFTER `fee_paid_at`,
  ADD COLUMN `terminated_at` DATETIME(3) NULL AFTER `ends_on`,
  ADD COLUMN `termination_reason` VARCHAR(500) NULL AFTER `terminated_at`,
  ADD COLUMN `notes` VARCHAR(500) NULL AFTER `termination_reason`;

ALTER TABLE `franchises`
  ADD INDEX `franchises_district_id_status_idx` (`district_id`, `status`),
  ADD INDEX `franchises_state_id_status_idx` (`state_id`, `status`),
  ADD INDEX `franchises_owner_user_id_status_idx` (`owner_user_id`, `status`);

ALTER TABLE `franchises`
  ADD CONSTRAINT `franchises_state_id_fkey` FOREIGN KEY (`state_id`) REFERENCES `states` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `franchises_owner_user_id_fkey` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `franchises_parent_user_id_fkey` FOREIGN KEY (`parent_user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL;

CREATE TABLE `franchise_documents` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `franchise_id` BIGINT NOT NULL,
  `type` VARCHAR(32) NOT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
  `storage_key` VARCHAR(255) NOT NULL,
  `original_name` VARCHAR(180) NULL,
  `mime` VARCHAR(80) NOT NULL,
  `size_bytes` INT NOT NULL,
  `checksum_sha256` VARCHAR(64) NOT NULL,
  `expires_at` DATE NULL,
  `rejection_reason` VARCHAR(500) NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `franchise_documents_storage_key_key` (`storage_key`),
  UNIQUE KEY `franchise_documents_franchise_id_type_key` (`franchise_id`, `type`),
  KEY `franchise_documents_status_idx` (`status`),
  CONSTRAINT `franchise_documents_franchise_id_fkey` FOREIGN KEY (`franchise_id`) REFERENCES `franchises` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `franchise_agreements` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `franchise_id` BIGINT NOT NULL,
  `version` VARCHAR(32) NOT NULL,
  `title` VARCHAR(160) NOT NULL,
  `signed_at` DATETIME(3) NULL,
  `expires_at` DATETIME(3) NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'draft',
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `franchise_agreements_franchise_id_created_at_idx` (`franchise_id`, `created_at`),
  CONSTRAINT `franchise_agreements_franchise_id_fkey` FOREIGN KEY (`franchise_id`) REFERENCES `franchises` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `franchise_fees` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `franchise_id` BIGINT NOT NULL,
  `kind` VARCHAR(24) NOT NULL,
  `amount_paise` BIGINT NOT NULL,
  `status` VARCHAR(24) NOT NULL DEFAULT 'due',
  `due_on` DATETIME(3) NULL,
  `paid_at` DATETIME(3) NULL,
  `note` VARCHAR(255) NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `franchise_fees_franchise_id_status_idx` (`franchise_id`, `status`),
  CONSTRAINT `franchise_fees_franchise_id_fkey` FOREIGN KEY (`franchise_id`) REFERENCES `franchises` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `franchise_renewals` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `franchise_id` BIGINT NOT NULL,
  `period_start` DATETIME(3) NOT NULL,
  `period_end` DATETIME(3) NOT NULL,
  `status` VARCHAR(24) NOT NULL DEFAULT 'pending',
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `franchise_renewals_franchise_id_period_end_idx` (`franchise_id`, `period_end`),
  CONSTRAINT `franchise_renewals_franchise_id_fkey` FOREIGN KEY (`franchise_id`) REFERENCES `franchises` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `franchise_events` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `franchise_id` BIGINT NOT NULL,
  `actor_user_id` BIGINT NOT NULL,
  `action` VARCHAR(48) NOT NULL,
  `from_status` VARCHAR(32) NULL,
  `to_status` VARCHAR(32) NULL,
  `note` VARCHAR(500) NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `franchise_events_franchise_id_created_at_idx` (`franchise_id`, `created_at`),
  CONSTRAINT `franchise_events_franchise_id_fkey` FOREIGN KEY (`franchise_id`) REFERENCES `franchises` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
