CREATE TABLE users (
    id INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(100),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE electrical_data (
    id INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    voltage DECIMAL(10,2),
    current DECIMAL(10,2),
    active_power DECIMAL(10,2),
    reactive_power DECIMAL(10,2),
    frequency DECIMAL(5,2),
    power_factor DECIMAL(4,3),
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_ (timestamp),
    INDEX idx_user_timestamp (user_id, timestamp)
) ENGINE=InnoDB;

-- Add missing columns to existing users table
ALTER TABLE users 
ADD COLUMN IF NOT EXISTS full_name VARCHAR(100) AFTER password,
ADD COLUMN IF NOT EXISTS email VARCHAR(100) AFTER full_name,
ADD COLUMN IF NOT EXISTS role ENUM('admin', 'manager', 'operator') DEFAULT 'operator' AFTER email,
ADD COLUMN IF NOT EXISTS status ENUM('active', 'inactive', 'suspended') DEFAULT 'active' AFTER role,
ADD COLUMN IF NOT EXISTS created_by INT DEFAULT NULL AFTER status,
ADD COLUMN IF NOT EXISTS created_at DATETIME DEFAULT CURRENT_TIMESTAMP AFTER created_by,
ADD COLUMN IF NOT EXISTS last_login DATETIME AFTER created_at;

-- Update existing users with default values
UPDATE users SET 
    full_name = username,
    email = CONCAT(username, '@example.com'),
    role = CASE 
        WHEN username = 'admin' THEN 'admin'
        ELSE 'operator'
    END,
    status = 'active'
WHERE full_name IS NULL OR email IS NULL OR role IS NULL;
-- Users table (for login)
CREATE TABLE IF NOT EXISTS users (
    id INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(100),
    full_name VARCHAR(100),
    role ENUM('admin', 'user', 'operator') DEFAULT 'user',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_login DATETIME,
    status ENUM('active', 'inactive', 'suspended') DEFAULT 'active'
) ENGINE=InnoDB;

-- User settings table (this was missing)
CREATE TABLE IF NOT EXISTS user_settings (
    id INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    report_email VARCHAR(100),
    report_frequency ENUM('daily', 'weekly', 'monthly') DEFAULT 'daily',
    voltage_min DECIMAL(10,2) DEFAULT 200,
    voltage_max DECIMAL(10,2) DEFAULT 250,
    current_max DECIMAL(10,2) DEFAULT 100,
    frequency_min DECIMAL(5,2) DEFAULT 49,
    frequency_max DECIMAL(5,2) DEFAULT 51,
    alert_threshold INT DEFAULT 90,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user (user_id)
) ENGINE=InnoDB;

-- Activity logs table
CREATE TABLE IF NOT EXISTS activity_logs (
    id INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    action VARCHAR(50) NOT NULL,
    details TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at  DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_action (user_id, action),
    INDEX idx_created (created_at)
) ENGINE=InnoDB;

-- Reports table
CREATE TABLE IF NOT EXISTS reports (
    id INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    report_type ENUM('daily', 'weekly', 'monthly', 'custom') NOT NULL,
    report_name VARCHAR(100) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    generated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    file_path VARCHAR(255),
    parameters TEXT,
    status ENUM('pending', 'generating', 'completed', 'failed') DEFAULT 'pending',
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_type (user_id, report_type),
    INDEX idx_date_range (start_date, end_date)
) ENGINE=InnoDB;

-- Alerts table
CREATE TABLE IF NOT EXISTS alerts (
    id INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    alert_type ENUM('voltage', 'current', 'power', 'frequency') NOT NULL,
    threshold DECIMAL(10,2) NOT NULL,
    actual_value DECIMAL(10,2) NOT NULL,
    message TEXT NOT NULL,
    severity ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
    acknowledged BOOLEAN DEFAULT FALSE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    acknowledged_at DATETIME,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_alert (user_id, alert_type),
    INDEX idx_severity (severity),
    INDEX idx_created (created_at)
) ENGINE=InnoDB;

-- Default user settings for existing users
INSERT INTO user_settings (user_id, report_email, report_frequency)
SELECT id, email, 'daily'
FROM users
WHERE NOT EXISTS (
    SELECT 1 FROM user_settings WHERE user_settings.user_id = users.id
);

-- Insert a default admin user if not exists
INSERT INTO users (username, password, email, full_name, role) 
VALUES (
    'admin', 
    'admin123456', -- Change this with actual hash
    'admin@example.com', 
    'Administrator', 
    'admin'
) ON DUPLICATE KEY UPDATE role='admin';

-- Add trigger to create user_settings automatically when new user is created
DELIMITER $$
CREATE TRIGGER after_user_insert
AFTER INSERT ON users
FOR EACH ROW
BEGIN
    INSERT INTO user_settings (user_id, report_email)
    VALUES (NEW.id, NEW.email);
END$$
DELIMITER ;
-- Update electrical_data table for high voltage support
ALTER TABLE electrical_data 
MODIFY voltage DECIMAL(12,2),
MODIFY current DECIMAL(10,2),
ADD COLUMN voltage_level ENUM('low', 'medium', 'high', 'extra_high') DEFAULT 'low',
ADD COLUMN line_voltage DECIMAL(12,2),
ADD COLUMN phase_current DECIMAL(10,2),
ADD COLUMN line_current DECIMAL(10,2),
ADD COLUMN power_factor_correction DECIMAL(4,3),
ADD COLUMN harmonics DECIMAL(6,2),
ADD COLUMN transformer_ratio DECIMAL(8,2),
ADD COLUMN insulation_resistance DECIMAL(10,2);

-- Add index for voltage level
CREATE INDEX idx_voltage_level ON electrical_data(voltage_level);

-- Create voltage level settings table
CREATE TABLE IF NOT EXISTS voltage_level_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    voltage_level ENUM('low', 'medium', 'high', 'extra_high') NOT NULL,
    min_voltage DECIMAL(12,2) NOT NULL,
    max_voltage DECIMAL(12,2) NOT NULL,
    min_current DECIMAL(10,2) NOT NULL,
    max_current DECIMAL(10,2) NOT NULL,
    frequency_range VARCHAR(50),
    power_factor_range VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_voltage (user_id, voltage_level)
);

