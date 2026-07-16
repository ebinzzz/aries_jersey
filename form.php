<?php
// form.php
require_once __DIR__ . '/includes/db_config.php';
require_once __DIR__ . '/includes/form_helpers.php';

// Feature Flag: Allow players to edit their registrations
$allow_edit_registration = false;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    $db = get_db_connection();
} catch (Exception $e) {
    die("System Configuration Error. Please contact administrator.");
}

$slug = trim($_GET['slug'] ?? '');
if (empty($slug)) {
    display_error_page("Invalid Request", "No form slug was provided. Please verify the URL link.");
    exit;
}

// Fetch form details
$stmt = $db->prepare("SELECT * FROM `forms` WHERE `slug` = ? LIMIT 1");
$stmt->bind_param("s", $slug);
$stmt->execute();
$form_res = $stmt->get_result();
if (!$form_res || $form_res->num_rows === 0) {
    display_error_page("Form Not Found", "The requested registration form does not exist. It may have been removed.");
    exit;
}
$form = $form_res->fetch_assoc();
$stmt->close();

// Check if form is closed
if ($form['status'] === 'closed' && !isset($_GET['success'])) {
    display_closed_page($form['title']);
    exit;
}

// Fetch dynamic form fields config
$fields_config = get_form_fields_config($form['id']);

// Check for lookup or edit parameters
$lookup_phone = $allow_edit_registration ? trim($_GET['lookup_phone'] ?? '') : '';
$edit_id = $allow_edit_registration ? intval($_GET['edit_id'] ?? 0) : 0;
$phone = $allow_edit_registration ? trim($_GET['phone'] ?? '') : '';
$editing_registration = null;
$lookup_results = null;
$lookup_error = '';

if (!empty($lookup_phone)) {
    // Search registrations under this form and mobile_number
    $stmt = $db->prepare("SELECT * FROM `registrations` WHERE `form_id` = ? AND `mobile_number` = ? ORDER BY `submitted_at` DESC");
    $stmt->bind_param("is", $form['id'], $lookup_phone);
    $stmt->execute();
    $reg_res = $stmt->get_result();
    $registrations = [];
    while ($row = $reg_res->fetch_assoc()) {
        $registrations[] = $row;
    }
    $stmt->close();

    if (count($registrations) === 0) {
        $lookup_error = "No registration found with mobile number '" . htmlspecialchars($lookup_phone) . "'.";
    } elseif (count($registrations) === 1) {
        // Redirect to edit mode for this registration
        header("Location: form.php?slug=" . urlencode($slug) . "&edit_id=" . $registrations[0]['id'] . "&phone=" . urlencode($lookup_phone));
        exit;
    } else {
        // Multiple results
        $lookup_results = $registrations;
    }
}

if ($edit_id > 0 && !empty($phone)) {
    $stmt = $db->prepare("SELECT * FROM `registrations` WHERE `id` = ? AND `form_id` = ? AND `mobile_number` = ? LIMIT 1");
    $stmt->bind_param("iis", $edit_id, $form['id'], $phone);
    $stmt->execute();
    $reg_res = $stmt->get_result();
    if ($reg_res && $reg_res->num_rows > 0) {
        $editing_registration = $reg_res->fetch_assoc();
    }
    $stmt->close();

    if (!$editing_registration) {
        display_error_page("Registration Not Found", "No matching registration was found for the provided details.");
        exit;
    }
}

// Partition fields for Stepper steps
$personal_keys = ['player_id', 'mobile_number', 'initials'];
$kit_keys = [
    'upper_jersey_size', 'lower_jersey_size',
    'helmet_size', 'pad_size', 'batting_hand',
    'half_sleeve_qty', 'full_sleeve_qty',
    'jersey_name', 'jersey_number_opt1', 'jersey_number_opt2', 'jersey_number_opt3',
    // Legacy fields (backward compatible)
    'jersey_number', 'shorts_size', 'trouser_size', 'initials', 'socks_size', 'chest_size'
];

// Sleeve-qty and stepper fields where 0 is a valid non-empty value
$qty_fields = ['half_sleeve_qty', 'full_sleeve_qty'];
$stepper_field_types = ['stepper']; // field_catalog types rendered as steppers

$step1_fields = [];
$step2_fields = [];
$step3_fields = [];

foreach ($fields_config as $key => $config) {
    $step = intval($config['step_number'] ?? 3);
    if ($step === 1) {
        $step1_fields[$key] = $config;
    } elseif ($step === 2) {
        $step2_fields[$key] = $config;
    } else {
        $step3_fields[$key] = $config;
    }
}

$has_step2 = (count($step2_fields) > 0);
$has_step3 = (count($step3_fields) > 0);

// Fetch teams list for selection
$teams = [];
$team_res = $db->query("SELECT * FROM `teams` ORDER BY `name` ASC");
if ($team_res) {
    while ($row = $team_res->fetch_assoc()) {
        $teams[] = $row;
    }
    $team_res->free();
}

