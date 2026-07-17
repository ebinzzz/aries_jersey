<?php
// index.php
require_once __DIR__ . '/includes/db_config.php';

try {
    $db = get_db_connection();
    // Fetch open registration forms
    $forms = [];
    $res = $db->query("SELECT * FROM `forms` WHERE `status` = 'open' ORDER BY `created_at` DESC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $forms[] = $row;
        }
        $res->free();
    }
} catch (Exception $e) {
    // Database connection not set up or uninitialized
    $forms = null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aries Kollam Sailors | Jersey Kit Room</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        /* Custom Landing Page Overrides and Neon Effects */
        .landing-body {
            background: radial-gradient(circle at top right, rgba(0, 102, 255, 0.12), transparent 50%),
                        radial-gradient(circle at bottom left, rgba(225, 29, 72, 0.08), transparent 55%),
                        #030712;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            overflow-x: hidden;
        }

        /* Hero Layout - Responsive grid */
        .landing-hero {
            display: grid;
            grid-template-columns: 1.2fr 1fr;
            gap: 4rem;
            padding: 4rem 2rem;
            max-width: 1400px;
            width: 100%;
            margin: 0 auto;
            align-items: center;
            flex: 1;
            box-sizing: border-box;
        }

        .hero-title {
            font-size: clamp(2.25rem, 5vw, 4rem);
            line-height: 1.1;
            margin-bottom: 1.5rem;
            background: linear-gradient(135deg, #ffffff 30%, var(--text-secondary) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-weight: 900;
        }

        .hero-accent-text {
            color: var(--primary);
            text-shadow: 0 0 15px var(--primary-glow);
        }

        .hero-description {
            color: var(--text-secondary);
            font-size: clamp(0.95rem, 1.5vw, 1.1rem);
            line-height: 1.6;
            margin-bottom: 2.5rem;
            max-width: 600px;
        }

        /* 3D Visualizer Card */
        .visualizer-card {
            background: rgba(11, 21, 40, 0.75);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 2rem;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(8px);
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            align-items: center;
            width: 100%;
            box-sizing: border-box;
        }

        .visualizer-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary) 50%, var(--accent-blue) 50%);
        }

        .jersey-video-container {
            width: 100%;
            height: 380px;
            border-radius: var(--radius-md);
            overflow: hidden;
            border: 1px solid var(--border-color);
            background: #d6d6d6;
            display: flex;
            justify-content: center;
            align-items: center;
            position: relative;
            box-shadow: inset 0 0 20px rgba(0, 0, 0, 0.15);
        }

        .jersey-video {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        /* Forms Listing section */
        .forms-section-title {
            font-family: var(--font-heading);
            font-style: italic;
            font-size: 1.75rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .form-link-card {
            background: rgba(30, 58, 101, 0.2);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.25rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all var(--transition-normal);
            margin-bottom: 1rem;
            gap: 1rem;
            text-decoration: none;
        }

        .form-link-card:hover {
            border-color: var(--accent-blue);
            background: rgba(30, 58, 101, 0.4);
            transform: translateX(4px);
        }

        .rotate-helper {
            position: absolute;
            bottom: 10px;
            right: 15px;
            font-size: 0.75rem;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 0.25rem;
            pointer-events: none;
            user-select: none;
        }

        /* Media Queries for visual polish */
        @media (max-width: 1024px) {
            .landing-hero {
                grid-template-columns: 1fr;
                gap: 3rem;
                padding: 3rem 1.5rem;
            }
            .hero-description {
                max-width: 100%;
            }
        }

        @media (max-width: 576px) {
            .landing-hero {
                padding: 2rem 1rem;
                gap: 2.5rem;
            }
            .visualizer-card {
                padding: 1.25rem 1rem;
            }
            .jersey-video-container {
                height: 300px;
            }
            .form-link-card {
                flex-direction: column;
                align-items: stretch;
                text-align: center;
                padding: 1.25rem 1rem;
            }
            .form-link-card .btn {
                width: 100%;
                justify-content: center;
                margin-top: 0.5rem;
            }
        }

        /* Light Theme Overrides for Landing Page */
        .theme-light .landing-body {
            background: radial-gradient(circle at top right, rgba(0, 102, 255, 0.05), transparent 45%),
                        radial-gradient(circle at bottom left, rgba(225, 29, 72, 0.04), transparent 45%),
                        #f8fafc !important;
            color: #0f172a !important;
        }

        .theme-light .hero-title {
            background: none !important;
            -webkit-text-fill-color: initial !important;
            color: #0f172a !important;
        }

        .theme-light .hero-description {
            color: #475569 !important;
        }

        .theme-light .forms-section-title {
            color: #475569 !important;
        }

        .theme-light .visualizer-card {
            background: #ffffff !important;
            border: 1px solid rgba(0, 102, 255, 0.08) !important;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06), 0 1px 3px rgba(15, 23, 42, 0.02) !important;
            color: #0f172a !important;
        }

        .theme-light .visualizer-card h2 {
            color: #0f172a !important;
        }

        .theme-light .visualizer-card p {
            color: #475569 !important;
        }

        .theme-light .jersey-video-container {
            background: #e2e8f0 !important;
            border-color: #cbd5e1 !important;
        }

        .theme-light .visualizer-card strong {
            color: #0f172a !important;
        }

        .theme-light .form-link-card {
            background: #ffffff !important;
            border: 1px solid #cbd5e1 !important;
        }

        .theme-light .form-link-card:hover {
            border-color: var(--accent-blue) !important;
            background: #f8fafc !important;
            box-shadow: 0 8px 24px rgba(0, 102, 255, 0.08) !important;
        }

        .theme-light .form-link-card strong {
            color: #0f172a !important;
        }
    </style>
    <!-- No external 3D libraries needed -->
</head>
<body class="landing-body">

    <?php
    // Shared public header
    if (!defined('PUBLIC_ROOT')) {
        define('PUBLIC_ROOT', '/aries_jersey/');
    }
    if (!defined('ADMIN_URL')) {
        define('ADMIN_URL', PUBLIC_ROOT . 'admin/login.php');
    }
    require_once __DIR__ . '/includes/public_header.php';
    ?>

    <!-- Main Hero visual grid -->
    <main class="landing-hero">
        
        <!-- Left details and forms -->
        <div>
            <h1 class="hero-title">
                SAILING TOWARDS<br>
                <span class="hero-accent-text">SUPREMACY</span>
            </h1>
            
            <p class="hero-description">
                Welcome to the Aries Kollam Sailors uniform room. Customize your official team jersey with your personal print name and selected number in real-time 3D, then register in one of the active kit size catalogs below.
            </p>

            <div>
                <h2 class="forms-section-title">
                    <span style="display: inline-block; width: 12px; height: 12px; background: linear-gradient(135deg, var(--primary), var(--secondary)); border-radius: 50%;"></span>
                    Active Kit Registrations
                </h2>

                <?php if ($forms === null) : ?>
                    <!-- Database uninitialized config link -->
                    <div class="alert alert-warning" style="margin-top: 1rem;">
                        <strong>System Notice:</strong> The database connections are uninitialized. If you are setting up the system for the first time:
                        <div style="margin-top: 1rem;">
                            <a href="admin/migrations.php" class="btn btn-primary btn-sm">Open Migration Setup Wizard</a>
                        </div>
                    </div>
                <?php elseif (count($forms) > 0) : ?>
                    <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                        <?php foreach ($forms as $form) : ?>
                            <a href="form.php?slug=<?php echo urlencode($form['slug']); ?>" class="form-link-card">
                                <div>
                                    <strong style="color: var(--text-primary); font-size: 1.05rem;"><?php echo htmlspecialchars($form['title']); ?></strong>
                                    <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 0.15rem;">Click here to select sizes & submit</div>
                                </div>
                                <span class="btn btn-secondary btn-sm" style="padding: 0.4rem 1rem; border-color: var(--accent-blue); color: var(--accent-blue);">Register ➔</span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else : ?>
                    <div style="padding: 2rem; background: rgba(30, 58, 101, 0.2); border: 1px dashed var(--border-color); border-radius: var(--radius-md); text-align: center; color: var(--text-secondary);">
                        <p style="font-weight: 500;">No registrations are open at the moment.</p>
                        <p style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0.25rem;">Please check back later or contact your team coach.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Right Jersey Showcase -->
        <div class="visualizer-card">
            <h2 style="font-size: 1.25rem; font-style: italic; color: var(--text-primary); margin-bottom: 0.25rem; text-transform: uppercase;">Official Team Jersey</h2>
            <p style="font-size: 0.8rem; color: var(--text-secondary); margin-bottom: 1rem;">Kollam Sailors Playing Kit Design</p>
            
            <div class="jersey-video-container">
                <img src="assets/images/jersey_showcase.jpg" alt="Official Team Jersey" class="jersey-video">
            </div>

            <div style="width: 100%; margin-top: 1.5rem; border-top: 1px solid var(--border-color); padding-top: 1.5rem; display: flex; flex-direction: column; gap: 0.75rem;">
                <div style="font-size: 0.85rem; color: var(--text-secondary); line-height: 1.5; display: flex; align-items: flex-start; gap: 0.5rem;">
                    <span style="color: var(--accent-blue); font-weight: bold; font-size: 1rem; line-height: 1;">✔</span>
                    <span><strong>Official Design:</strong> Sublimated premium fabric with dual-tone navy/red waves and gold accent stripes.</span>
                </div>
                <div style="font-size: 0.85rem; color: var(--text-secondary); line-height: 1.5; display: flex; align-items: flex-start; gap: 0.5rem;">
                    <span style="color: var(--primary); font-weight: bold; font-size: 1rem; line-height: 1;">✔</span>
                    <span><strong>Custom Print:</strong> You can select your own print name and jersey number during registration.</span>
                </div>
            </div>
        </div>

    </main>


    <?php require_once __DIR__ . '/includes/public_footer.php'; ?>
</body>
</html>
