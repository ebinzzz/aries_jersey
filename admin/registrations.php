<?php
// admin/registrations.php
require_once dirname(__DIR__) . '/includes/db_config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/form_helpers.php';

require_login();
$db = get_db_connection();

$form_id = intval($_GET['form_id'] ?? 0);
if ($form_id <= 0) {
    header("Location: index.php");
    exit;
}

// Fetch form details
$stmt = $db->prepare("SELECT * FROM `forms` WHERE `id` = ? LIMIT 1");
$stmt->bind_param("i", $form_id);
$stmt->execute();
$form_result = $stmt->get_result();
if (!$form_result || $form_result->num_rows === 0) {
    header("Location: index.php");
    exit;
}
$form = $form_result->fetch_assoc();
$stmt->close();

// Fetch form fields configuration
$fields_config = get_form_fields_config($form_id);

$message = '';
$message_type = 'success';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $message = "CSRF verification failed.";
        $message_type = "danger";
    } else {
        $action = $_POST['action'];
        if ($action === 'delete') {
            $reg_id = intval($_POST['registration_id'] ?? 0);
            $del_stmt = $db->prepare("DELETE FROM `registrations` WHERE `id` = ? AND `form_id` = ?");
            $del_stmt->bind_param("ii", $reg_id, $form_id);
            if ($del_stmt->execute()) {
                $message = "Player registration deleted successfully.";
            } else {
                $message = "Error deleting registration: " . $db->error;
                $message_type = "danger";
            }
            $del_stmt->close();
        } elseif ($action === 'clear_submissions') {
            $del_stmt = $db->prepare("DELETE FROM `registrations` WHERE `form_id` = ?");
            $del_stmt->bind_param("i", $form_id);
            if ($del_stmt->execute()) {
                $message = "All submissions for this form cleared successfully.";
            } else {
                $message = "Error clearing submissions: " . $db->error;
                $message_type = "danger";
            }
            $del_stmt->close();
        }
    }
}

$search = trim($_GET['search'] ?? '');

// Fetch registrations
$registrations = [];
if (!empty($search)) {
    $search_param = "%$search%";
    $query = "
        SELECT r.*, t.name as team_name 
        FROM `registrations` r
        JOIN `teams` t ON r.team_id = t.id
        WHERE r.form_id = ? 
        AND (r.player_name LIKE ? OR t.name LIKE ? OR r.jersey_name LIKE ? OR r.player_id LIKE ? OR r.mobile_number LIKE ?)
        ORDER BY r.submitted_at DESC
    ";
    $stmt = $db->prepare($query);
    $stmt->bind_param("isssss", $form_id, $search_param, $search_param, $search_param, $search_param, $search_param);
} else {
    $query = "
        SELECT r.*, t.name as team_name 
        FROM `registrations` r
        JOIN `teams` t ON r.team_id = t.id
        WHERE r.form_id = ?
        ORDER BY r.submitted_at DESC
    ";
    $stmt = $db->prepare($query);
    $stmt->bind_param("i", $form_id);
}

$stmt->execute();
$res = $stmt->get_result();
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $registrations[] = $row;
    }
    $res->free();
}
$stmt->close();

$csrf_token = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submissions - <?php echo htmlspecialchars($form['title']); ?> | PlayerKit Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>

