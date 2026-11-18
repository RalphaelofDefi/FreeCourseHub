SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(190) NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('user', 'admin') NOT NULL DEFAULT 'user',
    status ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
    free_unlocks_remaining INT NOT NULL DEFAULT 5,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role (role),
    KEY idx_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    slug VARCHAR(140) NOT NULL,
    description TEXT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_categories_slug (slug),
    KEY idx_categories_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS courses (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL,
    canonical_key VARCHAR(191) NULL,
    description TEXT NULL,
    thumbnail_url VARCHAR(600) NULL,
    instructor VARCHAR(190) NULL,
    category_id INT UNSIGNED NULL,
    rating DECIMAL(3, 2) NULL,
    review_count INT UNSIGNED NULL,
    student_count INT UNSIGNED NULL,
    language VARCHAR(80) NULL,
    level VARCHAR(80) NULL,
    duration VARCHAR(80) NULL,
    original_price DECIMAL(10, 2) NULL,
    coupon_price DECIMAL(10, 2) NULL,
    currency VARCHAR(8) NULL,
    udemy_url VARCHAR(600) NULL,
    coupon_url VARCHAR(600) NULL,
    expiry_date DATETIME NULL,
    verification_status ENUM('pending', 'verified', 'questionable', 'expired', 'unavailable', 'failed') NOT NULL DEFAULT 'pending',
    publication_status ENUM('draft', 'published', 'unpublished', 'archived') NOT NULL DEFAULT 'draft',
    verification_notes TEXT NULL,
    last_verified_at DATETIME NULL,
    discovered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_courses_slug (slug),
    UNIQUE KEY uq_courses_canonical (canonical_key),
    KEY idx_courses_title (title),
    KEY idx_courses_category (category_id),
    KEY idx_courses_status (verification_status, publication_status),
    KEY idx_courses_availability (publication_status, verification_status, expiry_date),
    KEY idx_courses_discovered (discovered_at),
    KEY idx_courses_rating (rating),
    KEY idx_courses_level (level),
    KEY idx_courses_language (language),
    CONSTRAINT fk_courses_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS course_sources (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    base_url VARCHAR(300) NOT NULL,
    adapter VARCHAR(120) NOT NULL,
    status ENUM('enabled', 'disabled') NOT NULL DEFAULT 'enabled',
    last_fetched_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sources_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    course_id INT UNSIGNED NOT NULL,
    transaction_reference VARCHAR(120) NOT NULL,
    amount DECIMAL(10, 2) NOT NULL,
    currency VARCHAR(8) NOT NULL DEFAULT 'NGN',
    provider VARCHAR(40) NOT NULL DEFAULT 'paystack',
    status ENUM('pending', 'success', 'failed', 'cancelled', 'abandoned') NOT NULL DEFAULT 'pending',
    verified_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payments_reference (transaction_reference),
    KEY idx_payments_user (user_id),
    KEY idx_payments_course (course_id),
    KEY idx_payments_status (status),
    CONSTRAINT fk_payments_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_payments_course FOREIGN KEY (course_id) REFERENCES courses (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS course_source_links (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    course_id INT UNSIGNED NOT NULL,
    source_id INT UNSIGNED NOT NULL,
    source_course_url VARCHAR(600) NOT NULL,
    discovered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_source_link (course_id, source_id),
    KEY idx_source_links_source (source_id),
    CONSTRAINT fk_source_links_course FOREIGN KEY (course_id) REFERENCES courses (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_source_links_source FOREIGN KEY (source_id) REFERENCES course_sources (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS course_unlocks (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    course_id INT UNSIGNED NOT NULL,
    unlock_type ENUM('free', 'paid') NOT NULL,
    payment_id INT UNSIGNED NULL,
    unlocked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_unlock_user_course (user_id, course_id),
    KEY idx_unlocks_payment (payment_id),
    CONSTRAINT fk_unlocks_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_unlocks_course FOREIGN KEY (course_id) REFERENCES courses (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_unlocks_payment FOREIGN KEY (payment_id) REFERENCES payments (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_settings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    setting_key VARCHAR(120) NOT NULL,
    setting_value TEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_settings_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS collection_logs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_id INT UNSIGNED NULL,
    status ENUM('running', 'completed', 'partial', 'failed') NOT NULL DEFAULT 'running',
    discovered_count INT UNSIGNED NOT NULL DEFAULT 0,
    new_courses_count INT UNSIGNED NOT NULL DEFAULT 0,
    duplicate_count INT UNSIGNED NOT NULL DEFAULT 0,
    failed_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_message TEXT NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_collection_source (source_id),
    KEY idx_collection_status (status),
    KEY idx_collection_started (started_at),
    CONSTRAINT fk_collection_source FOREIGN KEY (source_id) REFERENCES course_sources (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS verification_logs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    course_id INT UNSIGNED NOT NULL,
    verification_status ENUM('pending', 'verified', 'questionable', 'expired', 'unavailable', 'failed') NOT NULL,
    verification_details TEXT NULL,
    checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_verification_course (course_id),
    KEY idx_verification_status (verification_status),
    CONSTRAINT fk_verification_course FOREIGN KEY (course_id) REFERENCES courses (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_activity_logs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NULL,
    activity VARCHAR(120) NOT NULL,
    details TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_activity_user (user_id),
    KEY idx_activity_created (created_at),
    CONSTRAINT fk_activity_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_password_reset_token (token_hash),
    KEY idx_password_reset_user (user_id),
    CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
