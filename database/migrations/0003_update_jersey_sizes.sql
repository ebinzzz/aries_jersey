-- Migration 0003: Update Upper and Lower Jersey Sizes Options
-- database/migrations/0003_update_jersey_sizes.sql

UPDATE `field_catalog`
SET `options_list` = '36,38,40,42,44,46'
WHERE `field_key` = 'upper_jersey_size';

UPDATE `field_catalog`
SET `options_list` = '26,28,30,32,34,36,38,40,42,44'
WHERE `field_key` = 'lower_jersey_size';
