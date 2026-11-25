-- Stacey Laundry Hub: Customer Portal & Account Management Migration
-- Compatible with MySQL 8 / MariaDB (XAMPP)

CREATE TABLE IF NOT EXISTS customer_accounts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NOT NULL UNIQUE,
  email VARCHAR(254) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  account_status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  profile_picture VARCHAR(255) NULL,
  last_login DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ca_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_password_resets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_account_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cpr_account_expiry (customer_account_id, expires_at),
  CONSTRAINT fk_cpr_account FOREIGN KEY (customer_account_id) REFERENCES customer_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NULL,
  order_id INT NULL,
  title VARCHAR(150) NOT NULL,
  message TEXT NOT NULL,
  notification_type ENUM('order','payment','general') NOT NULL DEFAULT 'order',
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cn_customer_read (customer_id, is_read, created_at),
  CONSTRAINT fk_cn_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  CONSTRAINT fk_cn_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_notification_reads (
  notification_id INT NOT NULL,
  customer_id INT NOT NULL,
  read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (notification_id, customer_id),
  CONSTRAINT fk_cnr_notification FOREIGN KEY (notification_id) REFERENCES customer_notifications(id) ON DELETE CASCADE,
  CONSTRAINT fk_cnr_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_support_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NOT NULL,
  order_id INT NULL,
  subject VARCHAR(150) NOT NULL,
  message TEXT NOT NULL,
  status ENUM('open','resolved') NOT NULL DEFAULT 'open',
  staff_reply TEXT NULL,
  replied_by INT NULL,
  replied_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_csm_customer_status (customer_id, status, created_at),
  CONSTRAINT fk_csm_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  CONSTRAINT fk_csm_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
  CONSTRAINT fk_csm_replied_by FOREIGN KEY (replied_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed initial shop announcements for customers (idempotent check)
INSERT INTO customer_notifications (customer_id, order_id, title, message, notification_type, is_read)
SELECT NULL, NULL, 'Welcome to the Stacey Laundry Customer Portal',
       'Track your laundry in real time, review billing balances, and view shop updates anytime.', 'general', 0
WHERE NOT EXISTS (
  SELECT 1 FROM customer_notifications WHERE customer_id IS NULL AND title = 'Welcome to the Stacey Laundry Customer Portal'
);
