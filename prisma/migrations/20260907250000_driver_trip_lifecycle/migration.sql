-- Phase 14: trip timer stamps, ratings, safety helpline.

ALTER TABLE `bookings`
  ADD COLUMN `trip_started_at` DATETIME(3) NULL,
  ADD COLUMN `trip_ended_at` DATETIME(3) NULL;

CREATE TABLE `booking_ratings` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `booking_id` BIGINT NOT NULL,
  `from_role` VARCHAR(16) NOT NULL,
  `stars` INT NOT NULL,
  `comment` VARCHAR(255) NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE INDEX `booking_ratings_booking_id_from_role_key` (`booking_id`, `from_role`),
  INDEX `booking_ratings_booking_id_idx` (`booking_id`),
  CONSTRAINT `booking_ratings_booking_id_fkey`
    FOREIGN KEY (`booking_id`) REFERENCES `bookings`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT IGNORE INTO `system_settings` (`key`, `value`, `created_at`, `updated_at`)
VALUES
  ('driver_safety_sos', '112', CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3)),
  ('driver_safety_helpline', '112', CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3));
