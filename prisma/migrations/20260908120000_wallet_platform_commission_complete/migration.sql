-- Phase 23: platform wallet, complete-amount commission flag, ledger user + payment ref.

ALTER TABLE `wallets`
  MODIFY COLUMN `owner_type` ENUM('CUSTOMER','DRIVER','FLEET_OWNER','DISTRICT_HEAD','FRANCHISE','CORPORATE','PLATFORM') NOT NULL;

ALTER TABLE `commission_rules`
  ADD COLUMN `on_complete` BOOLEAN NOT NULL DEFAULT false AFTER `on_other`;

ALTER TABLE `wallet_ledger`
  ADD COLUMN `owner_user_id` BIGINT NULL AFTER `booking_id`,
  ADD COLUMN `payment_ref` VARCHAR(64) NULL AFTER `balance_after_paise`;

UPDATE `wallet_ledger` AS `l`
INNER JOIN `wallets` AS `w` ON `w`.`id` = `l`.`wallet_id`
SET `l`.`owner_user_id` = `w`.`owner_user_id`
WHERE `l`.`owner_user_id` IS NULL;

INSERT IGNORE INTO `system_settings` (`key`, `value`, `created_at`, `updated_at`)
VALUES
  ('platform_wallet_user_id', '', CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3));
