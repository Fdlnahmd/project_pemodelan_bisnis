-- Database Structure for ElektroShop Jakarta
-- Create database
CREATE DATABASE elektronik_shop;
USE elektronik_shop;

-- Users table
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    phone VARCHAR(20),
    address TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Categories table
CREATE TABLE categories (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(50) NOT NULL,
    slug VARCHAR(50) UNIQUE NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Products table
CREATE TABLE products (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    price DECIMAL(10,2) NOT NULL,
    stock INT NOT NULL DEFAULT 0,
    category_id INT,
    image VARCHAR(255),
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id)
);

-- Orders table
CREATE TABLE orders (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    order_number VARCHAR(20) UNIQUE NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL,
    delivery_method ENUM('pickup', 'gojek', 'grab') NOT NULL,
    delivery_fee DECIMAL(10,2) DEFAULT 0,
    payment_method ENUM('cod', 'transfer', 'ewallet') NOT NULL,
    status ENUM('pending', 'confirmed', 'preparing', 'shipped', 'delivered', 'cancelled') DEFAULT 'pending',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Order items table
CREATE TABLE order_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id)
);

-- Reviews table
CREATE TABLE reviews (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    rating INT NOT NULL CHECK (rating BETWEEN 1 AND 5),
    comment TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (order_id) REFERENCES orders(id),
    FOREIGN KEY (product_id) REFERENCES products(id)
);

-- Order status history table
CREATE TABLE order_status_history (
    id INT PRIMARY KEY AUTO_INCREMENT,
    order_id INT NOT NULL,
    status VARCHAR(50) NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

-- Shopping cart table (for persistent cart)
CREATE TABLE cart (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_product (user_id, product_id)
);

-- Insert sample categories
INSERT INTO categories (name, slug, description) VALUES
('Resistor', 'resistor', 'Komponen resistor berbagai nilai'),
('Kapasitor', 'capacitor', 'Kapasitor elektrolit dan ceramic'),
('IC', 'ic', 'Integrated Circuit dan mikroprosesor'),
('Sensor', 'sensor', 'Sensor suhu, cahaya, dan lainnya'),
('Arduino', 'arduino', 'Board Arduino dan shield'),
('LED', 'led', 'LED berbagai warna dan ukuran');

-- Insert sample products
INSERT INTO products (name, description, price, stock, category_id, image) VALUES
('Resistor 1K Ohm', 'Resistor karbon 1/4 watt 1000 ohm', 500.00, 100, 1, '⚡'),
('Resistor 10K Ohm', 'Resistor karbon 1/4 watt 10000 ohm', 500.00, 150, 1, '⚡'),
('Kapasitor 100uF', 'Kapasitor elektrolit 100uF 25V', 2000.00, 50, 2, '🔋'),
('Kapasitor 1000uF', 'Kapasitor elektrolit 1000uF 16V', 3500.00, 30, 2, '🔋'),
('IC 555 Timer', 'IC timer 555 DIP-8', 15000.00, 25, 3, '💾'),
('IC LM358', 'Operational Amplifier LM358', 8000.00, 40, 3, '💾'),
('Sensor Suhu DHT22', 'Sensor suhu dan kelembaban digital', 45000.00, 15, 4, '🌡️'),
('Sensor LDR', 'Light Dependent Resistor', 5000.00, 60, 4, '💡'),
('Arduino Uno R3', 'Microcontroller board Arduino Uno R3', 250000.00, 10, 5, '🤖'),
('Arduino Nano', 'Microcontroller board Arduino Nano', 150000.00, 20, 5, '🤖'),
('LED 5mm Merah', 'LED 5mm warna merah', 1000.00, 200, 6, '💡'),
('LED 5mm Biru', 'LED 5mm warna biru', 1000.00, 180, 6, '💡');

-- Insert sample admin user (password: admin123)
INSERT INTO users (name, email, password, phone, address) VALUES
('Admin', 'admin@elektroshop.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '081234567890', 'Jakarta Pusat');

-- Create indexes for better performance
CREATE INDEX idx_products_category ON products(category_id);
CREATE INDEX idx_products_status ON products(status);
CREATE INDEX idx_orders_user ON orders(user_id);
CREATE INDEX idx_orders_status ON orders(status);
CREATE INDEX idx_order_items_order ON order_items(order_id);
CREATE INDEX idx_reviews_product ON reviews(product_id);
CREATE INDEX idx_cart_user ON cart(user_id);