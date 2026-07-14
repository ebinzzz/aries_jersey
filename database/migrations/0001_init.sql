-- PlayerKit Database Initial Migration
-- 0001_init.sql

CREATE TABLE IF NOT EXISTS `admins` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `teams` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL UNIQUE,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `forms` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(150) NOT NULL,
  `slug` VARCHAR(150) NOT NULL UNIQUE,
  `status` ENUM('open', 'closed') DEFAULT 'open',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `field_catalog` (
  `field_key` VARCHAR(50) PRIMARY KEY,
  `field_label` VARCHAR(100) NOT NULL,
  `field_type` VARCHAR(20) NOT NULL,
  `options_list` VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `form_field_configs` (
  `form_id` INT NOT NULL,
  `field_key` VARCHAR(50) NOT NULL,
  `is_enabled` TINYINT(1) DEFAULT 0,
  `is_required` TINYINT(1) DEFAULT 0,
  PRIMARY KEY (`form_id`, `field_key`),
  FOREIGN KEY (`form_id`) REFERENCES `forms`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `registrations` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `form_id` INT NOT NULL,
  `player_name` VARCHAR(150) NOT NULL,
  `team_id` INT NOT NULL,
  `player_id` VARCHAR(50) NULL,
  `mobile_number` VARCHAR(30) NULL,
  `helmet_size` VARCHAR(20) NULL,
  `jersey_name` VARCHAR(100) NULL,
  `jersey_number` VARCHAR(20) NULL,
  `shorts_size` VARCHAR(20) NULL,
  `trouser_size` VARCHAR(20) NULL,
  `initials` VARCHAR(20) NULL,
  `socks_size` VARCHAR(20) NULL,
  `chest_size` VARCHAR(20) NULL,
  `submitted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`form_id`) REFERENCES `forms`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`team_id`) REFERENCES `teams`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed default admin if not exists
INSERT INTO `admins` (`username`, `password`)
VALUES ('admin', '$2y$10$ev9Za9jVzTBVdlfk1fP6MeznVr..GJIPv8k9I1kzCWMg.iRMrpvUO')
ON DUPLICATE KEY UPDATE `username`=`username`;

-- Seed default teams
INSERT INTO `teams` (`name`) VALUES 
('Thunderbolts'),
('Strikers FC'),
('Red Devils'),
('Titans'),
('Blue Angels')
ON DUPLICATE KEY UPDATE `name`=`name`;

-- Seed field catalog
INSERT INTO `field_catalog` (`field_key`, `field_label`, `field_type`, `options_list`) VALUES
('player_id', 'Player ID', 'text', NULL),
('mobile_number', 'Mobile Number', 'text', NULL),
('helmet_size', 'Helmet Size', 'select', 'XS,S,M,L,XL,XXL'),
('jersey_name', 'Jersey Name', 'text', NULL),
('jersey_number', 'Jersey Number', 'number', NULL),
('shorts_size', 'Shorts Size', 'select', 'XS,S,M,L,XL,XXL'),
('trouser_size', 'Trouser Size', 'select', 'XS,S,M,L,XL,XXL'),
('initials', 'Initials', 'text', NULL),
('socks_size', 'Socks Size', 'select', 'S,M,L'),
('chest_size', 'Chest Size', 'text', NULL)
ON DUPLICATE KEY UPDATE `field_key`=`field_key`;
