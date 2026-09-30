<?php
declare(strict_types=1);

require __DIR__ . '/_auth.php';

$requestId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$requestId) {
    http_response_code(400);
    exit('ລາຍການໄຟລ໌ແນບບໍ່ຖືກຕ້ອງ');
}

$requestStmt = $pdo->prepare(
    'SELECT id, request_no, handover_to_user_id, manager_id, attachment
     FROM leave_requests
     WHERE id = :id
     LIMIT 1'
);
$requestStmt->execute(['id' => $requestId]);
$request = $requestStmt->fetch();

if (!$request || empty($request['attachment'])) {
    http_response_code(404);
    exit('ບໍ່ພົບໄຟລ໌ແນບ');
}

// Files are intentionally available only to the person doing the handover work
// and to the manager assigned to approve this particular request.
$isHandoverAssignee = (int) ($request['handover_to_user_id'] ?? 0) === $userId;
$isApprover = (int) ($request['manager_id'] ?? 0) === $userId;
if (!$isHandoverAssignee && !$isApprover) {
    http_response_code(403);
    exit('ທ່ານບໍ່ມີສິດເບິ່ງໄຟລ໌ແນບນີ້');
}

$storedName = basename((string) $request['attachment']);
if ($storedName === '' || !preg_match('/^[a-f0-9]{32}\.(pdf|jpe?g|png)$/i', $storedName)) {
    http_response_code(404);
    exit('ບໍ່ພົບໄຟລ໌ແນບ');
}

$privatePath = dirname(__DIR__) . '/storage/leave_attachments/' . $storedName;
// This fallback lets existing uploads continue to work after the security
// upgrade; Apache denies direct requests to the legacy folder.
$legacyPath = __DIR__ . '/uploads/leave_attachments/' . $storedName;
$filePath = is_file($privatePath) ? $privatePath : $legacyPath;

if (!is_file($filePath) || !is_readable($filePath)) {
    http_response_code(404);
    exit('ບໍ່ພົບໄຟລ໌ແນບ');
}

$extension = strtolower(pathinfo($storedName, PATHINFO_EXTENSION));
$mimeByExtension = [
    'pdf' => 'application/pdf',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
];
$detectedMime = (new finfo(FILEINFO_MIME_TYPE))->file($filePath);
if (($mimeByExtension[$extension] ?? null) !== $detectedMime) {
    http_response_code(404);
    exit('ໄຟລ໌ແນບບໍ່ຖືກຕ້ອງ');
}

$isDownload = filter_input(INPUT_GET, 'download', FILTER_VALIDATE_INT) === 1;
$isRawPreview = filter_input(INPUT_GET, 'raw', FILTER_VALIDATE_INT) === 1;
$downloadName = 'leave-attachment-' . preg_replace('/[^A-Za-z0-9_-]/', '-', (string) $request['request_no']) . '.' . $extension;

if (!$isDownload && !$isRawPreview) {
    $context = leaveSessionContext();
    $contextSuffix = $context ? '&context=' . rawurlencode($context) : '';
    $rawUrl = 'attachment.php?id=' . (int) $request['id'] . '&raw=1' . $contextSuffix;
    $downloadUrl = 'attachment.php?id=' . (int) $request['id'] . '&download=1' . $contextSuffix;
    ?>
<!doctype html>
<html lang="lo">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ເບິ່ງໄຟລ໌ແນບ <?= h((string) $request['request_no']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Lao:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; background: #eef3fa; color: #17233c; font-family: "Noto Sans Lao", sans-serif; }
        .preview-shell { max-width: 1080px; margin: 0 auto; padding: 28px 20px; }
        .preview-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 14px; margin-bottom: 18px; }
        h1 { margin: 0; font-size: 1.3rem; }
        .sub { margin: 4px 0 0; color: #64748b; font-size: .9rem; }
        .actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .btn { display: inline-flex; align-items: center; gap: 7px; border: 1px solid #cbd5e1; border-radius: 9px; background: #fff; color: #1e3a8a; padding: 9px 12px; font: inherit; font-size: .9rem; font-weight: 700; text-decoration: none; cursor: pointer; }
        .btn.primary { border-color: #2563eb; background: #2563eb; color: #fff; }
        .preview-card { display: flex; min-height: 360px; max-height: 72vh; align-items: center; justify-content: center; overflow: hidden; border: 1px solid #d9e2ef; border-radius: 16px; background: #fff; box-shadow: 0 18px 42px rgba(30, 58, 138, .12); }
        .preview-image { display: block; max-width: 100%; max-height: 72vh; object-fit: contain; }
        .preview-pdf { width: 100%; height: 72vh; border: 0; }
        @media (max-width: 640px) { .preview-shell { padding: 18px 12px; } .preview-toolbar { align-items: flex-start; flex-direction: column; } .preview-card { min-height: 300px; max-height: 64vh; } .preview-image, .preview-pdf { max-height: 64vh; height: 64vh; } }
    </style>
</head>
<body>
    <main class="preview-shell">
        <div class="preview-toolbar">
            <div>
                <h1><i class="fa-solid fa-paperclip"></i> ເບິ່ງໄຟລ໌ແນບ</h1>
                <p class="sub">ຄຳຂໍ <?= h((string) $request['request_no']) ?></p>
            </div>
            <div class="actions">
                <button class="btn" type="button" onclick="history.back()"><i class="fa-solid fa-arrow-left"></i>ກັບ</button>
                <a class="btn primary" href="<?= h($downloadUrl) ?>"><i class="fa-solid fa-download"></i>ບັນທຶກໄຟລ໌</a>
            </div>
        </div>
        <section class="preview-card">
            <?php if ($detectedMime === 'application/pdf'): ?>
                <iframe class="preview-pdf" src="<?= h($rawUrl) ?>" title="ໄຟລ໌ແນບ PDF"></iframe>
            <?php else: ?>
                <img class="preview-image" src="<?= h($rawUrl) ?>" alt="ໄຟລ໌ແນບ <?= h((string) $request['request_no']) ?>">
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
<?php
    exit;
}

try {
    $auditStmt = $pdo->prepare(
        'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, new_value, ip_address, user_agent, created_at)
         VALUES (:user_id, :action, "leave_attachments", :entity_id, :new_value, :ip_address, :user_agent, NOW())'
    );
    $auditStmt->execute([
        'user_id' => $userId,
        'action' => $isDownload ? 'download_leave_attachment' : 'view_leave_attachment',
        'entity_id' => (int) $request['id'],
        'new_value' => json_encode(['request_no' => $request['request_no']], JSON_UNESCAPED_UNICODE),
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
    ]);
} catch (Throwable $exception) {
    error_log($exception->getMessage());
}

header('Content-Type: ' . $detectedMime);
header('Content-Length: ' . (string) filesize($filePath));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . ($isDownload ? 'attachment' : 'inline') . '; filename="' . $downloadName . '"');
readfile($filePath);
exit;
