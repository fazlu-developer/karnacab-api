-- Phase 11: driver KYC. Additive only.

ALTER TABLE `drivers`
  ADD COLUMN `kyc_status` VARCHAR(32) NOT NULL DEFAULT 'pending',
  ADD COLUMN `city` VARCHAR(80) NULL,
  ADD COLUMN `terms_accepted_at` DATETIME(3) NULL,
  ADD COLUMN `application_submitted_at` DATETIME(3) NULL,
  ADD COLUMN `kyc_rejected_reason` VARCHAR(500) NULL,
  ADD COLUMN `bank_account_holder` VARCHAR(120) NULL,
  ADD COLUMN `bank_ifsc` VARCHAR(20) NULL,
  ADD COLUMN `bank_account_last4` VARCHAR(4) NULL,
  ADD COLUMN `bank_account_hash` VARCHAR(64) NULL,
  ADD COLUMN `upi_id` VARCHAR(80) NULL,
  ADD COLUMN `id_type` VARCHAR(32) NULL,
  ADD COLUMN `id_last4` VARCHAR(8) NULL,
  ADD COLUMN `id_hash` VARCHAR(64) NULL;

CREATE INDEX `drivers_kyc_status_idx` ON `drivers`(`kyc_status`);

ALTER TABLE `vehicles`
  ADD COLUMN `brand` VARCHAR(80) NULL,
  ADD COLUMN `model` VARCHAR(80) NULL,
  ADD COLUMN `year` INTEGER NULL,
  ADD COLUMN `color` VARCHAR(40) NULL,
  ADD COLUMN `fuel` VARCHAR(20) NULL;

CREATE TABLE `driver_documents` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `driver_id` BIGINT NOT NULL,
  `type` VARCHAR(32) NOT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
  `storage_key` VARCHAR(255) NOT NULL,
  `original_name` VARCHAR(180) NULL,
  `mime` VARCHAR(80) NOT NULL,
  `size_bytes` INTEGER NOT NULL,
  `checksum_sha256` VARCHAR(64) NOT NULL,
  `expires_at` DATE NULL,
  `rejection_reason` VARCHAR(500) NULL,
  `reviewed_at` DATETIME(3) NULL,
  `reviewed_by_user_id` BIGINT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `driver_documents_storage_key_key` (`storage_key`),
  UNIQUE INDEX `driver_documents_driver_id_type_key` (`driver_id`, `type`),
  INDEX `driver_documents_status_idx` (`status`),
  CONSTRAINT `driver_documents_driver_id_fkey`
    FOREIGN KEY (`driver_id`) REFERENCES `drivers`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
