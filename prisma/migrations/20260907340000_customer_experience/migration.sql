-- Phase 27 customer experience. Additive only.

ALTER TABLE `bookings`
  ADD COLUMN `passenger_name` VARCHAR(120) NULL,
  ADD COLUMN `passenger_phone` VARCHAR(20) NULL,
  ADD COLUMN `instructions` VARCHAR(500) NULL,
  ADD COLUMN `booked_for_other` BOOLEAN NOT NULL DEFAULT false,
  ADD COLUMN `family_member_id` BIGINT NULL,
  ADD COLUMN `coupon_id` BIGINT NULL,
  ADD COLUMN `coupon_discount_paise` INT NOT NULL DEFAULT 0;

CREATE TABLE `family_members` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `phone` VARCHAR(20) NOT NULL,
  `relation` VARCHAR(40) NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  INDEX `family_members_user_id_idx` (`user_id`),
  CONSTRAINT `family_members_user_id_fkey` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `coupons` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(32) NOT NULL,
  `title` VARCHAR(160) NOT NULL,
  `subtitle` VARCHAR(255) NULL,
  `percent` INT NOT NULL DEFAULT 0,
  `amount_paise` INT NOT NULL DEFAULT 0,
  `min_fare_paise` INT NOT NULL DEFAULT 0,
  `starts_on` DATETIME(3) NOT NULL,
  `ends_on` DATETIME(3) NOT NULL,
  `active` BOOLEAN NOT NULL DEFAULT true,
  `district_id` INT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE INDEX `coupons_code_key` (`code`),
  INDEX `coupons_active_starts_on_ends_on_idx` (`active`, `starts_on`, `ends_on`),
  CONSTRAINT `coupons_district_id_fkey` FOREIGN KEY (`district_id`) REFERENCES `districts`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `coupon_redemptions` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `coupon_id` BIGINT NOT NULL,
  `user_id` BIGINT NOT NULL,
  `booking_id` BIGINT NULL,
  `discount_paise` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE INDEX `coupon_redemptions_booking_id_key` (`booking_id`),
  INDEX `coupon_redemptions_user_id_created_at_idx` (`user_id`, `created_at`),
  CONSTRAINT `coupon_redemptions_coupon_id_fkey` FOREIGN KEY (`coupon_id`) REFERENCES `coupons`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `coupon_redemptions_user_id_fkey` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `coupon_redemptions_booking_id_fkey` FOREIGN KEY (`booking_id`) REFERENCES `bookings`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `user_notifications` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT NOT NULL,
  `title` VARCHAR(160) NOT NULL,
  `body` VARCHAR(500) NOT NULL,
  `kind` VARCHAR(32) NOT NULL DEFAULT 'info',
  `entity_type` VARCHAR(40) NULL,
  `entity_id` VARCHAR(40) NULL,
  `read_at` DATETIME(3) NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  INDEX `user_notifications_user_id_created_at_idx` (`user_id`, `created_at`),
  CONSTRAINT `user_notifications_user_id_fkey` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `bookings`
  ADD CONSTRAINT `bookings_family_member_id_fkey` FOREIGN KEY (`family_member_id`) REFERENCES `family_members`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `bookings_coupon_id_fkey` FOREIGN KEY (`coupon_id`) REFERENCES `coupons`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

CREATE INDEX `bookings_booked_for_other_idx` ON `bookings`(`booked_for_other`);
