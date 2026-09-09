-- Phase 30 notification deliveries. Additive only.

CREATE TABLE `notification_deliveries` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT NULL,
  `event` VARCHAR(48) NOT NULL,
  `channel` VARCHAR(16) NOT NULL,
  `title` VARCHAR(160) NOT NULL,
  `body` VARCHAR(500) NOT NULL,
  `status` VARCHAR(24) NOT NULL DEFAULT 'sent',
  `provider_note` VARCHAR(160) NULL,
  `entity_type` VARCHAR(40) NULL,
  `entity_id` VARCHAR(40) NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  INDEX `notification_deliveries_user_id_created_at_idx` (`user_id`, `created_at`),
  INDEX `notification_deliveries_event_created_at_idx` (`event`, `created_at`),
  INDEX `notification_deliveries_channel_created_at_idx` (`channel`, `created_at`),
  CONSTRAINT `notification_deliveries_user_id_fkey` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
