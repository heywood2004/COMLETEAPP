-- =========================================================
-- HOTEL MANAGEMENT SYSTEM
-- COMPLETE DATABASE
-- =========================================================
--
-- Default Administrator Account
--
-- Email:    admin@hotel.com
-- Password: Admin@12345
--
-- IMPORTANT:
-- The password is stored as a bcrypt hash.
-- =========================================================



-- =========================================================
-- CREATE DATABASE
-- =========================================================


CREATE DATABASE IF NOT EXISTS hotel_management_system
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;



USE hotel_management_system;



-- =========================================================
-- DROP EXISTING TABLES
-- =========================================================
--
-- WARNING:
-- Importing this file will DELETE existing users,
-- bookings and rooms before recreating them.
--
-- Do NOT import this file on a database whose data
-- you want to preserve.
-- =========================================================


SET FOREIGN_KEY_CHECKS = 0;


DROP TABLE IF EXISTS payments;
DROP TABLE IF EXISTS invoice_items;
DROP TABLE IF EXISTS invoices;
DROP TABLE IF EXISTS bookings;
DROP TABLE IF EXISTS guests;
DROP TABLE IF EXISTS rooms;
DROP TABLE IF EXISTS hotel_media;
DROP TABLE IF EXISTS hotel_settings;
DROP TABLE IF EXISTS users;


SET FOREIGN_KEY_CHECKS = 1;



-- =========================================================
-- USERS TABLE
-- =========================================================


CREATE TABLE users (


    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,


    name VARCHAR(100) NOT NULL,


    email VARCHAR(150) NOT NULL UNIQUE,


    password_hash VARCHAR(255) NOT NULL,


    role ENUM('user', 'admin')
        NOT NULL DEFAULT 'user',


    created_at TIMESTAMP
        DEFAULT CURRENT_TIMESTAMP


) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;



-- =========================================================
-- ROOMS TABLE
-- =========================================================


CREATE TABLE rooms (


    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,


    room_number VARCHAR(20) NOT NULL UNIQUE,


    room_type VARCHAR(50) NOT NULL,


    price DECIMAL(10,2) NOT NULL DEFAULT 0.00,


    capacity INT NOT NULL DEFAULT 1,


    description TEXT NULL,


    created_at TIMESTAMP
        DEFAULT CURRENT_TIMESTAMP


) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;



-- =========================================================
-- BOOKINGS TABLE
-- =========================================================


CREATE TABLE bookings (


    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,


    /*
     * Links a booking to the logged-in user.
     *
     * NULL is allowed so that historical bookings
     * can remain when a user account is deleted.
     */


    user_id INT UNSIGNED NULL,


    room_id INT UNSIGNED NOT NULL,


    guest_name VARCHAR(100) NOT NULL,


    check_in DATE NOT NULL,


    check_out DATE NOT NULL,


    status ENUM('active', 'cancelled')
        NOT NULL DEFAULT 'active',


    cancelled_at DATETIME NULL,


    created_at TIMESTAMP
        DEFAULT CURRENT_TIMESTAMP,



    -- =====================================================
    -- USER FOREIGN KEY
    -- =====================================================


    CONSTRAINT fk_bookings_user


        FOREIGN KEY (user_id)


        REFERENCES users(id)


        ON DELETE SET NULL


        ON UPDATE CASCADE,



    -- =====================================================
    -- ROOM FOREIGN KEY
    -- =====================================================


    CONSTRAINT fk_bookings_room


        FOREIGN KEY (room_id)


        REFERENCES rooms(id)


        ON DELETE RESTRICT


        ON UPDATE CASCADE,



    -- =====================================================
    -- INDEXES
    -- =====================================================


    INDEX idx_bookings_user_id (user_id),


    INDEX idx_bookings_room_id (room_id),


    INDEX idx_bookings_status (status),


    INDEX idx_bookings_dates (
        check_in,
        check_out
    )


) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;