<body>

    <div class="app-container">
        <?php include '_partials/sidebar.php'; ?>

        <main class="main-content">
            <div class="page-header">
                <div class="page-title">
                    <h1>Submissions</h1>
                    <p>Form: <strong><?php echo htmlspecialchars($form['title']); ?></strong></p>
                </div>
                <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                    <button type="button" class="btn btn-secondary" onclick="openClearModal()" <?php echo count($registrations) === 0 ? 'disabled' : ''; ?>>
                        🗑️ Clear All Submissions
                    </button>
                    <a href="export_excel.php?form_id=<?php echo $form['id']; ?>" class="btn btn-secondary">
                        📊 Export Excel
                    </a>
                    <a href="preview_pdf.php?form_id=<?php echo $form['id']; ?>" class="btn btn-secondary">
                        📄 Preview PDF
                    </a>
                    <a href="index.php" class="btn btn-secondary">Back</a>
                </div>
            </div>

            <?php if (!empty($message)): ?>
                <div class="alert alert-<?php echo $message_type; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <!-- Search Bar Section -->
            <div class="card" style="margin-bottom: 2rem; padding: 1.25rem 2rem;">
                <form method="GET" style="display: flex; gap: 1rem; align-items: center; width: 100%;">
                    <input type="hidden" name="form_id" value="<?php echo $form_id; ?>">
                    <div class="form-group" style="margin-bottom: 0; flex: 1;">
                        <input type="text" name="search" class="form-control"
                            placeholder="Search by Player Name, Team, Jersey Name, Mobile, or Player ID..."
                            value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <button type="submit" class="btn btn-primary" style="padding: 0.75rem 2rem;">Search</button>
                    <?php if (!empty($search)): ?>
                        <a href="registrations.php?form_id=<?php echo $form_id; ?>" class="btn btn-secondary"
                            style="padding: 0.75rem 1.5rem;">Clear</a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Submissions Table Card -->
            <div class="card">
                <div class="card-header">
                    <h2>Registrations Logs</h2>
                    <span class="badge badge-success"><?php echo count($registrations); ?> records found</span>
                </div>

                <?php if (count($registrations) > 0): ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Player Name</th>

                                    <!-- Dynamic columns for active fields -->
                                    <?php foreach ($fields_config as $key => $config): ?>
                                        <th><?php echo htmlspecialchars($config['label']); ?></th>
                                    <?php endforeach; ?>

                                    <th>Submitted At</th>
                                    <th style="text-align: right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($registrations as $reg): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($reg['player_name']); ?></strong>
                                        </td>

                                        <!-- Dynamic fields data -->
                                        <?php
                                        $qty_display_keys = ['half_sleeve_qty', 'full_sleeve_qty'];
                                        foreach ($fields_config as $key => $config): ?>
                                            <td>
                                                <?php
                                                $val = $reg[$key] ?? null;
                                                $is_qty = in_array($key, $qty_display_keys) || ($config['type'] === 'stepper');
                                                // For qty fields, 0 means none — show dash
                                                if ($val === null || $val === '' || ($is_qty && intval($val) === 0)) {
                                                    echo '<span style="color:var(--text-muted);">—</span>';
                                                } else {
                                                    echo htmlspecialchars($val);
                                                }
                                                ?>
                                            </td>
                                        <?php endforeach; ?>

                                        <td style="font-size: 0.85rem; color: var(--text-secondary);">
                                            <?php echo date('M d, Y H:i', strtotime($reg['submitted_at'])); ?>
                                        </td>
                                        <td style="text-align: right;">
                                            <form method="POST" style="display: inline;"
                                                onsubmit="return confirm('Are you sure you want to delete this player registration?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="registration_id" value="<?php echo $reg['id']; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div style="text-align: center; padding: 4rem 2rem; color: var(--text-secondary);">
                        <svg width="48" height="48" fill="none" stroke="var(--text-muted)" stroke-width="1.5"
                            viewBox="0 0 24 24" style="margin-bottom: 1rem;">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                            <circle cx="9" cy="7" r="4"></circle>
                            <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                            <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                        </svg>
                        <h3>No Registrations Found</h3>
                        <p style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0.25rem;">
                            <?php echo !empty($search) ? 'Try clearing your filters or refining your search term.' : 'This form has not received any submissions yet.'; ?>
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </main>
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
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                
                <div class="modal-body">
                    <p style="margin-bottom: 0.75rem;">
                        You are about to clear all submissions for <strong style="color: var(--text-primary);"><?php echo htmlspecialchars($form['title']); ?></strong>.
                    </p>
                    <p style="color: var(--warning); font-weight: 500; margin-bottom: 1rem;">
                        ⚠️ All player registration records for this form will be permanently deleted. This action CANNOT be undone.
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
    function openClearModal() {
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
            closeClearModal();
        }
    });

    window.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeClearModal();
        }
    });
    </script>

</body>

</html>