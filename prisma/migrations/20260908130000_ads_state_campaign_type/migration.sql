-- Phase 25: campaign type, business info, state targeting.

ALTER TABLE `ad_campaigns`
  ADD COLUMN `business_info` TEXT NULL AFTER `business_name`,
  ADD COLUMN `campaign_type` VARCHAR(32) NOT NULL DEFAULT 'banner' AFTER `category`,
  ADD COLUMN `state_id` INT NULL AFTER `target_city`;

ALTER TABLE `ad_campaigns`
  ADD INDEX `ad_campaigns_state_id_status_idx` (`state_id`, `status`);

ALTER TABLE `ad_campaigns`
  ADD CONSTRAINT `ad_campaigns_state_id_fkey`
    FOREIGN KEY (`state_id`) REFERENCES `states`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE;
