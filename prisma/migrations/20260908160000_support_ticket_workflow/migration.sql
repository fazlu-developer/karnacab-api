-- Phase 29 support tickets. Additive only.

ALTER TABLE `support_tickets`
  ADD COLUMN `category` VARCHAR(32) NOT NULL DEFAULT 'other',
  ADD COLUMN `description` VARCHAR(2000) NOT NULL DEFAULT '',
  ADD COLUMN `priority` VARCHAR(16) NOT NULL DEFAULT 'medium',
  ADD COLUMN `assigned_agent_id` BIGINT NULL,
  ADD COLUMN `resolution` VARCHAR(2000) NULL,
  ADD COLUMN `closed_at` DATETIME(3) NULL;

CREATE INDEX `support_tickets_assigned_agent_id_status_idx` ON `support_tickets`(`assigned_agent_id`, `status`);

ALTER TABLE `support_tickets`
  ADD CONSTRAINT `support_tickets_assigned_agent_id_fkey`
  FOREIGN KEY (`assigned_agent_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

CREATE TABLE `support_ticket_events` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `ticket_id` BIGINT NOT NULL,
  `actor_user_id` BIGINT NULL,
  `action` VARCHAR(32) NOT NULL,
  `from_status` VARCHAR(24) NULL,
  `to_status` VARCHAR(24) NULL,
  `note` VARCHAR(500) NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  INDEX `support_ticket_events_ticket_id_created_at_idx` (`ticket_id`, `created_at`),
  CONSTRAINT `support_ticket_events_ticket_id_fkey` FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
