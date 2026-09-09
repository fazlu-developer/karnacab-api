-- CreateTable
CREATE TABLE `cms_pages` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `slug` VARCHAR(80) NOT NULL,
    `title` VARCHAR(160) NOT NULL,
    `eyebrow` VARCHAR(80) NULL,
    `seo_title` VARCHAR(180) NULL,
    `seo_description` VARCHAR(320) NULL,
    `lede` TEXT NOT NULL,
    `body` JSON NULL,
    `template` VARCHAR(40) NOT NULL,
    `lead_type` ENUM('RIDE', 'PARCEL', 'BIHAR_PARCEL', 'TRAVEL', 'BULK', 'CORPORATE', 'FRANCHISE', 'FLEET', 'ADVERTISE', 'SUPPORT') NULL,
    `register_kind` VARCHAR(32) NULL,
    `product_key` VARCHAR(40) NULL,
    `nav_group` VARCHAR(32) NOT NULL,
    `nav_label` VARCHAR(80) NOT NULL,
    `sort_order` INTEGER NOT NULL DEFAULT 0,
    `published` BOOLEAN NOT NULL DEFAULT true,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,

    UNIQUE INDEX `cms_pages_slug_key` (`slug`),
    INDEX `cms_pages_nav_group_published_sort_order_idx` (`nav_group`, `published`, `sort_order`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
