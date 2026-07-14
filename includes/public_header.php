<?php

/**
 * includes/public_header.php
 * Shared branded header for public-facing pages (landing + form).
 * Usage: require_once __DIR__ . '/../includes/public_header.php';
 *
 * Variables accepted (optional):
 *   $page_title  – <title> tag value (without " | Aries Sailors")
 *   $back_url    – shows a back link if set (for form page)
 *   $back_label  – label for back link (default "← Home")
 */

$page_title  = $page_title  ?? 'Aries Kollam Sailors';
$back_url    = $back_url    ?? null;
$back_label  = $back_label  ?? '← Home';
?>
<script>
// Instantly apply saved theme to prevent flashing
(function() {
    var savedTheme = localStorage.getItem('theme');
    var defaultTheme = 'light';
    var currentTheme = savedTheme || defaultTheme;
    if (currentTheme === 'light') {
        document.documentElement.classList.add('theme-light');
    } else {
        document.documentElement.classList.remove('theme-light');
    }
})();
</script>
<header class="pub-header">
    <div class="pub-header-inner">

        <!-- Logo -->
        <a href="<?php echo defined('PUBLIC_ROOT') ? PUBLIC_ROOT : '/aries_jersey/'; ?>" class="pub-logo" aria-label="Home">
            <img src="<?php echo defined('PUBLIC_ROOT') ? PUBLIC_ROOT : '/aries_jersey/'; ?>assets/images/logo.png" alt="Aries Kollam Sailors Logo" class="pub-logo-img">
            <div>
                <div class="pub-logo-name">Kollam Sailors</div>
                <div class="pub-logo-sub">Jersey Portal</div>
            </div>
        </a>

        <!-- Right nav -->
        <nav class="pub-nav" aria-label="Site navigation">
            <?php if ($back_url) : ?>
                <a href="<?php echo htmlspecialchars($back_url); ?>" class="pub-nav-link"><?php echo htmlspecialchars($back_label); ?></a>
            <?php endif; ?>
            <span class="pub-badge">Sailors Jersey Portal</span>
            <a href="<?php echo defined('ADMIN_URL') ? ADMIN_URL : '/aries_jersey/admin/login.php'; ?>" class="btn btn-secondary btn-sm pub-admin-btn">Admin</a>
        </nav>

        <!-- Toggle Theme Button -->
        <button id="themeToggleBtn" class="theme-toggle-btn" aria-label="Toggle dark/light theme">
            <!-- Sun Icon (visible in light mode) -->
            <svg class="sun-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg>
            <!-- Moon Icon (visible in dark mode) -->
            <svg class="moon-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>
        </button>

        <!-- Mobile hamburger (hidden on desktop) -->
        <button class="pub-menu-btn" id="pubMenuBtn" aria-label="Toggle menu">
            <span></span><span></span><span></span>
        </button>
    </div>

    <!-- Mobile nav drawer -->
    <div class="pub-mobile-nav" id="pubMobileNav">
        <?php if ($back_url) : ?>
            <a href="<?php echo htmlspecialchars($back_url); ?>" class="pub-mobile-link"><?php echo htmlspecialchars($back_label); ?></a>
        <?php endif; ?>
        <a href="<?php echo defined('ADMIN_URL') ? ADMIN_URL : '/aries_jersey/admin/login.php'; ?>" class="pub-mobile-link">Admin Access</a>
        <a href="#" class="pub-mobile-link" id="mobileThemeToggle" onclick="document.getElementById('themeToggleBtn').click(); return false;">Switch Theme</a>
    </div>
</header>

<script>
(function() {
    var btn = document.getElementById('pubMenuBtn');
    var nav = document.getElementById('pubMobileNav');
    if (btn && nav) {
        btn.addEventListener('click', function() {
            var open = nav.classList.toggle('open');
            btn.classList.toggle('open', open);
            btn.setAttribute('aria-expanded', open);
        });
    }

    var toggleBtn = document.getElementById('themeToggleBtn');
    if (toggleBtn) {
        var sunIcon = toggleBtn.querySelector('.sun-icon');
        var moonIcon = toggleBtn.querySelector('.moon-icon');
        var mobileThemeToggle = document.getElementById('mobileThemeToggle');

        function updateIcons() {
            var isLight = document.documentElement.classList.contains('theme-light');
            if (isLight) {
                sunIcon.style.display = 'block';
                moonIcon.style.display = 'none';
                if (mobileThemeToggle) mobileThemeToggle.textContent = 'Switch to Dark Mode';
            } else {
                sunIcon.style.display = 'none';
                moonIcon.style.display = 'block';
                if (mobileThemeToggle) mobileThemeToggle.textContent = 'Switch to Light Mode';
            }
        }

        updateIcons();

        toggleBtn.addEventListener('click', function() {
            var isLight = document.documentElement.classList.toggle('theme-light');
            localStorage.setItem('theme', isLight ? 'light' : 'dark');
            updateIcons();
        });
    }
})();
</script>
