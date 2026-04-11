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
