<?php
declare(strict_types=1);

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/session.php';

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

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function fetchValue(PDO $pdo, string $sql, array $params = []): int|float|string|null
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $value = $stmt->fetchColumn();

    return $value === false ? null : $value;
}

function fetchRows(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function thaiDate(?string $date): string
{
    if (!$date) {
        return '-';
    }

    return (new DateTimeImmutable($date))->format('d/m/Y');
}

function formatDays(float|int|string|null $days): string
{
    return number_format((float) $days, 1);
}

function renderStatusBadge(string $status): string
{
    $map = [
        'Draft' => ['secondary', 'ສະບັບຮ່າງ'],
        'Pending' => ['warning', 'ລໍອະນຸມັດ'],
        'Approved' => ['success', 'ອະນຸມັດແລ້ວ'],
        'Rejected' => ['danger', 'ບໍ່ອະນຸມັດ'],
        'Cancelled' => ['dark', 'ຍົກເລີກແລ້ວ'],
    ];
    [$color, $label] = $map[$status] ?? ['secondary', $status];

    return '<span class="badge text-bg-' . h($color) . '">' . h($label) . '</span>';
}

function statCard(string $label, string $value, string $meta, string $icon, string $tone = 'primary'): string
{
    return '<article class="stat-card">'
        . '<div class="stat-icon ' . h($tone) . '"><i class="fa-solid ' . h($icon) . '"></i></div>'
        . '<div><div class="stat-label">' . h($label) . '</div>'
        . '<h3 class="stat-value">' . h($value) . '</h3>'
        . '<div class="stat-meta">' . h($meta) . '</div></div>'
        . '</article>';
}

function adminActivityDetails(string $action, string $entityType): array
{
    $activity = match ($action) {
        'login' => ['fa-right-to-bracket', 'success', 'ເຂົ້າໃຊ້ງານລະບົບ'],
        'create_leave_request' => ['fa-file-circle-plus', 'primary', 'ສົ່ງຄຳຂໍລາພັກ'],
        'approve_leave_request' => ['fa-circle-check', 'success', 'ອະນຸມັດຄຳຂໍລາພັກ'],
        'reject_leave_request' => ['fa-circle-xmark', 'danger', 'ບໍ່ອະນຸມັດຄຳຂໍລາພັກ'],
        'update' => ['fa-pen-to-square', 'warning', 'ແກ້ໄຂຂໍ້ມູນ'],
        'create' => ['fa-circle-plus', 'primary', 'ເພີ່ມຂໍ້ມູນ'],
        'delete' => ['fa-trash-can', 'danger', 'ລຶບຂໍ້ມູນ'],
        default => ['fa-clock-rotate-left', 'slate', 'ບັນທຶກກິດຈະກຳ ' . $entityType],
    };

    return ['icon' => $activity[0], 'tone' => $activity[1], 'label' => $activity[2]];
}

$pdo = db();
$sessionUser = $_SESSION['user'];
$userId = (int) $sessionUser['id'];
$userRole = (string) ($sessionUser['role'] ?? 'employee');
$currentYear = (int) date('Y');
$adminDashboardYear = $currentYear;
if ($userRole === 'admin' && isset($_GET['year'])) {
    $requestedYear = (int) $_GET['year'];
    if ($requestedYear >= 2000 && $requestedYear <= 2100) {
        $adminDashboardYear = $requestedYear;
    }
}

$departmentName = fetchValue(
    $pdo,
    'SELECT d.name
     FROM users u
     LEFT JOIN departments d ON d.id = u.department_id
     WHERE u.id = :id
     LIMIT 1',
    ['id' => $userId]
) ?: 'ອົງກອນ';

$roleLabels = [
    'admin' => 'ຜູ້ດູແລລະບົບ',
    'hr' => 'ຝ່າຍບຸກຄະລາກອນ',
    'manager' => 'ຫົວໜ້າງານ',
    'employee' => 'ພະນັກງານ',
];

$dashboardLabels = [
    'admin' => 'ແດດບອດຜູ້ດູແລລະບົບ',
    'hr' => 'ແດດບອດຝ່າຍບຸກຄະລາກອນ',
    'manager' => 'ແດດບອດຫົວໜ້າງານ',
    'employee' => 'ແດດບອດພະນັກງານ',
];

$pageTitle = $dashboardLabels[$userRole] ?? 'ແດດບອດພະນັກງານ';
$activeMenu = 'dashboard';
$appName = 'ລະບົບຈັດການການລາພັກ';
$currentUser = [
    'name' => (string) ($sessionUser['name'] ?? 'ຜູ້ໃຊ້ງານ'),
    'role' => $roleLabels[$userRole] ?? 'ພະນັກງານ',
    'department' => (string) $departmentName,
    'avatar' => (string) mb_substr((string) ($sessionUser['name'] ?? 'ຜ'), 0, 1, 'UTF-8'),
];

$unreadCount = (int) fetchValue(
    $pdo,
    'SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND is_read = 0',
    ['user_id' => $userId]
);
$notifications = fetchUserNotifications($pdo, $userId, 5);
$notificationsUrl = 'notifications.php';

$employeeBalances = [];
$employeeRecentRequests = [];
$managerPendingRequests = [];
$managerTeamLeaves = [];
$managerCalendar = [];
$hrRecentRequests = [];
$hrLeaveSummary = [];
$adminRecentRequests = [];
$adminRoleDistribution = [];
$adminMonthlyTrend = [];
$adminActivities = [];
$adminAvailableYears = [];

if ($userRole === 'employee') {
    $employeeBalances = fetchRows(
        $pdo,
        'SELECT lt.name, lt.color, lb.entitled_days, lb.used_days, lb.pending_days, lb.remaining_days
         FROM leave_balances lb
         INNER JOIN leave_types lt ON lt.id = lb.leave_type_id
         WHERE lb.user_id = :user_id AND lb.year = :year
         ORDER BY lt.id',
        ['user_id' => $userId, 'year' => $currentYear]
    );

    $employeeRecentRequests = fetchRows(
        $pdo,
        'SELECT lr.request_no, lr.start_date, lr.end_date, lr.total_days, lr.status, lt.name AS leave_type_name
         FROM leave_requests lr
         INNER JOIN leave_types lt ON lt.id = lr.leave_type_id
         WHERE lr.user_id = :user_id
         ORDER BY lr.created_at DESC
         LIMIT 6',
        ['user_id' => $userId]
    );
} elseif ($userRole === 'manager') {
    $managerPendingRequests = fetchRows(
        $pdo,
        'SELECT lr.id, lr.request_no, lr.start_date, lr.end_date, lr.total_days, lr.status,
                u.first_name, u.last_name, lt.name AS leave_type_name
         FROM leave_requests lr
         INNER JOIN users u ON u.id = lr.user_id
         INNER JOIN leave_types lt ON lt.id = lr.leave_type_id
         WHERE lr.manager_id = :manager_id AND lr.status = "Pending"
         ORDER BY lr.created_at ASC
         LIMIT 8',
        ['manager_id' => $userId]
    );

    $managerTeamLeaves = fetchRows(
        $pdo,
        'SELECT lr.request_no, lr.start_date, lr.end_date, lr.total_days,
                u.first_name, u.last_name, lt.name AS leave_type_name, lt.color
         FROM leave_requests lr
         INNER JOIN users u ON u.id = lr.user_id
         INNER JOIN leave_types lt ON lt.id = lr.leave_type_id
         WHERE lr.manager_id = :manager_id
           AND lr.status = "Approved"
           AND lr.end_date >= CURDATE()
         ORDER BY lr.start_date ASC
         LIMIT 8',
        ['manager_id' => $userId]
    );

    $managerCalendar = fetchRows(
        $pdo,
        'SELECT lr.start_date, lr.end_date, u.first_name, u.last_name, lt.name AS leave_type_name, lt.color
         FROM leave_requests lr
         INNER JOIN users u ON u.id = lr.user_id
         INNER JOIN leave_types lt ON lt.id = lr.leave_type_id
         WHERE lr.manager_id = :manager_id
           AND lr.status = "Approved"
           AND lr.start_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 45 DAY)
         ORDER BY lr.start_date ASC
         LIMIT 10',
        ['manager_id' => $userId]
    );
} elseif ($userRole === 'hr') {
    $hrRecentRequests = fetchRows(
        $pdo,
        'SELECT lr.id, lr.request_no, lr.start_date, lr.end_date, lr.total_days, lr.status,
                u.first_name, u.last_name, d.name AS department_name, lt.name AS leave_type_name
         FROM leave_requests lr
         INNER JOIN users u ON u.id = lr.user_id
         LEFT JOIN departments d ON d.id = u.department_id
         INNER JOIN leave_types lt ON lt.id = lr.leave_type_id
         ORDER BY lr.created_at DESC
         LIMIT 8'
    );

    $hrLeaveSummary = fetchRows(
        $pdo,
        'SELECT lt.name, lt.color, COUNT(lr.id) AS request_count, COALESCE(SUM(lr.total_days), 0) AS total_days
         FROM leave_types lt
         LEFT JOIN leave_requests lr
           ON lr.leave_type_id = lt.id
          AND lr.status = "Approved"
          AND YEAR(lr.start_date) = :year
         GROUP BY lt.id, lt.name, lt.color
         ORDER BY total_days DESC',
        ['year' => $currentYear]
    );
} elseif ($userRole === 'admin') {
    $adminAvailableYears = array_map(
        static fn (array $row): int => (int) $row['year_value'],
        fetchRows(
            $pdo,
            'SELECT DISTINCT year_value
             FROM (
                 SELECT YEAR(created_at) AS year_value FROM leave_requests
                 UNION
                 SELECT YEAR(holiday_date) AS year_value FROM holidays
             ) years
             WHERE year_value IS NOT NULL
             ORDER BY year_value DESC'
        )
    );
    if (!in_array($adminDashboardYear, $adminAvailableYears, true)) {
        $adminAvailableYears[] = $adminDashboardYear;
        rsort($adminAvailableYears);
    }

    $adminRecentRequests = fetchRows(
        $pdo,
        'SELECT lr.id, lr.request_no, lr.start_date, lr.end_date, lr.total_days, lr.status,
                u.first_name, u.last_name, d.name AS department_name, lt.name AS leave_type_name
         FROM leave_requests lr
         INNER JOIN users u ON u.id = lr.user_id
         LEFT JOIN departments d ON d.id = u.department_id
         INNER JOIN leave_types lt ON lt.id = lr.leave_type_id
         ORDER BY lr.created_at DESC
         LIMIT 8'
    );

    $adminRoleDistribution = fetchRows(
        $pdo,
        'SELECT role, COUNT(*) AS total
         FROM users
         GROUP BY role
         ORDER BY FIELD(role, "admin", "hr", "manager", "employee")'
    );

    $adminMonthlyTrend = fetchRows(
        $pdo,
        'SELECT MONTH(created_at) AS month_number, COUNT(*) AS total
         FROM leave_requests
         WHERE YEAR(created_at) = :year
         GROUP BY MONTH(created_at)
         ORDER BY month_number',
        ['year' => $adminDashboardYear]
    );

    $adminActivities = fetchRows(
        $pdo,
        'SELECT al.action, al.entity_type, al.created_at, u.first_name, u.last_name, u.email
         FROM audit_logs al
         LEFT JOIN users u ON u.id = al.user_id
         ORDER BY al.created_at DESC
         LIMIT 8'
    );
}