-- =========================================================
-- INSERT DEFAULT ADMINISTRATOR
-- =========================================================
--
-- Email:
-- admin@hotel.com
--
-- Password:
-- Admin@12345
--
-- Stored as a bcrypt password hash.
-- =========================================================


INSERT INTO users (
    name,
    email,
    password_hash,
    role
)
VALUES (
    'Hotel Administrator',
    'admin@hotel.com',
    '$2y$12$9vvuJ3RiE7MxfJy2UXXBpeiAVe3V8t1gRNmsoXwhieYQ95PGZl9Zy',
    'admin'
);



-- =========================================================
-- INSERT ROOMS
-- =========================================================


INSERT INTO rooms
(
    room_number,
    room_type,
    price,
    capacity,
    description
)
VALUES


(
    '101',
    'Single',
    3500.00,
    1,
    'Standard single room'
),


(
    '102',
    'Single',
    3500.00,
    1,
    'Standard single room'
),


(
    '103',
    'Single',
    3500.00,
    1,
    'Standard single room'
),


(
    '104',
    'Double',
    5500.00,
    2,
    'Deluxe double room'
),


(
    '105',
    'Double',
    5500.00,
    2,
    'Deluxe double room'
),


(
    '106',
    'Double',
    5500.00,
    2,
    'Deluxe double room'
),


(
    '201',
    'Twin',
    6500.00,
    2,
    'Twin beds room'
),


(
    '202',
    'Twin',
    6500.00,
    2,
    'Twin beds room'
),


(
    '203',
    'Twin',
    6500.00,
    2,
    'Twin beds room'
),


(
    '204',
    'Deluxe',
    8500.00,
    2,
    'Deluxe room'
),


(
    '205',
    'Deluxe',
    8500.00,
    2,
    'Deluxe room'
),


(
    '301',
    'Executive',
    12000.00,
    3,
    'Executive suite'
),


(
    '302',
    'Executive',
    12000.00,
    3,
    'Executive suite'
),


(
    '401',
    'Presidential',
    25000.00,
    4,
    'Presidential suite'
);



-- =========================================================
-- HOTEL MEDIA
-- =========================================================


CREATE TABLE IF NOT EXISTS hotel_media (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    media_key VARCHAR(100) NOT NULL UNIQUE,
    media_type ENUM('room', 'hero') NOT NULL,
    title VARCHAR(150) NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
);


INSERT INTO hotel_media
    (media_key, media_type, title, image_path)
VALUES
    (
        'room_single',
        'room',
        'Single Room',
        'images/hotel-hero-1.jpg'
    ),
    (
        'room_double',
        'room',
        'Double Room',
        'images/hotel-hero-2.jpg'
    ),
    (
        'room_twin',
        'room',
        'Twin Room',
        'images/hotel-hero-3.jpg'
    ),
    (
        'room_deluxe',
        'room',
        'Deluxe Room',
        'images/hotel-hero-4.jpg'
    ),
    (
        'room_executive',
        'room',
        'Executive Room',
        'images/hotel-hero-5.jpg'
    ),
    (
        'room_presidential',
        'room',
        'Presidential Room',
        'images/hotel-hero-6.jpg'
    ),
    (
        'hero_1',
        'hero',
        'Hero Image 1',
        'images/hotel-hero-1.jpg'
    ),
    (
        'hero_2',
        'hero',
        'Hero Image 2',
        'images/hotel-hero-2.jpg'
    ),
    (
        'hero_3',
        'hero',
        'Hero Image 3',
        'images/hotel-hero-3.jpg'
    ),
    (
        'hero_4',
        'hero',
        'Hero Image 4',
        'images/hotel-hero-4.jpg'
    ),
    (
        'hero_5',
        'hero',
        'Hero Image 5',
        'images/hotel-hero-5.jpg'
    ),
    (
        'hero_6',
        'hero',
        'Hero Image 6',
        'images/hotel-hero-6.jpg'
    )
