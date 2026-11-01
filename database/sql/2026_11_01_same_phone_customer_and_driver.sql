-- One mobile number can sign in on both the customer app and the driver app.
-- Run this once on karnacab_platform before using the updated API.

ALTER TABLE users DROP INDEX users_phone_key;
ALTER TABLE users ADD UNIQUE KEY users_phone_role_unique (phone, role);
