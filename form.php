<?php
// form.php
require_once __DIR__ . '/includes/db_config.php';
require_once __DIR__ . '/includes/form_helpers.php';

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

$step2_fields = [];
$step3_fields = [];

foreach ($fields_config as $key => $config) {
    if (in_array($key, $personal_keys)) {
        $step2_fields[$key] = $config;
    } elseif (in_array($key, $kit_keys)) {
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
    $has_error = isset($errors[$key]);
    $value = isset($_POST[$key]) ? htmlspecialchars($_POST[$key]) : '';

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
        $cur = isset($_POST[$key]) ? intval($_POST[$key]) : 0;
        echo '<div class="stepper-container">';
        echo '<button type="button" class="stepper-btn" onclick="adjustQty(\'' . $key . '\', -1)" id="btn-minus-' . $key . '"' . ($cur <= 0 ? ' disabled' : '') . '>−</button>';
        echo '<span class="stepper-input" id="display-' . $key . '">' . $cur . '</span>';
        echo '<input type="hidden" name="' . $key . '" id="' . $key . '" value="' . $cur . '">';
        echo '<button type="button" class="stepper-btn" onclick="adjustQty(\'' . $key . '\', 1)" id="btn-plus-' . $key . '"' . ($cur >= 3 ? ' disabled' : '') . '>+</button>';
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
define('PUBLIC_ROOT', '/aries_jersey/');
define('ADMIN_URL', '/aries_jersey/admin/login.php');
$back_url   = '/aries_jersey/';
$back_label = '← Home';
require_once __DIR__ . '/includes/public_header.php';
?>


<div class="public-form-container">

    <!-- Stepper Form Card -->
    <div class="card">
        <div class="card-header form-card-header">
            <h1 class="form-title"><?php echo htmlspecialchars($form['title']); ?></h1>
        </div>

        <!-- Stepper Progress Header indicators -->
        <div class="stepper-header">
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

        <form method="POST" id="kitForm">
            
            <!-- STEP 1: Core Details (Player Name) -->
            <div class="form-step active" id="step-1">
                <h2 class="step-title">Step 1: Player Profile</h2>
                
                <div class="form-group">
                    <label class="form-label" for="player_name">Full Player Name <span style="color:var(--primary);">*</span></label>
                    <input type="text" id="player_name" name="player_name" class="form-control" placeholder="Enter your full name" required value="<?php echo isset($_POST['player_name']) ? htmlspecialchars($_POST['player_name']) : ''; ?>" autocomplete="name" inputmode="text">
                    <?php if (isset($errors['player_name'])) : ?>
                        <small style="color:var(--danger); font-size:0.8rem; margin-top:0.25rem; display:block;"><?php echo htmlspecialchars($errors['player_name']); ?></small>
                    <?php endif; ?>
                </div>

                <div class="form-actions align-right">
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
                    foreach ($step2_fields as $key => $config) {
                        render_field_input($key, $config, $errors);
                    }
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
                    /* ── classify enabled kit fields by group ── */
                    $size_keys    = ['upper_jersey_size','lower_jersey_size','helmet_size','pad_size'];
                    $hand_key     = 'batting_hand';
                    $sleeve_keys  = ['half_sleeve_qty','full_sleeve_qty'];
                    $jname_key    = 'jersey_name';
                    $jnum_keys    = ['jersey_number_opt1','jersey_number_opt2','jersey_number_opt3'];
                    $jnum_labels  = ['Option 1 (Priority)','Option 2','Option 3'];
                    $legacy_keys  = ['player_id','mobile_number','initials','jersey_number','shorts_size','trouser_size','socks_size','chest_size'];

                    $active_sizes   = array_intersect_key($step3_fields, array_flip($size_keys));
                    $active_sleeves = array_intersect_key($step3_fields, array_flip($sleeve_keys));
                    $active_jnums   = array_intersect_key($step3_fields, array_flip($jnum_keys));
                    ?>

                    <?php /* ── SIZE GRID: 2 columns ── */
                    if (!empty($active_sizes)) : ?>
                    <div class="kit-section-label">Jersey &amp; Kit Sizes</div>
                    <div class="kit-grid-2">
                        <?php foreach ($size_keys as $k) :
                            if (!isset($step3_fields[$k])) {
                                continue;
                            }
                            render_field_input($k, $step3_fields[$k], $errors);
                        endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <?php /* ── BATTING HAND ── */
                    if (isset($step3_fields[$hand_key])) : ?>
                    <div class="kit-section-label">Batting Preference</div>
                        <?php render_field_input($hand_key, $step3_fields[$hand_key], $errors); ?>
                    <?php endif; ?>

                    <?php /* ── SLEEVE STEPPERS: side by side ── */
                    if (!empty($active_sleeves)) : ?>
                    <div class="kit-section-label">Playing Jersey Quantity</div>
                    <div class="sleeve-row">
                        <?php foreach ($sleeve_keys as $k) :
                            if (!isset($step3_fields[$k])) {
                                continue;
                            }
                            $c = $step3_fields[$k];
                            $cur = isset($_POST[$k]) ? intval($_POST[$k]) : 0;
                            ?>
                        <div class="sleeve-cell">
                            <div class="form-label" style="margin-bottom:0.5rem;">
                                <?php echo htmlspecialchars($c['label']); ?>
                            </div>
                            <div class="stepper-container">
                                <button type="button" class="stepper-btn" onclick="adjustQty('<?php echo $k; ?>',-1)" id="btn-minus-<?php echo $k; ?>"<?php echo $cur <= 0 ? ' disabled' : ''; ?>>−</button>
                                <span class="stepper-input" id="display-<?php echo $k; ?>"><?php echo $cur; ?></span>
                                <input type="hidden" name="<?php echo $k; ?>" id="<?php echo $k; ?>" value="<?php echo $cur; ?>">
                                <button type="button" class="stepper-btn" onclick="adjustQty('<?php echo $k; ?>',1)" id="btn-plus-<?php echo $k; ?>"<?php echo $cur >= 3 ? ' disabled' : ''; ?>>+</button>
                            </div>
                            <?php if (isset($errors[$k])) : ?>
                                <small style="color:var(--danger);font-size:0.78rem;display:block;margin-top:0.25rem;"><?php echo htmlspecialchars($errors[$k]); ?></small>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                        <div class="sleeve-note">Max 3 per style<br>Combined max 4</div>
                    </div>
                    <?php endif; ?>

                    <?php /* ── JERSEY NAME ── */
                    if (isset($step3_fields[$jname_key])) : ?>
                    <div class="kit-section-label">Jersey Printing</div>
                        <?php render_field_input($jname_key, $step3_fields[$jname_key], $errors); ?>
                    <?php endif; ?>

                    <?php /* ── JERSEY NUMBER PRIORITY ── */
                    if (!empty($active_jnums)) : ?>
                    <div class="form-group" style="margin-top:0.25rem;">
                        <label class="form-label">Jersey Number
                            <?php if (isset($step3_fields['jersey_number_opt1']['required']) && $step3_fields['jersey_number_opt1']['required']) : ?>
                                <span style="color:var(--primary);">*</span>
                            <?php endif; ?>
                            <span style="font-weight:400;color:var(--text-muted);font-size:0.75rem;text-transform:none;letter-spacing:0;"> 0–99 · no duplicates</span>
                        </label>
                        <div class="jersey-number-grid">
                            <?php foreach ($jnum_keys as $i => $opt_key) :
                                if (!isset($step3_fields[$opt_key])) {
                                    continue;
                                }
                                $opt_val = isset($_POST[$opt_key]) ? htmlspecialchars($_POST[$opt_key]) : '';
                                $has_opt_err = isset($errors[$opt_key]);
                                ?>
                            <div>
                                <label style="display:block;font-size:0.75rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;margin-bottom:0.35rem;">
                                    <?php echo $jnum_labels[$i];
                                    if ($i === 0) {
                                        echo ' <span style="color:var(--primary)">*</span>';
                                    } ?>
                                </label>
                                <input type="number" id="<?php echo $opt_key; ?>" name="<?php echo $opt_key; ?>"
                                    class="form-control<?php echo $has_opt_err ? ' invalid' : ''; ?>"
                                    inputmode="numeric" min="0" max="99" placeholder="0–99"
                                    value="<?php echo $opt_val; ?>" autocomplete="off"
                                    <?php echo ($i === 0 && ($step3_fields[$opt_key]['required'] ?? false)) ? 'required' : ''; ?>>
                                <?php if ($has_opt_err) : ?>
                                    <small style="color:var(--danger);font-size:0.75rem;display:block;margin-top:0.2rem;"><?php echo htmlspecialchars($errors[$opt_key]); ?></small>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php /* ── LEGACY FIELDS (if enabled) ── */
                    foreach ($step3_fields as $key => $config) :
                        if (in_array($key, array_merge($size_keys, [$hand_key], $sleeve_keys, [$jname_key], $jnum_keys))) {
                            continue;
                        }
                        render_field_input($key, $config, $errors);
                    endforeach; ?>

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
            define('ADMIN_URL', '/aries_jersey/admin/login.php');
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
            define('ADMIN_URL', '/aries_jersey/admin/login.php');
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
            define('ADMIN_URL', '/aries_jersey/admin/login.php');
        }
        require_once __DIR__ . '/includes/public_header.php';
        ?>
        <div class="public-form-container" style="max-width: 540px;">
            <div class="card" style="position: relative; overflow: hidden;">
                
                <!-- Success icon header -->
                <div style="text-align: center; margin-bottom: 1.5rem;">
                    <div style="width: 70px; height: 70px; border-radius: 50%; background: var(--success-glow); border: 2px solid var(--success); color: var(--success); display: flex; align-items: center; justify-content: center; font-size: 2.5rem; margin: 0 auto 1rem auto; animation: pulse 2s infinite;">✓</div>
                    <h1 style="font-size: 1.75rem; color: var(--success);">Registration Success!</h1>
                    <p style="color: var(--text-secondary); font-size: 0.9rem;"><?php echo htmlspecialchars($formTitle); ?></p>
                </div>
                
                <p style="color: var(--text-secondary); font-size: 0.95rem; text-align: center; margin-bottom: 2rem;">
                    Your kit and uniform details have been registered in the database. Below is your printing confirmation summary:
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
                
                <p style="color: var(--text-muted); font-size: 0.8rem; text-align: center; font-style: italic;">
                    Need to correct an entry? Please contact your team administration to update your details before print manufacturing begins.
                </p>

                <div style="margin-top: 2rem; display: flex; justify-content: center;">
                    <a href="form.php?slug=<?php echo urlencode($_GET['slug']); ?>" class="btn btn-secondary btn-sm">Register Another Player</a>
                </div>
            </div>
        </div>
        <?php require_once __DIR__ . '/includes/public_footer.php'; ?>
    </body>
    </html>
    <?php
}
