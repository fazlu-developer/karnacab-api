-- Phase 16: commission component flags, immutable wallet ledger fields, withdrawals.

ALTER TABLE `commission_rules`
  ADD COLUMN `on_waiting` BOOLEAN NOT NULL DEFAULT false,
  ADD COLUMN `on_other` BOOLEAN NOT NULL DEFAULT false;

ALTER TABLE `wallet_ledger`
  ADD COLUMN `public_ref` VARCHAR(24) NULL,
  ADD COLUMN `gross_paise` BIGINT NOT NULL DEFAULT 0,
  ADD COLUMN `balance_before_paise` BIGINT NOT NULL DEFAULT 0,
  ADD COLUMN `kind` VARCHAR(24) NOT NULL DEFAULT 'trip';

UPDATE `wallet_ledger`
SET
  `public_ref` = CONCAT('KCW', LPAD(`id`, 12, '0')),
  `gross_paise` = `amount_paise` + `commission_paise`
WHERE `public_ref` IS NULL;

ALTER TABLE `wallet_ledger`
  MODIFY COLUMN `public_ref` VARCHAR(24) NOT NULL,
  ADD UNIQUE INDEX `wallet_ledger_public_ref_key` (`public_ref`),
  ADD INDEX `wallet_ledger_kind_created_at_idx` (`kind`, `created_at`);

CREATE TABLE `wallet_withdrawals` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `public_ref` VARCHAR(24) NOT NULL,
  `wallet_id` BIGINT NOT NULL,
  `ledger_id` BIGINT NOT NULL,
  `amount_paise` BIGINT NOT NULL,
  `status` VARCHAR(20) NOT NULL,
  `note` VARCHAR(255) NULL,
  `reviewed_by_id` BIGINT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `wallet_withdrawals_public_ref_key` (`public_ref`),
  UNIQUE INDEX `wallet_withdrawals_ledger_id_key` (`ledger_id`),
  INDEX `wallet_withdrawals_wallet_id_created_at_idx` (`wallet_id`, `created_at`),
  INDEX `wallet_withdrawals_status_idx` (`status`),
  CONSTRAINT `wallet_withdrawals_wallet_id_fkey`
    FOREIGN KEY (`wallet_id`) REFERENCES `wallets`(`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `wallet_withdrawals_ledger_id_fkey`
    FOREIGN KEY (`ledger_id`) REFERENCES `wallet_ledger`(`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT IGNORE INTO `system_settings` (`key`, `value`, `created_at`, `updated_at`)
VALUES
  ('driver_withdrawals_enabled', 'true', CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3)),
  ('driver_withdraw_min_paise', '50000', CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3));
