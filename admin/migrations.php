<?php
// admin/migrations.php
require_once dirname(__DIR__) . '/includes/db_config.php';
require_once dirname(__DIR__) . '/includes/migration_runner.php';
require_once dirname(__DIR__) . '/includes/auth.php';

$runner = new MigrationRunner();

// Enforce login if admin database/accounts already exist. 
// This allows a seamless setup on empty DBs while protecting live databases.
$admins_exist = any_admin_exists();
if ($admins_exist) {
    require_login();
}

$conn_error = $db_connection_error;
$db_exists = $runner->databaseExists();

$message = '';
$message_type = 'success';
$query_results = null;

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Only verify CSRF if there are already admins in the database (active installation)
    if ($admins_exist && (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token']))) {
        $message = "CSRF token verification failed.";
        $message_type = "danger";
    } else {
        $action = $_POST['action'] ?? '';
        
        try {
            if ($action === 'create_db') {
                $runner->createDatabase();
                $message = "Database '" . DB_NAME . "' created successfully!";
                $message_type = "success";
                $db_exists = true;
                $conn_error = null;
            } elseif ($action === 'run_migrations') {
                $executed = $runner->runPendingMigrations();
                if (count($executed) > 0) {
                    $message = "Successfully executed " . count($executed) . " migration(s): " . implode(', ', $executed);
                    $message_type = "success";
                    // If migrations completed, refresh admins_exist status
                    $admins_exist = any_admin_exists();
                } else {
                    $message = "No pending migrations to run.";
                    $message_type = "warning";
                }
            } elseif ($action === 'run_sql') {
                $sql = $_POST['sql_query'] ?? '';
                if (!empty($sql)) {
                    $query_results = $runner->runArbitraryQuery($sql);
                    $message = "Query executed successfully.";
                    $message_type = "success";
                    // Refresh admin check in case they added/changed accounts directly
                    $admins_exist = any_admin_exists();
                } else {
                    $message = "Query input was empty.";
                    $message_type = "danger";
                }
            }
        } catch (Exception $e) {
            $message = "Execution error: " . $e->getMessage();
            $message_type = "danger";
        }
    }
}

// Read migration list if DB is accessible
$applied = [];
$pending = [];
$migration_files = [];
if ($db_exists) {
    try {
        $applied = $runner->getAppliedMigrations();
        $pending = $runner->getPendingMigrations();
        $migration_files = $runner->getMigrationFiles();
    } catch (Exception $e) {
        $conn_error = $e->getMessage();
    }
}

$csrf_token = $admins_exist ? generate_csrf_token() : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Migrations Tracker | PlayerKit Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="<?php echo !$admins_exist ? 'public-form-body' : ''; ?>">

<?php if ($admins_exist): ?>
<div class="app-container">
    <?php include '_partials/sidebar.php'; ?>
    <main class="main-content">
        <div class="page-header">
            <div class="page-title">
                <h1>Migration Tracker</h1>
                <p>Monitor database state, run pending migration files, and execute maintenance queries.</p>
            </div>
            <div>
                <a href="index.php" class="btn btn-secondary">Go to Dashboard</a>
            </div>
        </div>
<?php else: ?>
<div class="public-form-container" style="max-width: 800px;">
    <div class="public-form-logo">
        <img src="../assets/images/logo.png" alt="Aries Kollam Sailors Logo" style="height: 60px; width: auto; object-fit: contain;">
        <div class="sidebar-logo-text" style="font-size: 1.75rem;">Sailors Installer</div>
    </div>
