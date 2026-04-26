-- Buat Database
CREATE DATABASE IF NOT EXISTS saas_pos;
USE saas_pos;

-- Tabel Users (Superadmin & Tenant)
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('superadmin', 'tenant') NOT NULL,
    status ENUM('pending', 'active', 'banned') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabel Tenants (Profil Toko)
CREATE TABLE tenants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    shop_name VARCHAR(150) NOT NULL,
    qris_image VARCHAR(255) DEFAULT NULL,
    bank_info TEXT DEFAULT NULL,
    is_delivery TINYINT(1) DEFAULT 1,
    is_pickup TINYINT(1) DEFAULT 1,
    is_dinein TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Tabel Products (Katalog Menu)
CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    price DECIMAL(12,2) NOT NULL,
    stock INT NOT NULL DEFAULT 0,
    image VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

-- Tabel Orders (Pesanan Online & POS)
CREATE TABLE orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    invoice_no VARCHAR(50) NOT NULL UNIQUE,
    order_type ENUM('delivery', 'pickup', 'dinein', 'pos') NOT NULL,
    status ENUM('pending', 'paid', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
    total DECIMAL(12,2) NOT NULL,
    payment_method ENUM('qris', 'bank', 'cash') NOT NULL,
    payment_proof VARCHAR(255) DEFAULT NULL,
    customer_name VARCHAR(100) DEFAULT NULL,
    customer_phone VARCHAR(20) DEFAULT NULL,
    address_table_no TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

-- Tabel Order Items (Detail Pesanan)
CREATE TABLE order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    qty INT NOT NULL,
    price DECIMAL(12,2) NOT NULL,
    notes TEXT DEFAULT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

-- INSERT Default Superadmin (Username: superadmin, Password: password123)
INSERT INTO users (username, password, role, status) 
VALUES ('superadmin', '$2y$10$WkL6T6o52k8Xw3D2B8O.UeaH/N3VvS.K/eT1g9g.L3P.J.XzBfJ4a', 'superadmin', 'active');