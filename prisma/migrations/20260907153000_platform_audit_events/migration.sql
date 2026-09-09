-- Additive only. Does not alter users, bookings, or existing columns.
CREATE TABLE `platform_audit_events` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `actor_user_id` BIGINT NULL,
  `domain` VARCHAR(40) NOT NULL,
  `action` VARCHAR(40) NOT NULL,
  `entity_type` VARCHAR(40) NULL,
  `entity_id` VARCHAR(40) NULL,
  `payload` JSON NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  INDEX `platform_audit_events_domain_created_at_idx` (`domain`, `created_at`),
  INDEX `platform_audit_events_actor_user_id_created_at_idx` (`actor_user_id`, `created_at`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
