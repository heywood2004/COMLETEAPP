USE hotel_management_system;

-- Expand staff roles beyond admin/user
ALTER TABLE users
  MODIFY COLUMN role ENUM(
    'user',
    'receptionist',
    'accountant',
    'manager',
    'admin'
  ) NOT NULL DEFAULT 'user';

-- Optional seed staff (password same pattern as admin: Staff@12345)
-- bcrypt for Staff@12345 - generate at runtime if needed; skip seed passwords for safety
