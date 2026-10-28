-- Live database: karnacab_platform
-- Safe to run more than once. Does not depend on php artisan migrate.

SET @db = DATABASE();

SET @sql = (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'patient_photo_url') = 0,
    'ALTER TABLE bookings ADD COLUMN patient_photo_url VARCHAR(512) NULL',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'is_emergency') = 0,
    'ALTER TABLE bookings ADD COLUMN is_emergency TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- bookings.category and vehicles.category are ENUMs without AMBULANCE.
SET @sql = (
  SELECT IF(
    (SELECT DATA_TYPE FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'category') = 'enum',
    'ALTER TABLE bookings MODIFY category VARCHAR(32) NOT NULL',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    (SELECT DATA_TYPE FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'vehicles' AND COLUMN_NAME = 'category') = 'enum',
    'ALTER TABLE vehicles MODIFY category VARCHAR(32) NOT NULL',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO catalog_services
  (title, slug, service_group, category_key, icon_key, sort_order, subtitle, featured, active, seats, created_at, updated_at)
SELECT 'Ambulance', 'ambulance', 'RIDE', 'AMBULANCE', 'ambulance', 15, 'Free emergency ambulance', 0, 1, 2, NOW(3), NOW(3)
WHERE NOT EXISTS (
  SELECT 1 FROM catalog_services WHERE category_key = 'AMBULANCE' OR slug = 'ambulance'
);
