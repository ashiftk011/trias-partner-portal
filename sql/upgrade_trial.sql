-- Database migration: Add Trial options for leads

USE trias_portal;

-- Update ENUM status in leads and lead_responses
ALTER TABLE leads MODIFY COLUMN status ENUM('new','contacted','interested','not_interested','follow_up','converted','trial','trial_ended') DEFAULT 'new';
ALTER TABLE lead_responses MODIFY COLUMN status_updated ENUM('new','contacted','interested','not_interested','follow_up','converted','trial','trial_ended') NULL;

-- Add trial fields to leads
ALTER TABLE leads ADD COLUMN IF NOT EXISTS trial_end_date DATE NULL AFTER interested_plan_id;
ALTER TABLE leads ADD COLUMN IF NOT EXISTS trial_login_url VARCHAR(255) NULL AFTER trial_end_date;
ALTER TABLE leads ADD COLUMN IF NOT EXISTS trial_username VARCHAR(100) NULL AFTER trial_login_url;
ALTER TABLE leads ADD COLUMN IF NOT EXISTS trial_password VARCHAR(100) NULL AFTER trial_username;
