ALTER TABLE `users`
  ADD COLUMN `date_of_birth` DATE NULL,
  ADD COLUMN `gender` ENUM('MALE', 'FEMALE', 'OTHER') NULL,
  ADD COLUMN `last_lat` DECIMAL(10, 7) NULL,
  ADD COLUMN `last_lng` DECIMAL(10, 7) NULL,
  ADD COLUMN `last_address` VARCHAR(255) NULL,
  ADD COLUMN `location_updated_at` DATETIME(3) NULL,
  ADD COLUMN `profile_completed_at` DATETIME(3) NULL;

CREATE TABLE IF NOT EXISTS `user_places` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT NOT NULL,
  `kind` ENUM('RECENT', 'SAVED') NOT NULL DEFAULT 'RECENT',
  `title` VARCHAR(120) NOT NULL,
  `subtitle` VARCHAR(180) NULL,
  `address` VARCHAR(255) NOT NULL,
  `lat` DECIMAL(10, 7) NOT NULL,
  `lng` DECIMAL(10, 7) NOT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  INDEX `user_places_user_id_kind_created_at_idx` (`user_id`, `kind`, `created_at`),
  CONSTRAINT `user_places_user_id_fkey` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
