-- Phase 28 notifications + support. Additive only.

ALTER TABLE `user_notifications`
  MODIFY `kind` VARCHAR(48) NOT NULL DEFAULT 'info';

CREATE TABLE `push_devices` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT NOT NULL,
  `token` VARCHAR(512) NOT NULL,
  `platform` VARCHAR(24) NOT NULL DEFAULT 'android',
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE INDEX `push_devices_token_key` (`token`),
  INDEX `push_devices_user_id_idx` (`user_id`),
  CONSTRAINT `push_devices_user_id_fkey` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `support_faqs` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `audience` VARCHAR(24) NOT NULL,
  `question` VARCHAR(255) NOT NULL,
  `answer` VARCHAR(2000) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `active` BOOLEAN NOT NULL DEFAULT true,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  INDEX `support_faqs_audience_active_sort_order_idx` (`audience`, `active`, `sort_order`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `support_tickets` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `public_ref` VARCHAR(24) NOT NULL,
  `user_id` BIGINT NOT NULL,
  `kind` VARCHAR(32) NOT NULL,
  `status` VARCHAR(24) NOT NULL DEFAULT 'open',
  `subject` VARCHAR(160) NOT NULL,
  `booking_id` BIGINT NULL,
  `safety_incident_id` BIGINT NULL,
  `district_id` INT NULL,
  `resolved_at` DATETIME(3) NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE INDEX `support_tickets_public_ref_key` (`public_ref`),
  INDEX `support_tickets_user_id_created_at_idx` (`user_id`, `created_at`),
  INDEX `support_tickets_status_created_at_idx` (`status`, `created_at`),
  INDEX `support_tickets_kind_status_idx` (`kind`, `status`),
  INDEX `support_tickets_district_id_status_idx` (`district_id`, `status`),
  CONSTRAINT `support_tickets_user_id_fkey` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `support_tickets_booking_id_fkey` FOREIGN KEY (`booking_id`) REFERENCES `bookings`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `support_tickets_safety_incident_id_fkey` FOREIGN KEY (`safety_incident_id`) REFERENCES `safety_incidents`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `support_tickets_district_id_fkey` FOREIGN KEY (`district_id`) REFERENCES `districts`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `support_messages` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `ticket_id` BIGINT NOT NULL,
  `author_id` BIGINT NOT NULL,
  `from_staff` BOOLEAN NOT NULL DEFAULT false,
  `body` VARCHAR(2000) NOT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  INDEX `support_messages_ticket_id_created_at_idx` (`ticket_id`, `created_at`),
  CONSTRAINT `support_messages_ticket_id_fkey` FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `support_messages_author_id_fkey` FOREIGN KEY (`author_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `support_attachments` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `ticket_id` BIGINT NOT NULL,
  `message_id` BIGINT NULL,
  `storage_key` VARCHAR(255) NOT NULL,
  `mime` VARCHAR(80) NOT NULL,
  `original_name` VARCHAR(160) NOT NULL,
  `bytes` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  INDEX `support_attachments_ticket_id_idx` (`ticket_id`),
  CONSTRAINT `support_attachments_ticket_id_fkey` FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `support_attachments_message_id_fkey` FOREIGN KEY (`message_id`) REFERENCES `support_messages`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE INDEX `user_notifications_user_id_kind_entity_id_idx` ON `user_notifications`(`user_id`, `kind`, `entity_id`);
