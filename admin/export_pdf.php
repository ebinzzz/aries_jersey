<?php
// admin/export_pdf.php
require_once dirname(__DIR__) . '/includes/db_config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/form_helpers.php';
require_once dirname(__DIR__) . '/includes/SimplePdfWriter.php';

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

// Fetch fields config
$fields_config = get_form_fields_config($form_id);

// Fetch registrations
$registrations = [];
$query = "
    SELECT r.*, t.name as team_name 
    FROM `registrations` r
    JOIN `teams` t ON r.team_id = t.id
    WHERE r.form_id = ?
    ORDER BY r.submitted_at DESC
";
$stmt = $db->prepare($query);
$stmt->bind_param("i", $form_id);
$stmt->execute();
$res = $stmt->get_result();
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $registrations[] = $row;
    }
    $res->free();
}
$stmt->close();

// Build headers
$headers = ['Player Name'];
foreach ($fields_config as $key => $config) {
    $headers[] = $config['label'];
}

// Setup file name
$cleanTitle = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '_', $form['title']), '_'));
$fileName = $cleanTitle . '_registrations_' . date('Ymd') . '.pdf';

// Compile PDF
$pdf = new SimplePdfWriter($form['title'], $fileName);
$pdf->setHeaders($headers);

foreach ($registrations as $reg) {
    $row = [
        $reg['player_name']
    ];
    foreach ($fields_config as $key => $config) {
        $row[] = $reg[$key] !== null ? $reg[$key] : '';
    }
    $pdf->addRow($row);
}

// Stream download or inline preview
$mode = $_GET['mode'] ?? 'download';
if ($mode === 'inline') {
    $pdf->inline();
} else {
    $pdf->download();
}
