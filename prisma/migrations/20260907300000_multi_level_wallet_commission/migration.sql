-- Phase 23: discount commission flag, ledger account, booking FK, idempotent trip posts.

ALTER TABLE `commission_rules`
  ADD COLUMN `on_discount` BOOLEAN NOT NULL DEFAULT false;

ALTER TABLE `wallet_ledger`
  ADD COLUMN `account` VARCHAR(24) NULL;

UPDATE `wallet_ledger` AS `l`
INNER JOIN `wallets` AS `w` ON `w`.`id` = `l`.`wallet_id`
SET `l`.`account` = `w`.`owner_type`
WHERE `l`.`account` IS NULL;

UPDATE `wallet_ledger`
SET `account` = 'CUSTOMER'
WHERE `account` IS NULL;

ALTER TABLE `wallet_ledger`
  MODIFY COLUMN `account` VARCHAR(24) NOT NULL,
  ADD INDEX `wallet_ledger_account_created_at_idx` (`account`, `created_at`);

ALTER TABLE `wallet_ledger`
  ADD CONSTRAINT `wallet_ledger_booking_id_fkey`
    FOREIGN KEY (`booking_id`) REFERENCES `bookings`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `wallet_ledger`
  ADD UNIQUE INDEX `wallet_ledger_booking_post_key` (`booking_id`, `wallet_id`, `kind`, `direction`);

INSERT IGNORE INTO `system_settings` (`key`, `value`, `created_at`, `updated_at`)
VALUES
  ('fleet_commission_share_percent', '0', CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3));
