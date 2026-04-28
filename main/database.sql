-- ============================================
-- Database Schema for Distoring.ru
-- IoT Sensor Monitoring System
-- ============================================

CREATE DATABASE IF NOT EXISTS sensor_monitoring CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE sensor_monitoring;

-- ============================================
-- Таблица пользователей
-- ============================================
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) UNIQUE,
    phone VARCHAR(20) UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    name VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    is_active BOOLEAN DEFAULT TRUE,
    verification_token VARCHAR(64),
    is_verified BOOLEAN DEFAULT FALSE,
    INDEX idx_email (email),
    INDEX idx_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Таблица датчиков
-- ============================================
CREATE TABLE sensors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id VARCHAR(50) UNIQUE NOT NULL COMMENT 'Уникальный ID датчика',
    name VARCHAR(100) DEFAULT 'Датчик',
    description TEXT,
    sim_number VARCHAR(20) COMMENT 'Номер SIM-карты',
    latitude DECIMAL(10, 8) COMMENT 'Широта',
    longitude DECIMAL(11, 8) COMMENT 'Долгота',
    is_precise_location BOOLEAN DEFAULT FALSE COMMENT 'Точная координата или вышка',
    first_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_seen TIMESTAMP NULL,
    is_active BOOLEAN DEFAULT TRUE,
    INDEX idx_device_id (device_id),
    INDEX idx_location (latitude, longitude)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Таблица данных от датчиков
