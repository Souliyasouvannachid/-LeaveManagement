<?php
require __DIR__ . '/_auth.php';

$pageTitle = 'ປະຕິທິນການລາພັກ';
$activeMenu = 'calendar';
$appName = 'ລະບົບຈັດການການລາພັກ';
$params = [];
$where = 'lr.status = "Approved"';

if ($userRole === 'manager') {
    $where .= ' AND lr.manager_id = :manager_id';
    $params['manager_id'] = $userId;
} elseif ($userRole === 'employee') {
    $where .= ' AND (lr.user_id = :user_id OR lr.manager_id = :manager_id)';
    $params['user_id'] = $userId;
    $params['manager_id'] = $userId;
}

$eventsStmt = $pdo->prepare(
    "SELECT lr.request_no, lr.start_date, lr.end_date, lr.total_days,
            u.first_name, u.last_name, lt.name AS leave_type_name, lt.color
     FROM leave_requests lr
     INNER JOIN users u ON u.id = lr.user_id
     INNER JOIN leave_types lt ON lt.id = lr.leave_type_id
     WHERE {$where}
     ORDER BY lr.start_date DESC
     LIMIT 60"
);
$eventsStmt->execute($params);
$events = $eventsStmt->fetchAll();

function laoDate(string $date): string
{
    return (new DateTimeImmutable($date))->format('d/m/Y');
}

require __DIR__ . '/../views/layouts/header.php';
require __DIR__ . '/../views/layouts/sidebar.php';
?>
<div class="app-main-wrapper">
    <?php require __DIR__ . '/../views/layouts/topbar.php'; ?>
    <main class="app-content">
        <div class="mb-4">
            <p class="text-secondary mb-1">ລາຍການລາທີ່ອະນຸມັດແລ້ວ ສະແດງຕາມສິດຂອງຜູ້ໃຊ້ງານ</p>
            <h2 class="h4 fw-bold mb-0">ປະຕິທິນການລາພັກ</h2>
        </div>

        <section class="content-card">
            <div class="card-toolbar">
                <div>
                    <h3 class="card-title">ລາຍການປະຕິທິນ</h3>
                    <div class="card-subtitle">ໃຊ້ສີແຍກຕາມປະເພດການລາພັກ</div>
                </div>
            </div>
            <div class="calendar-list">
                <?php foreach ($events as $event): ?>
                    <article class="calendar-event" style="--event-color: <?= h($event['color']) ?>">
                        <div class="calendar-event-date">
                            <strong><?= h((new DateTimeImmutable($event['start_date']))->format('d')) ?></strong>
                            <span><?= h((new DateTimeImmutable($event['start_date']))->format('m/Y')) ?></span>
                        </div>
                        <div>
                            <h3><?= h($event['first_name'] . ' ' . $event['last_name']) ?></h3>
                            <p><?= h($event['leave_type_name']) ?> · <?= h(laoDate($event['start_date'])) ?> - <?= h(laoDate($event['end_date'])) ?> · <?= h(number_format((float) $event['total_days'], 1)) ?> ມື້</p>
                        </div>
                    </article>
                <?php endforeach; ?>
                <?php if (empty($events)): ?>
                    <div class="empty-state">
                        <div class="empty-icon"><i class="fa-regular fa-calendar"></i></div>
                        <h3>ຍັງບໍ່ມີລາຍການລາພັກ</h3>
                        <p>ລາຍການທີ່ອະນຸມັດແລ້ວຈະສະແດງຢູ່ທີ່ນີ້</p>
                    </div>
                <?php endif; ?>
            </div>
        </section>
<?php require __DIR__ . '/../views/layouts/footer.php'; ?>