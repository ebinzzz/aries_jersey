<?php
// admin/index.php
require_once dirname(__DIR__) . '/includes/db_config.php';
require_once dirname(__DIR__) . '/includes/auth.php';

require_login();

try {
    $db = get_db_connection();
} catch (Exception $e) {
    // If database connection fails, redirect to migration page to debug
    header("Location: migrations.php");
    exit;
}

$message = '';
$message_type = 'success';

// Handle POST actions (Delete, Toggle Status)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $message = "CSRF verification failed.";
        $message_type = "danger";
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'delete') {
            $form_id = intval($_POST['form_id'] ?? 0);
            $stmt = $db->prepare("DELETE FROM `forms` WHERE `id` = ?");
            $stmt->bind_param("i", $form_id);
            if ($stmt->execute()) {
                $message = "Registration form deleted successfully.";
            } else {
                $message = "Error deleting form: " . $db->error;
                $message_type = "danger";
            }
            $stmt->close();
        } elseif ($action === 'clear_submissions') {
            $form_id = intval($_POST['form_id'] ?? 0);
            $stmt = $db->prepare("DELETE FROM `registrations` WHERE `form_id` = ?");
            $stmt->bind_param("i", $form_id);
            if ($stmt->execute()) {
                $message = "All form submissions cleared successfully.";
            } else {
                $message = "Error clearing form submissions: " . $db->error;
                $message_type = "danger";
            }
            $stmt->close();
        } elseif ($action === 'toggle_status') {
            $form_id = intval($_POST['form_id'] ?? 0);
            $current_status = $_POST['status'] ?? 'open';
            $new_status = $current_status === 'open' ? 'closed' : 'open';

            $stmt = $db->prepare("UPDATE `forms` SET `status` = ? WHERE `id` = ?");
            $stmt->bind_param("si", $new_status, $form_id);
            if ($stmt->execute()) {
                $message = "Form status updated to " . strtoupper($new_status) . ".";
            } else {
                $message = "Error updating status: " . $db->error;
                $message_type = "danger";
            }
            $stmt->close();
        }
    }
}

// Fetch stats
$total_forms = 0;
$active_forms = 0;
$total_registrations = 0;
$total_teams = 0;

$res = $db->query("SELECT COUNT(*) as count FROM `forms`");
if ($res) {
    $total_forms = $res->fetch_assoc()['count'];
    $res->free();
}

$res = $db->query("SELECT COUNT(*) as count FROM `forms` WHERE `status` = 'open'");
if ($res) {
    $active_forms = $res->fetch_assoc()['count'];
    $res->free();
}

$res = $db->query("SELECT COUNT(*) as count FROM `registrations`");
if ($res) {
    $total_registrations = $res->fetch_assoc()['count'];
    $res->free();
}

$res = $db->query("SELECT COUNT(*) as count FROM `teams`");
if ($res) {
    $total_teams = $res->fetch_assoc()['count'];
    $res->free();
}

// Fetch forms list with registration counts
$forms = [];
$query = "
    SELECT f.*, COUNT(r.id) as reg_count 
    FROM `forms` f 
    LEFT JOIN `registrations` r ON f.id = r.form_id 
    GROUP BY f.id 
    ORDER BY f.created_at DESC
";
$result = $db->query($query);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $forms[] = $row;
    }
    $result->free();
}

$csrf_token = generate_csrf_token();

// Construct base URL for public form links
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443 ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'];
$uri = rtrim(dirname($_SERVER['REQUEST_URI']), '/admin');
$base_url = $protocol . $host . $uri . "/form.php?slug=";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | PlayerKit Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>