-- ============================================
CREATE TABLE sensor_data (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    sensor_id INT NOT NULL,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    voltage DECIMAL(5, 2) COMMENT 'Напряжение в вольтах',
    charge_percent INT COMMENT 'Заряд батареи в %',
    roll_angle DECIMAL(6, 2) COMMENT 'Крен в градусах',
    pitch_angle DECIMAL(6, 2) COMMENT 'Тангаж в градусах',
    status VARCHAR(50) DEFAULT 'OK' COMMENT 'Статус датчика',
    FOREIGN KEY (sensor_id) REFERENCES sensors(id) ON DELETE CASCADE,
    INDEX idx_sensor_timestamp (sensor_id, timestamp),
    INDEX idx_timestamp (timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Связь пользователей с датчиками
-- ============================================
CREATE TABLE user_sensors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    sensor_id INT NOT NULL,
    custom_name VARCHAR(100) COMMENT 'Пользовательское имя',
    custom_latitude DECIMAL(10, 8) COMMENT 'Кастомная широта',
    custom_longitude DECIMAL(11, 8) COMMENT 'Кастомная долгота',
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    notifications_enabled BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (sensor_id) REFERENCES sensors(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_sensor (user_id, sensor_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Таблица сессий
-- ============================================
CREATE TABLE sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    session_token VARCHAR(64) UNIQUE NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token (session_token),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Таблица логов событий
-- ============================================
CREATE TABLE event_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    sensor_id INT,
    user_id INT,
    event_type VARCHAR(50) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sensor_id) REFERENCES sensors(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_event_type (event_type),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Представления
-- ============================================

ALTER TABLE sensor_data
ADD temperature DECIMAL(5,2) AFTER pitch_angle;

CREATE OR REPLACE VIEW latest_sensor_data AS
SELECT 
    s.id as sensor_id,
    s.device_id,
    s.name,
    s.latitude,
    s.longitude,
    s.is_precise_location,
    s.last_seen,
    sd.voltage,
    sd.charge_percent,
    sd.roll_angle,
    sd.pitch_angle,
    sd.temperature,
    sd.status,
    sd.timestamp as data_timestamp
FROM sensors s
LEFT JOIN sensor_data sd ON s.id = sd.sensor_id
WHERE sd.id = (
    SELECT id FROM sensor_data 
    WHERE sensor_id = s.id 
    ORDER BY timestamp DESC 
    LIMIT 1
)
OR sd.id IS NULL;

-- ============================================
-- Upgrade для многоуровневой системы
-- ============================================

-- ── ТАБЛИЦА ОРГАНИЗАЦИЙ ──
CREATE TABLE IF NOT EXISTS organizations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_active BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_created_by (created_by),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── ТАБЛИЦА РОЛЕЙ ──
CREATE TABLE IF NOT EXISTS roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) UNIQUE NOT NULL,
    description VARCHAR(255),
    is_system BOOLEAN DEFAULT TRUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Вставляем стандартные роли
INSERT IGNORE INTO roles (id, name, description, is_system) VALUES
(1, 'admin', 'Администратор системы', TRUE),
(2, 'lead_engineer', 'Главный инженер', TRUE),
(3, 'engineer', 'Инженер', TRUE),
(4, 'employee', 'Сотрудник', TRUE);

-- ── ТАБЛИЦА ПОЛЬЗОВАТЕЛЕЙ В ОРГАНИЗАЦИИ ──
CREATE TABLE IF NOT EXISTS user_organizations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    organization_id INT NOT NULL,
    role_id INT NOT NULL DEFAULT 4,  -- Default: employee
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT,
    UNIQUE KEY unique_user_org (user_id, organization_id),
    INDEX idx_org (organization_id),
    INDEX idx_role (role_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── ПРИВЯЗКА ДАТЧИКОВ К ОРГАНИЗАЦИЯМ ──
CREATE TABLE IF NOT EXISTS organization_sensors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NOT NULL,
    sensor_id INT NOT NULL,
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    FOREIGN KEY (sensor_id) REFERENCES sensors(id) ON DELETE CASCADE,
    UNIQUE KEY unique_org_sensor (organization_id, sensor_id),
    INDEX idx_org (organization_id),
    INDEX idx_sensor (sensor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── РАСШИРЕНИЕ ТАБЛИЦЫ USERS ──
ALTER TABLE users ADD COLUMN IF NOT EXISTS role_id INT DEFAULT 4;
ALTER TABLE users ADD COLUMN IF NOT EXISTS is_super_admin BOOLEAN DEFAULT FALSE;
ALTER TABLE users ADD FOREIGN KEY IF NOT EXISTS (role_id) REFERENCES roles(id);
ALTER TABLE users ADD INDEX IF NOT EXISTS idx_role (role_id);
ALTER TABLE users ADD INDEX IF NOT EXISTS idx_super_admin (is_super_admin);

-- ── РАСШИРЕНИЕ ТАБЛИЦЫ SENSORS ──
ALTER TABLE sensors ADD COLUMN IF NOT EXISTS custom_latitude DECIMAL(10, 8) COMMENT 'Пользовательские координаты';
ALTER TABLE sensors ADD COLUMN IF NOT EXISTS custom_longitude DECIMAL(11, 8) COMMENT 'Пользовательские координаты';

-- Создаём представление для получения доступных датчиков пользователя
CREATE OR REPLACE VIEW user_accessible_sensors AS
SELECT DISTINCT
    s.id, s.device_id, s.name, s.description,
    COALESCE(s.custom_latitude, s.latitude) as latitude,
    COALESCE(s.custom_longitude, s.longitude) as longitude,
    s.is_precise_location, s.first_seen, s.last_seen, s.is_active,
    o.id as organization_id, o.name as organization_name,
    uo.role_id
FROM sensors s
JOIN organization_sensors os ON s.id = os.sensor_id
JOIN organizations o ON os.organization_id = o.id
JOIN user_organizations uo ON o.id = uo.organization_id
WHERE s.is_active = 1 AND o.is_active = 1 AND uo.user_id = USER_ID
ORDER BY s.last_seen DESC;

-- ============================================
-- Upgrade для многоуровневой системы
-- ============================================

-- ── ТАБЛИЦА ОРГАНИЗАЦИЙ ──
CREATE TABLE IF NOT EXISTS organizations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_active BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_created_by (created_by),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── ТАБЛИЦА РОЛЕЙ ──
CREATE TABLE IF NOT EXISTS roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) UNIQUE NOT NULL,
    description VARCHAR(255),
    is_system BOOLEAN DEFAULT TRUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Вставляем стандартные роли
INSERT IGNORE INTO roles (id, name, description, is_system) VALUES
(1, 'admin', 'Администратор системы', TRUE),
(2, 'lead_engineer', 'Главный инженер', TRUE),
(3, 'engineer', 'Инженер', TRUE),
(4, 'employee', 'Сотрудник', TRUE);

-- ── ТАБЛИЦА ПОЛЬЗОВАТЕЛЕЙ В ОРГАНИЗАЦИИ ──
CREATE TABLE IF NOT EXISTS user_organizations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    organization_id INT NOT NULL,
    role_id INT NOT NULL DEFAULT 4,
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT,
    UNIQUE KEY unique_user_org (user_id, organization_id),
    INDEX idx_org (organization_id),
    INDEX idx_role (role_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── ПРИВЯЗКА ДАТЧИКОВ К ОРГАНИЗАЦИЯМ ──
CREATE TABLE IF NOT EXISTS organization_sensors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NOT NULL,
    sensor_id INT NOT NULL,
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    FOREIGN KEY (sensor_id) REFERENCES sensors(id) ON DELETE CASCADE,
    UNIQUE KEY unique_org_sensor (organization_id, sensor_id),
    INDEX idx_org (organization_id),
    INDEX idx_sensor (sensor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── РАСШИРЕНИЕ ТАБЛИЦЫ USERS ──
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_NAME='users' AND COLUMN_NAME='role_id' AND TABLE_SCHEMA=DATABASE());
SET @sql_add_role := IF(@exist=0, 
    'ALTER TABLE users ADD COLUMN role_id INT DEFAULT 4',
    'SELECT 1');
PREPARE stmt FROM @sql_add_role;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_NAME='users' AND COLUMN_NAME='is_super_admin' AND TABLE_SCHEMA=DATABASE());
SET @sql_add_admin := IF(@exist=0, 
    'ALTER TABLE users ADD COLUMN is_super_admin BOOLEAN DEFAULT FALSE',
    'SELECT 1');
PREPARE stmt FROM @sql_add_admin;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Добавляем внешний ключ для role_id (если его нет)
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
    WHERE TABLE_NAME='users' AND COLUMN_NAME='role_id' AND REFERENCED_TABLE_NAME='roles' AND TABLE_SCHEMA=DATABASE());
SET @sql_fk_role := IF(@exist=0, 
    'ALTER TABLE users ADD CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT',
    'SELECT 1');
PREPARE stmt FROM @sql_fk_role;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Добавляем индексы
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_NAME='users' AND INDEX_NAME='idx_role' AND TABLE_SCHEMA=DATABASE());
SET @sql_idx_role := IF(@exist=0, 
    'ALTER TABLE users ADD INDEX idx_role (role_id)',
    'SELECT 1');
PREPARE stmt FROM @sql_idx_role;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_NAME='users' AND INDEX_NAME='idx_super_admin' AND TABLE_SCHEMA=DATABASE());
SET @sql_idx_admin := IF(@exist=0, 
    'ALTER TABLE users ADD INDEX idx_super_admin (is_super_admin)',
    'SELECT 1');
PREPARE stmt FROM @sql_idx_admin;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── РАСШИРЕНИЕ ТАБЛИЦЫ SENSORS ──
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_NAME='sensors' AND COLUMN_NAME='custom_latitude' AND TABLE_SCHEMA=DATABASE());
SET @sql_add_lat := IF(@exist=0, 
    'ALTER TABLE sensors ADD COLUMN custom_latitude DECIMAL(10, 8) COMMENT "Пользовательские координаты"',
    'SELECT 1');
PREPARE stmt FROM @sql_add_lat;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_NAME='sensors' AND COLUMN_NAME='custom_longitude' AND TABLE_SCHEMA=DATABASE());
SET @sql_add_lon := IF(@exist=0, 
    'ALTER TABLE sensors ADD COLUMN custom_longitude DECIMAL(11, 8) COMMENT "Пользовательские координаты"',
    'SELECT 1');
PREPARE stmt FROM @sql_add_lon;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Проверяем результат
SELECT 'Database upgrade completed successfully!' as status;