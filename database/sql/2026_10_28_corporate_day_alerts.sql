-- Same-day corporate pickup alert, and a drop-time alert for round trips.
-- Safe to run more than once. Does not delete bookings.

SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'corporate_travel_bookings' AND COLUMN_NAME = 'return_time'),
    'SELECT 1',
    'ALTER TABLE corporate_travel_bookings ADD COLUMN return_time VARCHAR(8) NULL AFTER pickup_time'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'corporate_daily_trips' AND COLUMN_NAME = 'return_time'),
    'SELECT 1',
    'ALTER TABLE corporate_daily_trips ADD COLUMN return_time VARCHAR(8) NULL AFTER pickup_time'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'corporate_daily_trips' AND COLUMN_NAME = 'return_reminded_at'),
    'SELECT 1',
    'ALTER TABLE corporate_daily_trips ADD COLUMN return_reminded_at TIMESTAMP NULL AFTER reminded_at'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
