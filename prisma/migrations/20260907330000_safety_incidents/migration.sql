-- Phase 26 safety. Additive only. Existing users/bookings kept.

ALTER TABLE `users`
  ADD COLUMN `emergency_name` VARCHAR(120) NULL,
  ADD COLUMN `emergency_phone` VARCHAR(20) NULL;

ALTER TABLE `bookings`
  ADD COLUMN `share_token` VARCHAR(64) NULL,
  ADD COLUMN `share_expires_at` DATETIME(3) NULL;

CREATE UNIQUE INDEX `bookings_share_token_key` ON `bookings`(`share_token`);

CREATE TABLE `safety_incidents` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `public_ref` VARCHAR(24) NOT NULL,
  `type` VARCHAR(32) NOT NULL,
  `status` VARCHAR(24) NOT NULL DEFAULT 'open',
  `description` VARCHAR(1000) NOT NULL DEFAULT '',
  `booking_id` BIGINT NULL,
  `reporter_user_id` BIGINT NOT NULL,
  `driver_id` BIGINT NULL,
  `district_id` INT NULL,
  `lat` DECIMAL(10, 7) NULL,
  `lng` DECIMAL(10, 7) NULL,
  `location_text` VARCHAR(255) NULL,
  `admin_note` VARCHAR(1000) NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `safety_incidents_public_ref_key` (`public_ref`),
  INDEX `safety_incidents_status_created_at_idx` (`status`, `created_at`),
  INDEX `safety_incidents_type_created_at_idx` (`type`, `created_at`),
  INDEX `safety_incidents_reporter_user_id_created_at_idx` (`reporter_user_id`, `created_at`),
  INDEX `safety_incidents_booking_id_idx` (`booking_id`),
  INDEX `safety_incidents_driver_id_idx` (`driver_id`),
  INDEX `safety_incidents_district_id_status_idx` (`district_id`, `status`),
  CONSTRAINT `safety_incidents_booking_id_fkey` FOREIGN KEY (`booking_id`) REFERENCES `bookings`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `safety_incidents_reporter_user_id_fkey` FOREIGN KEY (`reporter_user_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `safety_incidents_driver_id_fkey` FOREIGN KEY (`driver_id`) REFERENCES `drivers`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `safety_incidents_district_id_fkey` FOREIGN KEY (`district_id`) REFERENCES `districts`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
