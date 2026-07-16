<?php
// admin/_partials/sidebar.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$current_page = basename($_SERVER['PHP_SELF']);
$admin_username = $_SESSION['admin_username'] ?? 'Admin';
?>
<div class="sidebar">
    <div class="sidebar-logo">
        <img src="../assets/images/logo.png" alt="Aries Kollam Sailors Logo" class="sidebar-logo-img">
        <div class="sidebar-logo-text">Sailors Admin</div>
    </div>
    
    <ul class="sidebar-menu">
        <li class="sidebar-menu-item <?php echo $current_page == 'index.php' || $current_page == 'form_edit.php' ? 'active' : ''; ?>">
            <a href="index.php">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9"></rect><rect x="14" y="3" width="7" height="5"></rect><rect x="14" y="12" width="7" height="9"></rect><rect x="3" y="16" width="7" height="5"></rect></svg>
                <span>Forms & Dashboard</span>
            </a>
        </li>
        <li class="sidebar-menu-item <?php echo $current_page == 'registrations.php' ? 'active' : ''; ?>" style="display: none;">
            <!-- Hidden by default, activated only when viewing registrations -->
            <a href="#">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                <span>Registrations</span>
            </a>
        </li>

        <li class="sidebar-menu-item <?php echo $current_page == 'admins.php' ? 'active' : ''; ?>">
            <a href="admins.php">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                <span>Admin Users</span>
            </a>
        </li>
        <li class="sidebar-menu-item <?php echo $current_page == 'migrations.php' ? 'active' : ''; ?>">
            <a href="migrations.php">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                <span>Migration Tracker</span>
            </a>
        </li>
        <li class="sidebar-menu-item">
            <a href="../" target="_blank">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
                <span>View Public Site</span>
            </a>
        </li>
    </ul>
    
    <div class="sidebar-footer">
        <div class="sidebar-user">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
            <span>Logged in: <strong><?php echo htmlspecialchars($admin_username); ?></strong></span>
        </div>
        <a href="logout.php" class="btn btn-secondary btn-sm" style="width: 100%;">Log Out</a>
    </div>
</div>
