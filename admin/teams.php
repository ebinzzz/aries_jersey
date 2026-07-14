<?php
// admin/teams.php
require_once dirname(__DIR__) . '/includes/db_config.php';
require_once dirname(__DIR__) . '/includes/auth.php';

require_login();
$db = get_db_connection();

$message = '';
$message_type = 'success';

// Handle Team Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $message = "CSRF verification failed.";
        $message_type = "danger";
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            $name = trim($_POST['team_name'] ?? '');
            if (empty($name)) {
                $message = "Team name cannot be empty.";
                $message_type = "danger";
            } else {
                $stmt = $db->prepare("INSERT INTO `teams` (`name`) VALUES (?)");
                $stmt->bind_param("s", $name);
                if ($stmt->execute()) {
                    $message = "Team '" . htmlspecialchars($name) . "' added successfully.";
                } else {
                    // Check duplicate entry code
                    if ($db->errno == 1062) {
                        $message = "A team named '" . htmlspecialchars($name) . "' already exists.";
                        $message_type = "warning";
                    } else {
                        $message = "Error adding team: " . $db->error;
                        $message_type = "danger";
                    }
                }
                $stmt->close();
            }
        } elseif ($action === 'delete') {
            $team_id = intval($_POST['team_id'] ?? 0);

            // Check references first to avoid foreign key violations
            $check = $db->prepare("SELECT COUNT(*) as count FROM `registrations` WHERE `team_id` = ?");
            $check->bind_param("i", $team_id);
            $check->execute();
            $count = $check->get_result()->fetch_assoc()['count'];
            $check->close();

            if ($count > 0) {
                $message = "Cannot delete this team. It is currently referenced in " . $count . " player registration(s).";
                $message_type = "danger";
            } else {
                $stmt = $db->prepare("DELETE FROM `teams` WHERE `id` = ?");
                $stmt->bind_param("i", $team_id);
                if ($stmt->execute()) {
                    $message = "Team deleted successfully.";
                } else {
                    $message = "Error deleting team: " . $db->error;
                    $message_type = "danger";
                }
                $stmt->close();
            }
        }
    }
}

// Fetch all teams
$teams = [];
$res = $db->query("SELECT * FROM `teams` ORDER BY `name` ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $teams[] = $row;
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
    <title>Manage Teams | PlayerKit Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>

<div class="app-container">
    <?php include '_partials/sidebar.php'; ?>
    
    <main class="main-content">
        <div class="page-header">
            <div class="page-title">
                <h1>Manage Teams</h1>
                <p>Register and maintain teams available in the public registration form dropdown.</p>
            </div>
            <div>
                <a href="index.php" class="btn btn-secondary">Go to Dashboard</a>
            </div>
        </div>

        <?php if (!empty($message)) : ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 2rem; align-items: start;">
            
            <!-- Left: Add Team Form -->
            <div class="card">
                <div class="card-header">
                    <h2>Add New Team</h2>
                </div>
                
                <form method="POST">
                    <input type="hidden" name="action" value="create">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    
                    <div class="form-group">
                        <label for="team_name" class="form-label">Team Name</label>
                        <input type="text" id="team_name" name="team_name" class="form-control" placeholder="e.g. Kollam Sailors" required autocomplete="off">
                    </div>
                    
                    <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 1rem;">
                        Add Team
                    </button>
                </form>
            </div>

            <!-- Right: Teams List -->
            <div class="card">
                <div class="card-header">
                    <h2>Active Teams Directory</h2>
                    <span class="badge badge-success"><?php echo count($teams); ?> teams</span>
                </div>

                <?php if (count($teams) > 0) : ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Team Name</th>
                                    <th>Registered Date</th>
                                    <th style="text-align: right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($teams as $team) : ?>
                                    <tr>
                                        <td style="color: var(--text-muted); font-size: 0.85rem; font-family: monospace;">
                                            #<?php echo $team['id']; ?>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($team['name']); ?></strong>
                                        </td>
                                        <td style="font-size: 0.85rem; color: var(--text-secondary);">
                                            <?php echo date('M d, Y', strtotime($team['created_at'])); ?>
                                        </td>
                                        <td style="text-align: right;">
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this team?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="team_id" value="<?php echo $team['id']; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else : ?>
                    <div style="text-align: center; padding: 3rem 0; color: var(--text-secondary);">
                        <p style="font-weight: 500;">No teams registered yet.</p>
                        <p style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0.25rem;">Use the form on the left to register a team.</p>
                    </div>
                <?php endif; ?>
            </div>
            
        </div>
    </main>
</div>

</body>
</html>
