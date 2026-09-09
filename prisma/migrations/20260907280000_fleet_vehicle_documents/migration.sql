-- Phase 18: fleet vehicle documents.

CREATE TABLE `vehicle_documents` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `vehicle_id` BIGINT NOT NULL,
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
  UNIQUE INDEX `vehicle_documents_storage_key_key` (`storage_key`),
  UNIQUE INDEX `vehicle_documents_vehicle_id_type_key` (`vehicle_id`, `type`),
  INDEX `vehicle_documents_status_idx` (`status`),
  CONSTRAINT `vehicle_documents_vehicle_id_fkey`
    FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