<div class="app-container">
    <?php include '_partials/sidebar.php'; ?>
    
    <main class="main-content">
        <div class="page-header">
            <div class="page-title">
                <h1>Forms & Dashboard</h1>
                <p>Welcome back! Monitor registrations and manage your customized kit forms.</p>
            </div>
            <div>
                <a href="form_edit.php" class="btn btn-primary">
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="margin-right: 0.25rem;"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    Create New Form
                </a>
            </div>
        </div>

        <?php if (!empty($message)) : ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Stat Cards Summary Grid -->
        <div class="dashboard-grid">
            <div class="stat-card">
                <div class="stat-icon">📋</div>
                <div class="stat-details">
                    <p>Total Forms</p>
                    <h3><?php echo $total_forms; ?></h3>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">🟢</div>
                <div class="stat-details">
                    <p>Active Forms</p>
                    <h3><?php echo $active_forms; ?></h3>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">👥</div>
                <div class="stat-details">
                    <p>Registrations</p>
                    <h3><?php echo $total_registrations; ?></h3>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">🛡️</div>
                <div class="stat-details">
                    <p>Total Teams</p>
                    <h3><?php echo $total_teams; ?></h3>
                </div>
            </div>
        </div>

        <!-- Registration Forms List Table -->
        <div class="card">
            <div class="card-header">
                <h2>Custom Kit Registration Forms</h2>
                <span class="badge badge-success"><?php echo count($forms); ?> forms created</span>
            </div>

            <?php if (count($forms) > 0) : ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Form Name</th>
                                <th>Public Share Link</th>
                                <th>Status</th>
                                <th>Registrations</th>
                                <th>Created</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($forms as $form) :
                                $form_url = $base_url . urlencode($form['slug']);
                                ?>
                                <tr>
                                    <td>
                                        <strong style="font-size: 1rem;"><?php echo htmlspecialchars($form['title']); ?></strong>
                                    </td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                                            <input type="text" class="form-control" style="font-size: 0.8rem; padding: 0.25rem 0.5rem; width: 220px;" readonly value="<?php echo htmlspecialchars($form_url); ?>" id="link-<?php echo $form['id']; ?>">
                                            <button onclick="copyToClipboard('link-<?php echo $form['id']; ?>', this)" class="btn btn-secondary btn-sm" style="padding: 0.25rem 0.5rem; font-size: 0.75rem;">Copy</button>
                                        </div>
                                    </td>
                                    <td>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="form_id" value="<?php echo $form['id']; ?>">
                                            <input type="hidden" name="status" value="<?php echo $form['status']; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            
                                            <?php if ($form['status'] === 'open') : ?>
                                                <button type="submit" class="badge badge-success" style="cursor: pointer; border: 1px solid rgba(16, 185, 129, 0.2);" title="Click to Close Form">Open</button>
                                            <?php else : ?>
                                                <button type="submit" class="badge badge-danger" style="cursor: pointer; border: 1px solid rgba(239, 68, 68, 0.2);" title="Click to Open Form">Closed</button>
                                            <?php endif; ?>
                                        </form>
                                    </td>
                                    <td>
                                        <a href="registrations.php?form_id=<?php echo $form['id']; ?>" class="badge badge-warning" style="font-weight: 700; text-decoration: underline;">
                                            <?php echo $form['reg_count']; ?> submitted
                                        </a>
                                    </td>
                                    <td style="font-size: 0.85rem; color: var(--text-secondary);">
                                        <?php echo date('M d, Y', strtotime($form['created_at'])); ?>
                                    </td>
                                    <td style="text-align: right;">
                                        <div style="display: inline-flex; gap: 0.5rem; justify-content: flex-end;">
                                            <a href="registrations.php?form_id=<?php echo $form['id']; ?>" class="btn btn-secondary btn-sm" title="View Submissions">
                                                Submissions
                                            </a>
                                            <button type="button" class="btn btn-secondary btn-sm" onclick="openClearModal(<?php echo $form['id']; ?>, '<?php echo htmlspecialchars(addslashes($form['title'])); ?>')" title="Clear All Submissions" <?php echo $form['reg_count'] == 0 ? 'disabled' : ''; ?>>
                                                Clear
                                            </button>
                                            <a href="form_edit.php?id=<?php echo $form['id']; ?>" class="btn btn-secondary btn-sm" title="Edit Form Settings">
                                                Edit
                                            </a>
                                            <button type="button" class="btn btn-danger btn-sm" onclick="openDeleteModal(<?php echo $form['id']; ?>, '<?php echo htmlspecialchars(addslashes($form['title'])); ?>')">
                                                Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else : ?>
                <div style="text-align: center; padding: 4rem 2rem; color: var(--text-secondary);">
                    <svg width="64" height="64" fill="none" stroke="var(--text-muted)" stroke-width="1" viewBox="0 0 24 24" style="margin-bottom: 1.5rem;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                    <h3>No Forms Created Yet</h3>
                    <p style="font-size: 0.9rem; color: var(--text-muted); max-width: 400px; margin: 0.5rem auto 1.5rem auto;">
                        Get started by setting up your first kit registration form. Choose which sizes, player ID, or mobile fields should appear.
                    </p>
                    <a href="form_edit.php" class="btn btn-primary">Create Your First Form</a>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<!-- Modal for Delete Form Confirmation -->