<?php endif; ?>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Database Connection Status Section -->
        <div class="card" style="margin-bottom: 2rem;">
            <div class="card-header">
                <h2>Connection Environment</h2>
                <?php if (!$runner->isConnected()): ?>
                    <span class="badge badge-danger">Offline</span>
                <?php elseif (!$db_exists): ?>
                    <span class="badge badge-warning">Database Missing</span>
                <?php else: ?>
                    <span class="badge badge-success">Connected</span>
                <?php endif; ?>
            </div>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem; margin-bottom: 1rem;">
                <div>
                    <label class="form-label" style="margin-bottom: 0.15rem;">Host Address</label>
                    <div style="font-weight: 600; font-size: 1.1rem;"><?php echo htmlspecialchars(DB_HOST); ?>:<?php echo htmlspecialchars(DB_PORT); ?></div>
                </div>
                <div>
                    <label class="form-label" style="margin-bottom: 0.15rem;">Database Name</label>
                    <div style="font-weight: 600; font-size: 1.1rem; color: var(--primary);"><?php echo htmlspecialchars(DB_NAME); ?></div>
                </div>
                <div>
                    <label class="form-label" style="margin-bottom: 0.15rem;">MySQL Username</label>
                    <div style="font-weight: 600; font-size: 1.1rem;"><?php echo htmlspecialchars(DB_USER); ?></div>
                </div>
            </div>

            <?php if ($conn_error): ?>
                <div class="alert alert-danger" style="margin-top: 1rem; margin-bottom: 0;">
                    <strong>Connection Error:</strong> <?php echo htmlspecialchars($conn_error); ?>
                </div>
            <?php endif; ?>

            <?php if ($runner->isConnected() && !$db_exists): ?>
                <div style="margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid var(--border-color);">
                    <p style="color: var(--text-secondary); margin-bottom: 1rem;">
                        MySQL is accessible, but the database <strong><?php echo htmlspecialchars(DB_NAME); ?></strong> does not exist on the server. Click below to create it.
                    </p>
                    <form method="POST">
                        <input type="hidden" name="action" value="create_db">
                        <?php if ($admins_exist): ?>
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary">Create Database "<?php echo htmlspecialchars(DB_NAME); ?>"</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($db_exists): ?>
            <!-- Migrations Dashboard -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 2rem; margin-bottom: 2rem;">
                <!-- Pending Migrations Panel -->
                <div class="card">
                    <div class="card-header">
                        <h2>Pending Migrations</h2>
                        <span class="badge <?php echo count($pending) > 0 ? 'badge-warning' : 'badge-success'; ?>">
                            <?php echo count($pending); ?> Pending
                        </span>
                    </div>

                    <?php if (count($pending) > 0): ?>
                        <div class="alert alert-warning" style="margin-bottom: 1.5rem;">
                            You have database schema updates ready to be applied.
                        </div>
                        <ul style="list-style: none; margin-bottom: 1.5rem; padding-left: 0;">
                            <?php foreach ($pending as $file): ?>
                                <li style="padding: 0.5rem 0; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; gap: 0.5rem;">
                                    <svg width="16" height="16" fill="none" stroke="var(--warning)" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                                    <span style="font-family: monospace; font-size: 0.9rem;"><?php echo htmlspecialchars($file); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <form method="POST">
                            <input type="hidden" name="action" value="run_migrations">
                            <?php if ($admins_exist): ?>
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <?php endif; ?>
                            <button type="submit" class="btn btn-primary" style="width: 100%;">Execute All Pending Migrations</button>
                        </form>
                    <?php else: ?>
                        <div style="text-align: center; padding: 2rem 0; color: var(--text-secondary);">
                            <svg width="48" height="48" fill="none" stroke="var(--success)" stroke-width="1.5" viewBox="0 0 24 24" style="margin-bottom: 1rem;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                            <p style="font-weight: 500;">All caught up!</p>
                            <p style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0.25rem;">Database schema is matching local migrations directory.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Migration Logs Panel -->
                <div class="card">
                    <div class="card-header">
                        <h2>Migration History</h2>
                        <span class="badge badge-success"><?php echo count($applied); ?> Applied</span>
                    </div>

                    <?php if (count($applied) > 0): ?>
                        <div class="table-responsive" style="max-height: 280px; overflow-y: auto;">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Migration File</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_reverse($applied) as $file): ?>
                                        <tr>
                                            <td style="font-family: monospace; font-size: 0.85rem;"><?php echo htmlspecialchars($file); ?></td>
                                            <td><span class="badge badge-success" style="font-size: 0.7rem;">Applied</span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div style="text-align: center; padding: 2rem 0; color: var(--text-secondary);">
                            <p style="font-weight: 500;">No history logged</p>
                            <p style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0.25rem;">Execute pending migrations to create logs.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- SQL Console (Admin Only) -->
            <?php if ($admins_exist): ?>
                <div class="card sql-editor-container">
                    <div class="card-header">
                        <h2>Developer SQL Console</h2>
                        <span class="badge badge-danger">Superuser Mode</span>
                    </div>
                    <p style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 1rem;">
                        Run direct SQL statements. This bypasses structural validation. Useful for database maintenance or checks on InfinityFree hosting.
                    </p>

                    <form method="POST">
                        <input type="hidden" name="action" value="run_sql">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <div class="form-group">
                            <textarea name="sql_query" class="form-control sql-editor" placeholder="SELECT * FROM `teams` LIMIT 5;"><?php echo isset($_POST['sql_query']) ? htmlspecialchars($_POST['sql_query']) : ''; ?></textarea>
                        </div>
                        <button type="submit" class="btn btn-secondary">Execute Statement</button>
                    </form>

                    <?php if ($query_results): ?>
                        <div style="margin-top: 1.5rem; border-top: 1px solid var(--border-color); padding-top: 1.5rem;">
                            <h3>Query Results</h3>
                            
                            <?php if ($query_results['type'] === 'success'): ?>
                                <div class="alert alert-success" style="margin-top: 1rem; margin-bottom: 0;">
                                    Success! Affected Rows: <?php echo $query_results['affected_rows']; ?>
                                </div>
                            <?php else: ?>
                                <p style="font-size: 0.85rem; color: var(--text-secondary); margin-top: 0.5rem; margin-bottom: 0.5rem;">
                                    Returned Rows: <?php echo $query_results['num_rows']; ?>
                                </p>
                                <?php if (count($query_results['rows']) > 0): ?>
                                    <div class="table-responsive query-results-table">
                                        <table class="table" style="font-size: 0.8rem;">
                                            <thead>
                                                <tr>
                                                    <?php foreach (array_keys($query_results['rows'][0]) as $col): ?>
                                                        <th><?php echo htmlspecialchars($col); ?></th>
                                                    <?php endforeach; ?>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($query_results['rows'] as $row): ?>
                                                    <tr>
                                                        <?php foreach ($row as $val): ?>
                                                            <td><?php echo $val === null ? '<em>NULL</em>' : htmlspecialchars($val); ?></td>
                                                        <?php endforeach; ?>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-warning" style="margin-top: 1rem;">
                                        The query returned 0 rows.
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

<?php if ($admins_exist): ?>
    </main>
</div>
<?php else: ?>
    <!-- Installer Footer -->
    <div style="text-align: center; margin-top: 2rem; font-size: 0.85rem; color: var(--text-muted);">
        <?php if ($db_exists && count($pending) === 0): ?>
            <p>Database and table structures initialized!</p>
            <a href="login.php" class="btn btn-primary" style="margin-top: 1rem; width: 100%;">Proceed to Admin Login</a>
        <?php else: ?>
            <p>Please setup the database using this wizard to begin configuring your registration system.</p>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

</body>
</html>
