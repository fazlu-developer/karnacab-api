-- Phase 12: driver duty status for Home / offer gating.

ALTER TABLE `drivers`
  ADD COLUMN `duty_status` VARCHAR(32) NOT NULL DEFAULT 'offline';

UPDATE `drivers` SET `duty_status` = 'online' WHERE `online` = 1;

CREATE INDEX `drivers_duty_status_idx` ON `drivers`(`duty_status`);
