-- QR Attendance Check-in migration
-- Adds a unique attendance token per registration + attended_at timestamp.

ALTER TABLE `registrations`
  ADD COLUMN `attendance_token` char(32) DEFAULT NULL AFTER `confirmed_at`,
  ADD COLUMN `attended_at` datetime DEFAULT NULL AFTER `attendance_token`,
  ADD UNIQUE KEY `uq_attendance_token` (`attendance_token`);

-- Generate tokens for existing confirmed registrations
UPDATE `registrations`
SET `attendance_token` = REPLACE(UUID(), '-', '')
WHERE `status` = 'registered'
  AND `attendance_token` IS NULL;
