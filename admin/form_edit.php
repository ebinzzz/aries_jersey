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
$field_configs = []; // key => ['enabled' => bool, 'required' => bool, 'step_number' => int, 'sort_order' => int]

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
                'required' => $row['is_required'] == 1,
                'step_number' => intval($row['step_number'] ?? 3),
                'sort_order' => intval($row['sort_order'] ?? 0)
            ];
        }
        $res_config->free();
    }
    $stmt->close();
} else {
    // Default config when creating a new form
    $default_step2 = ['player_id', 'mobile_number', 'initials'];
    foreach ($catalog as $field) {
        $fkey = $field['field_key'];
        $field_configs[$fkey] = [
            'enabled' => false,
            'required' => false,
            'step_number' => in_array($fkey, $default_step2) ? 2 : 3,
            'sort_order' => 999
        ];
    }
}

// Group fields into steps for presentation
$catalog_step1 = [];
$catalog_step2 = [];
$catalog_step3 = [];

foreach ($catalog as $field) {
    $fkey = $field['field_key'];
    $step = isset($field_configs[$fkey]['step_number']) ? intval($field_configs[$fkey]['step_number']) : 3;
    if ($step === 1) {
        $catalog_step1[] = $field;
    } elseif ($step === 2) {
        $catalog_step2[] = $field;
    } else {
        $catalog_step3[] = $field;
    }
}

// Sort each step group by its saved sort_order
$sort_func = function($a, $b) use ($field_configs) {
    $a_order = $field_configs[$a['field_key']]['sort_order'] ?? 999;
    $b_order = $field_configs[$b['field_key']]['sort_order'] ?? 999;
    return $a_order <=> $b_order;
};

