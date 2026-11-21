-- Cafresco ordering app schema (MySQL 5.7+/MariaDB 10.3+)
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role ENUM('customer','staff','admin','rider') NOT NULL DEFAULT 'customer',
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  mobile VARCHAR(20) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  email_verified_at DATETIME NULL,
  mobile_verified_at DATETIME NULL,
  status ENUM('active','blocked') NOT NULL DEFAULT 'active',
  points_balance INT NOT NULL DEFAULT 0,
  staff_note VARCHAR(500) NULL,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_email (email),
  UNIQUE KEY uq_mobile (mobile),
  KEY idx_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS verification_tokens (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  type ENUM('email','mobile','reset') NOT NULL,
  token_hash CHAR(64) NOT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user_type (user_id, type),
  CONSTRAINT fk_vt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rider_companies (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  contact VARCHAR(120) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rider_profiles (
  user_id INT UNSIGNED PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  vehicle VARCHAR(60) NULL,
  plate_no VARCHAR(20) NULL,
  approval ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  is_online TINYINT(1) NOT NULL DEFAULT 0,
  last_seen_at DATETIME NULL,
  CONSTRAINT fk_rp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_company FOREIGN KEY (company_id) REFERENCES rider_companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  sort INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  loyverse_id VARCHAR(64) NULL,
  UNIQUE KEY uq_cat_lv (loyverse_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id INT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(500) NULL,
  image_url VARCHAR(500) NULL,
  base_price DECIMAL(10,2) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  sold_out TINYINT(1) NOT NULL DEFAULT 0,
  featured TINYINT(1) NOT NULL DEFAULT 0,
  sort INT NOT NULL DEFAULT 0,
  loyverse_id VARCHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_prod_lv (loyverse_id),
  CONSTRAINT fk_prod_cat FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_variants (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  name VARCHAR(80) NOT NULL,
  price DECIMAL(10,2) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort INT NOT NULL DEFAULT 0,
  loyverse_id VARCHAR(64) NULL,
  UNIQUE KEY uq_var_lv (loyverse_id),
  CONSTRAINT fk_var_prod FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS addon_groups (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  min_select TINYINT UNSIGNED NOT NULL DEFAULT 0,
  max_select TINYINT UNSIGNED NOT NULL DEFAULT 5,
  loyverse_id VARCHAR(64) NULL,
  UNIQUE KEY uq_ag_lv (loyverse_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS addons (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  group_id INT UNSIGNED NOT NULL,
  name VARCHAR(80) NOT NULL,
  price DECIMAL(10,2) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort INT NOT NULL DEFAULT 0,
  loyverse_id VARCHAR(64) NULL,
  UNIQUE KEY uq_ad_lv (loyverse_id),
  CONSTRAINT fk_addon_group FOREIGN KEY (group_id) REFERENCES addon_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_addon_groups (
  product_id INT UNSIGNED NOT NULL,
  group_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (product_id, group_id),
  CONSTRAINT fk_pag_prod FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_pag_group FOREIGN KEY (group_id) REFERENCES addon_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS discounts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  code VARCHAR(40) NULL,
  type ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
  value DECIMAL(10,2) NOT NULL,
  min_subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
  starts_at DATETIME NULL,
  ends_at DATETIME NULL,
  max_uses INT UNSIGNED NULL,
  per_user_limit INT UNSIGNED NULL,
  uses INT UNSIGNED NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(16) NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  fulfillment ENUM('pickup','own_rider','partner_rider') NOT NULL,
  payment_method ENUM('gcash','pay_on_pickup') NOT NULL,
  status ENUM('pending_payment','payment_submitted','pending_confirmation','confirmed','preparing','ready','out_for_delivery','completed','cancelled','rejected') NOT NULL,
  subtotal DECIMAL(10,2) NOT NULL,
  discount_id INT UNSIGNED NULL,
  discount_total DECIMAL(10,2) NOT NULL DEFAULT 0,
  points_redeemed INT NOT NULL DEFAULT 0,
  points_value DECIMAL(10,2) NOT NULL DEFAULT 0,
  total DECIMAL(10,2) NOT NULL,
  points_earned INT NOT NULL DEFAULT 0,
  pickup_time DATETIME NULL,
  customer_note VARCHAR(500) NULL,
  delivery_address VARCHAR(500) NULL,
  own_rider_info VARCHAR(200) NULL,
  rider_id INT UNSIGNED NULL,
  gcash_ref VARCHAR(40) NULL,
  gcash_sender VARCHAR(120) NULL,
  proof_file VARCHAR(120) NULL,
  staff_note VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_order_code (code),
  KEY idx_order_user (user_id),
  KEY idx_order_status (status),
  KEY idx_order_rider (rider_id),
  KEY idx_gcash_ref (gcash_ref),
  CONSTRAINT fk_order_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_order_rider FOREIGN KEY (rider_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_order_discount FOREIGN KEY (discount_id) REFERENCES discounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NULL,
  variant_id INT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  variant_name VARCHAR(80) NULL,
  unit_price DECIMAL(10,2) NOT NULL,
  qty INT UNSIGNED NOT NULL,
  addons_json TEXT NULL,
  note VARCHAR(200) NULL,
  line_total DECIMAL(10,2) NOT NULL,
  CONSTRAINT fk_oi_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  status VARCHAR(40) NOT NULL,
  note VARCHAR(500) NULL,
  actor_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_oe_order (order_id),
  CONSTRAINT fk_oe_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  audience ENUM('user','staff','riders') NOT NULL DEFAULT 'user',
  order_id INT UNSIGNED NULL,
  title VARCHAR(120) NOT NULL,
  body VARCHAR(500) NULL,
  read_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_n_user (user_id, read_at),
  KEY idx_n_aud (audience, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS points_ledger (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  order_id INT UNSIGNED NULL,
  delta INT NOT NULL,
  reason VARCHAR(120) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pl_user (user_id),
  CONSTRAINT fk_pl_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(60) PRIMARY KEY,
  v TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
  rl_key VARCHAR(190) PRIMARY KEY,
  window_start INT UNSIGNED NOT NULL,
  hits INT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (k, v) VALUES
 ('store_name', 'Cafresco'),
 ('store_open', '1'),
 ('store_hours', 'Daily 10:00 AM - 9:00 PM'),
 ('store_address', ''),
 ('store_phone', ''),
 ('facebook_url', ''),
 ('gcash_name', ''),
 ('gcash_number', ''),
 ('gcash_qr', ''),
 ('payment_window_minutes', '30'),
 ('points_per_peso', '0.02'),
 ('peso_per_point', '1'),
 ('min_points_redeem', '20'),
 ('max_redeem_percent', '50'),
 ('new_account_order_cap', '1500'),
 ('max_open_orders', '2'),
 ('allow_pay_on_pickup', '1'),
 ('pay_on_pickup_min_completed', '1'),
 ('require_mobile_verification', '0'),
 ('min_prep_minutes', '15'),
 ('loyverse_token', ''),
 ('banner_text', 'Fresh from our home café · Order ahead, skip the wait · Pay with GCash · Earn points on every online order');
