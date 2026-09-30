<?php
declare(strict_types=1);

require __DIR__ . '/_auth.php';

$notificationId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$notificationId) {
    http_response_code(400);
    exit('ລາຍການແຈ້ງເຕືອນບໍ່ຖືກຕ້ອງ');
}

$notificationStmt = $pdo->prepare(
    'SELECT link
     FROM notifications
     WHERE id = :id AND user_id = :user_id
     LIMIT 1'
);
$notificationStmt->execute([
    'id' => $notificationId,
    'user_id' => $userId,
]);
$notification = $notificationStmt->fetch();

if (!$notification) {
    http_response_code(404);
    exit('ບໍ່ພົບການແຈ້ງເຕືອນ');
}

$markReadStmt = $pdo->prepare(
    'UPDATE notifications
     SET is_read = 1
     WHERE id = :id AND user_id = :user_id'
);
$markReadStmt->execute([
    'id' => $notificationId,
    'user_id' => $userId,
]);

$destination = normalizeNotificationLink($notification['link'] ?? null);
$context = leaveSessionContext();
if ($context) {
    $destination .= (str_contains($destination, '?') ? '&' : '?') . 'context=' . rawurlencode($context);
}

header('Location: ' . $destination);
exit;
