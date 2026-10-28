-- Live database: karnacab_platform
-- Removes ride bookings, wallet history, and notifications for these phones.
-- The user accounts stay. Wallet balances are set to 0.
-- Bookings include trips they booked and trips they drove.
-- Safe to read the SELECT block first. The DELETE block is one transaction.

USE karnacab_platform;

DROP TEMPORARY TABLE IF EXISTS tmp_phones;
CREATE TEMPORARY TABLE tmp_phones (phone CHAR(10) PRIMARY KEY);
INSERT INTO tmp_phones (phone) VALUES
  ('7065876175'),
  ('7065876176'),
  ('7428059960'),
  ('7428059961'),
  ('9560210876'),
  ('9560210875'),
  ('8595238890'),
  ('9534343483'),
  ('9296343483'),
  ('9523707084'),
  ('9204935020');

DROP TEMPORARY TABLE IF EXISTS tmp_users;
CREATE TEMPORARY TABLE tmp_users (id BIGINT PRIMARY KEY);
INSERT INTO tmp_users (id)
SELECT u.id
FROM users u
JOIN tmp_phones p
  ON p.phone = RIGHT(REPLACE(REPLACE(REPLACE(IFNULL(u.phone, ''), '+', ''), '-', ''), ' ', ''), 10);

DROP TEMPORARY TABLE IF EXISTS tmp_bookings;
CREATE TEMPORARY TABLE tmp_bookings (id BIGINT PRIMARY KEY);
INSERT INTO tmp_bookings (id)
SELECT b.id FROM bookings b JOIN tmp_users u ON u.id = b.customer_id;
INSERT IGNORE INTO tmp_bookings (id)
SELECT b.id FROM bookings b JOIN tmp_users u ON u.id = b.driver_id;
INSERT IGNORE INTO tmp_bookings (id)
SELECT b.id
FROM bookings b
JOIN tmp_phones p
  ON p.phone = RIGHT(REPLACE(REPLACE(REPLACE(IFNULL(b.passenger_phone, ''), '+', ''), '-', ''), ' ', ''), 10);

SELECT u.id, u.role, u.name, u.phone
FROM users u
JOIN tmp_users t ON t.id = u.id
ORDER BY u.phone;

SELECT
  (SELECT COUNT(*) FROM tmp_bookings) AS bookings,
  (SELECT COUNT(*) FROM wallet_ledger wl JOIN tmp_users u ON u.id = wl.owner_user_id) AS wallet_rows,
  (SELECT COUNT(*) FROM user_notifications n JOIN tmp_users u ON u.id = n.user_id) AS notifications;

START TRANSACTION;

DELETE e FROM payment_events e
JOIN payments p ON p.id = e.payment_id
JOIN tmp_bookings b ON b.id = p.booking_id;

DELETE child FROM payments child
JOIN payments parent ON parent.id = child.parent_id
JOIN tmp_bookings b ON b.id = parent.booking_id;

DELETE p FROM payments p
JOIN tmp_bookings b ON b.id = p.booking_id;

DELETE i FROM invoices i
JOIN tmp_bookings b ON b.id = i.booking_id;

DELETE ww FROM wallet_withdrawals ww
JOIN wallet_ledger wl ON wl.id = ww.ledger_id
JOIN tmp_bookings b ON b.id = wl.booking_id;

DELETE ww FROM wallet_withdrawals ww
JOIN wallets w ON w.id = ww.wallet_id
JOIN tmp_users u ON u.id = w.owner_user_id;

DELETE wl FROM wallet_ledger wl
JOIN tmp_bookings b ON b.id = wl.booking_id;

DELETE wl FROM wallet_ledger wl
JOIN tmp_users u ON u.id = wl.owner_user_id;

DELETE a FROM support_attachments a
JOIN support_tickets t ON t.id = a.ticket_id
JOIN tmp_bookings b ON b.id = t.booking_id;

DELETE m FROM support_messages m
JOIN support_tickets t ON t.id = m.ticket_id
JOIN tmp_bookings b ON b.id = t.booking_id;

DELETE ev FROM support_ticket_events ev
JOIN support_tickets t ON t.id = ev.ticket_id
JOIN tmp_bookings b ON b.id = t.booking_id;

DELETE t FROM support_tickets t
JOIN tmp_bookings b ON b.id = t.booking_id;

DELETE s FROM safety_incidents s
JOIN tmp_bookings b ON b.id = s.booking_id;

DELETE r FROM booking_ratings r JOIN tmp_bookings b ON b.id = r.booking_id;
DELETE s FROM booking_stops s JOIN tmp_bookings b ON b.id = s.booking_id;
DELETE r FROM booking_driver_requests r JOIN tmp_bookings b ON b.id = r.booking_id;
DELETE k FROM trip_km_logs k JOIN tmp_bookings b ON b.id = k.booking_id;
DELETE c FROM coupon_redemptions c JOIN tmp_bookings b ON b.id = c.booking_id;
DELETE c FROM coupon_redemptions c JOIN tmp_users u ON u.id = c.user_id;
DELETE i FROM incentives i JOIN tmp_bookings b ON b.id = i.booking_id;
DELETE l FROM loyalty_ledger l JOIN tmp_bookings b ON b.id = l.booking_id;

DELETE b FROM bookings b
JOIN tmp_bookings t ON t.id = b.id;

DELETE n FROM user_notifications n
JOIN tmp_users u ON u.id = n.user_id;

DELETE n FROM notification_deliveries n
JOIN tmp_users u ON u.id = n.user_id;

DELETE p FROM payment_intents p
JOIN tmp_users u ON u.id = p.user_id;

UPDATE wallets w
JOIN tmp_users u ON u.id = w.owner_user_id
SET w.balance_paise = 0,
    w.promo_balance_paise = 0,
    w.frozen_paise = 0,
    w.joining_credit_paise = 0,
    w.joining_credit_used_paise = 0,
    w.joining_credit_limit_paise = 0;

COMMIT;
