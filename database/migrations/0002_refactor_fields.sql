-- PlayerKit Database Migration 0002
-- Player Kit & Jersey Registration - Full Refactor
-- Spec: refactor.md
-- Upper Jersey: 36,38,40,42,44 | Lower Jersey: 26,28,30,32,34,36 (even steps per spec)
-- Helmet: Small,Medium,Large,XL | Pad: Youth,Small,Medium,Large
-- Batting Hand: radio | Sleeve: stepper 0-3 each, max 4 combined | Jersey#: 3 opts 0-99

-- Add new columns (IF NOT EXISTS avoids re-run errors)
ALTER TABLE `registrations`
    ADD COLUMN IF NOT EXISTS `upper_jersey_size` VARCHAR(10) NULL AFTER `mobile_number`,
    ADD COLUMN IF NOT EXISTS `lower_jersey_size` VARCHAR(10) NULL AFTER `upper_jersey_size`,
    ADD COLUMN IF NOT EXISTS `pad_size`          VARCHAR(20) NULL AFTER `helmet_size`,
    ADD COLUMN IF NOT EXISTS `batting_hand`      VARCHAR(20) NULL AFTER `pad_size`,
    ADD COLUMN IF NOT EXISTS `half_sleeve_qty`   TINYINT    NOT NULL DEFAULT 0 AFTER `batting_hand`,
    ADD COLUMN IF NOT EXISTS `full_sleeve_qty`   TINYINT    NOT NULL DEFAULT 0 AFTER `half_sleeve_qty`,
    ADD COLUMN IF NOT EXISTS `jersey_number_opt1` VARCHAR(10) NULL AFTER `jersey_name`,
    ADD COLUMN IF NOT EXISTS `jersey_number_opt2` VARCHAR(10) NULL AFTER `jersey_number_opt1`,
    ADD COLUMN IF NOT EXISTS `jersey_number_opt3` VARCHAR(10) NULL AFTER `jersey_number_opt2`;

-- Seed / update field catalog entries (exact values per refactor.md spec)
INSERT INTO `field_catalog` (`field_key`, `field_label`, `field_type`, `options_list`) VALUES
('upper_jersey_size',  'Upper Jersey Size',         'select',  '36,38,40,42,44'),
('lower_jersey_size',  'Lower Jersey Size',         'select',  '26,28,30,32,34,36'),
('pad_size',           'Pad Size',                  'select',  'Youth,Small,Medium,Large'),
('batting_hand',       'Batting Hand',              'radio',   'Right Hand (RH),Left Hand (LH)'),
('half_sleeve_qty',    'Half Sleeve Qty',           'stepper', NULL),
('full_sleeve_qty',    'Full Sleeve Qty',           'stepper', NULL),
('jersey_number_opt1', 'Jersey Number (Option 1)',  'number',  NULL),
('jersey_number_opt2', 'Jersey Number (Option 2)',  'number',  NULL),
('jersey_number_opt3', 'Jersey Number (Option 3)',  'number',  NULL)
ON DUPLICATE KEY UPDATE
    `field_label`   = VALUES(`field_label`),
    `field_type`    = VALUES(`field_type`),
    `options_list`  = VALUES(`options_list`);

-- Update existing fields to match spec
UPDATE `field_catalog` SET `options_list` = 'Small,Medium,Large,XL'
WHERE `field_key` = 'helmet_size';

-- Ensure jersey_name is in catalog (may be missing from old installs)
INSERT INTO `field_catalog` (`field_key`, `field_label`, `field_type`, `options_list`)
VALUES ('jersey_name', 'Jersey Name', 'text', NULL)
ON DUPLICATE KEY UPDATE `field_key` = `field_key`;
