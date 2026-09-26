<?php
// htdocs/config/schema.php

if (!isset($pdo)) {
    return;
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) DEFAULT '', email VARCHAR(150) UNIQUE, phone VARCHAR(20) NULL, password VARCHAR(255) NULL, role VARCHAR(20) DEFAULT 'customer', specialization VARCHAR(120) NULL, availability VARCHAR(20) DEFAULT 'available', kyc_status VARCHAR(20) DEFAULT 'pending', is_banned TINYINT(1) DEFAULT 0, area VARCHAR(120) NULL, address TEXT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS categories (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) DEFAULT '', icon VARCHAR(100) NULL, description TEXT NULL, base_price DECIMAL(10,2) DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS bookings (id INT AUTO_INCREMENT PRIMARY KEY, booking_ref VARCHAR(50) NULL, user_id INT NULL, mechanic_id INT NULL, category_id INT NULL, service_type VARCHAR(120) NULL, booking_date DATE NULL, booking_time TIME NULL, status VARCHAR(60) DEFAULT 'Pending', area VARCHAR(120) NULL, address TEXT NULL, notes TEXT NULL, amount DECIMAL(10,2) DEFAULT 0, mechanic_earning DECIMAL(10,2) DEFAULT 0, admin_commission DECIMAL(10,2) DEFAULT 0, commission_settled TINYINT(1) DEFAULT 0, settled_at DATETIME NULL, assigned_at DATETIME NULL, accepted_at DATETIME NULL, started_at DATETIME NULL, completed_at DATETIME NULL, broadcast_at DATETIME NULL, completion_otp VARCHAR(10) NULL, completion_proof VARCHAR(255) NULL, completion_notes TEXT NULL, mechanic_profit DECIMAL(10,2) DEFAULT 0, admin_verified_at DATETIME NULL, otp_verified TINYINT(1) DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (setting_key VARCHAR(100) PRIMARY KEY, setting_value TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS booking_history (id INT AUTO_INCREMENT PRIMARY KEY, booking_id INT NOT NULL, status_updated_to VARCHAR(100) NOT NULL, proof_file_path VARCHAR(255) NULL, notes TEXT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS payments (id INT AUTO_INCREMENT PRIMARY KEY, booking_id INT NOT NULL, user_id INT NOT NULL, amount DECIMAL(10,2) NOT NULL, status VARCHAR(50) DEFAULT 'pending', transaction_id VARCHAR(100) NULL, payment_method VARCHAR(50) NULL, proof_file VARCHAR(255) NULL, admin_verified TINYINT(1) DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS admins (id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(50) UNIQUE NOT NULL, email VARCHAR(100) UNIQUE NOT NULL, password VARCHAR(255) NOT NULL, role VARCHAR(20) DEFAULT 'superadmin', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_activity_logs (id INT AUTO_INCREMENT PRIMARY KEY, admin_id INT NULL, action VARCHAR(255) NOT NULL, ip_address VARCHAR(45) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS commission_settlements (id INT AUTO_INCREMENT PRIMARY KEY, mechanic_id INT NOT NULL, amount DECIMAL(10,2) NOT NULL, status VARCHAR(50) DEFAULT 'pending', proof_file VARCHAR(255) NULL, transaction_ref VARCHAR(100) NULL, processed_at DATETIME NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS financial_logs (id INT AUTO_INCREMENT PRIMARY KEY, type VARCHAR(50) NOT NULL, amount DECIMAL(10,2) NOT NULL, reference_id INT NULL, description TEXT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

    // Create default admin if not exists
    $stmt = $pdo->query("SELECT COUNT(*) FROM admins");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO admins (username, email, password) VALUES ('admin', 'admin@example.com', '" . password_hash('admin123', PASSWORD_DEFAULT) . "')");
    }
} catch (PDOException $e) {
    // Ignore if tables already exist or fail
}
?>
