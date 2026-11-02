USE hotel_management_system;

CREATE TABLE IF NOT EXISTS inventory_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  category ENUM('linen','amenity','cleaning','minibar','maintenance','other')
    NOT NULL DEFAULT 'other',
  unit VARCHAR(30) NOT NULL DEFAULT 'pcs',
  quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
  reorder_level DECIMAL(12,2) NOT NULL DEFAULT 0,
  unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  notes VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_inventory_name (name),
  INDEX idx_inv_category (category),
  INDEX idx_inv_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_transactions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id INT UNSIGNED NOT NULL,
  room_id INT UNSIGNED NULL,
  booking_id INT UNSIGNED NULL,
  txn_type ENUM('in','out','adjust','consume') NOT NULL,
  quantity DECIMAL(12,2) NOT NULL,
  balance_after DECIMAL(12,2) NOT NULL,
  reference VARCHAR(100) NULL,
  notes VARCHAR(255) NULL,
  recorded_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_inv_txn_item (item_id),
  INDEX idx_inv_txn_room (room_id),
  CONSTRAINT fk_inv_txn_item FOREIGN KEY (item_id) REFERENCES inventory_items(id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_inv_txn_room FOREIGN KEY (room_id) REFERENCES rooms(id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_inv_txn_user FOREIGN KEY (recorded_by) REFERENCES users(id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Standard quantities used when a room is made ready (by category defaults)
CREATE TABLE IF NOT EXISTS room_setup_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id INT UNSIGNED NOT NULL,
  room_type VARCHAR(50) NULL,          -- NULL = all room types
  quantity_per_room DECIMAL(12,2) NOT NULL DEFAULT 1,
  auto_consume_on ENUM('ready','cleaning','checkout') NOT NULL DEFAULT 'ready',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_setup_item FOREIGN KEY (item_id) REFERENCES inventory_items(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  INDEX idx_setup_type (room_type),
  INDEX idx_setup_when (auto_consume_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed common hotel supplies
INSERT INTO inventory_items (name, category, unit, quantity, reorder_level, unit_cost) VALUES
('Bath Towel', 'linen', 'pcs', 80, 20, 450),
('Hand Towel', 'linen', 'pcs', 100, 25, 200),
('Bed Sheet (Double)', 'linen', 'pcs', 60, 15, 800),
('Pillow Case', 'linen', 'pcs', 120, 30, 150),
('Toilet Paper Roll', 'amenity', 'pcs', 200, 40, 50),
('Soap Bar', 'amenity', 'pcs', 150, 30, 30),
('Shampoo Bottle', 'amenity', 'pcs', 100, 20, 80),
('Bottled Water', 'minibar', 'pcs', 120, 30, 50),
('All-Purpose Cleaner', 'cleaning', 'bottles', 25, 5, 350),
('Trash Bag', 'cleaning', 'pcs', 200, 50, 15)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Link some items to auto-consume when room marked Ready
INSERT INTO room_setup_items (item_id, room_type, quantity_per_room, auto_consume_on)
SELECT id, NULL, 2, 'ready' FROM inventory_items WHERE name = 'Bath Towel' LIMIT 1
ON DUPLICATE KEY UPDATE quantity_per_room = VALUES(quantity_per_room);

INSERT INTO room_setup_items (item_id, room_type, quantity_per_room, auto_consume_on)
SELECT id, NULL, 1, 'ready' FROM inventory_items WHERE name = 'Toilet Paper Roll' LIMIT 1;

INSERT INTO room_setup_items (item_id, room_type, quantity_per_room, auto_consume_on)
SELECT id, NULL, 1, 'ready' FROM inventory_items WHERE name = 'Soap Bar' LIMIT 1;

INSERT INTO room_setup_items (item_id, room_type, quantity_per_room, auto_consume_on)
SELECT id, NULL, 1, 'cleaning' FROM inventory_items WHERE name = 'Trash Bag' LIMIT 1;

INSERT INTO room_setup_items (item_id, room_type, quantity_per_room, auto_consume_on)
SELECT id, NULL, 0.1, 'cleaning' FROM inventory_items WHERE name = 'All-Purpose Cleaner' LIMIT 1;
