-- Run on karnacab_platform (live and local).
-- 1) PLATFORM no longer truncates wallet_ledger.account
-- 2) New wallets after TRUNCATE need updated_at default (admin add-money 419/logout)

ALTER TABLE wallet_ledger
  MODIFY COLUMN account VARCHAR(32) NULL;

ALTER TABLE wallets
  MODIFY COLUMN owner_type VARCHAR(32) NULL,
  MODIFY COLUMN updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3);