usort($catalog_step1, $sort_func);
usort($catalog_step2, $sort_func);
usort($catalog_step3, $sort_func);

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
                    $stmt = $db->prepare("INSERT INTO `form_field_configs` (`form_id`, `field_key`, `is_enabled`, `is_required`, `step_number`, `sort_order`) VALUES (?, ?, ?, ?, ?, ?)");
                    foreach ($catalog as $field) {
                        $fkey = $field['field_key'];
                        $is_enabled = isset($_POST['fields'][$fkey]['enabled']) ? 1 : 0;
                        $is_required = isset($_POST['fields'][$fkey]['required']) ? 1 : 0;
                        $step_number = intval($_POST['fields'][$fkey]['step_number'] ?? 3);
                        $sort_order = intval($_POST['fields'][$fkey]['sort_order'] ?? 0);

                        // Force required to be 0 if not enabled
                        if (!$is_enabled) {
                            $is_required = 0;
                        }

                        $stmt->bind_param("isiiii", $form_id, $fkey, $is_enabled, $is_required, $step_number, $sort_order);
                        $stmt->execute();

                        // Update local configs state
                        $field_configs[$fkey] = [
                            'enabled' => $is_enabled == 1,
                            'required' => $is_required == 1,
                            'step_number' => $step_number,
                            'sort_order' => $sort_order
                        ];
                    }
                    $stmt->close();

                    $db->commit();

                    if ($is_edit) {
                        // Refresh display lists from updated configs
                        $catalog_step1 = [];
                        $catalog_step2 = [];
                        $catalog_step3 = [];

                        foreach ($catalog as $field) {
                            $fkey = $field['field_key'];
                            $step = isset($field_configs[$fkey]['step_number']) ? intval($field_configs[$fkey]['step_number']) : 3;
                            if ($step === 1) {
                                $catalog_step1[] = $field;
                            } elseif ($step === 2) {
                                $catalog_step2[] = $field;
                            } else {
                                $catalog_step3[] = $field;
                            }
                        }
                        usort($catalog_step1, $sort_func);
                        usort($catalog_step2, $sort_func);
                        usort($catalog_step3, $sort_func);

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

function render_draggable_item($field, $field_configs) {
    $fkey = $field['field_key'];
    $enabled = isset($field_configs[$fkey]['enabled']) && $field_configs[$fkey]['enabled'];
    $required = isset($field_configs[$fkey]['required']) && $field_configs[$fkey]['required'];
    $step = isset($field_configs[$fkey]['step_number']) ? intval($field_configs[$fkey]['step_number']) : 3;
    $sort_order = isset($field_configs[$fkey]['sort_order']) ? intval($field_configs[$fkey]['sort_order']) : 999;
    ?>
    <div class="drag-item" draggable="true" data-key="<?php echo $fkey; ?>">
        <div class="drag-handle">☰</div>
        <div class="drag-details">
            <strong style="color: var(--text-primary);"><?php echo htmlspecialchars($field['field_label']); ?></strong>
            <span class="field-type-badge"><?php echo htmlspecialchars($field['field_type']); ?></span>
        </div>
        <div class="drag-controls">
            <label class="toggle-label">
                <span>Show</span>
                <input type="checkbox" name="fields[<?php echo $fkey; ?>][enabled]" class="field-enabled" value="1" <?php echo $enabled ? 'checked' : ''; ?> onchange="toggleRequired('<?php echo $fkey; ?>', this.checked)">
            </label>
            <label class="toggle-label req-label" id="req-label-<?php echo $fkey; ?>" style="<?php echo $enabled ? '' : 'opacity: 0.5; pointer-events: none;'; ?>">
                <span>Required</span>
                <input type="checkbox" id="req-<?php echo $fkey; ?>" name="fields[<?php echo $fkey; ?>][required]" class="field-required" value="1" <?php echo $required ? 'checked' : ''; ?> <?php echo !$enabled ? 'disabled' : ''; ?>>
            </label>
        </div>
        <input type="hidden" name="fields[<?php echo $fkey; ?>][step_number]" class="step-input" value="<?php echo $step; ?>">
        <input type="hidden" name="fields[<?php echo $fkey; ?>][sort_order]" class="order-input" value="<?php echo $sort_order; ?>">
    </div>
    <?php
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $is_edit ? 'Edit Form' : 'Create New Form'; ?> | PlayerKit Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .drag-container {
            background: rgba(30, 58, 101, 0.1);
            border: 2px dashed rgba(255, 255, 255, 0.1);
            border-radius: var(--radius-md);
            padding: 1rem;
            min-height: 100px;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            transition: background-color 0.2s, border-color 0.2s;
            margin-bottom: 1.5rem;
        }
        .drag-container.drag-over {
            background: rgba(0, 102, 255, 0.15);
            border-color: var(--accent-blue);
        }
        .drag-item {
            background: rgba(11, 21, 40, 0.85);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 0.75rem 1rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            cursor: grab;
            user-select: none;
            transition: transform 0.1s, box-shadow 0.1s, border-color 0.2s;
        }
        .drag-item:active {
            cursor: grabbing;
        }
        .drag-item.dragging {
            opacity: 0.4;
            border-color: var(--accent-blue);
            box-shadow: 0 0 15px rgba(0, 102, 255, 0.3);
        }
        .drag-handle {
            color: var(--text-muted);
            font-size: 1.2rem;
            cursor: grab;
        }
        .drag-details {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .field-type-badge {
            font-size: 0.68rem;
            background: rgba(255, 255, 255, 0.08);
            color: var(--text-muted);
            padding: 0.15rem 0.4rem;
            border-radius: 4px;
            text-transform: uppercase;
            font-weight: 600;
        }
        .drag-controls {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .toggle-label {
            display: flex;
            align-items: center;
            gap: 0.35rem;
            font-size: 0.85rem;
            color: var(--text-secondary);
            cursor: pointer;
        }
        .toggle-label input[type="checkbox"] {
            width: 15px;
            height: 15px;
            accent-color: var(--primary);
            cursor: pointer;
        }
        .toggle-label.req-label input[type="checkbox"] {
            accent-color: var(--accent-blue);
        }
        .locked-item {
            opacity: 0.65;
            background: rgba(20, 30, 50, 0.4);
            border-style: dotted;
            cursor: not-allowed;
        }
        .locked-item .drag-handle {
            visibility: hidden;
        }
    </style>
</head>
<body>

<div class="app-container">
    <?php include '_partials/sidebar.php'; ?>
    
    <main class="main-content">
        <div class="page-header">
            <div class="page-title">
                <h1><?php echo $is_edit ? 'Edit Form Details' : 'Create New Form'; ?></h1>
                <p><?php echo $is_edit ? 'Update options and layout structures for your form.' : 'Setup a new custom kit registration page with custom options.'; ?></p>
            </div>
            <div>
                <a href="index.php" class="btn btn-secondary">Back to Dashboard</a>
            </div>
        </div>

        <?php if (!empty($message)) : ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            
            <div style="display: grid; grid-template-columns: 2.2fr 1fr; gap: 2rem;">
                
                <!-- Left: Title, Slug, Field Configuration -->
                <div style="display: flex; flex-direction: column; gap: 2rem;">
                    
                    <!-- Core Info Card -->
                    <div class="card">
                        <div class="card-header">
                            <h2>Core Information</h2>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="title">Form Title</label>
                            <input type="text" id="title" name="title" class="form-control" placeholder="e.g. U-16 Kit Registration 2026" required value="<?php echo htmlspecialchars($form_title); ?>" oninput="generateSlug(this.value)">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="slug">Shareable URL Slug</label>
                            <input type="text" id="slug" name="slug" class="form-control" placeholder="e.g. u-16-kit-2026" value="<?php echo htmlspecialchars($form_slug); ?>">
                            <small style="color: var(--text-muted); font-size: 0.8rem; display: block; margin-top: 0.25rem;">
                                Leave blank to generate automatically from the title. Must contain only letters, numbers, and dashes.
                            </small>
                        </div>
                    </div>

                    <!-- Fields Selection Card -->
                    <div class="card">
                        <div class="card-header">
                            <h2>Form Layout Builder (Drag &amp; Drop)</h2>
                            <span class="badge badge-success">Dynamic Steps</span>
                        </div>
                        
                        <p style="color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 1.5rem;">
                            Drag and drop the field cards between steps to customize which page they appear on. Rearrange their order within steps by dragging them up or down.
                        </p>

                        <div style="display: flex; flex-direction: column; gap: 1.5rem;">
                            <!-- STEP 1 CONTAINER -->
                            <div>
                                <h3 style="font-size: 0.95rem; color: var(--primary); margin-bottom: 0.5rem; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 700;">
                                    Step 1: Player Profile Fields
                                </h3>
                                <div class="drag-container" data-step="1">
                                    <!-- Player Name: Locked Core Field -->
                                    <div class="drag-item locked-item">
                                        <div class="drag-handle">☰</div>
                                        <div class="drag-details">
                                            <strong style="color: var(--text-primary);">Player Name</strong>
                                            <span class="field-type-badge" style="background: rgba(255, 255, 255, 0.05); color: var(--text-muted);">Core Field</span>
                                        </div>
                                        <div class="drag-controls">
                                            <span style="font-size: 0.75rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase;">Always On &amp; Required</span>
                                        </div>
                                    </div>
                                    
                                    <?php
                                    foreach ($catalog_step1 as $field) {
                                        render_draggable_item($field, $field_configs);
                                    }
                                    ?>
                                </div>
                            </div>

                            <!-- STEP 2 CONTAINER -->
                            <div>
                                <h3 style="font-size: 0.95rem; color: var(--accent-blue); margin-bottom: 0.5rem; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 700;">
                                    Step 2: Contact &amp; Identification Fields
                                </h3>
                                <div class="drag-container" data-step="2">
                                    <?php
                                    foreach ($catalog_step2 as $field) {
                                        render_draggable_item($field, $field_configs);
                                    }
                                    ?>
                                </div>
                            </div>

                            <!-- STEP 3 CONTAINER -->
                            <div>
                                <h3 style="font-size: 0.95rem; color: var(--accent-yellow); margin-bottom: 0.5rem; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 700;">
                                    Step 3: Kit Details &amp; Customization
                                </h3>
                                <div class="drag-container" data-step="3">
                                    <?php
                                    foreach ($catalog_step3 as $field) {
                                        render_draggable_item($field, $field_configs);
                                    }
                                    ?>
                                </div>
                            </div>
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
                            <label class="form-label" for="status">Form Access Status</label>
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
    <?php if ($is_edit) : ?>
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

function toggleRequired(fieldKey, isEnabled) {
    var reqCheckbox = document.getElementById('req-' + fieldKey);
    var label = document.getElementById('req-label-' + fieldKey);
    if (reqCheckbox && label) {
        if (isEnabled) {
            reqCheckbox.removeAttribute('disabled');
            label.style.opacity = '1';
            label.style.pointerEvents = 'auto';
        } else {
            reqCheckbox.checked = false;
            reqCheckbox.setAttribute('disabled', 'true');
            label.style.opacity = '0.5';
            label.style.pointerEvents = 'none';
        }
    }
}

document.addEventListener('DOMContentLoaded', function() {
    var dragItems = document.querySelectorAll('.drag-item[draggable="true"]');
    var containers = document.querySelectorAll('.drag-container');

    dragItems.forEach(function(item) {
        item.addEventListener('dragstart', function(e) {
            item.classList.add('dragging');
            e.dataTransfer.setData('text/plain', item.dataset.key);
        });

        item.addEventListener('dragend', function() {
            item.classList.remove('dragging');
            updateSortOrders();
        });
    });

    containers.forEach(function(container) {
        container.addEventListener('dragover', function(e) {
            e.preventDefault();
            container.classList.add('drag-over');
            var dragging = document.querySelector('.dragging');
            if (dragging) {
                var afterElement = getDragAfterElement(container, e.clientY);
                if (afterElement == null) {
                    container.appendChild(dragging);
                } else {
                    container.insertBefore(dragging, afterElement);
                }
            }
        });

        container.addEventListener('dragleave', function() {
            container.classList.remove('drag-over');
        });

        container.addEventListener('drop', function() {
            container.classList.remove('drag-over');
            updateSortOrders();
        });
    });

    function getDragAfterElement(container, y) {
        var draggableElements = [...container.querySelectorAll('.drag-item:not(.dragging):not(.locked-item)')];

        return draggableElements.reduce(function(closest, child) {
            var box = child.getBoundingClientRect();
            var offset = y - box.top - box.height / 2;
            if (offset < 0 && offset > closest.offset) {
                return { offset: offset, element: child };
            } else {
                return closest;
            }
        }, { offset: Number.NEGATIVE_INFINITY }).element;
    }

    function updateSortOrders() {
        containers.forEach(function(container) {
            var stepNum = container.dataset.step;
            var items = container.querySelectorAll('.drag-item:not(.locked-item)');
            items.forEach(function(item, index) {
                var stepInput = item.querySelector('.step-input');
                if (stepInput) {
                    stepInput.value = stepNum;
                }
                var orderInput = item.querySelector('.order-input');
                if (orderInput) {
                    orderInput.value = index + 1;
                }
            });
        });
    }

    // Initialize sort orders on load
    updateSortOrders();
});
</script>
</body>
</html>
