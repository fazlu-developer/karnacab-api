-- Fix SQLSTATE 1265 Data truncated for column `account` (PLATFORM / FLEET_OWNER / DISTRICT_HEAD).
-- Run on karnacab_platform (live and local).

ALTER TABLE wallet_ledger
  MODIFY COLUMN account VARCHAR(32) NULL;

ALTER TABLE wallets
  MODIFY COLUMN owner_type VARCHAR(32) NULL;
