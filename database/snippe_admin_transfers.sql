CREATE TABLE IF NOT EXISTS snippe_admin_transfers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    fees DECIMAL(15,2) NOT NULL DEFAULT 0,
    total DECIMAL(15,2) NOT NULL,
    recipient_phone VARCHAR(20) NOT NULL,
    recipient_name VARCHAR(200) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    idempotency_key VARCHAR(30) NOT NULL UNIQUE,
    reference VARCHAR(100) DEFAULT NULL UNIQUE,
    metadata LONGTEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_admin_transfer_status (user_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
