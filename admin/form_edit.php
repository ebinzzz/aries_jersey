<?php
// admin/form_edit.php
require_once dirname(__DIR__) . '/includes/db_config.php';
require_once dirname(__DIR__) . '/includes/auth.php';

require_login();
$db = get_db_connection();

$id = intval($_GET['id'] ?? 0);
$is_edit = ($id > 0);

$form_title = '';
$form_slug = '';
$form_status = 'open';
$field_configs = []; // key => ['enabled' => bool, 'required' => bool]

$message = '';
$message_type = 'success';

// Fetch field catalog
$catalog = [];
$res = $db->query("SELECT * FROM `field_catalog` ORDER BY `field_label` ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $catalog[] = $row;
    }
    $res->free();
}

// Fetch form data if editing
if ($is_edit) {
    $stmt = $db->prepare("SELECT * FROM `forms` WHERE `id` = ? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $form = $result->fetch_assoc();
        $form_title = $form['title'];
        $form_slug = $form['slug'];
        $form_status = $form['status'];
    } else {
        header("Location: index.php");
        exit;
    }
    $stmt->close();
    
    // Fetch configs
    $stmt = $db->prepare("SELECT * FROM `form_field_configs` WHERE `form_id` = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res_config = $stmt->get_result();
    if ($res_config) {
        while ($row = $res_config->fetch_assoc()) {
            $field_configs[$row['field_key']] = [
                'enabled' => $row['is_enabled'] == 1,
                'required' => $row['is_required'] == 1
            ];
        }
        $res_config->free();
    }
    $stmt->close();
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $message = "CSRF verification failed.";
        $message_type = "danger";
    } else {
        $form_title = trim($_POST['title'] ?? '');
        $form_slug = trim($_POST['slug'] ?? '');
        $form_status = $_POST['status'] ?? 'open';
        
        // Generate slug if empty
        if (empty($form_slug)) {
            $form_slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $form_title), '-'));
        } else {
            $form_slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $form_slug), '-'));
        }
        
        if (empty($form_title)) {
            $message = "Form Title is required.";
            $message_type = "danger";
        } else {
            // Check unique slug
            $slug_check_query = "SELECT id FROM `forms` WHERE `slug` = ? " . ($is_edit ? "AND id != ?" : "");
            $stmt = $db->prepare($slug_check_query);
            if ($is_edit) {
                $stmt->bind_param("si", $form_slug, $id);
            } else {
                $stmt->bind_param("s", $form_slug);
            }
            $stmt->execute();
            $slug_check_res = $stmt->get_result();
            
            if ($slug_check_res && $slug_check_res->num_rows > 0) {
                $message = "A form with this slug or URL already exists. Please choose a different title or slug.";
                $message_type = "danger";
                $stmt->close();
            } else {
                $stmt->close();
                
                $db->begin_transaction();
                try {
                    if ($is_edit) {
                        $stmt = $db->prepare("UPDATE `forms` SET `title` = ?, `slug` = ?, `status` = ? WHERE `id` = ?");
                        $stmt->bind_param("sssi", $form_title, $form_slug, $form_status, $id);
                        $stmt->execute();
                        $stmt->close();
                        $form_id = $id;
                    } else {
                        $stmt = $db->prepare("INSERT INTO `forms` (`title`, `slug`, `status`) VALUES (?, ?, ?)");
                        $stmt->bind_param("sss", $form_title, $form_slug, $form_status);
                        $stmt->execute();
                        $form_id = $stmt->insert_id;
                        $stmt->close();
                    }
                    
                    // Clear old field configs
                    $stmt = $db->prepare("DELETE FROM `form_field_configs` WHERE `form_id` = ?");
                    $stmt->bind_param("i", $form_id);
                    $stmt->execute();
                    $stmt->close();
                    
                    // Save new configs
                    $stmt = $db->prepare("INSERT INTO `form_field_configs` (`form_id`, `field_key`, `is_enabled`, `is_required`) VALUES (?, ?, ?, ?)");
                    foreach ($catalog as $field) {
                        $fkey = $field['field_key'];
                        $is_enabled = isset($_POST['fields'][$fkey]['enabled']) ? 1 : 0;
                        $is_required = isset($_POST['fields'][$fkey]['required']) ? 1 : 0;
                        
                        // Force required to be 0 if not enabled
                        if (!$is_enabled) $is_required = 0;
                        
                        $stmt->bind_param("isii", $form_id, $fkey, $is_enabled, $is_required);
                        $stmt->execute();
                        
                        // Update local configs state
                        $field_configs[$fkey] = [
                            'enabled' => $is_enabled == 1,
                            'required' => $is_required == 1
                        ];
                    }
                    $stmt->close();
                    
                    $db->commit();
                    
                    if ($is_edit) {
                        $message = "Form updated successfully!";
                    } else {
                        header("Location: index.php");
                        exit;
                    }
                } catch (Exception $e) {
                    $db->rollback();
                    $message = "Failed to save form settings: " . $e->getMessage();
                    $message_type = "danger";
                }
            }
        }
    }
}

