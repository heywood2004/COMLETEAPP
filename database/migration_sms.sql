USE hotel_management_system;

-- Optional phone on user accounts (for SMS)
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS phone VARCHAR(20) NULL AFTER email;

ALTER TABLE users
  ADD INDEX IF NOT EXISTS idx_users_phone (phone);
