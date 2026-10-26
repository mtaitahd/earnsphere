CREATE TABLE IF NOT EXISTS marketplace_listings (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, request_key VARCHAR(64) NOT NULL,
 title VARCHAR(150) NOT NULL, description TEXT NOT NULL, category VARCHAR(30) NOT NULL, product_price INT UNSIGNED NOT NULL,
 location VARCHAR(150) NOT NULL, whatsapp VARCHAR(20) NOT NULL, images JSON NOT NULL, policy_snapshot TEXT NOT NULL,
 ad_fee INT UNSIGNED NOT NULL, duration_days INT UNSIGNED NOT NULL, fee_status VARCHAR(20) NOT NULL DEFAULT 'unpaid', fee_transaction_id INT UNSIGNED DEFAULT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'draft', review_note VARCHAR(1000) NOT NULL DEFAULT '', reviewed_by INT UNSIGNED DEFAULT NULL,
 paid_at DATETIME DEFAULT NULL, approved_at DATETIME DEFAULT NULL, expires_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uk_listing_request (request_key), KEY idx_listing_public (status,expires_at), KEY idx_listing_user (user_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS marketplace_reports (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, listing_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, reason VARCHAR(1000) NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'open', review_note VARCHAR(1000) NOT NULL DEFAULT '', reviewed_by INT UNSIGNED DEFAULT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uk_listing_report (listing_id,user_id), KEY idx_listing_report_status (status,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS marketplace_events (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, listing_id INT UNSIGNED NOT NULL, actor_id INT UNSIGNED NOT NULL, action VARCHAR(40) NOT NULL,
 note VARCHAR(1000) NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_listing_event (listing_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
