INSERT INTO users (username, password_hash, full_name, role, status) VALUES
('admin', '$2y$10$b4LJlfSWNi8nnwPYK2eV8usZ4rYXzlCfjBcQAIPqOc11Yrvm52O7i', 'System Administrator', 'Administrator', 'active'),
('staff', '$2y$10$b4LJlfSWNi8nnwPYK2eV8usZ4rYXzlCfjBcQAIPqOc11Yrvm52O7i', 'Laundry Staff', 'Staff', 'active');

INSERT INTO customers (full_name, contact_number, address, registration_date) VALUES
('Maria Santos', '09123456789', 'San Vicente, Dagupan', '2025-01-04'),
('Rafael Dela Cruz', '09234567890', 'Luzong, Urdaneta', '2025-02-12'),
('Susan Lim', '09345678901', 'Magsaysay, San Fernando', '2025-03-18');

INSERT INTO services (name, price, unit, status) VALUES
('Wash and Dry', 60.00, 'kg', 'active'),
('Wash, Dry, and Fold', 85.00, 'kg', 'active'),
('Dry Cleaning', 120.00, 'piece', 'active'),
('Ironing', 30.00, 'piece', 'active');

INSERT INTO orders (order_number, customer_id, status, weight_kg, quantity, subtotal, discount, total, special_instructions, estimated_completion, created_by)
VALUES
('LW-2025001', 1, 'Washing', 4.50, 2, 170.00, 10.00, 160.00, 'Handle delicate items carefully.', '2025-11-18', 1),
('LW-2025002', 2, 'Ready for Pickup', 3.00, 1, 95.00, 0.00, 95.00, NULL, '2025-11-17', 2),
('LW-2025003', 3, 'Received', 2.00, 2, 120.00, 0.00, 120.00, 'Use mild detergent.', '2025-11-20', 1);

INSERT INTO order_items (order_id, service_id, quantity, unit_price, line_total) VALUES
(1, 1, 2, 60.00, 120.00),
(2, 2, 1, 85.00, 85.00),
(3, 1, 2, 60.00, 120.00);

INSERT INTO order_status_history (order_id, old_status, new_status, changed_by, notes) VALUES
(1, 'Received', 'Washing', 2, 'Clothes moved to washing machine.'),
(2, 'Drying', 'Ready for Pickup', 1, 'Final quality check passed.'),
(3, 'Received', 'Received', 1, 'Initial job entry.');

INSERT INTO payments (order_id, amount, payment_method, payment_date, notes, created_by) VALUES
(1, 100.00, 'Cash', '2025-11-16 09:15:00', 'Partial payment', 1),
(2, 95.00, 'Cash', '2025-11-17 12:00:00', 'Full payment', 2),
(3, 50.00, 'Cash', '2025-11-18 08:10:00', 'Partial payment', 1);

INSERT INTO notifications (customer_id, title, message, type, is_read) VALUES
(1, 'Laundry Processing', 'Your order is currently being washed.', 'in_system', 0),
(2, 'Ready for Pickup', 'Your laundry is ready for pickup at the counter.', 'in_system', 1),
(3, 'Order Received', 'We have received your laundry service request.', 'in_system', 0);