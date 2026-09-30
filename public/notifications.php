<?php
require __DIR__ . '/_auth.php';

$pageTitle = 'ການແຈ້ງເຕືອນ';
$activeMenu = 'notifications';
$appName = 'ລະບົບຈັດການການລາພັກ';
$useDataTables = true;

$notificationsStmt = $pdo->prepare(
    'SELECT id, title, message, link, is_read, created_at
     FROM notifications
     WHERE user_id = :user_id
     ORDER BY created_at DESC, id DESC'
);
$notificationsStmt->execute(['user_id' => $userId]);
$notificationRows = array_map(
    static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'message' => (string) $row['message'],
            'link' => normalizeNotificationLink($row['link'] ?? null),
            'is_read' => (int) ($row['is_read'] ?? 0),
            'time' => notificationTimeLabel($row['created_at'] ?? null),
        ];
    },
    $notificationsStmt->fetchAll()
);

require __DIR__ . '/../views/layouts/header.php';
require __DIR__ . '/../views/layouts/sidebar.php';
?>
<div class="app-main-wrapper">
    <?php require __DIR__ . '/../views/layouts/topbar.php'; ?>
    <main class="app-content">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <p class="text-secondary mb-1">ຕິດຕາມລາຍການແຈ້ງເຕືອນລ່າສຸດຂອງບັນຊີທ່ານ</p>
                <h2 class="h4 fw-bold mb-0">ການແຈ້ງເຕືອນ</h2>
            </div>
        </div>

        <section class="content-card">
            <div class="card-toolbar">
                <div>
                    <h3 class="card-title">ລາຍການແຈ້ງເຕືອນ</h3>
                    <div class="card-subtitle">ທັງໝົດ <?= h((string) count($notificationRows)) ?> ລາຍການ</div>
                </div>
            </div>
            <div class="table-responsive">
                <table
                    class="table align-middle app-data-table"
                    data-datatable="true"
                    data-page-size="10"
                    data-search-placeholder="ຄົ້ນຫາຫົວຂໍ້ ຫຼື ຂໍ້ຄວາມແຈ້ງເຕືອນ"
                    data-empty-message="ຍັງບໍ່ມີການແຈ້ງເຕືອນ"
                >
                    <thead>
                        <tr>
                            <th>ຫົວຂໍ້</th>
                            <th>ລາຍລະອຽດ</th>
                            <th>ເວລາ</th>
                            <th class="text-end">ປາຍທາງ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($notificationRows as $notification): ?>
                            <tr>
                                <td class="fw-bold"><?= h($notification['title']) ?></td>
                                <td><?= h($notification['message']) ?></td>
                                <td><?= h($notification['time']) ?></td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-primary" href="notification_open.php?id=<?= h((string) $notification['id']) ?>">
                                        <i class="fa-solid fa-arrow-up-right-from-square me-1"></i>ເປີດແລ້ວ
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
<?php require __DIR__ . '/../views/layouts/footer.php'; ?>
