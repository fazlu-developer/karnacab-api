-- Additive BookingStatus values + stored server fare snapshot.
-- Does not drop tables or rewrite existing booking rows.
ALTER TABLE `bookings`
  MODIFY `status` ENUM(
    'DRAFT',
    'PENDING',
    'CONFIRMED',
    'DRIVER_SEARCHING',
    'DRIVER_ASSIGNED',
    'DRIVER_ARRIVING',
    'DRIVER_ARRIVED',
    'STARTED',
    'COMPLETED',
    'CANCELLED',
    'REQUESTED',
    'QUOTED',
    'ASSIGNED',
    'ONGOING'
  ) NOT NULL DEFAULT 'REQUESTED';

ALTER TABLE `bookings`
  ADD COLUMN `quote_snapshot` JSON NULL;
