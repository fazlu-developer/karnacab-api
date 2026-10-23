-- Run on the KarnaRide platform database (same DB as ad_campaigns).
-- Skip a statement if MySQL says the column already exists.

SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'ad_campaigns' AND COLUMN_NAME = 'image_url') = 0,
    'ALTER TABLE ad_campaigns ADD COLUMN image_url VARCHAR(500) NULL',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'ad_campaigns' AND COLUMN_NAME = 'placement') = 0,
    'ALTER TABLE ad_campaigns ADD COLUMN placement VARCHAR(40) NULL DEFAULT ''home''',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE ad_campaigns
SET placement = 'home'
WHERE placement IS NULL OR placement = '';
