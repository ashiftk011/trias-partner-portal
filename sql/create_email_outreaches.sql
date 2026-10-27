-- ============================================================
-- Email Outreach Module Schema Migration
-- ============================================================

CREATE TABLE IF NOT EXISTS email_outreaches (
    id INT PRIMARY KEY AUTO_INCREMENT,
    outreach_code VARCHAR(30) UNIQUE NOT NULL,
    project_id INT NOT NULL,
    contact_name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL,
    phone VARCHAR(30) NULL,
    company VARCHAR(200) NULL,
    designation VARCHAR(100) NULL,
    website VARCHAR(255) NULL,
    linkedin_url VARCHAR(255) NULL,
    country VARCHAR(100) NULL,
    city VARCHAR(100) NULL,
    campaign_name VARCHAR(150) NULL,
    email_subject VARCHAR(255) NULL,
    outreach_date DATE NOT NULL,
    first_sent_at DATETIME NULL,
    followup_count INT NOT NULL DEFAULT 0,
    last_followup_date DATE NULL,
    status ENUM('sent', 'replied', 'bounced', 'not_interested', 'converted') NOT NULL DEFAULT 'sent',
    response_type ENUM('none', 'meeting_scheduled', 'demo_requested', 'interested_more_info', 'pricing_inquiry', 'call_back_later', 'referral', 'not_interested', 'other') NOT NULL DEFAULT 'none',
    response_summary TEXT NULL,
    replied_at DATETIME NULL,
    meeting_date DATETIME NULL,
    next_followup_date DATE NULL,
    converted_lead_id INT NULL,
    notes TEXT NULL,
    assigned_to INT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_outreach_project (project_id),
    INDEX idx_outreach_status (status),
    INDEX idx_outreach_response (response_type),
    INDEX idx_outreach_email (email),
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (converted_lead_id) REFERENCES leads(id) ON DELETE SET NULL,
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_outreach_activities (
    id INT PRIMARY KEY AUTO_INCREMENT,
    outreach_id INT NOT NULL,
    activity_type ENUM('initial_email', 'follow_up_sent', 'reply_received', 'call', 'meeting_booked', 'demo_booked', 'note', 'converted_to_lead') NOT NULL,
    title VARCHAR(255) NOT NULL,
    notes TEXT NULL,
    scheduled_at DATETIME NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_activity_outreach (outreach_id),
    FOREIGN KEY (outreach_id) REFERENCES email_outreaches(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add outreach_id to leads table if it doesn't exist
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND COLUMN_NAME = 'outreach_id');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE leads ADD COLUMN outreach_id INT NULL DEFAULT NULL AFTER meta_lead_id', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
