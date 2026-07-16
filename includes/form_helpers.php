<?php

// includes/form_helpers.php
require_once __DIR__ . '/db_config.php';

/**
 * Fetch enabled fields config for a form
 */
function get_form_fields_config($form_id)
{
    try {
        $db = get_db_connection();
        $stmt = $db->prepare("
            SELECT fc.*, ffc.is_required, ffc.step_number, ffc.sort_order 
            FROM `form_field_configs` ffc
            JOIN `field_catalog` fc ON ffc.field_key = fc.field_key
            WHERE ffc.form_id = ? AND ffc.is_enabled = 1
            ORDER BY ffc.step_number ASC, ffc.sort_order ASC
        ");
        $stmt->bind_param("i", $form_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $fields = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $fields[$row['field_key']] = [
                    'label' => $row['field_label'],
                    'type' => $row['field_type'],
                    'required' => $row['is_required'] == 1,
                    'step_number' => intval($row['step_number']),
                    'sort_order' => intval($row['sort_order']),
                    'options' => $row['options_list'] ? explode(',', $row['options_list']) : null
                ];
            }
            $result->free();
        }
        $stmt->close();

        // Dynamically override upper/lower jersey size options to match constraints:
        // - Upper Jersey Size: Even numbers only, max 46 (start at 36)
        // - Lower Jersey Size: Even numbers only, max 44 (start at 26)
        if (isset($fields['upper_jersey_size'])) {
            $fields['upper_jersey_size']['options'] = ['36', '38', '40', '42', '44', '46'];
        }
        if (isset($fields['lower_jersey_size'])) {
            $fields['lower_jersey_size']['options'] = ['26', '28', '30', '32', '34', '36', '38', '40', '42', '44'];
        }

        return $fields;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Clean and format submitted inputs based on form configurations
 */
function sanitize_form_input($key, $val)
{
    $val = trim($val);
    if ($key === 'jersey_name') {
        // Auto uppercase jersey name for sports print standard
        $val = strtoupper($val);
    }
    return $val;
}
