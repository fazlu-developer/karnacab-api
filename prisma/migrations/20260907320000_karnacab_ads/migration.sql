-- Phase 25: KarnaCab Ads. Existing ad_campaigns rows are kept.

ALTER TABLE `ad_campaigns`
  ADD COLUMN `advertiser_user_id` BIGINT NULL,
  ADD COLUMN `business_name` VARCHAR(160) NOT NULL DEFAULT '',
  ADD COLUMN `category` VARCHAR(32) NOT NULL DEFAULT 'local_businesses',
  ADD COLUMN `banner_key` VARCHAR(255) NULL,
  ADD COLUMN `banner_mime` VARCHAR(80) NULL,
  ADD COLUMN `target_city` VARCHAR(80) NULL,
  ADD COLUMN `budget_used_paise` BIGINT NOT NULL DEFAULT 0,
  ADD COLUMN `cta_url` VARCHAR(500) NULL,
  ADD COLUMN `rejected_reason` VARCHAR(255) NULL,
  ADD COLUMN `reviewed_by_id` BIGINT NULL,
  ADD COLUMN `reviewed_at` DATETIME(3) NULL,
  ADD COLUMN `published_at` DATETIME(3) NULL,
  ADD COLUMN `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3);

ALTER TABLE `ad_campaigns`
  ADD INDEX `ad_campaigns_advertiser_user_id_created_at_idx` (`advertiser_user_id`, `created_at`),
  ADD INDEX `ad_campaigns_district_id_status_idx` (`district_id`, `status`),
  ADD INDEX `ad_campaigns_category_status_idx` (`category`, `status`);

ALTER TABLE `ad_campaigns`
  ADD CONSTRAINT `ad_campaigns_advertiser_user_id_fkey`
    FOREIGN KEY (`advertiser_user_id`) REFERENCES `users`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `ad_campaigns_district_id_fkey`
    FOREIGN KEY (`district_id`) REFERENCES `districts`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE;

CREATE TABLE `ad_events` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `campaign_id` BIGINT NOT NULL,
  `user_id` BIGINT NULL,
  `kind` VARCHAR(16) NOT NULL,
  `placement` VARCHAR(32) NOT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  INDEX `ad_events_campaign_id_kind_created_at_idx` (`campaign_id`, `kind`, `created_at`),
  INDEX `ad_events_user_id_campaign_id_kind_created_at_idx` (`user_id`, `campaign_id`, `kind`, `created_at`),
  CONSTRAINT `ad_events_campaign_id_fkey`
    FOREIGN KEY (`campaign_id`) REFERENCES `ad_campaigns`(`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT IGNORE INTO `system_settings` (`key`, `value`, `created_at`, `updated_at`)
VALUES
  ('ad_impression_paise', '10', CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3)),
  ('ad_click_paise', '100', CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3));
