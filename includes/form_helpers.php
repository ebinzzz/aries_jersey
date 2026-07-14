<?php
// includes/form_helpers.php
require_once __DIR__ . '/db_config.php';

/**
 * Fetch enabled fields config for a form
 */
function get_form_fields_config($form_id) {
    try {
        $db = get_db_connection();
        $stmt = $db->prepare("
            SELECT fc.*, ffc.is_required 
            FROM `form_field_configs` ffc
            JOIN `field_catalog` fc ON ffc.field_key = fc.field_key
            WHERE ffc.form_id = ? AND ffc.is_enabled = 1
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
                    'options' => $row['options_list'] ? explode(',', $row['options_list']) : null
                ];
            }
            $result->free();
        }
        $stmt->close();
        return $fields;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Clean and format submitted inputs based on form configurations
 */
function sanitize_form_input($key, $val) {
    $val = trim($val);
    if ($key === 'jersey_name') {
        // Auto uppercase jersey name for sports print standard
        $val = strtoupper($val);
    }
    return $val;
}
