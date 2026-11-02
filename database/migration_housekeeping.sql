USE hotel_management_system;

-- Housekeeping status on rooms
ALTER TABLE rooms
  ADD COLUMN IF NOT EXISTS housekeeping_status
    ENUM('ready', 'dirty', 'cleaning', 'out_of_order')
    NOT NULL DEFAULT 'ready'
    AFTER description;

ALTER TABLE rooms
  ADD COLUMN IF NOT EXISTS housekeeping_notes VARCHAR(255) NULL AFTER housekeeping_status;

ALTER TABLE rooms
  ADD COLUMN IF NOT EXISTS housekeeping_updated_at TIMESTAMP NULL DEFAULT NULL AFTER housekeeping_notes;

-- Log of status changes (optional audit)
CREATE TABLE IF NOT EXISTS housekeeping_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  room_id INT UNSIGNED NOT NULL,
  old_status VARCHAR(30) NULL,
  new_status VARCHAR(30) NOT NULL,
  notes VARCHAR(255) NULL,
  changed_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_hk_room (room_id),
  CONSTRAINT fk_hk_room FOREIGN KEY (room_id) REFERENCES rooms(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_hk_user FOREIGN KEY (changed_by) REFERENCES users(id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
