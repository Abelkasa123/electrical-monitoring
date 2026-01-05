-- User Settings Table
CREATE TABLE user_settings (
    id INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    email_notifications TINYINT DEFAULT 0,
    daily_report TINYINT DEFAULT 0,
    voltage_alerts TINYINT DEFAULT 1,
    current_alerts TINYINT DEFAULT 1,
    frequency_alerts TINYINT DEFAULT 1,
    report_email VARCHAR(255),
    alert_threshold_voltage DECIMAL(5,2) DEFAULT 10.00,
    alert_threshold_current DECIMAL(5,2) DEFAULT 20.00,
    alert_threshold_frequency DECIMAL(4,2) DEFAULT 0.50,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user (user_id)
) ENGINE=InnoDB;

-- Report Logs Table
CREATE TABLE report_logs (
    id INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    report_type VARCHAR(50),
    recipient_email VARCHAR(255),
    start_date DATE,
    end_date DATE,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status VARCHAR(20) DEFAULT 'sent',
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Email Templates Table (Optional)
CREATE TABLE email_templates (
    id INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
    template_name VARCHAR(100),
    subject VARCHAR(255),
    body TEXT,
    is_active TINYINT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Insert default email templates
INSERT INTO email_templates (template_name, subject, body) VALUES
('Daily Report', 'Daily Electrical Load Report - {date}', '<h2>Daily Electrical Load Monitoring Report</h2><p>Date: {date}</p><p>Please find attached the daily electrical load report.</p>'),
('Weekly Report', 'Weekly Electrical Load Analysis - Week {week_number}', '<h2>Weekly Electrical Load Analysis</h2><p>Week: {week_number}</p><p>Please find attached the weekly electrical load analysis.</p>'),
('Alert Notification', 'ALERT: Electrical Parameter Out of Range', '<h2>Electrical Alert Notification</h2><p>An electrical parameter has exceeded the defined threshold.</p><p>Please check the system immediately.</p>');