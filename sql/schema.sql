-- ============================================================
-- Trias Partner Portal - Database Schema
-- ============================================================

CREATE DATABASE IF NOT EXISTS trias_portal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE trias_portal;

-- Users (Admin, Finance, Telecall)
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'finance', 'telecall') DEFAULT 'telecall',
    phone VARCHAR(20),
    status ENUM('active', 'inactive') DEFAULT 'active',
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Regions
CREATE TABLE regions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(20),
    status ENUM('active', 'inactive') DEFAULT 'active'
);

-- Projects
CREATE TABLE projects (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(200) NOT NULL,
    description TEXT,
    logo VARCHAR(255),
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Plans (linked to Project + Region)
CREATE TABLE plans (
    id INT PRIMARY KEY AUTO_INCREMENT,
    project_id INT NOT NULL,
    region_id INT,
    name VARCHAR(200) NOT NULL,
    price DECIMAL(10,2) DEFAULT 0.00,
    duration_months INT DEFAULT 12,
    features TEXT,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE SET NULL
);

-- Leads
CREATE TABLE leads (
    id INT PRIMARY KEY AUTO_INCREMENT,
    lead_code VARCHAR(30) UNIQUE,
    project_id INT NOT NULL,
    region_id INT,
    name VARCHAR(200) NOT NULL,
    email VARCHAR(100),
    phone VARCHAR(20) NOT NULL,
    company VARCHAR(200),
    designation VARCHAR(100),
    source ENUM('website','referral','social_media','cold_call','email','exhibition','other') DEFAULT 'other',
    status ENUM('new','contacted','interested','not_interested','follow_up','converted') DEFAULT 'new',
    assigned_to INT,
    interested_plan_id INT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id),
    FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE SET NULL,
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (interested_plan_id) REFERENCES plans(id) ON DELETE SET NULL
);

-- Lead Response Feeding
CREATE TABLE lead_responses (
    id INT PRIMARY KEY AUTO_INCREMENT,
    lead_id INT NOT NULL,
    response_type ENUM('call','email','meeting','whatsapp','other') DEFAULT 'call',
    response TEXT NOT NULL,
    response_by INT NOT NULL,
    next_followup DATE,
    status_updated ENUM('new','contacted','interested','not_interested','follow_up','converted') NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
    FOREIGN KEY (response_by) REFERENCES users(id)
);

-- Clients (converted from leads)
CREATE TABLE clients (
    id INT PRIMARY KEY AUTO_INCREMENT,
    client_code VARCHAR(30) UNIQUE,
    lead_id INT,
    project_id INT NOT NULL,
    plan_id INT,
    region_id INT,
    name VARCHAR(200) NOT NULL,
    email VARCHAR(100),
    phone VARCHAR(20),
    alt_phone VARCHAR(20),
    company VARCHAR(200),
    designation VARCHAR(100),
    address TEXT,
    city VARCHAR(100),
    state VARCHAR(100),
    pincode VARCHAR(10),
    gst_no VARCHAR(20),
    pan_no VARCHAR(20),
    status ENUM('active','inactive','suspended') DEFAULT 'active',
    joined_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE SET NULL,
    FOREIGN KEY (project_id) REFERENCES projects(id),
    FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE SET NULL,
    FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE SET NULL
);

-- Client Settings (key-value store per client)
CREATE TABLE client_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    client_id INT NOT NULL,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_client_setting (client_id, setting_key),
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
);

-- Renewals
CREATE TABLE renewals (
    id INT PRIMARY KEY AUTO_INCREMENT,
    client_id INT NOT NULL,
    plan_id INT NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    amount DECIMAL(10,2) DEFAULT 0.00,
    status ENUM('active','expired','cancelled') DEFAULT 'active',
    notes TEXT,
    renewed_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    FOREIGN KEY (plan_id) REFERENCES plans(id),
    FOREIGN KEY (renewed_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Invoices
CREATE TABLE invoices (
    id INT PRIMARY KEY AUTO_INCREMENT,
    invoice_no VARCHAR(50) UNIQUE NOT NULL,
    client_id INT NOT NULL,
    renewal_id INT,
    invoice_date DATE NOT NULL,
    due_date DATE NOT NULL,
    subtotal DECIMAL(10,2) DEFAULT 0.00,
    tax_percent DECIMAL(5,2) DEFAULT 18.00,
    tax_amount DECIMAL(10,2) DEFAULT 0.00,
    total_amount DECIMAL(10,2) DEFAULT 0.00,
    paid_amount DECIMAL(10,2) DEFAULT 0.00,
    status ENUM('pending','paid','partial','overdue','cancelled') DEFAULT 'pending',
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    FOREIGN KEY (renewal_id) REFERENCES renewals(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Payments
CREATE TABLE payments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    invoice_id INT NOT NULL,
    client_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_date DATE NOT NULL,
    payment_mode ENUM('cash','cheque','neft','rtgs','upi','card','other') DEFAULT 'upi',
    transaction_id VARCHAR(100),
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- ============================================================
-- Seed Data
-- ============================================================

-- Default Admin user (password: Admin@123)
INSERT INTO users (name, email, password, role) VALUES
('Super Admin', 'admin@trias.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin'),
('Finance Manager', 'finance@trias.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'finance'),
('Telecall Agent', 'telecall@trias.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'telecall');

-- Note: Default password is 'password' for all seed users. Change immediately after first login.

-- Regions
INSERT INTO regions (name, code) VALUES
('North India', 'NORTH'), ('South India', 'SOUTH'),
('East India', 'EAST'), ('West India', 'WEST'),
('Central India', 'CENTRAL'), ('North East', 'NE');

-- Sample Projects
INSERT INTO projects (name, description, status) VALUES
('SEO Package', 'Search Engine Optimization services for businesses', 'active'),
('Social Media Marketing', 'Complete social media management and marketing', 'active'),
('Website Development', 'Custom website design and development', 'active');

-- Sample Plans
INSERT INTO plans (project_id, region_id, name, price, duration_months, features) VALUES
(1, 1, 'SEO Starter North', 5000.00, 6, 'On-page SEO, 10 Keywords, Monthly Report'),
(1, 2, 'SEO Starter South', 5000.00, 6, 'On-page SEO, 10 Keywords, Monthly Report'),
(1, NULL, 'SEO Pro (All India)', 12000.00, 12, 'On+Off page SEO, 30 Keywords, Weekly Report, Link Building'),
(2, NULL, 'Social Basic', 8000.00, 6, '3 Platforms, 15 posts/month, Basic Analytics'),
(2, NULL, 'Social Premium', 15000.00, 12, '5 Platforms, 30 posts/month, Ads Management'),
(3, NULL, 'Website Starter', 25000.00, 0, '5 Page Website, Responsive, SEO Ready');
