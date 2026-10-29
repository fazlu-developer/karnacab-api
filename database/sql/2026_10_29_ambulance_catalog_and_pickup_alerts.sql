-- Live database: karnacab_platform
-- Safe to run more than once. Does not depend on php artisan migrate.
--
-- Pickup reminders for bulk and tour, and the ambulance vehicle type
-- so a driver can register and appear in nearby search.

SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'bulk_bookings' AND COLUMN_NAME = 'pickup_reminded_at'),
    'SELECT 1',
    'ALTER TABLE bulk_bookings ADD COLUMN pickup_reminded_at TIMESTAMP NULL'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'travel_bookings' AND COLUMN_NAME = 'pickup_reminded_at'),
    'SELECT 1',
    'ALTER TABLE travel_bookings ADD COLUMN pickup_reminded_at TIMESTAMP NULL'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO catalog_vehicle_types
  (family_key, family_label, `key`, label, seats, sort_order, active, created_at, updated_at)
SELECT 'AMBULANCE', 'Ambulance', 'AMBULANCE', 'Ambulance', 2, 100, 1, NOW(3), NOW(3)
WHERE NOT EXISTS (
  SELECT 1 FROM catalog_vehicle_types WHERE `key` = 'AMBULANCE'
);
