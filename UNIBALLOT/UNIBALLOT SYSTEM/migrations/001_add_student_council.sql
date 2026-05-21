-- Migration: Add student_council column to votes table
-- Run this SQL manually in your DB client if preferred.

ALTER TABLE `votes`
ADD COLUMN `student_council` VARCHAR(255) NOT NULL DEFAULT 'abstain' AFTER `auditor`;
