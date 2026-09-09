ALTER TABLE `travel_packages`
  ADD COLUMN IF NOT EXISTS `category` VARCHAR(40) NOT NULL DEFAULT 'SIGHTSEEING',
  ADD COLUMN IF NOT EXISTS `duration_label` VARCHAR(80) NULL,
  ADD COLUMN IF NOT EXISTS `driver_label` VARCHAR(80) NOT NULL DEFAULT 'Dedicated driver',
  ADD COLUMN IF NOT EXISTS `itinerary` JSON NULL,
  ADD COLUMN IF NOT EXISTS `gallery` JSON NULL,
  ADD COLUMN IF NOT EXISTS `available_dates` JSON NULL;

CREATE INDEX IF NOT EXISTS `travel_packages_status_category_idx` ON `travel_packages` (`status`, `category`);

CREATE TABLE IF NOT EXISTS `travel_bookings` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `public_ref` VARCHAR(24) NOT NULL,
  `customer_id` BIGINT NOT NULL,
  `package_id` INT NOT NULL,
  `travel_date` DATE NOT NULL,
  `guests` INT NOT NULL DEFAULT 1,
  `contact_name` VARCHAR(120) NOT NULL,
  `contact_phone` VARCHAR(20) NOT NULL,
  `notes` VARCHAR(255) NULL,
  `quote_paise` BIGINT NOT NULL,
  `quote_snapshot` JSON NULL,
  `payment_status` VARCHAR(20) NOT NULL DEFAULT 'unpaid',
  `payment_method` VARCHAR(20) NULL,
  `status` VARCHAR(24) NOT NULL DEFAULT 'created',
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `travel_bookings_public_ref_key` (`public_ref`),
  KEY `travel_bookings_customer_id_created_at_idx` (`customer_id`, `created_at`),
  KEY `travel_bookings_package_id_travel_date_idx` (`package_id`, `travel_date`),
  KEY `travel_bookings_status_created_at_idx` (`status`, `created_at`),
  CONSTRAINT `travel_bookings_customer_id_fkey` FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`),
  CONSTRAINT `travel_bookings_package_id_fkey` FOREIGN KEY (`package_id`) REFERENCES `travel_packages` (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `payments`
  ADD COLUMN IF NOT EXISTS `travel_booking_id` BIGINT NULL;

SET @idx := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'payments'
    AND index_name = 'payments_travel_booking_id_status_idx'
);
SET @sql := IF(@idx = 0, 'CREATE INDEX `payments_travel_booking_id_status_idx` ON `payments` (`travel_booking_id`, `status`)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT INTO `system_settings` (`key`, `value`, `created_at`, `updated_at`)
SELECT 'travel_categories', '[{"key":"BIHAR_TOURS","label":"Bihar Tours"},{"key":"SIGHTSEEING","label":"Sightseeing"},{"key":"DARSHAN_YATRA","label":"Darshan/Yatra"},{"key":"FAMILY","label":"Family"},{"key":"GROUP_TOURS","label":"Group Tours"},{"key":"CUSTOM_TOURS","label":"Custom Tours"},{"key":"HOTEL_CAB","label":"Hotel + Cab"},{"key":"AIRPORT_CAB","label":"Airport + Cab"},{"key":"RAILWAY_CAB","label":"Railway + Cab"}]', CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3)
WHERE NOT EXISTS (SELECT 1 FROM `system_settings` WHERE `key` = 'travel_categories');

UPDATE `travel_packages`
SET `category` = 'DARSHAN_YATRA',
    `duration_label` = '1 day / 8 hours',
    `driver_label` = 'Dedicated driver',
    `itinerary` = JSON_ARRAY(JSON_OBJECT('day', 1, 'title', 'Patna city darshan', 'detail', 'Golghar, Gandhi Maidan, Patna Sahib, riverfront')),
    `available_dates` = JSON_ARRAY('2026-10-03','2026-10-10','2026-10-17','2026-10-24')
WHERE `title` = 'Patna city darshan';

INSERT INTO `travel_packages`
  (`district_id`, `category`, `title`, `destination`, `places`, `duration_hours`, `duration_label`, `km_included`, `vehicle_label`, `driver_label`, `price_paise`, `inclusions`, `exclusions`, `itinerary`, `gallery`, `available_dates`, `status`, `created_at`, `updated_at`)
SELECT v.`district_id`, v.`category`, v.`title`, v.`destination`, v.`places`, v.`duration_hours`, v.`duration_label`, v.`km_included`, v.`vehicle_label`, v.`driver_label`, v.`price_paise`, v.`inclusions`, v.`exclusions`, v.`itinerary`, NULL, v.`available_dates`, 'PUBLISHED', CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3)
FROM (
  SELECT (SELECT `id` FROM `districts` WHERE `code` = 'PATNA' LIMIT 1) AS `district_id`,
    'BIHAR_TOURS' AS `category`, 'Bihar circuit — Nalanda to Rajgir' AS `title`, 'Nalanda' AS `destination`,
    'Nalanda University ruins, Rajgir, Vishwa Shanti Stupa' AS `places`, 12 AS `duration_hours`, '1 day' AS `duration_label`,
    180 AS `km_included`, 'SUV' AS `vehicle_label`, 'Dedicated driver' AS `driver_label`, 890000 AS `price_paise`,
    'Cab, driver, fuel, parking' AS `inclusions`, 'Tickets, meals, guide' AS `exclusions`,
    JSON_ARRAY(JSON_OBJECT('day', 1, 'title', 'Nalanda and Rajgir', 'detail', 'Morning Nalanda ruins, afternoon Rajgir ropeway area')) AS `itinerary`,
    JSON_ARRAY('2026-10-04','2026-10-11','2026-10-18') AS `available_dates`
  UNION ALL SELECT (SELECT `id` FROM `districts` WHERE `code` = 'PATNA' LIMIT 1),
    'SIGHTSEEING', 'Patna riverfront evening', 'Patna', 'Gandhi Ghat, Sabhyata Dwar, Golghar',
    4, '4 hours', 40, 'Sedan', 'Dedicated driver', 290000,
    'Cab, driver, fuel', 'Food, tickets',
    JSON_ARRAY(JSON_OBJECT('day', 1, 'title', 'Riverfront loop', 'detail', 'Sunset drive along the Ganga')),
    JSON_ARRAY('2026-10-02','2026-10-09','2026-10-16')
  UNION ALL SELECT (SELECT `id` FROM `districts` WHERE `code` = 'GAYA' LIMIT 1),
    'DARSHAN_YATRA', 'Bodh Gaya darshan', 'Bodh Gaya', 'Mahabodhi Temple, Great Buddha, Sujata village',
    10, '1 day', 60, 'Sedan', 'Dedicated driver', 650000,
    'Cab, driver, fuel, waiting', 'Temple offerings, meals',
    JSON_ARRAY(JSON_OBJECT('day', 1, 'title', 'Mahabodhi circuit', 'detail', 'Temple, Bodhi tree, evening aarti time buffer')),
    JSON_ARRAY('2026-10-05','2026-10-12','2026-10-19')
  UNION ALL SELECT (SELECT `id` FROM `districts` WHERE `code` = 'PATNA' LIMIT 1),
    'FAMILY', 'Family day out — Patna Zoo and museums', 'Patna', 'Sanjay Gandhi Biological Park, museums, riverfront',
    8, '1 day', 50, 'SUV', 'Family driver', 540000,
    'Cab, driver, child seats on request', 'Tickets, snacks',
    JSON_ARRAY(JSON_OBJECT('day', 1, 'title', 'Zoo and city', 'detail', 'Morning zoo, afternoon museum and snacks stop')),
    JSON_ARRAY('2026-10-03','2026-10-10')
  UNION ALL SELECT (SELECT `id` FROM `districts` WHERE `code` = 'PATNA' LIMIT 1),
    'GROUP_TOURS', 'School / group Rajgir outing', 'Rajgir', 'Hot springs, ropeway, nature park',
    12, '1 day', 200, 'Traveller', 'Group driver', 1450000,
    'Traveller, driver, fuel', 'Meals, tickets, extra waiting beyond 12h',
    JSON_ARRAY(JSON_OBJECT('day', 1, 'title', 'Rajgir group day', 'detail', 'Pickup Patna, Rajgir circuit, drop')),
    JSON_ARRAY('2026-10-08','2026-10-15')
  UNION ALL SELECT (SELECT `id` FROM `districts` WHERE `code` = 'PATNA' LIMIT 1),
    'CUSTOM_TOURS', 'Custom Bihar itinerary (cab + driver)', 'Bihar', 'Route as discussed with ops',
    24, 'Flexible', 250, 'SUV', 'Dedicated driver', 990000,
    'Cab, driver, fuel for billed KM', 'Hotels, tickets, meals',
    JSON_ARRAY(JSON_OBJECT('day', 1, 'title', 'Custom day', 'detail', 'Share places in notes; ops confirms the route')),
    JSON_ARRAY('2026-10-01','2026-10-06','2026-10-13','2026-10-20')
  UNION ALL SELECT (SELECT `id` FROM `districts` WHERE `code` = 'PATNA' LIMIT 1),
    'HOTEL_CAB', 'Hotel stay + local cab (Patna)', 'Patna', 'Hotel drop, 8-hour city cab next day',
    24, '1 night + cab', 80, 'Sedan', 'Hotel transfer driver', 780000,
    'Airport/station transfer, next-day 8h cab', 'Hotel extras, meals',
    JSON_ARRAY(JSON_OBJECT('day', 1, 'title', 'Transfer', 'detail', 'Pickup and hotel drop'), JSON_OBJECT('day', 2, 'title', 'City cab', 'detail', '8-hour sightseeing cab')),
    JSON_ARRAY('2026-10-07','2026-10-14','2026-10-21')
  UNION ALL SELECT (SELECT `id` FROM `districts` WHERE `code` = 'PATNA' LIMIT 1),
    'AIRPORT_CAB', 'Patna airport + city cab combo', 'Patna Airport', 'Jay Prakash Narayan Airport, hotel or home',
    6, '6 hours', 70, 'Sedan', 'Airport driver', 360000,
    'Meet and greet buffer, cab, waiting 60 min', 'Flight delay beyond 60 min extra',
    JSON_ARRAY(JSON_OBJECT('day', 1, 'title', 'Airport transfer', 'detail', 'Flight-timed pickup or drop plus city hop')),
    JSON_ARRAY('2026-10-02','2026-10-09','2026-10-16','2026-10-23')
  UNION ALL SELECT (SELECT `id` FROM `districts` WHERE `code` = 'PATNA' LIMIT 1),
    'RAILWAY_CAB', 'Patna Junction + city cab combo', 'Patna Junction', 'Patna Junction / Rajendra Nagar, city drop',
    4, '4 hours', 40, 'Sedan', 'Station driver', 280000,
    'Station pickup, cab, 45 min waiting', 'Porter, extra stops beyond package',
    JSON_ARRAY(JSON_OBJECT('day', 1, 'title', 'Station transfer', 'detail', 'Train-timed pickup or drop')),
    JSON_ARRAY('2026-10-02','2026-10-09','2026-10-16')
) v
WHERE NOT EXISTS (SELECT 1 FROM `travel_packages` x WHERE x.`title` = v.`title`);
