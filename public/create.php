<?php
declare(strict_types=1);

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/session.php';

startLeaveSession();

const SESSION_TIMEOUT = 1800;
const MAX_UPLOAD_BYTES = 5242880;
// Keep attachments outside the browser-accessible uploads directory. They are
// delivered only by attachment.php after checking the request permissions.
const UPLOAD_DIR = __DIR__ . '/../storage/leave_attachments';

if (empty($_SESSION['user'])) {
    header('Location: login.php' . (leaveSessionContext() ? roleContextQuery(leaveSessionContext()) : ''));
    exit;
}

if (empty($_SESSION['last_activity']) || time() - (int) $_SESSION['last_activity'] > SESSION_TIMEOUT) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php?timeout=1' . (leaveSessionContext() ? '&context=' . rawurlencode((string) leaveSessionContext()) : ''));
    exit;
}

$_SESSION['last_activity'] = time();

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirectToCreate(): void
{
    header('Location: create.php' . (leaveSessionContext() ? roleContextQuery(leaveSessionContext()) : ''));
    exit;
}

function businessDaysBetween(string $startDate, string $endDate, array $holidays): int
{
    $start = new DateTimeImmutable($startDate);
    $end = new DateTimeImmutable($endDate);
    $days = 0;

    for ($date = $start; $date <= $end; $date = $date->modify('+1 day')) {
        $dayOfWeek = (int) $date->format('N');
        $dateKey = $date->format('Y-m-d');

        if ($dayOfWeek >= 6 || in_array($dateKey, $holidays, true)) {
            continue;
        }

        $days++;
    }

    return $days;
}

function calculateLeaveDays(string $startDate, string $endDate, string $period, array $holidays): float
{
    $businessDays = businessDaysBetween($startDate, $endDate, $holidays);

    if ($businessDays <= 0) {
        return 0.0;
    }

    if ($period !== 'full_day' && $startDate === $endDate) {
        return 0.5;
    }

    return (float) $businessDays;
}

function hasOverlappingLeave(PDO $pdo, int $userId, string $startDate, string $endDate): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM leave_requests
         WHERE user_id = :user_id
           AND status IN ('Pending', 'Approved')
           AND start_date <= :end_date
           AND end_date >= :start_date"
    );
    $stmt->execute([
        'user_id' => $userId,
        'start_date' => $startDate,
        'end_date' => $endDate,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function generateRequestNo(PDO $pdo, int $year): string
{
    $prefix = sprintf('LV-%d-', $year);
    $stmt = $pdo->prepare(
        'SELECT request_no
         FROM leave_requests
         WHERE request_no LIKE :prefix
         ORDER BY request_no DESC
         LIMIT 1
         FOR UPDATE'
    );
    $stmt->execute(['prefix' => $prefix . '%']);
    $lastRequestNo = $stmt->fetchColumn();
    $nextNumber = 1;

    if (is_string($lastRequestNo) && preg_match('/^LV-\d{4}-(\d{6})$/', $lastRequestNo, $matches)) {
        $nextNumber = (int) $matches[1] + 1;
    }

    return $prefix . str_pad((string) $nextNumber, 6, '0', STR_PAD_LEFT);
}

function saveAttachment(array $file, array &$errors): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $errors[] = 'ບໍ່ສາມາດອັບໂຫຼດໄຟລ໌ແນບໄດ້ ກະລຸນາລອງໃໝ່ອີກຄັ້ງ';
        return null;
    }

    if (($file['size'] ?? 0) > MAX_UPLOAD_BYTES) {
        $errors[] = 'ໄຟລ໌ແນບຈຳເປັນຕ້ອງມີຂະໜາດບໍ່ເກີນ 5MB';
        return null;
    }

    $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png'];

    if (!in_array($extension, $allowedExtensions, true)) {
        $errors[] = 'ໄຟລ໌ແນບຕິດຕ້ອງເປັນ PDF, JPG, JPEG ຫຼື PNG ເທົ່ານັ້ນ';
        return null;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file((string) $file['tmp_name']);
    $allowedMimeTypes = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
    ];

    if (($allowedMimeTypes[$extension] ?? null) !== $mimeType) {
        $errors[] = 'ປະເພດໄຟລ໌ແນບບໍ່ຖືກຕ້ອງ';
        return null;
    }

    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0775, true);
    }

    $newFileName = bin2hex(random_bytes(16)) . '.' . $extension;
    $destination = UPLOAD_DIR . DIRECTORY_SEPARATOR . $newFileName;

    if (!move_uploaded_file((string) $file['tmp_name'], $destination)) {
        $errors[] = 'ບໍ່ສາມາດແນບໄຟລ໌ໄດ້';
        return null;
    }

    return $newFileName;
}

