-- Per-stop surcharge for Multi-stop (admin fare_rules).
ALTER TABLE `fare_rules`
  ADD COLUMN IF NOT EXISTS `stop_paise` INT NOT NULL DEFAULT 0;

UPDATE `fare_rules`
SET `stop_paise` = 1500
WHERE `product` = 'MULTI_STOP' AND `stop_paise` = 0;

-- Transfer identifiers on the existing bookings table (no new booking stack).
ALTER TABLE `bookings`
  ADD COLUMN IF NOT EXISTS `flight_number` VARCHAR(32) NULL,
  ADD COLUMN IF NOT EXISTS `train_number` VARCHAR(32) NULL,
  ADD COLUMN IF NOT EXISTS `terminal` VARCHAR(80) NULL;

INSERT INTO `system_settings` (`key`, `value`, `created_at`, `updated_at`)
SELECT 'multi_stop_max', '3', CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3)
WHERE NOT EXISTS (SELECT 1 FROM `system_settings` WHERE `key` = 'multi_stop_max');
