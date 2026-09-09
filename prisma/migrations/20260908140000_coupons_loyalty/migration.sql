-- Phase 26: coupon rules, loyalty points.

ALTER TABLE `coupons`
  ADD COLUMN `kind` VARCHAR(16) NOT NULL DEFAULT 'percent' AFTER `subtitle`,
  ADD COLUMN `max_discount_paise` INT NOT NULL DEFAULT 0 AFTER `amount_paise`,
  ADD COLUMN `product` VARCHAR(32) NULL AFTER `min_fare_paise`,
  ADD COLUMN `state_id` INT NULL AFTER `product`,
  ADD COLUMN `audience` VARCHAR(24) NOT NULL DEFAULT 'all' AFTER `district_id`,
  ADD COLUMN `usage_limit` INT NOT NULL DEFAULT 0 AFTER `audience`,
  ADD COLUMN `user_limit` INT NOT NULL DEFAULT 0 AFTER `usage_limit`;

UPDATE `coupons` SET `kind` = CASE WHEN `percent` > 0 THEN 'percent' ELSE 'fixed' END;

ALTER TABLE `coupons`
  ADD INDEX `coupons_state_id_district_id_active_idx` (`state_id`, `district_id`, `active`);

ALTER TABLE `coupons`
  ADD CONSTRAINT `coupons_state_id_fkey`
    FOREIGN KEY (`state_id`) REFERENCES `states`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE;

CREATE TABLE `loyalty_accounts` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT NOT NULL,
  `points` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE INDEX `loyalty_accounts_user_id_key` (`user_id`),
  CONSTRAINT `loyalty_accounts_user_id_fkey` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `loyalty_ledger` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT NOT NULL,
  `booking_id` BIGINT NULL,
  `kind` VARCHAR(16) NOT NULL,
  `points` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  INDEX `loyalty_ledger_user_id_created_at_idx` (`user_id`, `created_at`),
  INDEX `loyalty_ledger_booking_id_kind_idx` (`booking_id`, `kind`),
  CONSTRAINT `loyalty_ledger_user_id_fkey` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT IGNORE INTO `system_settings` (`key`, `value`, `created_at`, `updated_at`)
VALUES
  ('loyalty_points_per_ride', '10', CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3)),
  ('loyalty_paise_per_point', '100', CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3)),
  ('loyalty_max_redeem_paise', '0', CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3));
