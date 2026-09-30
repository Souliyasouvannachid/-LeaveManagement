<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

startLeaveSession();

const SESSION_TIMEOUT = 1800;

if (empty($_SESSION['user'])) {
    header('Location: login.php' . (leaveSessionContext() ? roleContextQuery(leaveSessionContext()) : ''));
    exit;
}

if (empty($_SESSION['last_activity']) || time() - (int) $_SESSION['last_activity'] > SESSION_TIMEOUT) {
    $_SESSION = [];
    session_destroy();
    $contextSuffix = leaveSessionContext() ? '&context=' . rawurlencode((string) leaveSessionContext()) : '';
    header('Location: login.php?timeout=1' . $contextSuffix);
    exit;
}

$_SESSION['last_activity'] = time();

if (!function_exists('h')) {
    function h(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

// ປ່ຽນຈາກ thaiDate ເປັນ laoDate ໃຫ້ກົງກັບບໍລິບົດພາສາລາວ
if (!function_exists('laoDate')) {
    function laoDate(?string $date): string
    {
        return $date ? (new DateTimeImmutable($date))->format('d/m/Y') : '-';
    }
}

// ຮັກສາຊື່ thaiDate ໄວ້ເພື່ອຄວາມເຂົ້າກັນໄດ້ກັບໜ້າທີ່ຍັງໃຊ້ຊື່ເກົ່າ
if (!function_exists('thaiDate')) {
    function thaiDate(?string $date): string
    {
        return laoDate($date);
    }
}

// ແປສະຖານະ (Status) ເປັນພາສາລາວ
if (!function_exists('renderStatusBadge')) {
    function renderStatusBadge(string $status): string
    {
        $map = [
            'Draft' => ['secondary', 'ຮ່າງ'],
            'Pending' => ['warning', 'ລໍຖ້າອະນຸມັດ'],
            'Approved' => ['success', 'ອະນຸມັດແລ້ວ'],
            'Rejected' => ['danger', 'ບໍ່ອະນຸມັດ'],
            'Cancelled' => ['dark', 'ຍົກເລີກແລ້ວ'],
        ];
        [$color, $label] = $map[$status] ?? ['secondary', $status];

        return '<span class="badge text-bg-' . h($color) . '">' . h($label) . '</span>';
    }
}

$pdo = db();
$sessionUser = $_SESSION['user'];
$userId = (int) $sessionUser['id'];
$userRole = (string) ($sessionUser['role'] ?? 'employee');

// ປ່ຽນ Label ຂອງ Role ເປັນພາສາລາວ (ຫຼື ໃຊ້ ທັບສັບພາສາອັງກິດ)
$roleLabels = [
    'admin' => 'ຜູ້ດູແລລະບົບ',
    'hr' => 'ຝ່າຍບຸກຄະລາກອນ',
    'manager' => 'ຫົວໜ້າ / ຜູ້ຈັດການ',
    'employee' => 'ພະນັກງານ',
];

$departmentNameStmt = $pdo->prepare(
    'SELECT d.name
     FROM users u
     LEFT JOIN departments d ON d.id = u.department_id
     WHERE u.id = :id
     LIMIT 1'
);
$departmentNameStmt->execute(['id' => $userId]);
$departmentName = $departmentNameStmt->fetchColumn() ?: 'ອົງກອນ';

$currentUser = [
    'name' => (string) ($sessionUser['name'] ?? 'ຜູ້ໃຊ້ງານ'),
    'role' => $roleLabels[$userRole] ?? 'ພະນັກງານ',
    'department' => (string) $departmentName,
    'avatar' => (string) mb_substr((string) ($sessionUser['name'] ?? 'ຜ'), 0, 1, 'UTF-8'),
];

$unreadStmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND is_read = 0');
$unreadStmt->execute(['user_id' => $userId]);
$unreadCount = (int) $unreadStmt->fetchColumn();

$notifications = fetchUserNotifications($pdo, $userId, 5);
$notificationsUrl = 'notifications.php';