require __DIR__ . '/../views/layouts/header.php';
require __DIR__ . '/../views/layouts/sidebar.php';
?>
<div class="app-main-wrapper">
    <?php require __DIR__ . '/../views/layouts/topbar.php'; ?>
    <main class="app-content">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <p class="text-secondary mb-1">ພາບລວມລະບົບລາພັກພາຍໃນອົງກອນສຳລັບ <?= h($currentUser['role']) ?></p>
                <h2 class="h4 fw-bold mb-0">ສະບາຍດີ, <?= h($currentUser['name']) ?></h2>
            </div>
            <div class="quick-actions">
                <?php if (in_array($userRole, ['employee', 'manager'], true)): ?>
                    <a class="btn btn-primary" href="create.php"><i class="fa-solid fa-calendar-plus me-2"></i>ຍື່ນຄຳຂໍລາ</a>
                <?php endif; ?>
                <?php if ($userRole === 'manager'): ?>
                    <a class="btn btn-outline-primary" href="approvals.php"><i class="fa-solid fa-circle-check me-2"></i>ອະນຸມັດຄຳຂໍ</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($userRole === 'employee'): ?>
            <?php
            $remainingDays = array_sum(array_map(static fn (array $row): float => (float) $row['remaining_days'], $employeeBalances));
            $usedDays = array_sum(array_map(static fn (array $row): float => (float) $row['used_days'], $employeeBalances));
            $pendingDays = array_sum(array_map(static fn (array $row): float => (float) $row['pending_days'], $employeeBalances));
            $pendingRequests = (int) fetchValue($pdo, 'SELECT COUNT(*) FROM leave_requests WHERE user_id = :user_id AND status = "Pending"', ['user_id' => $userId]);
            ?>
            <section class="summary-grid">
                <?= statCard('ວັນລາພັກຄົງເຫຼືອ', formatDays($remainingDays), 'ລວມທຸກປະເພດໃນປີ ' . $currentYear, 'fa-umbrella-beach', 'primary') ?>
                <?= statCard('ໃຊ້ໄປແລ້ວ', formatDays($usedDays), 'ຈຳນວນວັນລາພັກທີ່ອະນຸມັດແລ້ວ', 'fa-calendar-check', 'success') ?>
                <?= statCard('ລໍອະນຸມັດ', (string) $pendingRequests, formatDays($pendingDays) . ' ວັນຢູ່ລະຫວ່າງພິຈາລະນາ', 'fa-clock', 'warning') ?>
                <?= statCard('ຄຳຂໍຫຼ້າສຸດ', (string) count($employeeRecentRequests), 'ລາຍການເຄື່ອນໄຫວຫຼ້າສຸດ', 'fa-file-lines', 'primary') ?>
            </section>

            <section class="dashboard-grid">
                <article class="content-card">
                    <div class="card-toolbar">
                        <div>
                            <h3 class="card-title">ຄຳຂໍຫຼ້າສຸດ</h3>
                            <div class="card-subtitle">ຕິດຕາມສະຖານະຄຳຂໍຂອງທ່ານ</div>
                        </div>
                        <a class="btn btn-sm btn-primary" href="create.php"><i class="fa-solid fa-plus me-2"></i>ຍື່ນລາພັກ</a>
                    </div>
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead><tr><th>ເລກທີຄຳຂໍ</th><th>ປະເພດ</th><th>ຊ່ວງວັນທີ</th><th>ຈຳນວນ</th><th>ສະຖານະ</th></tr></thead>
                            <tbody>
                                <?php foreach ($employeeRecentRequests as $request): ?>
                                    <tr>
                                        <td class="fw-bold"><?= h($request['request_no']) ?></td>
                                        <td><?= h($request['leave_type_name']) ?></td>
                                        <td><?= h(thaiDate($request['start_date'])) ?> - <?= h(thaiDate($request['end_date'])) ?></td>
                                        <td><?= h(formatDays($request['total_days'])) ?> ວັນ</td>
                                        <td><?= renderStatusBadge($request['status']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($employeeRecentRequests)): ?>
                                    <tr><td colspan="5" class="text-center text-secondary py-4">ຍັງບໍ່ມີຄຳຂໍລາພັກ</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </article>

                <aside class="content-card">
                    <div class="card-toolbar">
                        <div>
                            <h3 class="card-title">ວັນລາພັກຄົງເຫຼືອ</h3>
                            <div class="card-subtitle">ແຍກຕາມປະເພດການລາ</div>
                        </div>
                    </div>
                    <div class="balance-list">
                        <?php foreach ($employeeBalances as $balance): ?>
                            <?php
                            $entitled = max((float) $balance['entitled_days'], 0.01);
                            $percent = min(100, max(0, ((float) $balance['remaining_days'] / $entitled) * 100));
                            ?>
                            <div class="balance-row">
                                <div class="balance-head"><?= h($balance['name']) ?> <span><?= h(formatDays($balance['remaining_days'])) ?> / <?= h(formatDays($balance['entitled_days'])) ?> ວັນ</span></div>
                                <div class="progress"><div class="progress-bar" style="width: <?= h((string) round($percent)) ?>%; background-color: <?= h($balance['color']) ?>"></div></div>
                                <div class="small text-secondary">ໃຊ້ໄປ <?= h(formatDays($balance['used_days'])) ?> ວັນ · ລໍອະນຸມັດ <?= h(formatDays($balance['pending_days'])) ?> ວັນ</div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </aside>
            </section>
        <?php elseif ($userRole === 'manager'): ?>
            <?php
            $teamMembers = (int) fetchValue($pdo, 'SELECT COUNT(*) FROM users WHERE manager_id = :manager_id AND status = "active"', ['manager_id' => $userId]);
            $approvedTeamLeaves = (int) fetchValue($pdo, 'SELECT COUNT(*) FROM leave_requests WHERE manager_id = :manager_id AND status = "Approved"', ['manager_id' => $userId]);
            ?>
            <section class="summary-grid">
                <?= statCard('ຄຳຂໍລໍອະນຸມັດ', (string) count($managerPendingRequests), 'ຄຳຂໍຈາກລູກທີມ', 'fa-clock', 'warning') ?>
                <?= statCard('ລູກທີມທັງໝົດ', (string) $teamMembers, 'ພະນັກງານທີ່ຢູ່ພາຍໃຕ້ການດູແລ', 'fa-users', 'primary') ?>
                <?= statCard('ລາຍການລາພັກທີ່ອະນຸມັດ', (string) $approvedTeamLeaves, 'ປະຫວັດຄຳຂໍທີ່ອະນຸມັດແລ້ວ', 'fa-circle-check', 'success') ?>
                <?= statCard('ປະຕິທິນທີມ', (string) count($managerCalendar), 'ລາຍການລາພັກທີ່ກຳລັງຈະມາເຖິງ', 'fa-calendar-days', 'primary') ?>
            </section>

            <section class="dashboard-grid">
                <article class="content-card">
                    <div class="card-toolbar">
                        <div><h3 class="card-title">ຄຳຂໍລໍອະນຸມັດ</h3><div class="card-subtitle">ອະນຸມັດ ຫຼື ບໍ່ອະນຸມັດໄດ້ຈາກໜ້າລາຍລະອຽດ</div></div>
                        <a class="btn btn-sm btn-primary" href="approvals.php">ໄປໜ້າອະນຸມັດ</a>
                    </div>
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead><tr><th>ເລກທີ</th><th>ພະນັກງານ</th><th>ປະເພດ</th><th>ຊ່ວງວັນທີ</th><th>ຈຳນວນ</th><th>ສະຖານະ</th></tr></thead>
                            <tbody>
                                <?php foreach ($managerPendingRequests as $request): ?>
                                    <tr>
                                        <td class="fw-bold"><?= h($request['request_no']) ?></td>
                                        <td><?= h($request['first_name'] . ' ' . $request['last_name']) ?></td>
                                        <td><?= h($request['leave_type_name']) ?></td>
                                        <td><?= h(thaiDate($request['start_date'])) ?> - <?= h(thaiDate($request['end_date'])) ?></td>
                                        <td><?= h(formatDays($request['total_days'])) ?> ວັນ</td>
                                        <td><?= renderStatusBadge($request['status']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($managerPendingRequests)): ?>
                                    <tr><td colspan="6" class="text-center text-secondary py-4">ບໍ່ມີຄຳຂໍລໍອະນຸມັດ</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </article>

                <aside class="d-grid gap-3">
                    <article class="content-card">
                        <div class="card-toolbar"><div><h3 class="card-title">ລູກທີມທີ່ລາພັກ </h3><div class="card-subtitle">ລາຍການລາພັກທີ່ອະນຸມັດແລ້ວ ແລະ ຍັງບໍ່ທັນສິ້ນສຸດ</div></div></div>
                        <div class="request-summary">
                            <?php foreach ($managerTeamLeaves as $leave): ?>
                                <div><span><?= h($leave['first_name'] . ' ' . $leave['last_name']) ?></span><strong><?= h($leave['leave_type_name']) ?> · <?= h(thaiDate($leave['start_date'])) ?></strong></div>
                            <?php endforeach; ?>
                            <?php if (empty($managerTeamLeaves)): ?><p class="text-secondary mb-0">ບໍ່ມີລູກທີມທີ່ລາພັກໃນຊ່ວງນີ້</p><?php endif; ?>
                        </div>
                    </article>
                    <article class="content-card">
                        <div class="card-toolbar"><div><h3 class="card-title">ປະຕິທິນທີມ</h3><div class="card-subtitle">ລາຍການລາພັກທີ່ກຳລັງຈະມາເຖິງ</div></div></div>
                        <div class="request-summary">
                            <?php foreach ($managerCalendar as $event): ?>
                                <div><span><?= h(thaiDate($event['start_date'])) ?></span><strong><?= h($event['first_name'] . ' ' . $event['last_name']) ?> · <?= h($event['leave_type_name']) ?></strong></div>
                            <?php endforeach; ?>
                            <?php if (empty($managerCalendar)): ?><p class="text-secondary mb-0">ຍັງບໍ່ມີລາຍການລາພັກໃນປະຕິທິນທີມ</p><?php endif; ?>
                        </div>
                    </article>
                </aside>
            </section>
        <?php elseif ($userRole === 'hr'): ?>
            <?php
            $employeeCount = (int) fetchValue($pdo, 'SELECT COUNT(*) FROM users WHERE status = "active"');
            $allRequests = (int) fetchValue($pdo, 'SELECT COUNT(*) FROM leave_requests');
            $pendingRequests = (int) fetchValue($pdo, 'SELECT COUNT(*) FROM leave_requests WHERE status = "Pending"');
            $approvedDays = (float) fetchValue($pdo, 'SELECT COALESCE(SUM(total_days), 0) FROM leave_requests WHERE status = "Approved" AND YEAR(start_date) = :year', ['year' => $currentYear]);
            ?>
            <section class="summary-grid">
                <?= statCard('ຈຳນວນພະນັກງານ', (string) $employeeCount, 'ບັນຊີ active ທົ່ວອົງກອນ', 'fa-users', 'primary') ?>
                <?= statCard('ຄຳຂໍລາພັກທັງໝົດ', (string) $allRequests, 'ທຸກສະຖານະໃນລະບົບ', 'fa-file-lines', 'primary') ?>
                <?= statCard('ລາຍງານວັນລາພັກ', formatDays($approvedDays), 'ວັນລາພັກທີ່ອະນຸມັດໃນປີ ' . $currentYear, 'fa-chart-column', 'success') ?>
                <?= statCard('ຄຳຂໍລໍຈັດການ', (string) $pendingRequests, 'ລາຍການທີ່ລໍອະນຸມັດ', 'fa-clock', 'warning') ?>
            </section>

            <section class="dashboard-grid">
                <article class="content-card">
                    <div class="card-toolbar"><div><h3 class="card-title">ຄຳຂໍລາພັກທັງໝົດຫຼ້າສຸດ</h3><div class="card-subtitle">ພາບລວມສຳລັບຝ່າຍບຸກຄະລາກອນ</div></div></div>
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead><tr><th>ເລກທີ</th><th>ພະນັກງານ</th><th>ພະແນກ</th><th>ປະເພດ</th><th>ຈຳນວນ</th><th>ສະຖານະ</th><th></th></tr></thead>
                            <tbody>
                                <?php foreach ($hrRecentRequests as $request): ?>
                                    <tr>
                                        <td class="fw-bold"><?= h($request['request_no']) ?></td>
                                        <td><?= h($request['first_name'] . ' ' . $request['last_name']) ?></td>
                                        <td><?= h($request['department_name'] ?? '-') ?></td>
                                        <td><?= h($request['leave_type_name']) ?></td>
                                        <td><?= h(formatDays($request['total_days'])) ?> ວັນ</td>
                                        <td><?= renderStatusBadge($request['status']) ?></td>
                                        <td class="text-end">
                                            <a class="btn btn-sm btn-outline-primary js-print-preview" href="print_leave.php?id=<?= h((string) $request['id']) ?>" data-print-title="<?= h((string) $request['request_no']) ?>">
                                                <i class="fa-solid fa-print me-1"></i>ພິມ
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
                <aside class="content-card">
                    <div class="card-toolbar"><div><h3 class="card-title">ລາຍງານວັນລາ</h3><div class="card-subtitle">ແຍກຕາມປະເພດການລາ</div></div></div>
                    <div class="balance-list">
                        <?php foreach ($hrLeaveSummary as $summary): ?>
                            <div class="balance-row">
                                <div class="balance-head"><?= h($summary['name']) ?> <span><?= h((string) $summary['request_count']) ?> ຄຳຂໍ</span></div>
                                <div class="small text-secondary">ລວມ <?= h(formatDays($summary['total_days'])) ?> ວັນ</div>
                                <div class="progress"><div class="progress-bar" style="width: <?= h((string) min(100, (float) $summary['total_days'] * 5)) ?>%; background-color: <?= h($summary['color']) ?>"></div></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </aside>
            </section>
        <?php else: ?>
            <?php
            $userCount = (int) fetchValue($pdo, 'SELECT COUNT(*) FROM users');
            $activeUserCount = (int) fetchValue($pdo, 'SELECT COUNT(*) FROM users WHERE status = "active"');
            $departmentCount = (int) fetchValue($pdo, 'SELECT COUNT(*) FROM departments WHERE status = "active"');
            $leaveTypeCount = (int) fetchValue($pdo, 'SELECT COUNT(*) FROM leave_types WHERE status = "active"');
            $pendingRequestCount = (int) fetchValue($pdo, 'SELECT COUNT(*) FROM leave_requests WHERE status = "Pending"');
            $holidayCount = (int) fetchValue($pdo, 'SELECT COUNT(*) FROM holidays WHERE status = "active" AND YEAR(holiday_date) = :year', ['year' => $adminDashboardYear]);
            $roleTotal = max(array_sum(array_map(static fn (array $row): int => (int) $row['total'], $adminRoleDistribution)), 1);
            $roleMeta = [
                'admin' => ['label' => 'Admin', 'icon' => 'fa-shield-halved', 'tone' => 'danger'],
                'hr' => ['label' => 'HR', 'icon' => 'fa-user-tie', 'tone' => 'warning'],
                'manager' => ['label' => 'Manager', 'icon' => 'fa-users-gear', 'tone' => 'primary'],
                'employee' => ['label' => 'Employee', 'icon' => 'fa-user', 'tone' => 'success'],
            ];
            $monthNames = [
                1 => 'ມ.ກ.', 2 => 'ກ.ພ.', 3 => 'ມ.ນ.', 4 => 'ມ.ສ.', 5 => 'ພ.ພ.', 6 => 'ມິ.ຖ.',
                7 => 'ກ.ລ.', 8 => 'ສ.ຫ.', 9 => 'ກ.ຍ.', 10 => 'ຕ.ລ.', 11 => 'ພ.ຈ.', 12 => 'ທ.ວ.',
            ];
            ?>
            <section class="dashboard-page-header">
                <div class="dashboard-heading">
                    <span class="dashboard-heading-icon"><i class="fa-solid fa-gauge-high"></i></span>
                    <div>
                        <p class="dashboard-eyebrow">ສູນຄວບຄຸມ LeavePro</p>
                        <h2>ແດດບອດຜູ້ດູແລລະບົບ</h2>
                        <p>ພາບລວມຜູ້ໃຊ້ ການລາພັກ ແລະ ກິດຈະກຳສຳຄັນໃນລະບົບ</p>
                    </div>
                </div>
                <div class="dashboard-actions" aria-label="ຄຳສັ່ງດ່ວນ">
                    <a class="btn btn-outline-primary" href="employees.php"><i class="fa-solid fa-users"></i><span>ຈັດການຜູ້ໃຊ້</span></a>
                    <a class="btn btn-outline-primary" href="#activity-log"><i class="fa-solid fa-clock-rotate-left"></i><span>ບັນທຶກກິດຈະກຳ</span></a>
                    <a class="btn btn-primary" href="settings.php"><i class="fa-solid fa-gear"></i><span>ຕັ້ງຄ່າລະບົບ</span></a>
                </div>
            </section>

            <section class="summary-grid admin-summary-grid" aria-label="ສະຫຼຸບຂໍ້ມູນລະບົບ">
                <?= statCard('ຜູ້ໃຊ້ທັງໝົດ', (string) $userCount, 'ບັນຊີໃນລະບົບ', 'fa-users-gear', 'primary') ?>
                <?= statCard('ຜູ້ໃຊ້ທີ່ເຄື່ອນໄຫວ', (string) $activeUserCount, 'ບັນຊີທີ່ເປີດໃຊ້ງານ', 'fa-user-check', 'success') ?>
                <?= statCard('ພະແນກ', (string) $departmentCount, 'ພະແນກທີ່ເປີດໃຊ້ງານ', 'fa-building', 'purple') ?>
                <?= statCard('ຄຳຂໍລໍອະນຸມັດ', (string) $pendingRequestCount, 'ລາຍການທີ່ລໍຈັດການ', 'fa-hourglass-half', 'warning') ?>
                <?= statCard('ປະເພດວັນລາພັກ', (string) $leaveTypeCount, 'ປະເພດທີ່ເປີດໃຊ້ງານ', 'fa-tags', 'danger') ?>
                <?= statCard('ວັນພັກ', (string) $holidayCount, 'ວັນພັກໃນປີ ' . $adminDashboardYear, 'fa-calendar-check', 'slate') ?>
            </section>

            <section class="admin-dashboard-grid">
                <article class="content-card role-distribution-card">
                    <div class="card-toolbar card-toolbar-premium">
                        <div><span class="card-title-icon"><i class="fa-solid fa-chart-pie"></i></span><div><h3 class="card-title">ການແຈກຢາຍບົດບາດ</h3><div class="card-subtitle">ຈຳນວນຜູ້ໃຊ້ແຍກຕາມສິດທິໃນລະບົບ</div></div></div>
                    </div>
                    <div class="role-progress-list">
                        <?php foreach ($adminRoleDistribution as $role): ?>
                            <?php $roleInfo = $roleMeta[$role['role']] ?? ['label' => ucfirst((string) $role['role']), 'icon' => 'fa-user', 'tone' => 'slate']; $percentage = ((int) $role['total'] / $roleTotal) * 100; ?>
                            <div class="role-progress-item">
                                <span class="role-progress-icon <?= h($roleInfo['tone']) ?>"><i class="fa-solid <?= h($roleInfo['icon']) ?>"></i></span>
                                <div class="role-progress-details"><div class="role-progress-head"><strong><?= h($roleInfo['label']) ?></strong><span><b><?= h((string) $role['total']) ?></b> · <?= h(number_format($percentage, 1)) ?>%</span></div><div class="role-progress-track"><span class="<?= h($roleInfo['tone']) ?>" style="width: <?= h((string) round($percentage, 1)) ?>%"></span></div></div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($adminRoleDistribution)): ?><p class="empty-panel-message">ຍັງບໍ່ມີຂໍ້ມູນບົດບາດ</p><?php endif; ?>
                    </div>
                </article>

                <article class="content-card trend-card">
                    <div class="card-toolbar card-toolbar-premium">
                        <div><span class="card-title-icon"><i class="fa-solid fa-chart-line"></i></span><div><h3 class="card-title">ແນວໂນ້ມການລາລາຍເດືອນ</h3><div class="card-subtitle">ຈຳນວນຄຳຂໍທີ່ສ້າງໃນແຕ່ລະເດືອນ</div></div></div>
                        <form class="admin-year-filter" method="get" action="index.php">
                            <input type="hidden" name="dashboard" value="admin">
                            <label for="dashboardYear" class="visually-hidden">ເລືອກປີ</label>
                            <i class="fa-regular fa-calendar" aria-hidden="true"></i>
                            <select id="dashboardYear" name="year" onchange="this.form.submit()">
                                <?php foreach ($adminAvailableYears as $year): ?>
                                    <option value="<?= h((string) $year) ?>" <?= $year === $adminDashboardYear ? 'selected' : '' ?>><?= h((string) $year) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>
                    <div class="trend-chart-wrap"><canvas id="adminMonthlyTrendChart" aria-label="ແນວໂນ້ມການລາລາຍເດືອນ" role="img"></canvas></div>
                </article>

                <article class="content-card activity-card" id="activity-log">
                    <div class="card-toolbar card-toolbar-premium">
                        <div><span class="card-title-icon"><i class="fa-regular fa-clock"></i></span><div><h3 class="card-title">ບັນທຶກກິດຈະກຳ</h3><div class="card-subtitle">ຄວາມເຄື່ອນໄຫວຫຼ້າສຸດໃນລະບົບ</div></div></div>
                        <a class="btn btn-sm btn-link" href="activity_logs.php">ເບິ່ງທັງໝົດ <i class="fa-solid fa-arrow-right"></i></a>
                    </div>
                    <div class="activity-list">
                        <?php foreach ($adminActivities as $activity): ?>
                            <?php $activityInfo = adminActivityDetails((string) $activity['action'], (string) $activity['entity_type']); $activityUser = trim((string) $activity['first_name'] . ' ' . (string) $activity['last_name']) ?: ((string) $activity['email'] ?: 'ລະບົບ'); ?>
                            <div class="activity-item"><span class="activity-icon <?= h($activityInfo['tone']) ?>"><i class="fa-solid <?= h($activityInfo['icon']) ?>"></i></span><div><p><strong><?= h($activityUser) ?></strong> <span><?= h($activityInfo['label']) ?></span></p><time datetime="<?= h((string) $activity['created_at']) ?>"><?= h((new DateTimeImmutable((string) $activity['created_at']))->format('d/m/Y H:i')) ?></time></div></div>
                        <?php endforeach; ?>
                        <?php if (empty($adminActivities)): ?><p class="empty-panel-message">ຍັງບໍ່ມີບັນທຶກກິດຈະກຳ</p><?php endif; ?>
                    </div>
                </article>
            </section>

            <section class="content-card mb-3">
                <div class="card-toolbar">
                    <div>
                        <h3 class="card-title">ຄຳຂໍລາພັກຫຼ້າສຸດ</h3>
                        <div class="card-subtitle">ລາຍການສຳລັບກວດສອບ ແລະ ພິມເອກະສານ</div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead><tr><th>ເລກທີ</th><th>ພະນັກງານ</th><th>ພະແນກ</th><th>ປະເພດ</th><th>ຊ່ວງວັນທີ</th><th>ສະຖານະ</th><th></th></tr></thead>
                        <tbody>
                            <?php foreach ($adminRecentRequests as $request): ?>
                                <tr>
                                    <td class="fw-bold"><?= h($request['request_no']) ?></td>
                                    <td><?= h($request['first_name'] . ' ' . $request['last_name']) ?></td>
                                    <td><?= h($request['department_name'] ?? '-') ?></td>
                                    <td><?= h($request['leave_type_name']) ?></td>
                                    <td><?= h(thaiDate($request['start_date'])) ?> - <?= h(thaiDate($request['end_date'])) ?></td>
                                    <td><?= renderStatusBadge($request['status']) ?></td>
                                    <td class="text-end">
                                        <a class="btn btn-sm btn-outline-primary js-print-preview" href="print_leave.php?id=<?= h((string) $request['id']) ?>" data-print-title="<?= h((string) $request['request_no']) ?>">
                                            <i class="fa-solid fa-print me-1"></i>ພິມ
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($adminRecentRequests)): ?>
                                <tr><td colspan="7" class="text-center text-secondary py-4">ຍັງບໍ່ມີຄຳຂໍລາ</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <script>
                window.adminDashboardCharts = {
                    monthlyTrend: {
                        labels: <?= json_encode(array_map(static fn (array $row): string => $monthNames[(int) $row['month_number']] ?? (string) $row['month_number'], $adminMonthlyTrend), JSON_UNESCAPED_UNICODE) ?>,
                        values: <?= json_encode(array_map('intval', array_column($adminMonthlyTrend, 'total'))) ?>
                    }
                };
            </script>
        <?php endif; ?>
<?php require __DIR__ . '/../views/layouts/footer.php'; ?>
