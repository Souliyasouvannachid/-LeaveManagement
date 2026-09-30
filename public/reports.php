<?php
require __DIR__ . '/_auth.php';

if (!in_array($userRole, ['admin', 'hr'], true)) {
    http_response_code(403);
    exit('ທ່ານບໍ່ມີສິດທິເຂົ້າເຖິງໜ້າລາຍງານ');
}

$pageTitle = 'ລາຍງານ';
$activeMenu = 'reports';
$appName = 'ລະບົບຈັດການການລາພັກ';
$summaryStmt = $pdo->prepare(
    'SELECT lt.name, COUNT(lr.id) AS request_count, COALESCE(SUM(lr.total_days), 0) AS total_days
     FROM leave_types lt
     LEFT JOIN leave_requests lr ON lr.leave_type_id = lt.id AND YEAR(lr.start_date) = YEAR(CURDATE())
     GROUP BY lt.id, lt.name
     ORDER BY total_days DESC'
);
$summaryStmt->execute();
$summaries = $summaryStmt->fetchAll();
$pendingCount = (int) $pdo->query('SELECT COUNT(*) FROM leave_requests WHERE status = "Pending"')->fetchColumn();
$approvedCount = (int) $pdo->query('SELECT COUNT(*) FROM leave_requests WHERE status = "Approved"')->fetchColumn();

require __DIR__ . '/../views/layouts/header.php';
require __DIR__ . '/../views/layouts/sidebar.php';
?>
<div class="app-main-wrapper">
    <?php require __DIR__ . '/../views/layouts/topbar.php'; ?>
    <main class="app-content">
        <div class="mb-4">
            <p class="text-secondary mb-1">ສະຫຼຸບຂໍ້ມູນການລາພັກເພື່ອໃຊ້ຕິດຕາມແລະສົ່ງອອກໃນຂັ້ນຕໍ່ໄປ</p>
            <h2 class="h4 fw-bold mb-0">ລາຍງານ</h2>
        </div>
        <section class="summary-grid">
            <article class="stat-card"><div class="stat-icon warning"><i class="fa-solid fa-clock"></i></div><div><div class="stat-label">ລໍຖ້າອະນຸມັດ</div><h3 class="stat-value"><?= h((string) $pendingCount) ?></h3><div class="stat-meta">ຄຳຂໍທີ່ລໍຖ້າດຳເນີນການ</div></div></article>
            <article class="stat-card"><div class="stat-icon success"><i class="fa-solid fa-circle-check"></i></div><div><div class="stat-label">ອະນຸມັດແລ້ວ</div><h3 class="stat-value"><?= h((string) $approvedCount) ?></h3><div class="stat-meta">ຄຳຂໍທີ່ອະນຸມັດແລ້ວ</div></div></article>
        </section>
        <section class="content-card">
            <div class="card-toolbar"><div><h3 class="card-title">ລາຍງານແຍກຕາມປະເພດລາພັກ</h3><div class="card-subtitle">ຂໍ້ມູນປີປະຈຸບັນ</div></div></div>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead><tr><th>ປະເພດລາພັກ</th><th>ຈຳນວນຄຳຂໍ</th><th>ຈຳນວນວັນລວມ</th></tr></thead>
                    <tbody>
                        <?php foreach ($summaries as $summary): ?>
                            <tr><td class="fw-bold"><?= h($summary['name']) ?></td><td><?= h((string) $summary['request_count']) ?></td><td><?= h(number_format((float) $summary['total_days'], 1)) ?> ມື້</td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
<?php require __DIR__ . '/../views/layouts/footer.php'; ?>