<div id="deleteModal" class="modal-backdrop" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; width: 100vw; height: 100vh; background: rgba(3, 7, 18, 0.85); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); z-index: 99999; align-items: center; justify-content: center;">
    <div class="modal-card">
        <div class="modal-header">
            <h3>Delete Registration Form</h3>
            <button type="button" class="modal-close" onclick="closeDeleteModal()">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="form_id" id="delete_modal_form_id" value="">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            
            <div class="modal-body">
                <p style="margin-bottom: 0.75rem;">
                    You are about to delete form <strong id="delete_modal_form_title" style="color: var(--text-primary);"></strong>. 
                </p>
                <p style="color: var(--danger); font-weight: 500; margin-bottom: 1rem;">
                    ⚠️ This will permanently remove the form, its configuration, and ALL player registration data associated with it. This action CANNOT be undone.
                </p>
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label" for="delete_confirm_text">Type <strong style="color: var(--primary);">DELETE</strong> to confirm:</label>
                    <input type="text" id="delete_confirm_text" class="form-control" placeholder="Type DELETE here" autocomplete="off" oninput="validateDeleteConfirm()">
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                <button type="submit" id="delete_confirm_btn" class="btn btn-danger" disabled>Delete Form</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal for Clear Submissions Confirmation -->
<div id="clearModal" class="modal-backdrop" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; width: 100vw; height: 100vh; background: rgba(3, 7, 18, 0.85); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); z-index: 99999; align-items: center; justify-content: center;">
    <div class="modal-card warning-theme">
        <div class="modal-header">
            <h3>Clear Form Submissions</h3>
            <button type="button" class="modal-close" onclick="closeClearModal()">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="clear_submissions">
            <input type="hidden" name="form_id" id="clear_modal_form_id" value="">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            
            <div class="modal-body">
                <p style="margin-bottom: 0.75rem;">
                    You are about to clear all submissions for <strong id="clear_modal_form_title" style="color: var(--text-primary);"></strong>.
                </p>
                <p style="color: var(--warning); font-weight: 500; margin-bottom: 1rem;">
                    ⚠️ All player registration entries for this form will be permanently deleted. The form itself will remain active.
                </p>
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label" for="clear_confirm_text">Type <strong style="color: var(--warning);">CLEAR</strong> to confirm:</label>
                    <input type="text" id="clear_confirm_text" class="form-control" placeholder="Type CLEAR here" autocomplete="off" oninput="validateClearConfirm()">
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeClearModal()">Cancel</button>
                <button type="submit" id="clear_confirm_btn" class="btn btn-danger" style="background: var(--warning); border-color: var(--warning);" disabled>Clear All Submissions</button>
            </div>
        </form>
    </div>
</div>

<script>
function copyToClipboard(elementId, button) {
    var copyText = document.getElementById(elementId);
    copyText.select();
    copyText.setSelectionRange(0, 99999);
    
    navigator.clipboard.writeText(copyText.value).then(function() {
        var originalText = button.textContent;
        button.textContent = "Copied!";
        button.style.backgroundColor = "var(--success)";
        button.style.color = "#fff";
        button.style.borderColor = "var(--success)";
        
        setTimeout(function() {
            button.textContent = originalText;
            button.style.backgroundColor = "";
            button.style.color = "";
            button.style.borderColor = "";
        }, 1500);
    }, function(err) {
        alert("Failed to copy link: " + err);
    });
}

function openDeleteModal(formId, formTitle) {
    document.getElementById('delete_modal_form_id').value = formId;
    document.getElementById('delete_modal_form_title').textContent = formTitle;
    document.getElementById('delete_confirm_text').value = '';
    document.getElementById('delete_confirm_btn').disabled = true;
    var modal = document.getElementById('deleteModal');
    modal.style.display = 'flex';
    modal.classList.add('active');
    setTimeout(function() {
        document.getElementById('delete_confirm_text').focus();
    }, 100);
}

function closeDeleteModal() {
    var modal = document.getElementById('deleteModal');
    modal.style.display = 'none';
    modal.classList.remove('active');
}

function validateDeleteConfirm() {
    var val = document.getElementById('delete_confirm_text').value.trim();
    document.getElementById('delete_confirm_btn').disabled = (val.toUpperCase() !== 'DELETE');
}

function openClearModal(formId, formTitle) {
    document.getElementById('clear_modal_form_id').value = formId;
    document.getElementById('clear_modal_form_title').textContent = formTitle;
    document.getElementById('clear_confirm_text').value = '';
    document.getElementById('clear_confirm_btn').disabled = true;
    var modal = document.getElementById('clearModal');
    modal.style.display = 'flex';
    modal.classList.add('active');
    setTimeout(function() {
        document.getElementById('clear_confirm_text').focus();
    }, 100);
}

function closeClearModal() {
    var modal = document.getElementById('clearModal');
    modal.style.display = 'none';
    modal.classList.remove('active');
}

function validateClearConfirm() {
    var val = document.getElementById('clear_confirm_text').value.trim();
    document.getElementById('clear_confirm_btn').disabled = (val.toUpperCase() !== 'CLEAR');
}

window.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal-backdrop')) {
        closeDeleteModal();
        closeClearModal();
    }
});

window.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeDeleteModal();
        closeClearModal();
    }
});
</script>
</body>
</html>

