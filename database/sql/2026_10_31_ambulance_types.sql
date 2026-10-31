-- Adds Basic, BLS, and ALS under Ambulance.
-- Existing catalog rows are not updated or deleted. The original AMBULANCE type stays.

INSERT INTO catalog_vehicle_types (family_key, family_label, `key`, label, seats, sort_order, active, blurb, image_url, created_at, updated_at)
SELECT 'AMBULANCE', 'Ambulance', 'AMBULANCE', 'Ambulance', 2, 100, 1, 'Emergency ambulance', NULL, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM catalog_vehicle_types WHERE `key` = 'AMBULANCE');

INSERT INTO catalog_vehicle_types (family_key, family_label, `key`, label, seats, sort_order, active, blurb, image_url, created_at, updated_at)
SELECT 'AMBULANCE', 'Ambulance', 'BASIC', 'Basic Patient Transport', 2, 101, 1, 'सामान्य मरीज ट्रांसफर', '/uploads/vehicles/ambulance_basic.png', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM catalog_vehicle_types WHERE `key` = 'BASIC');

INSERT INTO catalog_vehicle_types (family_key, family_label, `key`, label, seats, sort_order, active, blurb, image_url, created_at, updated_at)
SELECT 'AMBULANCE', 'Ambulance', 'BLS', 'BLS Ambulance', 2, 102, 1, 'बेसिक लाइफ सपोर्ट', '/uploads/vehicles/ambulance_bls.png', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM catalog_vehicle_types WHERE `key` = 'BLS');

INSERT INTO catalog_vehicle_types (family_key, family_label, `key`, label, seats, sort_order, active, blurb, image_url, created_at, updated_at)
SELECT 'AMBULANCE', 'Ambulance', 'ALS', 'ALS Ambulance', 2, 103, 1, 'उन्नत चिकित्सा सहायता', '/uploads/vehicles/ambulance_als.png', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM catalog_vehicle_types WHERE `key` = 'ALS');
