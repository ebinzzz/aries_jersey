<?php

// admin/export_excel.php
require_once dirname(__DIR__) . '/includes/db_config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/form_helpers.php';
require_once dirname(__DIR__) . '/includes/SimpleXlsxWriter.php';

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

// Build header row
$headers = ['Player Name'];
foreach ($fields_config as $key => $config) {
    $headers[] = $config['label'];
}
$headers[] = 'Submitted Date';

// Setup file name
$cleanTitle = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '_', $form['title']), '_'));
$fileName = $cleanTitle . '_registrations_' . date('Ymd') . '.xlsx';

// Initialize Excel Writer
$writer = new SimpleXlsxWriter($fileName);
$writer->addRow($headers);

foreach ($registrations as $reg) {
    $row = [$reg['player_name']];
    foreach ($fields_config as $key => $config) {
        $val = $reg[$key] ?? null;
        $is_qty = ($config['type'] === 'stepper') || in_array($key, ['half_sleeve_qty','full_sleeve_qty']);
        // Show blank for 0-qty fields (means none ordered)
        if ($is_qty && intval($val) === 0) {
            $row[] = '';
        } else {
            $row[] = $val !== null ? $val : '';
        }
    }
    $row[] = date('Y-m-d H:i', strtotime($reg['submitted_at']));
    $writer->addRow($row);
}

// Stream download
$writer->download();
