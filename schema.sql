-- Latest fresh-install schema for Staybook.
-- Create an empty database in cPanel/phpMyAdmin first, select it, then import this file.
-- Create the database user and grant its privileges through cPanel's MySQL Databases page.
-- Existing database upgrade: run `UPDATE rooms SET public_token = NULL;` first, then
-- `ALTER TABLE rooms MODIFY public_token CHAR(24) NULL, ADD COLUMN public_expires_at DATETIME NULL;`.
-- This invalidates old public links; create new links from each hotel's room category page.

CREATE TABLE IF NOT EXISTS hotels (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    location VARCHAR(255) NOT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rooms (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hotel_id INT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    description TEXT NULL,
    buying_rate VARCHAR(120) NULL,
    commission_rate VARCHAR(120) NULL,
    selling_rate VARCHAR(120) NULL,
    available_dates JSON NULL,
    public_token CHAR(24) NULL UNIQUE,
    public_expires_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (hotel_id),
    CONSTRAINT fk_rooms_hotel FOREIGN KEY (hotel_id) REFERENCES hotels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS room_images (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_id INT UNSIGNED NOT NULL,
    path VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (room_id),
    CONSTRAINT fk_images_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO users (email, password_hash)
VALUES ('knabirofficial@gmail.com', '$2y$10$zL0c6a6PrhIAwCykCYJU6.1GCj2bf.YRLdOzWRXEtRk5LdyTMpY5G')
ON DUPLICATE KEY UPDATE email = VALUES(email);
