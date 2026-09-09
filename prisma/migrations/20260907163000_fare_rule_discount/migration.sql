-- Additive discount fields on existing fare_rules (admin-configured).
ALTER TABLE `fare_rules`
  ADD COLUMN `discount_paise` INT NOT NULL DEFAULT 0,
  ADD COLUMN `discount_percent` INT NOT NULL DEFAULT 0;