$pdo = db();
$sessionUser = $_SESSION['user'];
$userId = (int) $sessionUser['id'];
$currentYear = (int) date('Y');
$successMessage = '';
$errors = [];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$leaveTypesStmt = $pdo->prepare(
    'SELECT lt.id, lt.name, lt.code, lt.allow_half_day, lt.require_attachment, lt.min_notice_days,
            lt.max_days_per_request, lt.color,
            COALESCE(lb.entitled_days, lt.annual_quota) AS entitled_days,
            COALESCE(lb.used_days, 0) AS used_days,
            COALESCE(lb.pending_days, 0) AS pending_days,
            COALESCE(lb.remaining_days, lt.annual_quota) AS remaining_days
     FROM leave_types lt
     LEFT JOIN leave_balances lb
        ON lb.leave_type_id = lt.id
       AND lb.user_id = :user_id
       AND lb.year = :year
     WHERE lt.status = "active"
     ORDER BY lt.id'
);
$leaveTypesStmt->execute(['user_id' => $userId, 'year' => $currentYear]);
$leaveTypes = $leaveTypesStmt->fetchAll();

$employeesStmt = $pdo->prepare(
    'SELECT id, first_name, last_name, email
     FROM users
     WHERE status = "active" AND id <> :user_id
     ORDER BY first_name, last_name'
);
$employeesStmt->execute(['user_id' => $userId]);
$employees = $employeesStmt->fetchAll();

$holidaysStmt = $pdo->prepare(
    'SELECT holiday_date
     FROM holidays
     WHERE status = "active"
        AND YEAR(holiday_date) IN (:current_year, :next_year)'
);
$holidaysStmt->execute(['current_year' => $currentYear, 'next_year' => $currentYear + 1]);
$holidays = array_map(static fn (array $row): string => (string) $row['holiday_date'], $holidaysStmt->fetchAll());

$balancesByType = [];
foreach ($leaveTypes as $leaveType) {
    $balancesByType[(int) $leaveType['id']] = $leaveType;
}

