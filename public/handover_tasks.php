<?php
declare(strict_types=1);

require __DIR__ . '/_auth.php';

$tasksStmt = $pdo->prepare(
    'SELECT lr.id, lr.request_no, lr.start_date, lr.end_date, lr.total_days, lr.status, lr.attachment, lr.created_at,
            u.first_name, u.last_name, u.employee_code,
            lt.name AS leave_type_name
     FROM leave_requests lr
     INNER JOIN users u ON u.id = lr.user_id
     INNER JOIN leave_types lt ON lt.id = lr.leave_type_id
     WHERE lr.handover_to_user_id = :user_id
     ORDER BY lr.created_at DESC'
);
$tasksStmt->execute(['user_id' => $userId]);
$tasks = $tasksStmt->fetchAll();

$pageTitle = 'ວຽກທີ່ໄດ້ຮັບມອບໝາຍ';
$activeMenu = 'handover_tasks';
$appName = 'ລະບົບຈັດການການລາພັກ';
$useDataTables = true;

require __DIR__ . '/../views/layouts/header.php';
require __DIR__ . '/../views/layouts/sidebar.php';
?>
<div class="app-main-wrapper">
    <?php require __DIR__ . '/../views/layouts/topbar.php'; ?>
    <main class="app-content">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <p class="text-secondary mb-1">ລາຍການທີ່ເພື່ອນຮ່ວມງານມອບໝາຍໃຫ້ທ່ານດຳເນີນການ</p>
                <h2 class="h4 fw-bold mb-0"><i class="fa-solid fa-people-arrows-left-right text-primary me-2"></i>ວຽກທີ່ໄດ້ຮັບມອບໝາຍ</h2>
            </div>
            <span class="badge text-bg-primary fs-6"><?= h((string) count($tasks)) ?> ລາຍການ</span>
        </div>

        <section class="content-card">
            <div class="card-toolbar">
                <div>
                    <h3 class="card-title">ລາຍການມອບໝາຍ</h3>
                    <div class="card-subtitle">ທ່ານສາມາດເບິ່ງ ຫຼື ບັນທຶກໄຟລ໌ແນບຂອງວຽກທີ່ມອບໝາຍໃຫ້ທ່ານເທົ່ານັ້ນ</div>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table align-middle app-data-table" data-datatable="true" data-page-size="10" data-search-placeholder="ຄົ້ນຫາເລກຄຳຂໍ ຫຼື ຜູ້ຍື່ນ" data-empty-message="ຍັງບໍ່ມີວຽກທີ່ໄດ້ຮັບມອບໝາຍ">
                    <thead>
                        <tr>
                            <th>ເລກຄຳຂໍ</th>
                            <th>ຜູ້ຍື່ນ</th>
                            <th>ປະເພດການລາ</th>
                            <th>ຊ່ວງວັນທີ</th>
                            <th>ສະຖານະ</th>
                            <th class="text-end">ໄຟລ໌ແນບ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tasks as $task): ?>
                            <tr>
                                <td class="fw-bold"><?= h((string) $task['request_no']) ?></td>
                                <td><?= h(trim((string) $task['first_name'] . ' ' . (string) $task['last_name'])) ?><span class="d-block small text-secondary"><?= h((string) $task['employee_code']) ?></span></td>
                                <td><?= h((string) $task['leave_type_name']) ?></td>
                                <td><?= h(laoDate((string) $task['start_date'])) ?> - <?= h(laoDate((string) $task['end_date'])) ?></td>
                                <td><?= renderStatusBadge((string) $task['status']) ?></td>
                                <td class="text-end">
                                    <?php if (!empty($task['attachment'])): ?>
                                        <div class="btn-group btn-group-sm">
                                            <a class="btn btn-outline-primary js-attachment-preview" href="attachment.php?id=<?= h((string) $task['id']) ?>" data-attachment-title="<?= h((string) $task['request_no']) ?>"><i class="fa-solid fa-eye me-1"></i>ເບິ່ງ</a>
                                            <a class="btn btn-outline-secondary" href="attachment.php?id=<?= h((string) $task['id']) ?>&amp;download=1" aria-label="ບັນທຶກໄຟລ໌"><i class="fa-solid fa-download"></i></a>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-secondary small">ບໍ່ມີໄຟລ໌ແນບ</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>
<?php require __DIR__ . '/../views/layouts/footer.php'; ?>