$csrf_token = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $is_edit ? 'Edit Form' : 'Create New Form'; ?> | PlayerKit Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>

<div class="app-container">
    <?php include '_partials/sidebar.php'; ?>
    
    <main class="main-content">
        <div class="page-header">
            <div class="page-title">
                <h1><?php echo $is_edit ? 'Edit Form Details' : 'Create New Form'; ?></h1>
                <p><?php echo $is_edit ? 'Update options and toggleable fields for your form.' : 'Setup a new custom kit registration page with custom options.'; ?></p>
            </div>
            <div>
                <a href="index.php" class="btn btn-secondary">Back to Dashboard</a>
            </div>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            
            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 2rem;">
                
                <!-- Left: Title, Slug, Field Configuration -->
                <div style="display: flex; flex-direction: column; gap: 2rem;">
                    
                    <!-- Core Info Card -->
                    <div class="card">
                        <div class="card-header">
                            <h2>Core Information</h2>
                        </div>
                        
                        <div class="form-group">
                            <label for="title" class="form-label">Form Title</label>
                            <input type="text" id="title" name="title" class="form-control" placeholder="e.g. U-16 Kit Registration 2026" required value="<?php echo htmlspecialchars($form_title); ?>" oninput="generateSlug(this.value)">
                        </div>
                        
                        <div class="form-group">
                            <label for="slug" class="form-label">Shareable URL Slug</label>
                            <input type="text" id="slug" name="slug" class="form-control" placeholder="e.g. u-16-kit-2026" value="<?php echo htmlspecialchars($form_slug); ?>">
                            <small style="color: var(--text-muted); font-size: 0.8rem; display: block; margin-top: 0.25rem;">
                                Leave blank to generate automatically from the title. Must contain only letters, numbers, and dashes.
                            </small>
                        </div>
                    </div>

                    <!-- Fields Selection Card -->
                    <div class="card">
                        <div class="card-header">
                            <h2>Toggleable Registration Fields</h2>
                            <span class="badge badge-success">Dynamic Catalog</span>
                        </div>
                        
                        <p style="color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 1.5rem;">
                            Player Name and Team Selection are core fields and are <strong>always active & required</strong>. Toggle the fields below to customize this registration form.
                        </p>

                        <!-- Standard table of fields -->
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Field Name</th>
                                        <th>Status (Show in Form)</th>
                                        <th>Requirement (Required)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- Core Row 1: Player Name -->
                                    <tr style="opacity: 0.7;">
                                        <td><strong>Player Name</strong> <span style="font-size:0.75rem; color:var(--text-muted);">(Core Field)</span></td>
                                        <td><span class="badge badge-success">Always On</span></td>
                                        <td><span class="badge badge-success">Required</span></td>
                                    </tr>
                                    <!-- Core Row 2: Team — auto-assigned, not shown to player -->
                                    <tr style="opacity: 0.5;">
                                        <td><strong>Team</strong> <span style="font-size:0.75rem; color:var(--text-muted);">(Auto: Kollam Sailors)</span></td>
                                        <td><span class="badge badge-warning">Auto-assigned</span></td>
                                        <td><span class="badge badge-warning">Hidden</span></td>
                                    </tr>
                                    
                                    <!-- Dynamic fields -->
                                    <?php foreach ($catalog as $field): 
                                        $fkey = $field['field_key'];
                                        $enabled = isset($field_configs[$fkey]['enabled']) && $field_configs[$fkey]['enabled'];
                                        $required = isset($field_configs[$fkey]['required']) && $field_configs[$fkey]['required'];
                                    ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($field['field_label']); ?></strong>
                                                <div style="font-size: 0.75rem; color: var(--text-muted);">
                                                    Type: <?php echo htmlspecialchars(ucfirst($field['field_type'])); ?> 
                                                    <?php echo $field['options_list'] ? '('.htmlspecialchars($field['options_list']).')' : ''; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <label class="switch">
                                                    <input type="checkbox" name="fields[<?php echo $fkey; ?>][enabled]" value="1" <?php echo $enabled ? 'checked' : ''; ?> onchange="toggleRequiredCheckbox('<?php echo $fkey; ?>', this.checked)">
                                                    <span class="slider"></span>
                                                </label>
                                            </td>
                                            <td>
                                                <label class="switch">
                                                    <input type="checkbox" id="req-<?php echo $fkey; ?>" name="fields[<?php echo $fkey; ?>][required]" value="1" <?php echo $required ? 'checked' : ''; ?> <?php echo !$enabled ? 'disabled' : ''; ?>>
                                                    <span class="slider"></span>
                                                </label>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Right: Status Panel -->
                <div style="display: flex; flex-direction: column; gap: 2rem;">
                    <div class="card">
                        <div class="card-header">
                            <h2>Status Settings</h2>
                        </div>
                        
                        <div class="form-group">
                            <label for="status" class="form-label">Form Access Status</label>
                            <select id="status" name="status" class="form-control">
                                <option value="open" <?php echo $form_status === 'open' ? 'selected' : ''; ?>>Open (Accept Submissions)</option>
                                <option value="closed" <?php echo $form_status === 'closed' ? 'selected' : ''; ?>>Closed (Read-only)</option>
                            </select>
                        </div>
                        
                        <div style="margin-top: 2rem; display: flex; flex-direction: column; gap: 0.75rem;">
                            <button type="submit" class="btn btn-primary" style="width: 100%;">
                                <?php echo $is_edit ? 'Save Settings' : 'Publish Registration Form'; ?>
                            </button>
                            <a href="index.php" class="btn btn-secondary" style="width: 100%;">Cancel</a>
                        </div>
                    </div>
                </div>
                
            </div>
        </form>
    </main>
</div>

<script>
function generateSlug(text) {
    <?php if ($is_edit): ?>
        // If editing, we do not want to auto-overwrite the slug unless they clear it first
        var slugInput = document.getElementById('slug');
        if (slugInput.dataset.manual === 'true') return;
    <?php endif; ?>
    
    var slug = text.toLowerCase()
        .replace(/[^a-z0-9\s-]/g, '') // remove invalid characters
        .replace(/\s+/g, '-')         // replace spaces with dashes
        .replace(/-+/g, '-')          // replace multiple dashes with single
        .trim();
        
    document.getElementById('slug').value = slug;
}

// Track manual slug changes
document.getElementById('slug').addEventListener('input', function() {
    this.dataset.manual = 'true';
});

function toggleRequiredCheckbox(fieldKey, isEnabled) {
    var reqCheckbox = document.getElementById('req-' + fieldKey);
    if (isEnabled) {
        reqCheckbox.removeAttribute('disabled');
    } else {
        reqCheckbox.checked = false;
        reqCheckbox.setAttribute('disabled', 'true');
    }
}
</script>
</body>
</html>
