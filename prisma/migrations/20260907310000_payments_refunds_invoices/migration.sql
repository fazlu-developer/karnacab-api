-- Phase 24: unified payments, refunds, invoices. Existing payment rows are kept.

CREATE TABLE `invoices` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `public_ref` VARCHAR(24) NOT NULL,
  `kind` VARCHAR(16) NOT NULL,
  `customer_id` BIGINT NOT NULL,
  `booking_id` BIGINT NULL,
  `parcel_id` BIGINT NULL,
  `travel_booking_id` BIGINT NULL,
  `bulk_booking_id` BIGINT NULL,
  `corporate_invoice_id` BIGINT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'issued',
  `currency` VARCHAR(8) NOT NULL DEFAULT 'INR',
  `subtotal_paise` BIGINT NOT NULL,
  `tax_paise` BIGINT NOT NULL DEFAULT 0,
  `total_paise` BIGINT NOT NULL,
  `paid_paise` BIGINT NOT NULL DEFAULT 0,
  `refunded_paise` BIGINT NOT NULL DEFAULT 0,
  `lines` JSON NULL,
  `issued_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `invoices_public_ref_key` (`public_ref`),
  INDEX `invoices_customer_id_created_at_idx` (`customer_id`, `created_at`),
  INDEX `invoices_kind_status_idx` (`kind`, `status`),
  INDEX `invoices_booking_id_idx` (`booking_id`),
  INDEX `invoices_parcel_id_idx` (`parcel_id`),
  INDEX `invoices_travel_booking_id_idx` (`travel_booking_id`),
  INDEX `invoices_bulk_booking_id_idx` (`bulk_booking_id`),
  INDEX `invoices_corporate_invoice_id_idx` (`corporate_invoice_id`),
  CONSTRAINT `invoices_customer_id_fkey` FOREIGN KEY (`customer_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `invoices_booking_id_fkey` FOREIGN KEY (`booking_id`) REFERENCES `bookings`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `invoices_parcel_id_fkey` FOREIGN KEY (`parcel_id`) REFERENCES `parcel_shipments`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `invoices_travel_booking_id_fkey` FOREIGN KEY (`travel_booking_id`) REFERENCES `travel_bookings`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `invoices_bulk_booking_id_fkey` FOREIGN KEY (`bulk_booking_id`) REFERENCES `bulk_bookings`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `invoices_corporate_invoice_id_fkey` FOREIGN KEY (`corporate_invoice_id`) REFERENCES `corporate_invoices`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `payments`
  ADD COLUMN `public_ref` VARCHAR(24) NULL,
  ADD COLUMN `invoice_id` BIGINT NULL,
  ADD COLUMN `customer_id` BIGINT NULL,
  ADD COLUMN `parent_id` BIGINT NULL,
  ADD COLUMN `kind` VARCHAR(24) NOT NULL DEFAULT 'payment',
  ADD COLUMN `intent` VARCHAR(24) NOT NULL DEFAULT 'capture',
  ADD COLUMN `gateway` VARCHAR(32) NOT NULL DEFAULT 'none',
  ADD COLUMN `gateway_order_id` VARCHAR(64) NULL,
  ADD COLUMN `gateway_payment_id` VARCHAR(64) NULL,
  ADD COLUMN `failure_code` VARCHAR(40) NULL,
  ADD COLUMN `failure_note` VARCHAR(255) NULL,
  ADD COLUMN `verified_at` DATETIME(3) NULL,
  ADD COLUMN `verified_source` VARCHAR(24) NULL,
  ADD COLUMN `attempt_no` INT NOT NULL DEFAULT 1,
  ADD COLUMN `note` VARCHAR(255) NULL,
  ADD COLUMN `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3);

UPDATE `payments`
SET
  `public_ref` = CONCAT('KCP', LPAD(`id`, 12, '0')),
  `status` = CASE
    WHEN `status` IN ('paid', 'success', 'captured') THEN 'captured'
    WHEN `status` IN ('failed', 'failure') THEN 'failed'
    WHEN `status` IN ('pending', 'created', 'initiated') THEN 'pending'
    ELSE `status`
  END,
  `method` = LOWER(`method`),
  `updated_at` = CURRENT_TIMESTAMP(3)
WHERE `public_ref` IS NULL;

ALTER TABLE `payments`
  MODIFY COLUMN `public_ref` VARCHAR(24) NOT NULL,
  ADD UNIQUE INDEX `payments_public_ref_key` (`public_ref`),
  ADD INDEX `payments_invoice_id_status_idx` (`invoice_id`, `status`),
  ADD INDEX `payments_customer_id_created_at_idx` (`customer_id`, `created_at`),
  ADD INDEX `payments_status_created_at_idx` (`status`, `created_at`),
  ADD INDEX `payments_kind_created_at_idx` (`kind`, `created_at`);

ALTER TABLE `payments`
  ADD CONSTRAINT `payments_invoice_id_fkey`
    FOREIGN KEY (`invoice_id`) REFERENCES `invoices`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `payments_customer_id_fkey`
    FOREIGN KEY (`customer_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `payments_parent_id_fkey`
    FOREIGN KEY (`parent_id`) REFERENCES `payments`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

CREATE TABLE `payment_events` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `payment_id` BIGINT NOT NULL,
  `source` VARCHAR(24) NOT NULL,
  `event_type` VARCHAR(40) NOT NULL,
  `gateway_event_id` VARCHAR(80) NULL,
  `payload` JSON NULL,
  `signature_valid` BOOLEAN NOT NULL DEFAULT false,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE INDEX `payment_events_gateway_event_id_key` (`gateway_event_id`),
  INDEX `payment_events_payment_id_created_at_idx` (`payment_id`, `created_at`),
  CONSTRAINT `payment_events_payment_id_fkey`
    FOREIGN KEY (`payment_id`) REFERENCES `payments`(`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
