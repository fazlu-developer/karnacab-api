-- Phase 13: persist declined offers so they are not shown again.

CREATE TABLE `driver_offer_declines` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `driver_id` BIGINT NOT NULL,
  `kind` VARCHAR(16) NOT NULL,
  `target_id` BIGINT NOT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE INDEX `driver_offer_declines_driver_id_kind_target_id_key` (`driver_id`, `kind`, `target_id`),
  INDEX `driver_offer_declines_driver_id_kind_idx` (`driver_id`, `kind`),
  CONSTRAINT `driver_offer_declines_driver_id_fkey`
    FOREIGN KEY (`driver_id`) REFERENCES `drivers`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT IGNORE INTO `system_settings` (`key`, `value`, `created_at`, `updated_at`)
VALUES ('driver_offer_radius_km', '30', CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3));
