-- Phase 17: driver emergency contact, incentive awards, support/SOS settings.

ALTER TABLE `drivers`
  ADD COLUMN `emergency_name` VARCHAR(120) NULL,
  ADD COLUMN `emergency_phone` VARCHAR(20) NULL;

CREATE TABLE `driver_incentive_awards` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `driver_id` BIGINT NOT NULL,
  `incentive_id` VARCHAR(64) NOT NULL,
  `period_key` VARCHAR(24) NOT NULL,
  `amount_paise` BIGINT NOT NULL,
  `ledger_id` BIGINT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE INDEX `driver_incentive_awards_driver_id_incentive_id_period_key_key` (`driver_id`, `incentive_id`, `period_key`),
  INDEX `driver_incentive_awards_driver_id_created_at_idx` (`driver_id`, `created_at`),
  CONSTRAINT `driver_incentive_awards_driver_id_fkey`
    FOREIGN KEY (`driver_id`) REFERENCES `drivers`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT IGNORE INTO `system_settings` (`key`, `value`, `created_at`, `updated_at`)
VALUES
  ('driver_support_phone', '08041234500', CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3)),
  ('driver_support_chat_url', '', CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3)),
  ('driver_faq', '[{"q":"When do I get paid?","a":"Trip earnings credit to your wallet after completion, minus configured commission. Withdraw when KYC and bank details are in place."},{"q":"What if a document expires?","a":"You will see 30, 15 and 7 day alerts. Expired documents must be re-uploaded before going online."},{"q":"How do incentives work?","a":"Each offer has trip and/or earnings targets, a bonus, and a validity window. Progress updates from completed jobs. The bonus posts to your wallet ledger when you hit the target."},{"q":"How do I use SOS?","a":"On an active trip tap SOS to call 112, your emergency contact, or KarnaCab support, and to share live location with the current booking."}]', CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3));
