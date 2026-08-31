-- 为现有演示营业日补充晚餐，不修改或删除已有订单。
SET @dinner_date = COALESCE(
  (SELECT MAX(service_date) FROM service_sessions WHERE service_date > CURDATE() OR (service_date = CURDATE() AND CURTIME() < '16:30:00')),
  DATE_ADD(CURDATE(), INTERVAL 1 DAY)
);

INSERT INTO service_sessions (
  service_date, meal_type, order_start_at, cutoff_at,
  enabled_delivery, enabled_takeaway, enabled_dine_in, status
) VALUES (
  @dinner_date, 'dinner', NOW(), CONCAT(@dinner_date, ' 16:30:00'),
  1, 1, 1, 'open'
) ON DUPLICATE KEY UPDATE
  enabled_delivery = 1, enabled_takeaway = 1, enabled_dine_in = 1, status = 'open';

SET @dinner_session_id = (
  SELECT id FROM service_sessions WHERE service_date = @dinner_date AND meal_type = 'dinner' LIMIT 1
);

INSERT INTO session_products (session_id, product_id, sale_price, stock, sold_qty, status) VALUES
(@dinner_session_id, 1, 58.00, 50, 0, 'active'),
(@dinner_session_id, 2, 28.00, 50, 0, 'active'),
(@dinner_session_id, 3, 12.00, NULL, 0, 'active'),
(@dinner_session_id, 4, 88.00, 30, 0, 'active')
ON DUPLICATE KEY UPDATE sale_price = VALUES(sale_price), stock = VALUES(stock), status = VALUES(status);

INSERT INTO time_slots (session_id, fulfillment_type, start_time, end_time, capacity, used_capacity, status)
SELECT @dinner_session_id, 'delivery', '17:30:00', '18:30:00', 20, 0, 'active'
WHERE NOT EXISTS (SELECT 1 FROM time_slots WHERE session_id = @dinner_session_id AND fulfillment_type = 'delivery' AND start_time = '17:30:00');
INSERT INTO time_slots (session_id, fulfillment_type, start_time, end_time, capacity, used_capacity, status)
SELECT @dinner_session_id, 'takeaway', '17:30:00', '18:30:00', 30, 0, 'active'
WHERE NOT EXISTS (SELECT 1 FROM time_slots WHERE session_id = @dinner_session_id AND fulfillment_type = 'takeaway' AND start_time = '17:30:00');
INSERT INTO time_slots (session_id, fulfillment_type, start_time, end_time, capacity, used_capacity, status)
SELECT @dinner_session_id, 'dine_in', '17:30:00', '20:30:00', 40, 0, 'active'
WHERE NOT EXISTS (SELECT 1 FROM time_slots WHERE session_id = @dinner_session_id AND fulfillment_type = 'dine_in' AND start_time = '17:30:00');
