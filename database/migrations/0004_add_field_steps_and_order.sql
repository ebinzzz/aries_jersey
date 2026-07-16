-- Migration 0004: Add Step Number and Sort Order to Form Field Configs
-- database/migrations/0004_add_field_steps_and_order.sql

ALTER TABLE `form_field_configs`
  ADD COLUMN IF NOT EXISTS `step_number` INT NOT NULL DEFAULT 2,
  ADD COLUMN IF NOT EXISTS `sort_order` INT NOT NULL DEFAULT 0;

-- Set default step numbers
UPDATE `form_field_configs` SET `step_number` = 2 WHERE `field_key` IN ('player_id', 'mobile_number', 'initials');
UPDATE `form_field_configs` SET `step_number` = 3 WHERE `field_key` NOT IN ('player_id', 'mobile_number', 'initials');

-- Set default sort orders to maintain original sequence
UPDATE `form_field_configs` SET `sort_order` = 1 WHERE `field_key` = 'player_id';
UPDATE `form_field_configs` SET `sort_order` = 2 WHERE `field_key` = 'mobile_number';
UPDATE `form_field_configs` SET `sort_order` = 3 WHERE `field_key` = 'initials';
UPDATE `form_field_configs` SET `sort_order` = 4 WHERE `field_key` = 'upper_jersey_size';
UPDATE `form_field_configs` SET `sort_order` = 5 WHERE `field_key` = 'lower_jersey_size';
UPDATE `form_field_configs` SET `sort_order` = 6 WHERE `field_key` = 'helmet_size';
UPDATE `form_field_configs` SET `sort_order` = 7 WHERE `field_key` = 'pad_size';
UPDATE `form_field_configs` SET `sort_order` = 8 WHERE `field_key` = 'batting_hand';
UPDATE `form_field_configs` SET `sort_order` = 9 WHERE `field_key` = 'half_sleeve_qty';
UPDATE `form_field_configs` SET `sort_order` = 10 WHERE `field_key` = 'full_sleeve_qty';
UPDATE `form_field_configs` SET `sort_order` = 11 WHERE `field_key` = 'jersey_name';
UPDATE `form_field_configs` SET `sort_order` = 12 WHERE `field_key` = 'jersey_number_opt1';
UPDATE `form_field_configs` SET `sort_order` = 13 WHERE `field_key` = 'jersey_number_opt2';
UPDATE `form_field_configs` SET `sort_order` = 14 WHERE `field_key` = 'jersey_number_opt3';
UPDATE `form_field_configs` SET `sort_order` = 15 WHERE `field_key` = 'jersey_number';
UPDATE `form_field_configs` SET `sort_order` = 16 WHERE `field_key` = 'shorts_size';
UPDATE `form_field_configs` SET `sort_order` = 17 WHERE `field_key` = 'trouser_size';
UPDATE `form_field_configs` SET `sort_order` = 18 WHERE `field_key` = 'socks_size';
UPDATE `form_field_configs` SET `sort_order` = 19 WHERE `field_key` = 'chest_size';
