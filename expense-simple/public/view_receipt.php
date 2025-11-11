<?php
require_once __DIR__ . '/../inc/layout.php';
require_login();
$u = current_user();

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    http_response_code(400);
    echo "Invalid request.";
    exit;
}

$st = $pdo->prepare("SELECT receipt_blob, receipt_type, user_id FROM expenses WHERE id=?");
$st->execute([$id]);
$row = $st->fetch(PDO::FETCH_ASSOC);

if (!$row || (int)$row['user_id'] !== (int)$u['id']) {
    http_response_code(404);
    echo "Receipt not found.";
    exit;
}

if (empty($row['receipt_blob'])) {
    http_response_code(404);
    echo "No receipt available for this record.";
    exit;
}

$type = $row['receipt_type'] ?: 'application/octet-stream';
$data = $row['receipt_blob'];

header("Content-Type: $type");
header("Content-Length: " . strlen($data));

// for inline display in browser (PDF, image, etc.)
header("Content-Disposition: inline; filename=receipt_" . $id);

echo $data;
exit;
