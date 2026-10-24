-- Live `states` is missing status (districts already have it).
-- Organization heads 500: select id, name, code, status from states

ALTER TABLE states
  ADD COLUMN status VARCHAR(24) NOT NULL DEFAULT 'ACTIVE' AFTER name;
