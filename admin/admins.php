<?php
// admin/admins.php
require_once dirname(__DIR__) . '/includes/db_config.php';
require_once dirname(__DIR__) . '/includes/auth.php';

require_login();
$db = get_db_connection();

$message = '';
$message_type = 'success';

$current_admin_id = $_SESSION['admin_id'] ?? 0;

// Handle Admin Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $message = "CSRF verification failed.";
        $message_type = "danger";
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'create') {
            $username = trim($_POST['username'] ?? '');
            $password = trim($_POST['password'] ?? '');
            
            if (empty($username) || empty($password)) {
                $message = "All fields are required.";
                $message_type = "danger";
            } elseif (strlen($password) < 6) {
                $message = "Password must be at least 6 characters long.";
                $message_type = "danger";
            } else {
                $hashed_password = password_hash($password, PASSWORD_BCRYPT);
                
                $stmt = $db->prepare("INSERT INTO `admins` (`username`, `password`) VALUES (?, ?)");
                $stmt->bind_param("ss", $username, $hashed_password);
                
                if ($stmt->execute()) {
                    $message = "Admin user '" . htmlspecialchars($username) . "' created successfully.";
                } else {
                    if ($db->errno == 1062) {
                        $message = "An admin user with the username '" . htmlspecialchars($username) . "' already exists.";
                        $message_type = "warning";
                    } else {
                        $message = "Error creating admin account: " . $db->error;
                        $message_type = "danger";
                    }
                }
                $stmt->close();
            }
        } elseif ($action === 'delete') {
            $admin_id = intval($_POST['admin_id'] ?? 0);
            
            if ($admin_id === $current_admin_id) {
                $message = "You cannot delete your own administrative account.";
                $message_type = "danger";
            } else {
                // Ensure we don't delete the last admin account
                $res = $db->query("SELECT COUNT(*) as count FROM `admins`");
                $admin_count = $res ? $res->fetch_assoc()['count'] : 0;
                if ($res) { $res->close(); }
                
                if ($admin_count <= 1) {
                    $message = "Cannot delete the final administrator account on this server.";
                    $message_type = "danger";
                } else {
                    $stmt = $db->prepare("DELETE FROM `admins` WHERE `id` = ?");
                    $stmt->bind_param("i", $admin_id);
                    if ($stmt->execute()) {
                        $message = "Admin user deleted successfully.";
                    } else {
                        $message = "Error deleting account: " . $db->error;
                        $message_type = "danger";
                    }
                    $stmt->close();
                }
            }
        }
    }
}

// Fetch admin list
$admins = [];
$res = $db->query("SELECT id, username, created_at FROM `admins` ORDER BY `username` ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $admins[] = $row;
    }
    $res->free();
}

$csrf_token = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Accounts | PlayerKit Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>

<div class="app-container">
    <?php include '_partials/sidebar.php'; ?>
    
    <main class="main-content">
        <div class="page-header">
            <div class="page-title">
                <h1>Admin Users</h1>
                <p>Add, monitor, or remove administrative credentials for this registration backend.</p>
            </div>
            <div>
                <a href="index.php" class="btn btn-secondary">Go to Dashboard</a>
            </div>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 2rem; align-items: start;">
            
            <!-- Left: Add Admin Form -->
            <div class="card">
                <div class="card-header">
                    <h2>Create Admin</h2>
                </div>
                
                <form method="POST" autocomplete="off">
                    <input type="hidden" name="action" value="create">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    
                    <div class="form-group">
                        <label for="username" class="form-label">Username</label>
                        <input type="text" id="username" name="username" class="form-control" placeholder="Enter username" required autocomplete="new-username">
                    </div>
                    
                    <div class="form-group">
                        <label for="password" class="form-label">Password</label>
                        <input type="password" id="password" name="password" class="form-control" placeholder="Enter password (min 6 chars)" required autocomplete="new-password">
                    </div>
                    
                    <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 1rem;">
                        Register Admin
                    </button>
                </form>
            </div>

            <!-- Right: Admin Users List -->
            <div class="card">
                <div class="card-header">
                    <h2>Active Administrators</h2>
                    <span class="badge badge-success"><?php echo count($admins); ?> accounts</span>
                </div>

                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Username</th>
                                <th>Created Date</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($admins as $admin): ?>
                                <tr>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                                            <span style="font-weight: 700;"><?php echo htmlspecialchars($admin['username']); ?></span>
                                            <?php if ($admin['id'] === $current_admin_id): ?>
                                                <span class="badge badge-warning" style="font-size: 0.65rem;">You</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td style="font-size: 0.85rem; color: var(--text-secondary);">
                                        <?php echo date('M d, Y', strtotime($admin['created_at'])); ?>
                                    </td>
                                    <td style="text-align: right;">
                                        <?php if ($admin['id'] !== $current_admin_id): ?>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this admin account?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="admin_id" value="<?php echo $admin['id']; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                            </form>
                                        <?php else: ?>
                                            <button class="btn btn-secondary btn-sm" disabled title="Cannot delete your active account">Locked</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
        </div>
    </main>
</div>

</body>
</html>