ON DUPLICATE KEY UPDATE
    title = VALUES(title);



-- =========================================================
-- GUESTS
-- =========================================================


CREATE TABLE IF NOT EXISTS guests (


    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,


    full_name VARCHAR(150) NOT NULL,


    phone VARCHAR(30) NULL,


    email VARCHAR(150) NULL,


    id_number VARCHAR(80) NULL,


    address VARCHAR(255) NULL,


    nationality VARCHAR(80) NULL,


    notes TEXT NULL,


    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,


    updated_at TIMESTAMP
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,


    INDEX idx_guest_name (full_name),


    INDEX idx_guest_phone (phone),


    INDEX idx_guest_email (email),


    INDEX idx_guest_id_number (id_number)


) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;




-- =========================================================
-- EXTEND BOOKINGS
-- =========================================================


ALTER TABLE bookings


    ADD COLUMN IF NOT EXISTS guest_id INT UNSIGNED NULL,


    ADD COLUMN IF NOT EXISTS stay_status
        ENUM(
            'not_arrived',
            'checked_in',
            'checked_out'
        )
        NOT NULL DEFAULT 'not_arrived',


    ADD COLUMN IF NOT EXISTS actual_check_in DATETIME NULL,


    ADD COLUMN IF NOT EXISTS actual_check_out DATETIME NULL,


    ADD COLUMN IF NOT EXISTS special_requests TEXT NULL;



ALTER TABLE bookings


    ADD INDEX idx_booking_guest (guest_id);



SET @fk_exists := (
    SELECT COUNT(*)
    FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND CONSTRAINT_NAME = 'fk_bookings_guest'
);


SET @sql := IF(
    @fk_exists = 0,
    'ALTER TABLE bookings
     ADD CONSTRAINT fk_bookings_guest
     FOREIGN KEY (guest_id)
     REFERENCES guests(id)
     ON DELETE SET NULL
     ON UPDATE CASCADE',
    'SELECT 1'
);


PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;




-- =========================================================
-- INVOICES
-- =========================================================


