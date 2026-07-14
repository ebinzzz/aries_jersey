<?php
// admin/preview_pdf.php
require_once dirname(__DIR__) . '/includes/db_config.php';
require_once dirname(__DIR__) . '/includes/auth.php';

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
$result = $stmt->get_result();
if (!$result || $result->num_rows === 0) {
    header("Location: index.php");
    exit;
}
$form = $result->fetch_assoc();
$stmt->close();

$cleanTitle  = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '_', $form['title']), '_'));
$fileName    = $cleanTitle . '_registrations_' . date('Ymd') . '.pdf';
$inlineUrl   = 'export_pdf.php?form_id=' . $form_id . '&mode=inline';
$downloadUrl = 'export_pdf.php?form_id=' . $form_id . '&mode=download';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PDF Preview — <?php echo htmlspecialchars($form['title']); ?> | PlayerKit Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .preview-wrapper {
            display: flex;
            flex-direction: column;
            height: 100vh;
            overflow: hidden;
        }
        .preview-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem 1.5rem;
            background: var(--bg-surface);
            border-bottom: 1px solid var(--border-color);
            flex-shrink: 0;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .preview-toolbar-title {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .preview-toolbar-title h1 {
            font-size: 1.1rem;
            background: linear-gradient(135deg, #fff, #94a3b8);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .preview-toolbar-title span {
            font-size: 0.78rem;
            color: var(--text-muted);
            font-weight: 400;
            font-style: normal;
            text-transform: none;
            letter-spacing: 0;
            -webkit-text-fill-color: var(--text-muted);
        }
        .preview-actions {
            display: flex;
            gap: 0.75rem;
            align-items: center;
        }
        .pdf-frame-container {
            flex: 1;
            background: #525659;
            display: flex;
            align-items: stretch;
            overflow: hidden;
        }
        .pdf-frame {
            width: 100%;
            height: 100%;
            border: none;
            display: block;
        }
        /* Loading overlay */
        .pdf-loading {
            position: absolute;
            inset: 0;
            background: #525659;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #ccc;
            font-size: 0.95rem;
            gap: 1rem;
            z-index: 10;
            transition: opacity 0.3s ease;
        }
        .pdf-loading.hidden { opacity: 0; pointer-events: none; }
        .spin {
            width: 40px; height: 40px;
            border: 3px solid rgba(255,255,255,0.2);
            border-top-color: #0066ff;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        @media (max-width: 600px) {
            .preview-toolbar { padding: 0.6rem 1rem; }
            .preview-toolbar-title h1 { font-size: 0.95rem; }
        }
    </style>
</head>
<body style="margin:0; padding:0; overflow:hidden;">

<div class="preview-wrapper">

    <!-- Toolbar -->
    <div class="preview-toolbar">
        <div class="preview-toolbar-title">
            <div class="sidebar-logo-icon" style="width:32px;height:32px;font-size:1rem;">PK</div>
            <div>
                <h1><?php echo htmlspecialchars($form['title']); ?></h1>
                <span>PDF Preview · <?php echo date('M d, Y'); ?></span>
            </div>
        </div>

        <div class="preview-actions">
            <a href="registrations.php?form_id=<?php echo $form_id; ?>" class="btn btn-secondary btn-sm">
                ← Back
            </a>
            <a href="<?php echo $downloadUrl; ?>" class="btn btn-primary" id="downloadBtn" download="<?php echo htmlspecialchars($fileName); ?>">
                ⬇ Download PDF
            </a>
        </div>
    </div>

    <!-- PDF Preview Frame -->
    <div class="pdf-frame-container" style="position:relative;">
        <div class="pdf-loading" id="loadingOverlay">
            <div class="spin"></div>
            <div>Generating PDF preview…</div>
        </div>
        <iframe
            id="pdfFrame"
            class="pdf-frame"
            src="<?php echo $inlineUrl; ?>"
            title="PDF Preview"
            onload="document.getElementById('loadingOverlay').classList.add('hidden')"
        ></iframe>
    </div>

</div>

</body>
</html>
