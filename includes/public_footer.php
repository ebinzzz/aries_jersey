<?php

/**
 * includes/public_footer.php
 * Shared branded footer for public-facing pages.
 * Usage: require_once __DIR__ . '/../includes/public_footer.php';
 */

?>
<footer class="pub-footer">
    <div class="pub-footer-inner">

        <!-- Brand -->
        <div class="pub-footer-brand">
            <img src="<?php echo defined('PUBLIC_ROOT') ? PUBLIC_ROOT : '/aries_jersey/'; ?>assets/images/logo.png" alt="Aries Kollam Sailors Logo" class="pub-footer-logo-img">
            <span class="pub-footer-name">Kollam Sailors &mdash; Jersey Portal</span>
        </div>

        <!-- Links -->
        <div class="pub-footer-links">
            <a href="<?php echo defined('PUBLIC_ROOT') ? PUBLIC_ROOT : '/aries_jersey/'; ?>">Home</a>
            <a href="<?php echo defined('ADMIN_URL') ? ADMIN_URL : '/aries_jersey/admin/login.php'; ?>">Admin</a>
        </div>

        <!-- Copyright -->
        <div class="pub-footer-copy">
            &copy; <?php echo date('Y'); ?> Aries Kollam Sailors &bull; All rights reserved
        </div>

    </div>
</footer>
