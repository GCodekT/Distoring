-- SQL скрипт для создания таблиц
-- Выполните этот скрипт в phpMyAdmin вашего хостинга SprintHost

-- Таблица организаций/групп
CREATE TABLE IF NOT EXISTS organizations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица пользователей
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) DEFAULT NULL UNIQUE,
    phone VARCHAR(20) DEFAULT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('engineer', 'employee') NOT NULL DEFAULT 'employee',
    organization_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    CHECK (email IS NOT NULL OR phone IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Индексы для быстрого поиска
CREATE INDEX idx_email ON users(email);
CREATE INDEX idx_phone ON users(phone);
CREATE INDEX idx_organization ON users(organization_id);