$form = [
    'leave_type_id' => '',
    'start_date' => '',
    'end_date' => '',
    'period' => 'full_day',
    'reason' => '',
    'handover_to_user_id' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $today = new DateTimeImmutable('today');
    $form = [
        'leave_type_id' => trim((string) ($_POST['leave_type_id'] ?? '')),
        'start_date' => trim((string) ($_POST['start_date'] ?? '')),
        'end_date' => trim((string) ($_POST['end_date'] ?? '')),
        'period' => trim((string) ($_POST['period'] ?? 'full_day')),
        'reason' => trim((string) ($_POST['reason'] ?? '')),
        'handover_to_user_id' => trim((string) ($_POST['handover_to_user_id'] ?? '')),
    ];

    if (!hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'ຄຳຮ້ອງຂໍບໍ່ຖືກຕ້ອງ ກະລຸນາຣີເຟຣຊໜ້າແລ້ວລອງໃໝ່';
    }

    $leaveTypeId = filter_var($form['leave_type_id'], FILTER_VALIDATE_INT);
    $handoverToUserId = $form['handover_to_user_id'] !== ''
        ? filter_var($form['handover_to_user_id'], FILTER_VALIDATE_INT)
        : null;
    $periods = ['full_day', 'morning', 'afternoon'];
    $selectedLeaveType = $leaveTypeId ? ($balancesByType[(int) $leaveTypeId] ?? null) : null;

    if (!$leaveTypeId || !$selectedLeaveType) {
        $errors[] = 'ກະລຸນາເລືອກປະເພດການລາພັກ';
    }

    $startDate = null;
    $endDate = null;
    if ($form['start_date'] === '' || $form['end_date'] === '') {
        $errors[] = 'ກະລຸນາເລືອກວັນທີເລີ່ມ ແລະ ວັນທີສິ້ນສຸດ';
    } else {
        $startDate = DateTimeImmutable::createFromFormat('Y-m-d', $form['start_date']);
        $endDate = DateTimeImmutable::createFromFormat('Y-m-d', $form['end_date']);

        if (!$startDate || !$endDate) {
            $errors[] = 'ຮູບແບບວັນທີບໍ່ຖືກຕ້ອງ';
        } elseif ($startDate < $today) {
            $errors[] = 'ບໍ່ສາມາດຍື່ນໃບລາພັກຍ້ອນຫຼັງໄດ້';
        } elseif ($endDate < $startDate) {
            $errors[] = 'ວັນທີສິ້ນສຸດຕ້ອງບໍ່ໜ້ອຍກວ່າວັນທີເລີ່ມ';
        }
    }

    if (!in_array($form['period'], $periods, true)) {
        $errors[] = 'ກະລຸນາເລືອກຊ່ວງເວລາໃຫ້ຖືກຕ້ອງ';
    }

    if ($selectedLeaveType && $form['period'] !== 'full_day' && (int) $selectedLeaveType['allow_half_day'] !== 1) {
        $errors[] = 'ປະເພດການລານີ້ບໍ່ຮອງຮັບການລາພັກເຄິ່ງວັນ';
    }

    if ($form['period'] !== 'full_day' && $form['start_date'] !== $form['end_date']) {
        $errors[] = 'ການລາພັກເຄິ່ງວັນຕ້ອງເລືອກວັນທີເລີ່ມ ແລະ ສິ້ນສຸດເປັນວັນດຽວກັນ';
    }

    if ($form['reason'] === '') {
        $errors[] = 'ກະລຸນາປ້ອນເຫດຜົນການລາພັກ';
    } elseif (mb_strlen($form['reason'], 'UTF-8') < 5) {
        $errors[] = 'ເຫດຜົນການລາພັກຕ້ອງມີຢ່າງໜ້ອຍ 5 ຕົວອັກສອນ';
    }

    if ($selectedLeaveType && $startDate instanceof DateTimeImmutable) {
        $minNoticeDays = max(0, (int) $selectedLeaveType['min_notice_days']);
        if ($minNoticeDays > 0) {
            $noticeDays = (int) $today->diff($startDate)->format('%r%a');
            if ($noticeDays < $minNoticeDays) {
                $errors[] = 'ປະເພດການລານີ້ຕ້ອງຍື່ນລາພັກລ່ວງໜ້າຢ່າງໜ້ອຍ ' . $minNoticeDays . ' ວັນ';
            }
        }
    }

    if ($handoverToUserId === false) {
        $errors[] = 'ຜູ້ຮັບມອບໝາຍວຽກແທນບໍ່ຖືກຕ້ອງ';
    }

    $totalDays = 0.0;
    if ($form['start_date'] !== '' && $form['end_date'] !== '') {
        $totalDays = calculateLeaveDays($form['start_date'], $form['end_date'], $form['period'], $holidays);
        if ($totalDays <= 0) {
            $errors[] = 'ຊ່ວງວັນທີທີ່ເລືອກບໍ່ມີວັນເຮັດວຽກທີ່ສາມາດລາພັກໄດ້';
        }
    }

    if ($leaveTypeId && $totalDays > 0 && hasOverlappingLeave($pdo, $userId, $form['start_date'], $form['end_date'])) {
        $errors[] = 'ມີຄຳຮ້ອງຂໍລາພັກ ທີ່ລໍຖ້າອະນຸມັດ ຫຼື ອະນຸມັດແລ້ວຊ້ອນທັບກັບຊ່ວງວັນທີນີ້';
    }

    if ($selectedLeaveType && $totalDays > (float) $selectedLeaveType['remaining_days']) {
        $errors[] = 'ວັນລາພັກຄົງເຫຼືອບໍ່ພຽງພໍ ສຳລັບຄຳຮ້ອງຂໍນີ້';
    }

    if (
        $selectedLeaveType
        && $selectedLeaveType['max_days_per_request'] !== null
        && $totalDays > (float) $selectedLeaveType['max_days_per_request']
    ) {
        $errors[] = 'ປະເພດການລານີ້ຍື່ນໄດ້ສູງສຸດ ' . number_format((float) $selectedLeaveType['max_days_per_request'], 1) . ' ວັນຕໍ່ຄຳຮ້ອງຂໍ';
    }

    if ($selectedLeaveType && (int) $selectedLeaveType['require_attachment'] === 1 && (($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE)) {
        $errors[] = 'ປະເພດການລານີ້ຕ້ອງແນບໄຟລ໌ປະກອບ';
    }

    $attachmentPath = null;
    if (empty($errors)) {
        $attachmentPath = saveAttachment($_FILES['attachment'] ?? [], $errors);
    }

    if (empty($errors) && $selectedLeaveType) {
        try {
            $pdo->beginTransaction();

            $requestNo = generateRequestNo($pdo, (int) date('Y'));
            $currentUserStmt = $pdo->prepare('SELECT manager_id FROM users WHERE id = :id LIMIT 1');
            $currentUserStmt->execute(['id' => $userId]);
            $managerId = $currentUserStmt->fetchColumn() ?: null;
            ensureLeaveBalance($pdo, $userId, (int) $leaveTypeId, $currentYear);

            $balanceStmt = $pdo->prepare(
                'SELECT id, remaining_days
                 FROM leave_balances
                 WHERE user_id = :user_id
                   AND leave_type_id = :leave_type_id
                   AND year = :year
                 FOR UPDATE'
            );
            $balanceStmt->execute([
                'user_id' => $userId,
                'leave_type_id' => (int) $leaveTypeId,
                'year' => $currentYear,
            ]);
            $lockedBalance = $balanceStmt->fetch();

            if (!$lockedBalance || (float) $lockedBalance['remaining_days'] < $totalDays) {
                throw new RuntimeException('ວັນລາພັກຄົງເຫຼືອບໍ່ພຽງພໍ ສຳລັບຄຳຮ້ອງຂໍນີ້');
            }

            $insertStmt = $pdo->prepare(
                'INSERT INTO leave_requests (
                    request_no, user_id, leave_type_id, start_date, end_date, start_period, end_period,
                    total_days, reason, handover_to_user_id, attachment, status, manager_id, created_at, updated_at
                 ) VALUES (
                    :request_no, :user_id, :leave_type_id, :start_date, :end_date, :start_period, :end_period,
                    :total_days, :reason, :handover_to_user_id, :attachment, "Pending", :manager_id, NOW(), NOW()
                 )'
            );
            $insertStmt->execute([
                'request_no' => $requestNo,
                'user_id' => $userId,
                'leave_type_id' => (int) $leaveTypeId,
                'start_date' => $form['start_date'],
                'end_date' => $form['end_date'],
                'start_period' => $form['period'],
                'end_period' => $form['period'],
                'total_days' => $totalDays,
                'reason' => $form['reason'],
                'handover_to_user_id' => $handoverToUserId ?: null,
                'attachment' => $attachmentPath,
                'manager_id' => $managerId ?: null,
            ]);
            $leaveRequestId = (int) $pdo->lastInsertId();

            $updateBalanceStmt = $pdo->prepare(
                'UPDATE leave_balances
                 SET pending_days = pending_days + :pending_days,
                     remaining_days = remaining_days - :remaining_days,
                     updated_at = NOW()
                 WHERE id = :id'
            );
            $updateBalanceStmt->execute([
                'pending_days' => $totalDays,
                'remaining_days' => $totalDays,
                'id' => (int) $lockedBalance['id'],
            ]);

            if ($managerId) {
                $notificationStmt = $pdo->prepare(
                    'INSERT INTO notifications (user_id, title, message, link, is_read, created_at)
                     VALUES (:user_id, :title, :message, :link, 0, NOW())'
                );
                $notificationStmt->execute([
                    'user_id' => (int) $managerId,
                    'title' => 'ມີຄຳຮ້ອງຂໍລາພັກລໍຖ້າອະນຸມັດ',
                    'message' => $sessionUser['name'] . ' ສົ່ງຄຳຮ້ອງຂໍລາພັກ ' . $requestNo,
                    'link' => 'approvals.php',
                ]);
            }

            if ($handoverToUserId) {
                $handoverNotificationStmt = $pdo->prepare(
                    'INSERT INTO notifications (user_id, title, message, link, is_read, created_at)
                     VALUES (:user_id, :title, :message, :link, 0, NOW())'
                );
                $handoverNotificationStmt->execute([
                    'user_id' => (int) $handoverToUserId,
                    'title' => 'ໄດ້ຮັບມອບໝາຍວຽກແທນ',
                    'message' => $sessionUser['name'] . ' ມອບໝາຍວຽກສຳລັບຄຳຂໍລາ ' . $requestNo,
                    'link' => 'handover_tasks.php',
                ]);
            }

            $auditStmt = $pdo->prepare(
                'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, old_value, new_value, ip_address, user_agent, created_at)
                 VALUES (:user_id, "create_leave_request", "leave_requests", :entity_id, NULL, :new_value, :ip_address, :user_agent, NOW())'
            );
            $auditStmt->execute([
                'user_id' => $userId,
                'entity_id' => $leaveRequestId,
                'new_value' => json_encode([
                    'request_no' => $requestNo,
                    'status' => 'Pending',
                    'total_days' => $totalDays,
                ], JSON_UNESCAPED_UNICODE),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);

            $pdo->commit();

            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $successMessage = 'ສົ່ງຄຳຮ້ອງຂໍລາພັກຮຽບຮ້ອຍແລ້ວ ເລກທີຄຳຮ້ອງຂໍ ' . $requestNo;
            $form = [
                'leave_type_id' => '',
                'start_date' => '',
                'end_date' => '',
                'period' => 'full_day',
                'reason' => '',
                'handover_to_user_id' => '',
            ];

            $leaveTypesStmt->execute(['user_id' => $userId, 'year' => $currentYear]);
            $leaveTypes = $leaveTypesStmt->fetchAll();
            $balancesByType = [];
            foreach ($leaveTypes as $leaveType) {
                $balancesByType[(int) $leaveType['id']] = $leaveType;
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($attachmentPath) {
                @unlink(UPLOAD_DIR . DIRECTORY_SEPARATOR . basename($attachmentPath));
            }
            error_log($exception->getMessage());
            $errors[] = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'ບໍ່ສາມາດບັນທຶກຄຳຮ້ອງຂໍລາພັກໄດ້ ກະລຸນາລອງໃໝ່ອີກຄັ້ງ';
        }
    }
}

$roleLabels = [
    'admin' => 'ຜູ້ດູແລລະບົບ',
    'hr' => 'ຝ່າຍບຸກຄະລາກອນ',
    'manager' => 'ຫົວໜ້າງານ',
    'employee' => 'ພະນັກງານ',
];
$pageTitle = 'ຍື່ນຄຳຮ້ອງຂໍລາພັກ';
$activeMenu = 'request';
$appName = 'ລະບົບຈັດການການລາພັກ';
$userRole = (string) ($sessionUser['role'] ?? 'employee');
$currentUser = [
    'name' => (string) ($sessionUser['name'] ?? 'ຜູ້ໃຊ້ງານ'),
    'role' => $roleLabels[$userRole] ?? 'ພະນັກງານ',
    'department' => 'ອົງກອນ',
    'avatar' => (string) mb_substr((string) ($sessionUser['name'] ?? 'ຜ'), 0, 1, 'UTF-8'),
];
$unreadStmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND is_read = 0');
$unreadStmt->execute(['user_id' => $userId]);
$unreadCount = (int) $unreadStmt->fetchColumn();
$notifications = fetchUserNotifications($pdo, $userId, 5);
$notificationsUrl = 'notifications.php';
$leaveTypesForJs = [];
foreach ($leaveTypes as $leaveType) {
    $leaveTypesForJs[(string) $leaveType['id']] = [
        'name' => $leaveType['name'],
        'remaining_days' => (float) $leaveType['remaining_days'],
        'used_days' => (float) $leaveType['used_days'],
        'pending_days' => (float) $leaveType['pending_days'],
        'allow_half_day' => (int) $leaveType['allow_half_day'],
        'require_attachment' => (int) $leaveType['require_attachment'],
        'min_notice_days' => (int) $leaveType['min_notice_days'],
        'max_days_per_request' => $leaveType['max_days_per_request'] !== null
            ? (float) $leaveType['max_days_per_request']
            : null,
    ];
}

require __DIR__ . '/../views/layouts/header.php';
require __DIR__ . '/../views/layouts/sidebar.php';
?>
<div class="app-main-wrapper">
    <?php require __DIR__ . '/../views/layouts/topbar.php'; ?>
    <main class="app-content">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <p class="text-secondary mb-1">ປ້ອນຂໍ້ມູນຄຳຮ້ອງຂໍລາພັກ ແລະ ກວດສອບຍອດຄົງເຫຼືອກ່ອນສົ່ງຄຳຮ້ອງຂໍ</p>
                <h2 class="h4 fw-bold mb-0">ຍື່ນຄຳຮ້ອງຂໍລາພັກ</h2>
            </div>
            <a class="btn btn-outline-primary" href="index.php"><i class="fa-solid fa-arrow-left me-2"></i>ກັບຄືນແດຊບອດ</a>
        </div>

        <?php if ($successMessage !== ''): ?>
            <div class="alert alert-success">
                <i class="fa-solid fa-circle-check me-2"></i><?= h($successMessage) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <div class="fw-bold mb-1"><i class="fa-solid fa-circle-exclamation me-2"></i>ກະລຸນາກວດສອບຂໍ້ມູນ</div>
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?= h($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <section class="leave-form-grid">
            <form class="content-card leave-form-card" method="post" enctype="multipart/form-data" novalidate>
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">

                <div class="card-toolbar">
                    <div>
                        <h3 class="card-title">ລາຍລະອຽດການລາພັກ</h3>
                        <div class="card-subtitle">ລະບົບຈະຄິດໄລ່ຈຳນວນວັນລາພັກໃຫ້ໂດຍອັດໂນມັດ</div>
                    </div>
                    <span class="badge text-bg-warning">ລໍຖ້າອະນຸມັດຫຼັງສົ່ງຄຳຮ້ອງຂໍ</span>
                </div>

                <div class="leave-form-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label for="leave_type_id" class="form-label">ປະເພດການລາ <span class="text-danger">*</span></label>
                            <select class="form-select" id="leave_type_id" name="leave_type_id" required>
                                <option value="">ເລືອກປະເພດການລາພັກ</option>
                                <?php foreach ($leaveTypes as $leaveType): ?>
                                    <option value="<?= h((string) $leaveType['id']) ?>" <?= $form['leave_type_id'] === (string) $leaveType['id'] ? 'selected' : '' ?>>
                                        <?= h($leaveType['name']) ?> (ຄົງເຫຼືອ <?= h(number_format((float) $leaveType['remaining_days'], 1)) ?> ວັນ)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label for="start_date_trigger" class="form-label">ວັນທີເລີ່ມ <span class="text-danger">*</span></label>
                            <div class="lao-date-field" data-date-field="start_date">
                                <input type="hidden" id="start_date" name="start_date" min="<?= h(date('Y-m-d')) ?>" value="<?= h($form['start_date']) ?>">
                                <button type="button" class="lao-date-trigger" id="start_date_trigger" aria-haspopup="dialog" aria-expanded="false">
                                    <span class="lao-date-trigger-value">ເລືອກວັນທີ</span>
                                    <i class="fa-regular fa-calendar-days" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label for="end_date_trigger" class="form-label">ວັນທີສິ້ນສຸດ <span class="text-danger">*</span></label>
                            <div class="lao-date-field lao-date-field-end" data-date-field="end_date">
                                <input type="hidden" id="end_date" name="end_date" min="<?= h(date('Y-m-d')) ?>" value="<?= h($form['end_date']) ?>">
                                <button type="button" class="lao-date-trigger" id="end_date_trigger" aria-haspopup="dialog" aria-expanded="false">
                                    <span class="lao-date-trigger-value">ເລືອກວັນທີ</span>
                                    <i class="fa-regular fa-calendar-days" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="form-label">ຊ່ວງເວລາ <span class="text-danger">*</span></label>
                            <div class="period-options">
                                <input type="radio" class="btn-check" name="period" id="period_full_day" value="full_day" <?= $form['period'] === 'full_day' ? 'checked' : '' ?>>
                                <label class="btn btn-outline-primary" for="period_full_day"><i class="fa-regular fa-sun me-2"></i>ເຕັມວັນ</label>

                                <input type="radio" class="btn-check" name="period" id="period_morning" value="morning" <?= $form['period'] === 'morning' ? 'checked' : '' ?>>
                                <label class="btn btn-outline-primary" for="period_morning"><i class="fa-solid fa-cloud-sun me-2"></i>ເຄິ່ງເຊົ້າ</label>

                                <input type="radio" class="btn-check" name="period" id="period_afternoon" value="afternoon" <?= $form['period'] === 'afternoon' ? 'checked' : '' ?>>
                                <label class="btn btn-outline-primary" for="period_afternoon"><i class="fa-solid fa-sun me-2"></i>ເຄິ່ງແລງ</label>
                            </div>
                            <div class="form-text">ເຄິ່ງວັນໃຊ້ໄດ້ເມື່ອເລືອກວັນດຽວກັນເທົ່ານັ້ນ</div>
                        </div>

                        <div class="col-12">
                            <label for="reason" class="form-label">ເຫດຜົນ <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="reason" name="reason" rows="4" placeholder="ລະບຸເຫດຜົນການລາພັກ" required><?= h($form['reason']) ?></textarea>
                        </div>

                        <div class="col-md-6">
                            <label for="handover_to_user_id" class="form-label">ຜູ້ຮັບມອບໝາຍວຽກແທນ</label>
                            <select class="form-select" id="handover_to_user_id" name="handover_to_user_id">
                                <option value="">ບໍ່ລະບຸ</option>
                                <?php foreach ($employees as $employee): ?>
                                    <option value="<?= h((string) $employee['id']) ?>" <?= $form['handover_to_user_id'] === (string) $employee['id'] ? 'selected' : '' ?>>
                                        <?= h($employee['first_name'] . ' ' . $employee['last_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label for="attachment" class="form-label">ແນບໄຟລ໌</label>
                            <input type="file" class="form-control" id="attachment" name="attachment" accept=".pdf,.jpg,.jpeg,.png">
                            <div class="form-text" id="attachmentHelp">ຮອງຮັບ PDF, JPG, JPEG, PNG ຂະໜາດບໍ່ເກີນ 5MB</div>
                        </div>
                    </div>
                </div>

                <div class="leave-form-footer">
                    <div class="calculated-days">
                        <span>ຈຳນວນວັນທີ່ລາພັກ</span>
                        <strong id="totalDaysPreview">0ວັນ</strong>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-paper-plane me-2"></i>ສົ່ງຄຳຮ້ອງຂໍລາພັກ
                    </button>
                </div>
            </form>

            <aside class="d-grid gap-3">
                <article class="content-card">
                    <div class="card-toolbar">
                        <div>
                            <h3 class="card-title">ວັນລາພັກຄົງເຫຼືອ</h3>
                            <div class="card-subtitle">ຍອດປີ <?= h((string) $currentYear) ?></div>
                        </div>
                    </div>
                    <div class="balance-list">
                        <?php foreach ($leaveTypes as $leaveType): ?>
                            <?php
                            $entitled = max((float) $leaveType['entitled_days'], 0.01);
                            $remaining = (float) $leaveType['remaining_days'];
                            $percent = min(100, max(0, ($remaining / $entitled) * 100));
                            ?>
                            <div class="balance-row">
                                <div class="balance-head">
                                    <?= h($leaveType['name']) ?>
                                    <span><?= h(number_format($remaining, 1)) ?> / <?= h(number_format((float) $leaveType['entitled_days'], 1)) ?> ວັນ</span>
                                </div>
                                <div class="progress">
                                    <div class="progress-bar" style="width: <?= h(number_format($percent, 0)) ?>%; background-color: <?= h($leaveType['color']) ?>"></div>
                                </div>
                                <div class="small text-secondary">
                                    ໃຊ້ໄປ <?= h(number_format((float) $leaveType['used_days'], 1)) ?> ວັນ,
                                    ລໍຖ້າອະນຸມັດ <?= h(number_format((float) $leaveType['pending_days'], 1)) ?> ວັນ
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </article>

                <article class="content-card">
                    <div class="card-toolbar">
                        <div>
                            <h3 class="card-title">ສະຫຼຸບຄຳຮ້ອງຂໍ</h3>
                            <div class="card-subtitle">ກວດສອບກ່ອນສົ່ງ</div>
                        </div>
                    </div>
                    <div class="request-summary">
                        <div><span>ປະເພດການລາພັກ</span><strong id="summaryLeaveType">-</strong></div>
                        <div><span>ຊ່ວງວັນທີ</span><strong id="summaryDateRange">-</strong></div>
                        <div><span>ຊ່ວງເວລາ</span><strong id="summaryPeriod">ເຕັມວັນ</strong></div>
                        <div><span>ຍອດຄົງເຫຼືອ</span><strong id="summaryRemaining">-</strong></div>
                    </div>
                </article>
            </aside>
        </section>

        <script>
            window.leaveRequestConfig = {
                leaveTypes: <?= json_encode($leaveTypesForJs, JSON_UNESCAPED_UNICODE) ?>,
                holidays: <?= json_encode($holidays, JSON_UNESCAPED_UNICODE) ?>,
                today: '<?= h(date('Y-m-d')) ?>'
            };
        </script>
        <script src="assets/js/leave-create.js?v=20260929-lao-datepicker-fix"></script>
<?php require __DIR__ . '/../views/layouts/footer.php'; ?>