-- Insert default voltage level settings
INSERT INTO voltage_level_settings (user_id, voltage_level, min_voltage, max_voltage, min_current, max_current) VALUES
(1, 'low', 110, 1000, 0.1, 100),
(1, 'medium', 1001, 35000, 0.1, 500),
(1, 'high', 35001, 230000, 0.1, 1000),
(1, 'extra_high', 230001, 500000, 0.1, 2000);

-- Update users table to add roles and permissions
ALTER TABLE users 
ADD COLUMN role ENUM('admin', 'manager', 'operator') DEFAULT 'operator',
ADD COLUMN permissions TEXT,
ADD COLUMN status ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
ADD COLUMN last_login DATETIME,
ADD COLUMN created_by INT DEFAULT NULL,
ADD FOREIGN KEY (created_by) REFERENCES users(id);

-- Create user_roles table for additional permissions
CREATE TABLE IF NOT EXISTS user_roles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    role_name VARCHAR(50) UNIQUE NOT NULL,
    description TEXT,
    permissions JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create role_permissions table
CREATE TABLE IF NOT EXISTS role_permissions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    role_id INT,
    permission_key VARCHAR(100) NOT NULL,
    permission_value BOOLEAN DEFAULT FALSE,
    FOREIGN KEY (role_id) REFERENCES user_roles(id) ON DELETE CASCADE
);

-- Insert default roles
INSERT INTO user_roles (role_name, description, permissions) VALUES
('admin', 'System Administrator', '{"dashboard":true,"data_entry":true,"view_data":true,"edit_data":true,"delete_data":true,"reports":true,"summaries":true,"import_export":true,"user_management":true,"settings":true,"approve_reject":true}'),
('manager', 'Manager/Supervisor', '{"dashboard":true,"data_entry":false,"view_data":true,"edit_data":false,"delete_data":false,"reports":true,"summaries":true,"import_export":false,"user_management":false,"settings":false,"approve_reject":true}'),
('operator', 'Data Entry Operator', '{"dashboard":true,"data_entry":true,"view_data":true,"edit_data":true,"delete_data":true,"reports":false,"summaries":false,"import_export":false,"user_management":false,"settings":false,"approve_reject":false}');

-- Update existing users with default role (set first user as admin)
UPDATE users SET role = 'admin' WHERE id = 1;
UPDATE users SET role = 'operator' WHERE role IS NULL OR role = '';

-- Create approval_logs table for manager approvals
CREATE TABLE IF NOT EXISTS approval_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    record_id INT NOT NULL,
    user_id INT NOT NULL,
    action ENUM('approve', 'reject', 'modify') NOT NULL,
    comments TEXT,
    status ENUM('pending', 'approved', 'rejected', 'modified') DEFAULT 'pending',
    approved_by INT,
    approved_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (record_id) REFERENCES electrical_data(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Add approval_status column to electrical_data table
ALTER TABLE electrical_data 
ADD COLUMN approval_status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
ADD COLUMN approved_by INT DEFAULT NULL,
ADD COLUMN approved_at DATETIME DEFAULT NULL,
ADD FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL;

-- Create audit_logs table for tracking all actions
CREATE TABLE IF NOT EXISTS audit_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    action VARCHAR(100) NOT NULL,
    table_name VARCHAR(50) NOT NULL,
    record_id INT,
    old_values JSON,
    new_values JSON,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);