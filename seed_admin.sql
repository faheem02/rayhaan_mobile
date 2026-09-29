-- Seed default admin user (password: admin123) + categories
USE rayhaan_mobile;

INSERT INTO branches (id, name, address, phone, status, created_at) VALUES
(1, 'RAYHAAN MOBILE KAHUTA', 'Near Ameen Plaza, Kallar Road, Kahuta', '0336-5389945', 1, CURDATE());

INSERT INTO users (username, password, full_name, role, branch_id, status, created_at) VALUES
('admin', 'admin123', 'Administrator', 'admin', 1, 1, CURDATE());

INSERT INTO categories (name, description, product_type, status, created_at, updated_at) VALUES
('Mobile', 'Mobile phones', 'mobile', 1, CURDATE(), CURDATE()),
('Laptop', 'Laptops and computers', 'laptop', 1, CURDATE(), CURDATE()),
('General', 'General products', 'general', 1, CURDATE(), CURDATE());
