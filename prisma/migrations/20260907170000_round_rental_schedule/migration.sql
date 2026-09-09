-- Additive rental extra-hour and round-way night-stay rates.
ALTER TABLE `fare_rules`
  ADD COLUMN IF NOT EXISTS `extra_hour_paise` INT NOT NULL DEFAULT 15000,
  ADD COLUMN IF NOT EXISTS `night_stay_paise` INT NOT NULL DEFAULT 0;

UPDATE `fare_rules`
SET `night_stay_paise` = 50000
WHERE `product` = 'ROUND_WAY' AND `night_stay_paise` = 0;

-- 2 / 4 / 6 / 12 hour rental packages copied from existing 8-hour rules.
INSERT INTO `fare_rules` (
  `district_id`, `product`, `category`, `min_km`, `included_km`, `per_km_paise`, `extra_km_paise`,
  `waiting_paise_per_min`, `night_percent`, `gst_percent`, `cancel_paise`, `discount_paise`,
  `discount_percent`, `driver_allow_paise`, `rental_hours`, `extra_hour_paise`, `night_stay_paise`,
  `apply_toll`, `apply_parking`, `apply_gst_to_base`, `active`, `created_at`, `updated_at`
)
SELECT
  r.`district_id`, r.`product`, r.`category`,
  (h.`hours` * 10), (h.`hours` * 10), r.`per_km_paise`, r.`extra_km_paise`,
  r.`waiting_paise_per_min`, r.`night_percent`, r.`gst_percent`, r.`cancel_paise`,
  r.`discount_paise`, r.`discount_percent`, r.`driver_allow_paise`, h.`hours`,
  r.`extra_hour_paise`, 0, r.`apply_toll`, r.`apply_parking`, r.`apply_gst_to_base`,
  r.`active`, CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3)
FROM `fare_rules` r
JOIN (
  SELECT 2 AS `hours` UNION ALL SELECT 4 UNION ALL SELECT 6 UNION ALL SELECT 12
) h
WHERE r.`product` = 'RENTAL' AND r.`rental_hours` = 8
  AND NOT EXISTS (
    SELECT 1 FROM `fare_rules` x
    WHERE x.`product` = 'RENTAL'
      AND x.`category` = r.`category`
      AND x.`rental_hours` = h.`hours`
      AND ((x.`district_id` IS NULL AND r.`district_id` IS NULL) OR x.`district_id` = r.`district_id`)
  );

INSERT INTO `system_settings` (`key`, `value`, `created_at`, `updated_at`)
SELECT 'schedule_reminder_minutes', '60', CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3)
WHERE NOT EXISTS (SELECT 1 FROM `system_settings` WHERE `key` = 'schedule_reminder_minutes');

INSERT INTO `system_settings` (`key`, `value`, `created_at`, `updated_at`)
SELECT 'schedule_assign_lead_minutes', '120', CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3)
WHERE NOT EXISTS (SELECT 1 FROM `system_settings` WHERE `key` = 'schedule_assign_lead_minutes');