CREATE TABLE IF NOT EXISTS invoices (


    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,


    invoice_number VARCHAR(40) NOT NULL UNIQUE,


    booking_id INT UNSIGNED NOT NULL,


    guest_id INT UNSIGNED NULL,


    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,


    tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,


    discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,


    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,


    amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00,


    balance_due DECIMAL(12,2) NOT NULL DEFAULT 0.00,


    status ENUM(
        'unpaid',
        'partially_paid',
        'paid',
        'cancelled'
    ) NOT NULL DEFAULT 'unpaid',


    issued_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,


    updated_at TIMESTAMP
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,


    UNIQUE KEY uq_invoice_booking (booking_id),


    INDEX idx_invoice_guest (guest_id),


    CONSTRAINT fk_invoice_booking
        FOREIGN KEY (booking_id)
        REFERENCES bookings(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,


    CONSTRAINT fk_invoice_guest
        FOREIGN KEY (guest_id)
        REFERENCES guests(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE


) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;




-- =========================================================
-- INVOICE ITEMS
-- =========================================================


CREATE TABLE IF NOT EXISTS invoice_items (


    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,


    invoice_id INT UNSIGNED NOT NULL,


    item_type ENUM(
        'room',
        'service',
        'tax',
        'discount',
        'other'
    ) NOT NULL DEFAULT 'other',


    description VARCHAR(255) NOT NULL,


    quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,


    unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,


    total_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,


    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,


    INDEX idx_invoice_items_invoice (invoice_id),


    CONSTRAINT fk_invoice_items_invoice
        FOREIGN KEY (invoice_id)
        REFERENCES invoices(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE


) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;




-- =========================================================
-- PAYMENTS
-- =========================================================


CREATE TABLE IF NOT EXISTS payments (


    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,


    invoice_id INT UNSIGNED NOT NULL,


    amount DECIMAL(12,2) NOT NULL,


    payment_method ENUM(
        'cash',
        'card',
        'mpesa',
        'bank_transfer',
        'other'
    ) NOT NULL DEFAULT 'cash',


    transaction_reference VARCHAR(100) NULL,


    notes VARCHAR(255) NULL,


    paid_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,


    recorded_by INT UNSIGNED NULL,


    INDEX idx_payment_invoice (invoice_id),


    INDEX idx_payment_recorded_by (recorded_by),


    CONSTRAINT fk_payment_invoice
        FOREIGN KEY (invoice_id)
        REFERENCES invoices(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,


    CONSTRAINT fk_payment_user
        FOREIGN KEY (recorded_by)
        REFERENCES users(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE


) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;




-- =========================================================
-- HOTEL SETTINGS
-- =========================================================


CREATE TABLE IF NOT EXISTS hotel_settings (


    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,


    setting_key VARCHAR(100) NOT NULL UNIQUE,


    setting_value VARCHAR(255) NULL,


    updated_at TIMESTAMP
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP


) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;



INSERT INTO hotel_settings
(
    setting_key,
    setting_value
)
VALUES
(
    'tax_rate',
    '16'
)
ON DUPLICATE KEY UPDATE
    setting_key = VALUES(setting_key);

-- =========================================================
-- BILLING RULE SETTINGS (idempotent)
-- =========================================================
INSERT INTO hotel_settings (setting_key, setting_value) VALUES
('tax_rate', '16'),
('service_charge_rate', '0'),
('currency', 'KES'),
('min_nights', '1'),
('early_checkout_policy', 'actual'),
('late_checkout_hour', '12'),
('late_checkout_fee', '0'),
('auto_apply_tax', '1'),
('auto_apply_service', '1')
ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key);

-- =========================================================
-- M-PESA TRANSACTIONS
-- =========================================================
CREATE TABLE IF NOT EXISTS mpesa_transactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT UNSIGNED NULL,
    booking_id INT UNSIGNED NULL,
    phone VARCHAR(20) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    account_reference VARCHAR(50) NOT NULL,
    transaction_desc VARCHAR(100) NOT NULL DEFAULT 'Hotel Payment',
    merchant_request_id VARCHAR(100) NULL,
    checkout_request_id VARCHAR(100) NULL,
    mpesa_receipt VARCHAR(50) NULL,
    result_code INT NULL,
    result_desc VARCHAR(255) NULL,
    status ENUM('pending', 'success', 'failed', 'cancelled', 'timeout')
        NOT NULL DEFAULT 'pending',
    raw_callback LONGTEXT NULL,
    recorded_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_mpesa_invoice (invoice_id),
    INDEX idx_mpesa_checkout (checkout_request_id),
    INDEX idx_mpesa_status (status),
    CONSTRAINT fk_mpesa_invoice
        FOREIGN KEY (invoice_id) REFERENCES invoices(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_mpesa_user
        FOREIGN KEY (recorded_by) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Housekeeping columns (for fresh installs – safe if already applied via migration)
ALTER TABLE rooms
  ADD COLUMN IF NOT EXISTS housekeeping_status
    ENUM('ready', 'dirty', 'cleaning', 'out_of_order')
    NOT NULL DEFAULT 'ready';

ALTER TABLE rooms
  ADD COLUMN IF NOT EXISTS housekeeping_notes VARCHAR(255) NULL;

ALTER TABLE rooms
  ADD COLUMN IF NOT EXISTS housekeeping_updated_at TIMESTAMP NULL DEFAULT NULL;

CREATE TABLE IF NOT EXISTS housekeeping_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  room_id INT UNSIGNED NOT NULL,
  old_status VARCHAR(30) NULL,
  new_status VARCHAR(30) NOT NULL,
  notes VARCHAR(255) NULL,
  changed_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_hk_room (room_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

