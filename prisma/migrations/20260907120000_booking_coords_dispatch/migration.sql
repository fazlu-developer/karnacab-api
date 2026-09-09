ALTER TABLE `bookings`
  ADD COLUMN `pickup_lat` DECIMAL(10, 7) NULL,
  ADD COLUMN `pickup_lng` DECIMAL(10, 7) NULL,
  ADD COLUMN `drop_lat` DECIMAL(10, 7) NULL,
  ADD COLUMN `drop_lng` DECIMAL(10, 7) NULL,
  ADD COLUMN `polyline` TEXT NULL;
