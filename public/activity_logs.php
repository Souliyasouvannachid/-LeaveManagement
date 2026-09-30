<?php
declare(strict_types=1);

require __DIR__ . '/_auth.php';

if ($userRole !== 'admin') {
    http_response_code(403);
    exit('ທ່ານບໍ່ມີສິດເຂົ້າເຖິງໜ້ານີ້');
}

function activityLabel(string $action, string $entityType): array
{
    $details = match ($action) {
        'login' => ['fa-right-to-bracket', 'success', 'ເຂົ້າໃຊ້ງານລະບົບ'],
        'create_leave_request' => ['fa-file-circle-plus', 'primary', 'ສົ່ງຄຳຂໍລາພັກ'],
        'approve_leave_request' => ['fa-circle-check', 'success', 'ອະນຸມັດຄຳຂໍລາພັກ'],
        'reject_leave_request' => ['fa-circle-xmark', 'danger', 'ບໍ່ອະນຸມັດຄຳຂໍລາພັກ'],
        default => ['fa-clock-rotate-left', 'slate', 'ບັນທຶກກິດຈະກຳ ' . $entityType],
    };

    return ['icon' => $details[0], 'tone' => $details[1], 'label' => $details[2]];
}

$pageTitle = 'ບັນທຶກກິດຈະກຳ';
$activeMenu = 'activity_logs';
$appName = 'ລະບົບຈັດການການລາພັກ';
$activityLogs = $pdo->query(
    'SELECT al.action, al.entity_type, al.entity_id, al.created_at, u.first_name, u.last_name, u.email
     FROM audit_logs al
     LEFT JOIN users u ON u.id = al.user_id
     ORDER BY al.created_at DESC
     LIMIT 100'
)->fetchAll();

require __DIR__ . '/../views/layouts/header.php';
require __DIR__ . '/../views/layouts/sidebar.php';
?>
<div class="app-main-wrapper">
    <?php require __DIR__ . '/../views/layouts/topbar.php'; ?>
    <main class="app-content">
        <section class="audit-page-header">
            <div>
                <p class="dashboard-eyebrow">LeavePro Control Center</p>
                <h2><i class="fa-solid fa-clock-rotate-left"></i> ບັນທຶກກິດຈະກຳ</h2>
                <p>ປະຫວັດການເຄື່ອນໄຫວຫຼ້າສຸດທີ່ເກີດຂຶ້ນໃນລະບົບ</p>
            </div>
            <a class="btn btn-outline-primary" href="index.php?dashboard=admin"><i class="fa-solid fa-arrow-left me-2"></i>ກັບໄປແດດບອດ</a>
        </section>

        <section class="content-card audit-log-card">
            <div class="card-toolbar">
                <div>
                    <h3 class="card-title">ລາຍການກິດຈະກຳ</h3>
                    <div class="card-subtitle">ສະແດງສູງສຸດ 100 ລາຍການຫຼ້າສຸດ</div>
                </div>
            </div>
            <div class="audit-log-list">
                <?php foreach ($activityLogs as $log): ?>
                    <?php
                    $details = activityLabel((string) $log['action'], (string) $log['entity_type']);
                    $actor = trim((string) $log['first_name'] . ' ' . (string) $log['last_name']) ?: ((string) $log['email'] ?: 'ລະບົບ');
                    ?>
                    <article class="audit-log-row">
                        <span class="activity-icon <?= h($details['tone']) ?>"><i class="fa-solid <?= h($details['icon']) ?>"></i></span>
                        <div class="audit-log-copy"><strong><?= h($actor) ?></strong><span><?= h($details['label']) ?></span><?php if ($log['entity_id'] !== null): ?><small>ລາຍການ #<?= h((string) $log['entity_id']) ?></small><?php endif; ?></div>
                        <time datetime="<?= h((string) $log['created_at']) ?>"><?= h((new DateTimeImmutable((string) $log['created_at']))->format('d/m/Y H:i')) ?></time>
                    </article>
                <?php endforeach; ?>
                <?php if (empty($activityLogs)): ?><p class="empty-panel-message">ຍັງບໍ່ມີບັນທຶກກິດຈະກຳ</p><?php endif; ?>
            </div>
        </section>
    </main>
</div>
<?php require __DIR__ . '/../views/layouts/footer.php'; ?>
