-- Migration: Add discount_type column to quotations table
-- Run this once on the existing database

USE trias_portal;

ALTER TABLE quotations
    ADD COLUMN IF NOT EXISTS discount_type ENUM('before_gst', 'after_gst') NOT NULL DEFAULT 'after_gst'
    AFTER discount;

-- All existing quotations default to 'after_gst' (preserves original behaviour)
