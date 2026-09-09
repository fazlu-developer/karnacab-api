ALTER TABLE `parcel_shipments`
  ADD COLUMN IF NOT EXISTS `description` VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS `length_cm` DECIMAL(8,2) NULL,
  ADD COLUMN IF NOT EXISTS `width_cm` DECIMAL(8,2) NULL,
  ADD COLUMN IF NOT EXISTS `height_cm` DECIMAL(8,2) NULL,
  ADD COLUMN IF NOT EXISTS `quantity` INT NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS `category` ENUM('BIKE','AUTO','E_RICKSHAW','MINI','SEDAN','SUV','TRAVELLER') NOT NULL DEFAULT 'BIKE',
  ADD COLUMN IF NOT EXISTS `pickup_lat` DECIMAL(10,7) NULL,
  ADD COLUMN IF NOT EXISTS `pickup_lng` DECIMAL(10,7) NULL,
  ADD COLUMN IF NOT EXISTS `drop_lat` DECIMAL(10,7) NULL,
  ADD COLUMN IF NOT EXISTS `drop_lng` DECIMAL(10,7) NULL,
  ADD COLUMN IF NOT EXISTS `distance_km` DECIMAL(10,2) NULL,
  ADD COLUMN IF NOT EXISTS `contact_name` VARCHAR(120) NULL,
  ADD COLUMN IF NOT EXISTS `contact_phone` VARCHAR(20) NULL,
  ADD COLUMN IF NOT EXISTS `instructions` VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS `compliance_confirmed` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `quote_paise` BIGINT NULL,
  ADD COLUMN IF NOT EXISTS `quote_snapshot` JSON NULL,
  ADD COLUMN IF NOT EXISTS `payment_status` VARCHAR(20) NOT NULL DEFAULT 'unpaid',
  ADD COLUMN IF NOT EXISTS `payment_method` VARCHAR(20) NULL,
  ADD COLUMN IF NOT EXISTS `driver_id` BIGINT NULL,
  ADD COLUMN IF NOT EXISTS `vehicle_id` BIGINT NULL,
  ADD COLUMN IF NOT EXISTS `pickup_otp` VARCHAR(8) NULL,
  ADD COLUMN IF NOT EXISTS `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3);

ALTER TABLE `parcel_shipments`
  MODIFY `status` VARCHAR(24) NOT NULL DEFAULT 'created';

CREATE TABLE IF NOT EXISTS `parcel_fare_rules` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `lane` VARCHAR(16) NOT NULL,
  `category` ENUM('BIKE','AUTO','E_RICKSHAW','MINI','SEDAN','SUV','TRAVELLER') NOT NULL,
  `min_km` DECIMAL(8,2) NOT NULL,
  `included_km` DECIMAL(8,2) NOT NULL,
  `per_km_paise` INT NOT NULL,
  `extra_km_paise` INT NOT NULL,
  `per_kg_paise` INT NOT NULL,
  `min_charge_paise` INT NOT NULL,
  `gst_percent` INT NOT NULL,
  `volumetric_divisor` INT NOT NULL DEFAULT 5000,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `parcel_fare_rules_lane_category_key` (`lane`, `category`),
  KEY `parcel_fare_rules_lane_category_active_idx` (`lane`, `category`, `active`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT INTO `parcel_fare_rules`
  (`lane`, `category`, `min_km`, `included_km`, `per_km_paise`, `extra_km_paise`, `per_kg_paise`, `min_charge_paise`, `gst_percent`, `volumetric_divisor`, `active`, `created_at`, `updated_at`)
SELECT v.`lane`, v.`category`, v.`min_km`, v.`included_km`, v.`per_km`, v.`extra_km`, v.`per_kg`, v.`min_charge`, 5, 5000, 1, CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3)
FROM (
  SELECT 'LOCAL' AS `lane`, 'BIKE' AS `category`, 2 AS `min_km`, 2 AS `included_km`, 1200 AS `per_km`, 1500 AS `extra_km`, 800 AS `per_kg`, 4900 AS `min_charge`
  UNION ALL SELECT 'LOCAL', 'AUTO', 2, 2, 1400, 1700, 700, 5900
  UNION ALL SELECT 'LOCAL', 'MINI', 3, 3, 1800, 2200, 500, 9900
  UNION ALL SELECT 'LOCAL', 'SEDAN', 3, 3, 2000, 2400, 500, 11900
  UNION ALL SELECT 'BIHAR', 'BIKE', 5, 5, 1600, 1900, 900, 7900
  UNION ALL SELECT 'BIHAR', 'AUTO', 5, 5, 1800, 2100, 800, 9900
  UNION ALL SELECT 'BIHAR', 'MINI', 8, 8, 2200, 2600, 600, 14900
  UNION ALL SELECT 'BIHAR', 'SEDAN', 8, 8, 2500, 2900, 600, 17900
) v
WHERE NOT EXISTS (
  SELECT 1 FROM `parcel_fare_rules` x WHERE x.`lane` = v.`lane` AND x.`category` = v.`category`
);

ALTER TABLE `payments`
  ADD COLUMN IF NOT EXISTS `parcel_id` BIGINT NULL;

UPDATE `parcel_shipments` SET `status` = 'created' WHERE `status` = 'requested';

SET @idx := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'payments'
    AND index_name = 'payments_parcel_id_status_idx'
);
SET @sql := IF(@idx = 0, 'CREATE INDEX `payments_parcel_id_status_idx` ON `payments` (`parcel_id`, `status`)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT INTO `system_settings` (`key`, `value`, `created_at`, `updated_at`)
SELECT 'parcel_types', '[{"key":"documents","label":"Documents"},{"key":"electronics","label":"Electronics"},{"key":"clothes","label":"Clothes"},{"key":"food","label":"Packed food"},{"key":"household","label":"Household"},{"key":"other","label":"Other permitted goods"}]', CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3)
WHERE NOT EXISTS (SELECT 1 FROM `system_settings` WHERE `key` = 'parcel_types');

INSERT INTO `system_settings` (`key`, `value`, `created_at`, `updated_at`)
SELECT 'parcel_prohibited_goods', '[{"key":"flammables","label":"Flammable liquids or gases"},{"key":"explosives","label":"Explosives"},{"key":"weapons","label":"Weapons or ammunition"},{"key":"drugs","label":"Illegal drugs"},{"key":"cash","label":"Cash, gold, or jewellery"},{"key":"live_animals","label":"Live animals"},{"key":"hazardous","label":"Hazardous chemicals"}]', CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3)
WHERE NOT EXISTS (SELECT 1 FROM `system_settings` WHERE `key` = 'parcel_prohibited_goods');