$errors = [];

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['success'])) {
    // 1. Core validations
    $player_name = sanitize_form_input('player_name', $_POST['player_name'] ?? '');

    if (empty($player_name)) {
        $errors['player_name'] = "Player Name is required.";
    }

    // Auto-resolve team_id to "Kollam Sailors"
    $team_id = 0;
    $t_stmt = $db->prepare("SELECT `id` FROM `teams` WHERE `name` = 'Kollam Sailors' LIMIT 1");
    if ($t_stmt) {
        $t_stmt->execute();
        $t_res = $t_stmt->get_result();
        if ($t_res && $t_res->num_rows > 0) {
            $team_id = $t_res->fetch_assoc()['id'];
        }
        $t_stmt->close();
    }
    if ($team_id <= 0) {
        $t_res = $db->query("SELECT `id` FROM `teams` LIMIT 1");
        if ($t_res && $t_res->num_rows > 0) {
            $team_id = $t_res->fetch_assoc()['id'];
        }
    }

    // 2. Dynamic fields validation & collecting values
    $field_values = [];
    // Initialize all columns: qty fields to 0, others to NULL
    $all_catalog_keys = array_merge($personal_keys, $kit_keys);
    foreach ($all_catalog_keys as $key) {
        $field_values[$key] = in_array($key, $qty_fields) ? 0 : null;
    }

    foreach ($fields_config as $key => $config) {
        $is_qty = in_array($key, $qty_fields) || ($config['type'] ?? '') === 'stepper';
        $raw    = $_POST[$key] ?? '';
        $val    = sanitize_form_input($key, $raw);
        $is_empty = $is_qty ? ($raw === '') : empty($val);

        if ($config['required'] && $is_empty) {
            $errors[$key] = $config['label'] . " is required.";
        }

        // Custom validations
        if (!$is_empty) {
            if ($key === 'mobile_number' && !preg_match('/^[0-9+ ]{8,20}$/', $val)) {
                $errors[$key] = "Please enter a valid mobile number.";
            }
            if ($key === 'jersey_name' && !preg_match('/^[A-Za-z ]+$/', $val)) {
                $errors[$key] = "Jersey Name must contain only English characters and spaces.";
            }
            if ($key === 'jersey_number' && (intval($val) < 0 || intval($val) > 999)) {
                $errors[$key] = "Jersey number must be between 0 and 999.";
            }
            if (in_array($key, ['jersey_number_opt1', 'jersey_number_opt2', 'jersey_number_opt3'])) {
                $n = intval($val);
                if ($n < 0 || $n > 99) {
                    $errors[$key] = "Jersey number must be between 0 and 99.";
                }
            }
            if ($is_qty) {
                $q = intval($val);
                if ($q < 0 || $q > 3) {
                    $errors[$key] = $config['label'] . " must be 0–3.";
                }
            }
        }

        $field_values[$key] = $is_qty ? intval($val) : (!$is_empty ? $val : null);
    }

    // Combined sleeve-qty validation (max 4 total)
    if (isset($fields_config['half_sleeve_qty']) || isset($fields_config['full_sleeve_qty'])) {
        $hq = intval($field_values['half_sleeve_qty']);
        $fq = intval($field_values['full_sleeve_qty']);
        if ($hq + $fq > 4) {
            $errors['half_sleeve_qty'] = "Total playing jersey quantity (Half + Full Sleeve) cannot exceed 4.";
        }
    }

    // Duplicate jersey number validation (Options 1, 2, 3 must be unique when filled)
    $jersey_opts = [];
    foreach (['jersey_number_opt1', 'jersey_number_opt2', 'jersey_number_opt3'] as $opt_key) {
        $opt_val = $field_values[$opt_key] ?? null;
        if ($opt_val !== null && $opt_val !== '') {
            if (in_array($opt_val, $jersey_opts)) {
                $errors[$opt_key] = "Duplicate jersey number. Each option must be a different number.";
            } else {
                $jersey_opts[] = $opt_val;
            }
        }
    }

    // Save record if no validation errors
    if (empty($errors)) {
        try {
            $hq_int = intval($field_values['half_sleeve_qty']);
            $fq_int = intval($field_values['full_sleeve_qty']);

            if ($editing_registration) {
                $update_query = "
                    UPDATE `registrations` SET
                        `player_name` = ?,
                        `player_id` = ?,
                        `mobile_number` = ?,
                        `upper_jersey_size` = ?,
                        `lower_jersey_size` = ?,
                        `helmet_size` = ?,
                        `pad_size` = ?,
                        `batting_hand` = ?,
                        `half_sleeve_qty` = ?,
                        `full_sleeve_qty` = ?,
                        `jersey_name` = ?,
                        `jersey_number_opt1` = ?,
                        `jersey_number_opt2` = ?,
                        `jersey_number_opt3` = ?,
                        `jersey_number` = ?,
                        `shorts_size` = ?,
                        `trouser_size` = ?,
                        `initials` = ?,
                        `socks_size` = ?,
                        `chest_size` = ?
                    WHERE `id` = ? AND `form_id` = ?
                ";

                $upd = $db->prepare($update_query);
                $upd->bind_param(
                    "ssssssssiissssssssssii",
                    $player_name,
                    $field_values['player_id'],
                    $field_values['mobile_number'],
                    $field_values['upper_jersey_size'],
                    $field_values['lower_jersey_size'],
                    $field_values['helmet_size'],
                    $field_values['pad_size'],
                    $field_values['batting_hand'],
                    $hq_int,
                    $fq_int,
                    $field_values['jersey_name'],
                    $field_values['jersey_number_opt1'],
                    $field_values['jersey_number_opt2'],
                    $field_values['jersey_number_opt3'],
                    $field_values['jersey_number'],
                    $field_values['shorts_size'],
                    $field_values['trouser_size'],
                    $field_values['initials'],
                    $field_values['socks_size'],
                    $field_values['chest_size'],
                    $editing_registration['id'],
                    $form['id']
                );

                if ($upd->execute()) {
                    $_SESSION['last_submission'] = [
                        'player_name'       => $player_name,
                        'team_name'         => 'Kollam Sailors',
                        'jersey_name'       => $field_values['jersey_name'],
                        'jersey_number_opt1' => $field_values['jersey_number_opt1'],
                        'jersey_number_opt2' => $field_values['jersey_number_opt2'],
                        'jersey_number_opt3' => $field_values['jersey_number_opt3'],
                        'half_sleeve_qty'   => $hq_int,
                        'full_sleeve_qty'   => $fq_int,
                    ];
                    header("Location: form.php?slug=" . urlencode($slug) . "&success=2");
                    exit;
                } else {
                    $errors['global'] = "Error updating registration: " . $db->error;
                }
                $upd->close();
            } else {
                $insert_query = "
                    INSERT INTO `registrations` (
                        `form_id`, `player_name`, `team_id`,
                        `player_id`, `mobile_number`,
                        `upper_jersey_size`, `lower_jersey_size`,
                        `helmet_size`, `pad_size`, `batting_hand`,
                        `half_sleeve_qty`, `full_sleeve_qty`,
                        `jersey_name`,
                        `jersey_number_opt1`, `jersey_number_opt2`, `jersey_number_opt3`,
                        `jersey_number`, `shorts_size`, `trouser_size`, `initials`, `socks_size`, `chest_size`
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ";

                $ins = $db->prepare($insert_query);
                $ins->bind_param(
                    "isisssssssiissssssssss",
                    $form['id'],
                    $player_name,
                    $team_id,
                    $field_values['player_id'],
                    $field_values['mobile_number'],
                    $field_values['upper_jersey_size'],
                    $field_values['lower_jersey_size'],
                    $field_values['helmet_size'],
                    $field_values['pad_size'],
                    $field_values['batting_hand'],
                    $hq_int,
                    $fq_int,
                    $field_values['jersey_name'],
                    $field_values['jersey_number_opt1'],
                    $field_values['jersey_number_opt2'],
                    $field_values['jersey_number_opt3'],
                    $field_values['jersey_number'],
                    $field_values['shorts_size'],
                    $field_values['trouser_size'],
                    $field_values['initials'],
                    $field_values['socks_size'],
                    $field_values['chest_size']
                );

                if ($ins->execute()) {
                    $_SESSION['last_submission'] = [
                        'player_name'       => $player_name,
                        'team_name'         => 'Kollam Sailors',
                        'jersey_name'       => $field_values['jersey_name'],
                        'jersey_number_opt1' => $field_values['jersey_number_opt1'],
                        'jersey_number_opt2' => $field_values['jersey_number_opt2'],
                        'jersey_number_opt3' => $field_values['jersey_number_opt3'],
                        'half_sleeve_qty'   => $hq_int,
                        'full_sleeve_qty'   => $fq_int,
                    ];
                    header("Location: form.php?slug=" . urlencode($slug) . "&success=1");
                    exit;
                } else {
                    $errors['global'] = "Error saving registration: " . $db->error;
                }
                $ins->close();
            }
        } catch (Exception $e) {
            $errors['global'] = "System database error. Please try again later.";
        }
    }
}

// Display Success view
if (isset($_GET['success'])) {
    $summary = $_SESSION['last_submission'] ?? null;
    display_success_page($form['title'], $summary);
    exit;
}

// Helper to draw inputs
function render_field_input($key, $config, $errors)
{
    global $editing_registration;
    $has_error = isset($errors[$key]);
    
    if (isset($_POST[$key])) {
        $value = htmlspecialchars($_POST[$key]);
    } elseif ($editing_registration && isset($editing_registration[$key])) {
        $value = htmlspecialchars($editing_registration[$key]);
    } else {
        $value = '';
    }

    echo '<div class="form-group">';
    echo '<label class="form-label" for="' . $key . '">' . htmlspecialchars($config['label']) . ($config['required'] ? ' <span style="color:var(--primary);">*</span>' : '') . '</label>';

    if ($config['type'] === 'select') {
        echo '<select id="' . $key . '" name="' . $key . '" class="form-control" ' . ($config['required'] ? 'required' : '') . '>';
        echo '<option value="">Select option</option>';
        foreach ($config['options'] as $opt) {
            $selected = ($value === $opt) ? 'selected' : '';
            echo '<option value="' . htmlspecialchars($opt) . '" ' . $selected . '>' . htmlspecialchars($opt) . '</option>';
        }
        echo '</select>';
    } elseif ($config['type'] === 'radio' && !empty($config['options'])) {
        // Custom styled radio button group
        echo '<div class="radio-group">';
        foreach ($config['options'] as $opt) {
            $checked = ($value === $opt) ? 'checked' : '';
            $uid = $key . '_' . md5($opt);
            echo '<label class="radio-label" for="' . $uid . '">';
            echo '<input type="radio" class="radio-input" id="' . $uid . '" name="' . $key . '" value="' . htmlspecialchars($opt) . '" ' . $checked . ($config['required'] ? ' required' : '') . '>';
            echo htmlspecialchars($opt);
            echo '</label>';
        }
        echo '</div>';
    } elseif (in_array($key, ['half_sleeve_qty', 'full_sleeve_qty']) || ($config['type'] ?? '') === 'stepper') {
        // Quantity stepper (handles both legacy key check and catalog type='stepper')
        $cur = 0;
        if (isset($_POST[$key])) {
            $cur = intval($_POST[$key]);
        } elseif ($editing_registration && isset($editing_registration[$key])) {
            $cur = intval($editing_registration[$key]);
        }
        
        $other_key = ($key === 'half_sleeve_qty') ? 'full_sleeve_qty' : 'half_sleeve_qty';
        $other_cur = 0;
        if (isset($_POST[$other_key])) {
            $other_cur = intval($_POST[$other_key]);
        } elseif ($editing_registration && isset($editing_registration[$other_key])) {
            $other_cur = intval($editing_registration[$other_key]);
        }
        $combined_total = $cur + $other_cur;

        echo '<div class="stepper-container">';
        echo '<button type="button" class="stepper-btn" onclick="adjustQty(\'' . $key . '\', -1)" id="btn-minus-' . $key . '"' . ($cur <= 0 ? ' disabled' : '') . '>−</button>';
        echo '<span class="stepper-input" id="display-' . $key . '">' . $cur . '</span>';
        echo '<input type="hidden" name="' . $key . '" id="' . $key . '" value="' . $cur . '">';
        echo '<button type="button" class="stepper-btn" onclick="adjustQty(\'' . $key . '\', 1)" id="btn-plus-' . $key . '"' . ($cur >= 3 || $combined_total >= 4 ? ' disabled' : '') . '>+</button>';
        echo '</div>';
        echo '<small style="color:var(--text-muted); font-size:0.78rem; margin-top:0.35rem; display:block;">Max 3 per style · Combined total max 4</small>';
    } else {
        $placeholder = $config['placeholder'] ?? 'Enter ' . $config['label'];
        $type = $config['type'];
        $extra = '';
        if ($key === 'jersey_name') {
            $extra = 'oninput="this.value = this.value.toUpperCase().replace(/[^A-Z ]/g, \'\')" inputmode="text" autocomplete="off" maxlength="20" placeholder="e.g. SUBIN"';
        } elseif ($key === 'mobile_number') {
            $extra = 'inputmode="tel" autocomplete="tel"';
            $type = 'tel';
        } elseif ($key === 'jersey_number') {
            $extra = 'inputmode="numeric" pattern="[0-9]*" autocomplete="off" min="0" max="999"';
        } elseif (in_array($key, ['jersey_number_opt1','jersey_number_opt2','jersey_number_opt3'])) {
            $extra = 'inputmode="numeric" pattern="[0-9]*" autocomplete="off" min="0" max="99" placeholder="0–99"';
            $type = 'number';
        } elseif ($key === 'player_id') {
            $extra = 'inputmode="text" autocomplete="off"';
        } elseif ($key === 'initials') {
            $extra = 'inputmode="text" autocomplete="off" maxlength="5"';
        } elseif ($key === 'chest_size') {
            $extra = 'inputmode="numeric" autocomplete="off"';
        } else {
            $extra = 'autocomplete="off"';
        }
        echo '<input type="' . $type . '" id="' . $key . '" name="' . $key . '" class="form-control" placeholder="' . htmlspecialchars($placeholder) . '" value="' . $value . '" ' . ($config['required'] ? 'required' : '') . ' ' . $extra . '>';
    }

    if ($has_error) {
        echo '<small style="color:var(--danger); font-size:0.8rem; margin-top:0.25rem; display:block;">' . htmlspecialchars($errors[$key]) . '</small>';
    }
    echo '</div>';
}

function render_step_fields($fields, $errors) {
    global $editing_registration;
    $keys = array_keys($fields);
    $total = count($keys);
    $i = 0;
    
    while ($i < $total) {
        $key = $keys[$i];
        $config = $fields[$key];
        
        // Group sizes
        if (in_array($key, ['upper_jersey_size', 'lower_jersey_size', 'helmet_size', 'pad_size'])) {
            echo '<div class="kit-section-label">Jersey &amp; Kit Sizes</div>';
            echo '<div class="kit-grid-2">';
            while ($i < $total && in_array($keys[$i], ['upper_jersey_size', 'lower_jersey_size', 'helmet_size', 'pad_size'])) {
                render_field_input($keys[$i], $fields[$keys[$i]], $errors);
                $i++;
            }
            echo '</div>';
            continue;
        }
        
        // Group sleeves
        if (in_array($key, ['half_sleeve_qty', 'full_sleeve_qty'])) {
            echo '<div class="kit-section-label">Playing Jersey Quantity</div>';
            echo '<div class="sleeve-row">';
            while ($i < $total && in_array($keys[$i], ['half_sleeve_qty', 'full_sleeve_qty'])) {
                $k = $keys[$i];
                $c = $fields[$k];
                if (isset($_POST[$k])) {
                    $cur = intval($_POST[$k]);
                } elseif ($editing_registration && isset($editing_registration[$k])) {
                    $cur = intval($editing_registration[$k]);
                } else {
                    $cur = 0;
                }
                
                $other_key = ($k === 'half_sleeve_qty') ? 'full_sleeve_qty' : 'half_sleeve_qty';
                $other_qty = 0;
                if (isset($_POST[$other_key])) {
                    $other_qty = intval($_POST[$other_key]);
                } elseif ($editing_registration && isset($editing_registration[$other_key])) {
                    $other_qty = intval($editing_registration[$other_key]);
                }
                $combined_sleeve_total = $cur + $other_qty;
                
                echo '<div class="sleeve-cell">';
                echo '<div class="form-label" style="margin-bottom:0.5rem;">' . htmlspecialchars($c['label']) . '</div>';
                echo '<div class="stepper-container">';
                echo '<button type="button" class="stepper-btn" onclick="adjustQty(\'' . $k . '\',-1)" id="btn-minus-' . $k . '"' . ($cur <= 0 ? ' disabled' : '') . '>−</button>';
                echo '<span class="stepper-input" id="display-' . $k . '">' . $cur . '</span>';
                echo '<input type="hidden" name="' . $k . '" id="' . $k . '" value="' . $cur . '">';
                echo '<button type="button" class="stepper-btn" onclick="adjustQty(\'' . $k . '\', 1)" id="btn-plus-' . $k . '"' . (($cur >= 3 || $combined_sleeve_total >= 4) ? ' disabled' : '') . '>+</button>';
                echo '</div>';
                if (isset($errors[$k])) {
                    echo '<small style="color:var(--danger);font-size:0.78rem;display:block;margin-top:0.25rem;">' . htmlspecialchars($errors[$k]) . '</small>';
                }
                echo '</div>';
                $i++;
            }
            echo '<div class="sleeve-note">Max 3 per style<br>Combined max 4</div>';
            echo '</div>';
            continue;
        }
        
        // Group jersey number options
        if (in_array($key, ['jersey_number_opt1', 'jersey_number_opt2', 'jersey_number_opt3'])) {
            echo '<div class="form-group" style="margin-top:0.25rem;">';
            echo '<label class="form-label">Jersey Number';
            if (isset($fields['jersey_number_opt1']['required']) && $fields['jersey_number_opt1']['required']) {
                echo ' <span style="color:var(--primary);">*</span>';
            }
            echo '<span style="font-weight:400;color:var(--text-muted);font-size:0.75rem;text-transform:none;letter-spacing:0;"> 0–99 · no duplicates</span>';
            echo '</label>';
            echo '<div class="jersey-number-grid">';
            
            $jnum_labels = [
                'jersey_number_opt1' => 'Option 1 (Priority)',
                'jersey_number_opt2' => 'Option 2',
                'jersey_number_opt3' => 'Option 3'
            ];
            
            while ($i < $total && in_array($keys[$i], ['jersey_number_opt1', 'jersey_number_opt2', 'jersey_number_opt3'])) {
                $opt_key = $keys[$i];
                if (isset($_POST[$opt_key])) {
                    $opt_val = htmlspecialchars($_POST[$opt_key]);
                } elseif ($editing_registration && isset($editing_registration[$opt_key])) {
                    $opt_val = htmlspecialchars($editing_registration[$opt_key]);
                } else {
                    $opt_val = '';
                }
                $has_opt_err = isset($errors[$opt_key]);
                
                echo '<div>';
                echo '<label style="display:block;font-size:0.75rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;margin-bottom:0.35rem;">';
                echo htmlspecialchars($jnum_labels[$opt_key]);
                if ($opt_key === 'jersey_number_opt1' && ($fields[$opt_key]['required'] ?? false)) {
                    echo ' <span style="color:var(--primary)">*</span>';
                }
                echo '</label>';
                echo '<input type="number" id="' . $opt_key . '" name="' . $opt_key . '" class="form-control' . ($has_opt_err ? ' invalid' : '') . '" inputmode="numeric" min="0" max="99" placeholder="0–99" value="' . $opt_val . '" autocomplete="off" ' . (($opt_key === 'jersey_number_opt1' && ($fields[$opt_key]['required'] ?? false)) ? 'required' : '') . '>';
                if ($has_opt_err) {
                    echo '<small style="color:var(--danger);font-size:0.75rem;display:block;margin-top:0.25rem;">' . htmlspecialchars($errors[$opt_key]) . '</small>';
                }
                echo '</div>';
                
                $i++;
            }
            
            echo '</div>';
            echo '</div>';
            continue;
        }
        
        // Single field rendering
        render_field_input($key, $config, $errors);
        $i++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($form['title']); ?> | Player Kit Registration</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        /* Ensure select elements are styled correctly on iOS */
        select.form-control {
            -webkit-appearance: none;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            padding-right: 2.5rem;
        }
    </style>
</head>
<body class="public-form-body">

<?php
// Shared public header with back-to-home link
if (!defined('PUBLIC_ROOT')) {
    define('PUBLIC_ROOT', '/aries_jersey/');
}
if (!defined('ADMIN_URL')) {
    define('ADMIN_URL', PUBLIC_ROOT . 'admin/login.php');
}
$back_url   = PUBLIC_ROOT;
$back_label = '← Home';
require_once __DIR__ . '/includes/public_header.php';
?>


<div class="public-form-container">

    <!-- Stepper Form Card -->
    <div class="card">
        <div class="card-header form-card-header">
            <h1 class="form-title"><?php echo htmlspecialchars($form['title']); ?></h1>
        </div>

        <!-- Edit Registration Link/Form -->
        <?php if ($editing_registration) : ?>
            <div class="alert alert-info" style="margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;">
                <div>
                    <strong>Mode: Editing Registration</strong><br>
                    <span style="font-size: 0.85rem; opacity: 0.9;">Editing details for <strong><?php echo htmlspecialchars($editing_registration['player_name']); ?></strong> (<?php echo htmlspecialchars($editing_registration['mobile_number']); ?>)</span>
                </div>
                <a href="form.php?slug=<?php echo urlencode($slug); ?>" class="btn btn-secondary btn-sm" style="background: rgba(255, 255, 255, 0.15); border-color: transparent; color: white;">Cancel Edit</a>
            </div>
        <?php else : ?>
            <!-- Phone Lookup form -->
            <div id="phone-lookup-container" style="display: <?php echo (!empty($lookup_phone)) ? 'block' : 'none'; ?>; margin-bottom: 2rem; border-radius: var(--radius-md); padding: 1.5rem;">
                <h3 style="font-size: 1.1rem; color: var(--text-primary); margin-top: 0; margin-bottom: 0.5rem; font-style: italic; font-family: var(--font-heading);">FIND YOUR REGISTRATION</h3>
                <p style="font-size: 0.8rem; color: var(--text-secondary); margin-bottom: 1.25rem; line-height: 1.4;">Enter the mobile number used during registration to retrieve and edit your details.</p>
                
                <?php if (!empty($lookup_error)) : ?>
                    <div class="alert alert-danger" style="padding: 0.75rem 1rem; font-size: 0.85rem; margin-bottom: 1rem;">
                        <?php echo $lookup_error; ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($lookup_results)) : ?>
                    <!-- Display matching players list -->
                    <div style="margin-bottom: 1.5rem;">
                        <label class="form-label" style="margin-bottom: 0.75rem; color: var(--accent-yellow);">Multiple registrations found. Select a player to edit:</label>
                        <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                            <?php foreach ($lookup_results as $reg) : ?>
                                <a href="form.php?slug=<?php echo urlencode($slug); ?>&edit_id=<?php echo $reg['id']; ?>&phone=<?php echo urlencode($lookup_phone); ?>" class="form-link-card" style="margin-bottom: 0; padding: 0.75rem 1rem; background: rgba(30, 58, 101, 0.4); text-decoration: none; display: flex; justify-content: space-between; align-items: center; border: 1px solid var(--border-color); border-radius: var(--radius-md);">
                                    <div>
                                        <strong style="color: var(--text-primary); font-size: 0.95rem;"><?php echo htmlspecialchars($reg['player_name']); ?></strong>
                                        <?php if (!empty($reg['jersey_name'])) : ?>
                                            <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.1rem;">Jersey Name: <?php echo htmlspecialchars($reg['jersey_name']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <span class="btn btn-primary btn-sm" style="padding: 0.25rem 0.75rem; font-size: 0.75rem;">Edit ➔</span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <form id="phoneLookupForm" method="GET" action="form.php">
                    <input type="hidden" name="slug" value="<?php echo htmlspecialchars($slug); ?>">
                    <div class="form-group" style="margin-bottom: 1.25rem;">
                        <label class="form-label" for="lookup_phone">Mobile Number</label>
                        <input type="text" id="lookup_phone" name="lookup_phone" class="form-control" placeholder="Enter registered mobile number" value="<?php echo htmlspecialchars($lookup_phone); ?>" required>
                    </div>
                    <div style="display: flex; gap: 0.75rem; justify-content: flex-end;">
                        <button type="button" id="cancel-lookup-btn" class="btn btn-secondary btn-sm">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-sm">Search</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <!-- Landing / Mode Choice Screen -->
        <?php 
        $show_choice_screen = (empty($lookup_phone) && !$editing_registration && $allow_edit_registration);
        ?>
        
        <div id="mode-choice-container" style="display: <?php echo $show_choice_screen ? 'block' : 'none'; ?>; padding: 0.5rem 0 1.5rem 0;">
            <p style="color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 1.75rem; text-align: center; line-height: 1.5;">
                Select an option to proceed with your player kit registration<?php echo $allow_edit_registration ? ' or edit your current details' : ''; ?>.
            </p>
            
            <div style="display: flex; flex-direction: column; gap: 1rem;">
                <!-- Choice 1: New Registration -->
                <button type="button" id="choice-new-btn" class="choice-card" style="background: rgba(11, 21, 40, 0.6); border: 2px solid var(--border-color); border-radius: var(--radius-lg); padding: 1.25rem; text-align: left; cursor: pointer; display: flex; align-items: center; gap: 1.25rem; transition: all var(--transition-normal); width: 100%; color: var(--text-primary); outline: none;">
                    <div style="width: 46px; height: 46px; border-radius: var(--radius-md); background: rgba(225, 29, 72, 0.15); border: 1px solid var(--primary); display: flex; align-items: center; justify-content: center; font-size: 1.3rem; color: var(--primary); flex-shrink: 0;">
                        📝
                    </div>
                    <div style="flex: 1;">
                        <h3 style="font-size: 1.15rem; margin: 0 0 0.2rem 0; color: var(--text-primary); font-family: var(--font-heading); text-transform: uppercase; font-style: italic;">New Registration</h3>
                        <p style="margin: 0; font-size: 0.8rem; color: var(--text-secondary); font-family: var(--font-sans); font-weight: normal; line-height: 1.4;">Submit your details, select sizes, jersey quantities and choose numbers.</p>
                    </div>
                    <div style="font-size: 1.2rem; color: var(--text-muted); padding-left: 0.25rem;">➔</div>
                </button>

                <?php if ($allow_edit_registration) : ?>
                <!-- Choice 2: Edit Existing Registration -->
                <button type="button" id="choice-edit-btn" class="choice-card" style="background: rgba(11, 21, 40, 0.6); border: 2px solid var(--border-color); border-radius: var(--radius-lg); padding: 1.25rem; text-align: left; cursor: pointer; display: flex; align-items: center; gap: 1.25rem; transition: all var(--transition-normal); width: 100%; color: var(--text-primary); outline: none;">
                    <div style="width: 46px; height: 46px; border-radius: var(--radius-md); background: rgba(0, 102, 255, 0.15); border: 1px solid var(--accent-blue); display: flex; align-items: center; justify-content: center; font-size: 1.3rem; color: var(--accent-blue); flex-shrink: 0;">
                        ✏️
                    </div>
                    <div style="flex: 1;">
                        <h3 style="font-size: 1.15rem; margin: 0 0 0.2rem 0; color: var(--text-primary); font-family: var(--font-heading); text-transform: uppercase; font-style: italic;">Edit Registration</h3>
                        <p style="margin: 0; font-size: 0.8rem; color: var(--text-secondary); font-family: var(--font-sans); font-weight: normal; line-height: 1.4;">Retrieve your previous registration using your mobile number to make changes.</p>
                    </div>
                    <div style="font-size: 1.2rem; color: var(--text-muted); padding-left: 0.25rem;">➔</div>
                </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Stepper Progress Header indicators -->
        <div class="stepper-header" style="display: <?php echo $show_choice_screen ? 'none' : 'flex'; ?>;">
            <!-- Step 1 Indicator -->
            <div class="step-indicator active" id="ind-1">1</div>
            
            <!-- Step 2 Indicator (Optional) -->
            <?php if ($has_step2) : ?>
                <div class="step-indicator" id="ind-2">2</div>
            <?php endif; ?>
            
            <!-- Step 3 Indicator (Optional) -->
            <?php if ($has_step3) : ?>
                <div class="step-indicator" id="ind-3"><?php echo $has_step2 ? '3' : '2'; ?></div>
            <?php endif; ?>
        </div>

        <?php if (!empty($errors['global'])) : ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($errors['global']); ?></div>
        <?php endif; ?>

        <form method="POST" id="kitForm" style="display: <?php echo $show_choice_screen ? 'none' : 'block'; ?>;">
            
            <!-- STEP 1: Core Details (Player Name) -->
            <div class="form-step active" id="step-1">
                <h2 class="step-title">Step 1: Player Profile</h2>
                
                <div class="form-group">
                    <label class="form-label" for="player_name">Full Player Name <span style="color:var(--primary);">*</span></label>
                    <?php
                    $player_name_val = '';
                    if (isset($_POST['player_name'])) {
                        $player_name_val = $_POST['player_name'];
                    } elseif ($editing_registration && isset($editing_registration['player_name'])) {
                        $player_name_val = $editing_registration['player_name'];
                    }
                    ?>
                    <input type="text" id="player_name" name="player_name" class="form-control" placeholder="Enter your full name" required value="<?php echo htmlspecialchars($player_name_val); ?>" autocomplete="name" inputmode="text">
                    <?php if (isset($errors['player_name'])) : ?>
                        <small style="color:var(--danger); font-size:0.8rem; margin-top:0.25rem; display:block;"><?php echo htmlspecialchars($errors['player_name']); ?></small>
                    <?php endif; ?>
                </div>

                <?php
                if (!empty($step1_fields)) {
                    render_step_fields($step1_fields, $errors);
                }
                ?>

                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" id="step1-back-btn">Back</button>
                    <?php if ($has_step2 || $has_step3) : ?>
                        <button type="button" class="btn btn-primary" onclick="nextStep(1)">Continue</button>
                    <?php else : ?>
                        <button type="submit" class="btn btn-primary">Submit Registration</button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- STEP 2: Personal Details (Optional) -->
            <?php if ($has_step2) : ?>
                <div class="form-step" id="step-2">
                    <h2 class="step-title">Step 2: Contact & Identification</h2>
                    
                    <?php
                    render_step_fields($step2_fields, $errors);
                    ?>

                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="prevStep(2)">Back</button>
                        <?php if ($has_step3) : ?>
                            <button type="button" class="btn btn-primary" onclick="nextStep(2)">Continue</button>
                        <?php else : ?>
                            <button type="submit" class="btn btn-primary">Submit Registration</button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- STEP 3: Kit Sizes & Customization -->
            <?php if ($has_step3) : ?>
                <div class="form-step" id="step-3">
                    <h2 class="step-title">Step 3: Kit Details &amp; Printing</h2>

                    <?php
                    render_step_fields($step3_fields, $errors);
                    ?>

                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="prevStep(3)">Back</button>
                        <button type="submit" class="btn btn-primary">Submit Registration</button>
                    </div>
                </div>
            <?php endif; ?>

        </form>
    </div>
</div>

<script src="assets/js/form.js"></script>
<script>
// Expose configuration flags to JS stepper
var hasStep2 = <?php echo $has_step2 ? 'true' : 'false'; ?>;
var hasStep3 = <?php echo $has_step3 ? 'true' : 'false'; ?>;

document.addEventListener('DOMContentLoaded', function() {
    var choiceContainer = document.getElementById('mode-choice-container');
    var choiceNewBtn = document.getElementById('choice-new-btn');
    var choiceEditBtn = document.getElementById('choice-edit-btn');
    
    var stepperHeader = document.querySelector('.stepper-header');
    var kitForm = document.getElementById('kitForm');
    var step1BackBtn = document.getElementById('step1-back-btn');
    
    var lookupContainer = document.getElementById('phone-lookup-container');
    var cancelBtn = document.getElementById('cancel-lookup-btn');
    var lookupPhoneInput = document.getElementById('lookup_phone');

    // 1. Choose New Registration
    if (choiceNewBtn && choiceContainer && stepperHeader && kitForm) {
        choiceNewBtn.addEventListener('click', function() {
            choiceContainer.style.display = 'none';
            stepperHeader.style.display = 'flex';
            kitForm.style.display = 'block';
            showStep(1); // Ensure step 1 is active
            if (typeof window.scrollTo === 'function') {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        });
    }

    // 2. Choose Edit Existing Registration
    if (choiceEditBtn && choiceContainer && lookupContainer) {
        choiceEditBtn.addEventListener('click', function() {
            choiceContainer.style.display = 'none';
            lookupContainer.style.display = 'block';
            if (lookupPhoneInput) {
                lookupPhoneInput.focus();
            }
        });
    }

    // 3. Step 1 Back to Menu / Home
    if (step1BackBtn && kitForm && stepperHeader && choiceContainer) {
        step1BackBtn.addEventListener('click', function() {
            <?php if ($allow_edit_registration) : ?>
                kitForm.style.display = 'none';
                stepperHeader.style.display = 'none';
                choiceContainer.style.display = 'block';
            <?php else : ?>
                window.location.href = '<?php echo $back_url; ?>';
            <?php endif; ?>
        });
    }

    // 4. Cancel lookup (go back to menu or reload URL)
    if (cancelBtn && lookupContainer && choiceContainer) {
        cancelBtn.addEventListener('click', function() {
            if (window.location.search.indexOf('lookup_phone') !== -1) {
                window.location.href = 'form.php?slug=<?php echo urlencode($slug); ?>';
            } else {
                lookupContainer.style.display = 'none';
                choiceContainer.style.display = 'block';
            }
        });
    }
});
</script>
<?php require_once __DIR__ . '/includes/public_footer.php'; ?>
</body>
</html>
<?php
// HTML Layout render functions for distinct states
function display_error_page($title, $body)
{
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo htmlspecialchars($title); ?> | Sailors Portal</title>
        <link rel="stylesheet" href="assets/css/style.css">
    </head>
    <body class="public-form-body">
        <?php
        if (!defined('PUBLIC_ROOT')) {
            define('PUBLIC_ROOT', '/aries_jersey/');
        }
        if (!defined('ADMIN_URL')) {
            define('ADMIN_URL', PUBLIC_ROOT . 'admin/login.php');
        }
        require_once __DIR__ . '/includes/public_header.php';
        ?>
        <div class="public-form-container" style="max-width: 480px;">
            <div class="card" style="text-align: center;">
                <div style="font-size: 3rem; margin-bottom: 1rem;">⚠️</div>
                <h1 style="font-size: 1.5rem; color: var(--primary); margin-bottom: 1rem;"><?php echo htmlspecialchars($title); ?></h1>
                <p style="color: var(--text-secondary); font-size: 0.95rem; line-height: 1.6;"><?php echo htmlspecialchars($body); ?></p>
                <div style="margin-top: 2rem;">
                    <a href="javascript:history.back()" class="btn btn-secondary btn-sm">Go Back</a>
                </div>
            </div>
        </div>
        <?php require_once __DIR__ . '/includes/public_footer.php'; ?>
    </body>
    </html>
    <?php
}

function display_closed_page($formTitle)
{
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Registrations Closed | Sailors Portal</title>
        <link rel="stylesheet" href="assets/css/style.css">
    </head>
    <body class="public-form-body">
        <?php
        if (!defined('PUBLIC_ROOT')) {
            define('PUBLIC_ROOT', '/aries_jersey/');
        }
        if (!defined('ADMIN_URL')) {
            define('ADMIN_URL', PUBLIC_ROOT . 'admin/login.php');
        }
        require_once __DIR__ . '/includes/public_header.php';
        ?>
        <div class="public-form-container" style="max-width: 500px;">
            <div class="card" style="text-align: center;">
                <div style="font-size: 3.5rem; margin-bottom: 1rem;">🔏</div>
                <h1 style="font-size: 1.75rem; color: var(--primary); margin-bottom: 0.5rem;">Registrations Closed</h1>
                <p style="color: var(--text-muted); font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 700; margin-bottom: 1rem;"><?php echo htmlspecialchars($formTitle); ?></p>
                <p style="color: var(--text-secondary); font-size: 0.95rem; line-height: 1.6;">
                     Submissions are no longer being accepted for this registration form. Please contact your team manager or administrator for assistance.
                </p>
                <div style="margin-top: 2rem; border-top: 1px solid var(--border-color); padding-top: 1.5rem; text-align: center;">
                    <span class="badge badge-danger">Status: Offline</span>
                </div>
            </div>
        </div>
        <?php require_once __DIR__ . '/includes/public_footer.php'; ?>
    </body>
    </html>
    <?php
}

function display_success_page($formTitle, $summary)
{
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Registration Complete! | Sailors Portal</title>
        <link rel="stylesheet" href="assets/css/style.css">
    </head>
    <body class="public-form-body">
        <?php
        if (!defined('PUBLIC_ROOT')) {
            define('PUBLIC_ROOT', '/aries_jersey/');
        }
        if (!defined('ADMIN_URL')) {
            define('ADMIN_URL', PUBLIC_ROOT . 'admin/login.php');
        }
        require_once __DIR__ . '/includes/public_header.php';
        ?>
        <div class="public-form-container" style="max-width: 540px;">
            <div class="card" style="position: relative; overflow: hidden;">
                <?php
                $is_update = isset($_GET['success']) && $_GET['success'] == '2';
                ?>
                
                <!-- Success icon header -->
                <div style="text-align: center; margin-bottom: 1.5rem;">
                    <div style="width: 70px; height: 70px; border-radius: 50%; background: var(--success-glow); border: 2px solid var(--success); color: var(--success); display: flex; align-items: center; justify-content: center; font-size: 2.5rem; margin: 0 auto 1rem auto; animation: pulse 2s infinite;">✓</div>
                    <h1 style="font-size: 1.75rem; color: var(--success);"><?php echo $is_update ? 'Registration Updated!' : 'Registration Success!'; ?></h1>
                    <p style="color: var(--text-secondary); font-size: 0.9rem;"><?php echo htmlspecialchars($formTitle); ?></p>
                </div>
                
                <p style="color: var(--text-secondary); font-size: 0.95rem; text-align: center; margin-bottom: 2rem;">
                    <?php echo $is_update ? 'Your kit and uniform details have been updated in the database. Below is your updated confirmation summary:' : 'Your kit and uniform details have been registered in the database. Below is your printing confirmation summary:'; ?>
                </p>

                <?php if ($summary) : ?>
                    <div style="background: rgba(30, 58, 101, 0.4); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 1.25rem 1.5rem; margin-bottom: 1.5rem;">
                        <div style="display: grid; grid-template-columns: 1fr 1.5fr; gap: 0.75rem; font-size: 0.95rem;">
                            <div style="color: var(--text-muted); font-weight: 600; text-transform: uppercase; font-size: 0.8rem;">Player Name:</div>
                            <div style="font-weight: 700; color: var(--text-primary);"><?php echo htmlspecialchars($summary['player_name']); ?></div>
                            
                            <div style="color: var(--text-muted); font-weight: 600; text-transform: uppercase; font-size: 0.8rem;">Team:</div>
                            <div style="font-weight: 700; color: var(--accent-blue);">Kollam Sailors</div>
                            
                            <?php if (!empty($summary['jersey_name'])) : ?>
                                <div style="color: var(--text-muted); font-weight: 600; text-transform: uppercase; font-size: 0.8rem;">Jersey Name:</div>
                                <div style="font-weight: 800; font-family: monospace; letter-spacing: 0.05em; color: var(--primary);"><?php echo htmlspecialchars($summary['jersey_name']); ?></div>
                            <?php endif; ?>
                            
                            <?php if (!empty($summary['jersey_number_opt1'])) : ?>
                                <div style="color: var(--text-muted); font-weight: 600; text-transform: uppercase; font-size: 0.8rem;">Jersey #1 (Priority):</div>
                                <div style="font-weight: 800; font-family: monospace; font-size: 1.1rem; color: var(--accent-yellow);"><?php echo htmlspecialchars($summary['jersey_number_opt1']); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($summary['jersey_number_opt2'])) : ?>
                                <div style="color: var(--text-muted); font-weight: 600; text-transform: uppercase; font-size: 0.8rem;">Jersey #2 (Alt):</div>
                                <div style="font-weight: 800; font-family: monospace; font-size: 1.1rem; color: var(--accent-yellow); opacity:.7;"><?php echo htmlspecialchars($summary['jersey_number_opt2']); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($summary['jersey_number_opt3'])) : ?>
                                <div style="color: var(--text-muted); font-weight: 600; text-transform: uppercase; font-size: 0.8rem;">Jersey #3 (Alt):</div>
                                <div style="font-weight: 800; font-family: monospace; font-size: 1.1rem; color: var(--accent-yellow); opacity:.5;"><?php echo htmlspecialchars($summary['jersey_number_opt3']); ?></div>
                            <?php endif; ?>
                            
                            <?php if (($summary['half_sleeve_qty'] ?? 0) > 0 || ($summary['full_sleeve_qty'] ?? 0) > 0) : ?>
                                <div style="color: var(--text-muted); font-weight: 600; text-transform: uppercase; font-size: 0.8rem;">Playing Jerseys:</div>
                                <div style="font-weight: 700; color: var(--text-primary);">
                                    <?php
                                    $parts = [];
                                    if (($summary['half_sleeve_qty'] ?? 0) > 0) {
                                        $parts[] = $summary['half_sleeve_qty'] . '× Half Sleeve';
                                    }
                                    if (($summary['full_sleeve_qty'] ?? 0) > 0) {
                                        $parts[] = $summary['full_sleeve_qty'] . '× Full Sleeve';
                                    }
                                    echo implode(', ', $parts);
                                    ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if ($allow_edit_registration) : ?>
                <p style="color: var(--text-muted); font-size: 0.8rem; text-align: center; font-style: italic;">
                    Need to correct an entry again? You can search and edit it using your phone number at any time before manufacturing begins.
                </p>
                <?php endif; ?>

                <div style="margin-top: 2rem; display: flex; justify-content: center; gap: 1rem;">
                    <?php if ($is_update) : ?>
                        <a href="form.php?slug=<?php echo urlencode($_GET['slug']); ?>" class="btn btn-secondary btn-sm">Back to Form</a>
                    <?php else : ?>
                        <a href="form.php?slug=<?php echo urlencode($_GET['slug']); ?>" class="btn btn-secondary btn-sm">Register Another Player</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php require_once __DIR__ . '/includes/public_footer.php'; ?>
    </body>
    </html>
    <?php
}
