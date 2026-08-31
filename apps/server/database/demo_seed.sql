-- 仅用于本地开发。首次登录后请立即修改密码。
-- admin / Admin@123456
INSERT INTO admin_users (username, password_hash, role_code, status)
VALUES ('admin', '$2y$12$7grZeI6QCwWjAIm6OsElo.rloJHkDo0iMBipW6z0MQHMgUZJ8CLjm', 'super_admin', 'active')
ON DUPLICATE KEY UPDATE role_code = VALUES(role_code), status = VALUES(status);

INSERT INTO categories (id, name, sort_order, status) VALUES
(1, '主菜', 10, 'active'), (2, '汤品', 20, 'active'), (3, '饮品', 30, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), sort_order = VALUES(sort_order), status = VALUES(status);

INSERT INTO products (id, type, category_id, name, description, base_price, sort_order, status) VALUES
(1, 'dish', 1, '匈牙利牛肉烩饭', '经典牛肉、彩椒与香料烩饭', 58.00, 10, 'active'),
(2, 'dish', 2, '古拉什汤', '传统匈牙利风味', 28.00, 20, 'active'),
(3, 'dish', 3, '自制柠檬水', '每日鲜制', 12.00, 30, 'active'),
(4, 'package', 1, '布达佩斯经典套餐', '主菜、汤品和饮品组合', 88.00, 1, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), base_price = VALUES(base_price), status = VALUES(status);

INSERT INTO package_items (package_id, product_id, quantity) VALUES (4, 1, 1), (4, 2, 1), (4, 3, 1)
ON DUPLICATE KEY UPDATE quantity = VALUES(quantity);

INSERT INTO delivery_zones (id, name, delivery_fee, min_order_amount, status) VALUES
(1, '市中心配送区', 8.00, 50.00, 'active')
ON DUPLICATE KEY UPDATE delivery_fee = VALUES(delivery_fee), min_order_amount = VALUES(min_order_amount), status = VALUES(status);

INSERT INTO dining_tables (id, table_no, capacity, status) VALUES
(1, 'A01', 2, 'active'), (2, 'A02', 4, 'active'), (3, 'B01', 6, 'active')
ON DUPLICATE KEY UPDATE capacity = VALUES(capacity), status = VALUES(status);

SET @service_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY);
INSERT INTO service_sessions (id, service_date, meal_type, order_start_at, cutoff_at, status)
VALUES (1, @service_date, 'lunch', NOW(), CONCAT(@service_date, ' 10:30:00'), 'open')
ON DUPLICATE KEY UPDATE order_start_at = VALUES(order_start_at), cutoff_at = VALUES(cutoff_at), status = VALUES(status);

INSERT INTO session_products (session_id, product_id, sale_price, stock, sold_qty, status) VALUES
(1, 1, 58.00, 50, 0, 'active'), (1, 2, 28.00, 50, 0, 'active'),
(1, 3, 12.00, NULL, 0, 'active'), (1, 4, 88.00, 30, 0, 'active')
ON DUPLICATE KEY UPDATE sale_price = VALUES(sale_price), stock = VALUES(stock), status = VALUES(status);

INSERT INTO time_slots (id, session_id, fulfillment_type, start_time, end_time, capacity, used_capacity, status) VALUES
(1, 1, 'delivery', '11:30:00', '12:00:00', 20, 0, 'active'),
(2, 1, 'takeaway', '11:30:00', '12:00:00', 30, 0, 'active'),
(3, 1, 'dine_in', '11:30:00', '13:00:00', 40, 0, 'active')
ON DUPLICATE KEY UPDATE capacity = VALUES(capacity), status = VALUES(status);

UPDATE categories SET name_en='Mains', name_hu='Főételek' WHERE id=1;
UPDATE categories SET name_en='Soups', name_hu='Levesek' WHERE id=2;
UPDATE categories SET name_en='Drinks', name_hu='Italok' WHERE id=3;
UPDATE products SET name_en='Hungarian Beef Rice', name_hu='Magyar marhahúsos rizs', description_en='Classic beef, peppers and spiced rice', description_hu='Klasszikus marhahús, paprika és fűszeres rizs' WHERE id=1;
UPDATE products SET name_en='Goulash Soup', name_hu='Gulyásleves', description_en='Traditional Hungarian flavour', description_hu='Hagyományos magyar ízek' WHERE id=2;
UPDATE products SET name_en='Homemade Lemonade', name_hu='Házi limonádé', description_en='Made fresh daily', description_hu='Naponta frissen készítve' WHERE id=3;
UPDATE products SET name_en='Budapest Classic Set', name_hu='Budapest klasszikus menü', description_en='Main, soup and drink', description_hu='Főétel, leves és ital' WHERE id=4;
