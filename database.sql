-- PlayerKit & Jersey Registration System Database Schema
-- database.sql

CREATE DATABASE IF NOT EXISTS `playerkit_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `playerkit_db`;

-- Admins Table
CREATE TABLE IF NOT EXISTS `admins` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Teams Table
CREATE TABLE IF NOT EXISTS `teams` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL UNIQUE,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Registration Forms Table
CREATE TABLE IF NOT EXISTS `forms` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(150) NOT NULL,
  `slug` VARCHAR(150) NOT NULL UNIQUE,
  `status` ENUM('open', 'closed') DEFAULT 'open',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Field Catalog Table
CREATE TABLE IF NOT EXISTS `field_catalog` (
  `field_key` VARCHAR(50) PRIMARY KEY,
  `field_label` VARCHAR(100) NOT NULL,
  `field_type` VARCHAR(20) NOT NULL,
  `options_list` VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-form Dynamic Field Configs Table
CREATE TABLE IF NOT EXISTS `form_field_configs` (
  `form_id` INT NOT NULL,
  `field_key` VARCHAR(50) NOT NULL,
  `is_enabled` TINYINT(1) DEFAULT 0,
  `is_required` TINYINT(1) DEFAULT 0,
  PRIMARY KEY (`form_id`, `field_key`),
  FOREIGN KEY (`form_id`) REFERENCES `forms`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Submissions Table
CREATE TABLE IF NOT EXISTS `registrations` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `form_id` INT NOT NULL,
  `player_name` VARCHAR(150) NOT NULL,
  `team_id` INT NOT NULL,
  -- Personal / Contact
  `player_id` VARCHAR(50) NULL,
  `mobile_number` VARCHAR(30) NULL,
  `initials` VARCHAR(20) NULL,
  -- Jersey Sizes (Refactored)
  `upper_jersey_size` VARCHAR(20) NULL,
  `lower_jersey_size` VARCHAR(20) NULL,
  -- Kit Details
  `helmet_size` VARCHAR(20) NULL,
  `pad_size` VARCHAR(20) NULL,
  `batting_hand` VARCHAR(20) NULL,
  -- Playing Jersey Quantities
  `half_sleeve_qty` INT DEFAULT 0,
  `full_sleeve_qty` INT DEFAULT 0,
  -- Jersey Print Details
  `jersey_name` VARCHAR(100) NULL,
  -- Jersey Number Priority Options (0-99)
  `jersey_number_opt1` VARCHAR(20) NULL,
  `jersey_number_opt2` VARCHAR(20) NULL,
  `jersey_number_opt3` VARCHAR(20) NULL,
  -- Legacy fields (backward compat)
  `jersey_number` VARCHAR(20) NULL,
  `shorts_size` VARCHAR(20) NULL,
  `trouser_size` VARCHAR(20) NULL,
  `socks_size` VARCHAR(20) NULL,
  `chest_size` VARCHAR(20) NULL,
  `submitted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`form_id`) REFERENCES `forms`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`team_id`) REFERENCES `teams`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed Default Administrative Credentials
-- Default: admin / Admin@123
INSERT INTO `admins` (`username`, `password`)
VALUES ('admin', '$2y$10$ev9Za9jVzTBVdlfk1fP6MeznVr..GJIPv8k9I1kzCWMg.iRMrpvUO')
ON DUPLICATE KEY UPDATE `username`=`username`;

-- Seed Default Team Options
INSERT INTO `teams` (`name`) VALUES
('Kollam Sailors'),
('Thunderbolts'),
('Strikers FC'),
('Red Devils'),
('Titans'),
('Blue Angels')
ON DUPLICATE KEY UPDATE `name`=`name`;

-- Seed Dynamic Field Catalog (Refactored per refactor.md)
INSERT INTO `field_catalog` (`field_key`, `field_label`, `field_type`, `options_list`) VALUES
('player_id',          'Player ID',                  'text',   NULL),
('mobile_number',      'Mobile Number',              'text',   NULL),
('initials',           'Initials',                   'text',   NULL),
('upper_jersey_size',  'Upper Jersey Size',          'select', '36,37,38,39,40,41,42,43,44'),
('lower_jersey_size',  'Lower Jersey Size',          'select', '26,27,28,29,30,31,32,33,34,35,36'),
('helmet_size',        'Helmet Size',                'select', 'Small,Medium,Large,XL'),
('pad_size',           'Pad Size',                   'select', 'Youth,Small,Medium,Large'),
('batting_hand',       'Batting Hand',               'radio',  'Right Hand (RH),Left Hand (LH)'),
('half_sleeve_qty',    'Half Sleeve Jersey',         'number', NULL),
('full_sleeve_qty',    'Full Sleeve Jersey',         'number', NULL),
('jersey_name',        'Jersey Name',                'text',   NULL),
('jersey_number_opt1', 'Jersey Number (1st Choice)', 'number', NULL),
('jersey_number_opt2', 'Jersey Number (2nd Choice)', 'number', NULL),
('jersey_number_opt3', 'Jersey Number (3rd Choice)', 'number', NULL),
-- Legacy fields
('jersey_number',      'Jersey Number',              'number', NULL),
('shorts_size',        'Shorts Size',                'select', 'XS,S,M,L,XL,XXL'),
('trouser_size',       'Trouser Size',               'select', 'XS,S,M,L,XL,XXL'),
('socks_size',         'Socks Size',                 'select', 'S,M,L'),
('chest_size',         'Chest Size',                 'text',   NULL)
ON DUPLICATE KEY UPDATE
  `field_label`  = VALUES(`field_label`),
  `field_type`   = VALUES(`field_type`),
  `options_list` = VALUES(`options_list`);
