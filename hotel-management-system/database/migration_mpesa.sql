USE hotel_management_system;

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
    INDEX idx_mpesa_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
