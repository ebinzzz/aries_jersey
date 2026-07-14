<?php
// admin/login.php
require_once dirname(__DIR__) . '/includes/db_config.php';
require_once dirname(__DIR__) . '/includes/auth.php';

if (is_logged_in()) {
    header("Location: index.php");
    exit;
}

$admins_exist = any_admin_exists();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$admins_exist) {
        header("Location: migrations.php");
        exit;
    }

    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        $error = "Please fill in all fields.";
    } else {
        try {
            $db = get_db_connection();
            $stmt = $db->prepare("SELECT * FROM `admins` WHERE `username` = ? LIMIT 1");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result && $result->num_rows > 0) {
                $user = $result->fetch_assoc();
                if (password_verify($password, $user['password'])) {
                    // Start session
                    $_SESSION['admin_logged_in'] = true;
                    $_SESSION['admin_id'] = $user['id'];
                    $_SESSION['admin_username'] = $user['username'];

                    header("Location: index.php");
                    exit;
                }
            }
            $error = "Invalid username or password.";
            if ($stmt) {
                $stmt->close();
            }
        } catch (Exception $e) {
            $error = "System Error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login | PlayerKit</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="login-body">

<div class="login-card card">
    <div style="text-align: center; margin-bottom: 2rem;">
        <img src="../assets/images/logo.png" alt="Aries Kollam Sailors Logo" style="height: 70px; width: auto; object-fit: contain; margin: 0 auto 0.75rem auto; display: block;">
        <h1 style="font-size: 1.75rem;">Welcome Back</h1>
        <p style="color: var(--text-secondary); font-size: 0.9rem; margin-top: 0.25rem;">Sign in to manage registrations & forms</p>
    </div>

    <?php if (!$admins_exist) : ?>
        <div class="alert alert-warning">
            <strong>System Uninitialized:</strong> No admin users found. Please visit the migration tracker to setup the system.
            <a href="migrations.php" class="btn btn-secondary btn-sm" style="width: 100%; margin-top: 1rem; display: block; text-align: center;">Go to Installation</a>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)) : ?>
        <div class="alert alert-danger">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <form method="POST" <?php echo !$admins_exist ? 'style="display:none;"' : ''; ?>>
        <div class="form-group">
            <label for="username" class="form-label">Username</label>
            <input type="text" id="username" name="username" class="form-control" placeholder="Enter username" required autocomplete="username">
        </div>
        
        <div class="form-group">
            <label for="password" class="form-label">Password</label>
            <input type="password" id="password" name="password" class="form-control" placeholder="Enter password" required autocomplete="current-password">
        </div>
        
        <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 1rem;">Sign In</button>
    </form>
    
    <div style="text-align: center; margin-top: 1.5rem;">
        <a href="migrations.php" style="font-size: 0.85rem; color: var(--text-muted);">Database Migration Tracker</a>
    </div>
</div>

</body>
</html>
