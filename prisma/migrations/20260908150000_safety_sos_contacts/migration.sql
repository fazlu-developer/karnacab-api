-- Phase 28 safety. Additive only.

CREATE TABLE `emergency_contacts` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `phone` VARCHAR(20) NOT NULL,
  `relation` VARCHAR(40) NULL,
  `is_primary` BOOLEAN NOT NULL DEFAULT false,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  INDEX `emergency_contacts_user_id_idx` (`user_id`),
  CONSTRAINT `emergency_contacts_user_id_fkey` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `safety_incidents`
  ADD COLUMN `kind` VARCHAR(24) NULL,
  ADD COLUMN `actor_role` VARCHAR(24) NULL,
  ADD COLUMN `emergency_name` VARCHAR(120) NULL,
  ADD COLUMN `emergency_phone` VARCHAR(20) NULL;
