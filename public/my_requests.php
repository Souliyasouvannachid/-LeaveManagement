<?php
require __DIR__ . '/_auth.php';

function redirectToMyRequests(): void
{
    header('Location: ' . leaveContextUrl('my_requests.php'));
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $requestId = filter_var($_POST['request_id'] ?? null, FILTER_VALIDATE_INT);

    if (!hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'ຄຳຮ້ອງຂໍບໍ່ຖືກຕ້ອງ ກະລຸນາຣີເຟຣດໜ້າ ແລ້ວລອງໃໝ່';
    }

    if ($action !== 'cancel') {
        $errors[] = 'ຄຳສັ່ງບໍ່ຖືກຕ້ອງ';
    }

    if (!$requestId) {
        $errors[] = 'ບໍ່ພົບຄຳຂໍລາພັກທີ່ຕ້ອງການຍົກເລີກ';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $requestStmt = $pdo->prepare(
                'SELECT id, request_no, user_id, leave_type_id, manager_id, start_date, total_days, status
                 FROM leave_requests
                 WHERE id = :id
                   AND user_id = :user_id
                   AND status = "Pending"
                 LIMIT 1
                 FOR UPDATE'
            );
            $requestStmt->execute([
                'id' => $requestId,
                'user_id' => $userId,
            ]);
            $request = $requestStmt->fetch();

            if (!$request) {
                throw new RuntimeException('ຍົກເລີກໄດ້ສະເພາະຄຳຂໍລາພັກທີ່ເປັນສະຖານະລໍອະນຸມັດຂອງທ່ານເທົ່ານັ້ນ');
            }

            $year = (int) (new DateTimeImmutable((string) $request['start_date']))->format('Y');
            ensureLeaveBalance($pdo, (int) $request['user_id'], (int) $request['leave_type_id'], $year);

            $cancelStmt = $pdo->prepare(
                'UPDATE leave_requests
                 SET status = "Cancelled",
                     cancelled_at = NOW(),
                     updated_at = NOW()
                 WHERE id = :id'
            );
            $cancelStmt->execute(['id' => (int) $request['id']]);

            syncLeaveBalance($pdo, (int) $request['user_id'], (int) $request['leave_type_id'], $year);

            if (!empty($request['manager_id'])) {
                $notificationStmt = $pdo->prepare(
                    'INSERT INTO notifications (user_id, title, message, link, is_read, created_at)
                     VALUES (:user_id, :title, :message, :link, 0, NOW())'
                );
                $notificationStmt->execute([
                    'user_id' => (int) $request['manager_id'],
                    'title' => 'ຄຳຂໍລາພັກຖືກຍົກເລີກ',
                    'message' => $sessionUser['name'] . ' ຍົກເລີກຄຳຂໍລາພັກ ' . $request['request_no'],
                    'link' => 'approvals.php',
                ]);
            }

            $auditStmt = $pdo->prepare(
                'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, old_value, new_value, ip_address, user_agent, created_at)
                 VALUES (:user_id, "cancel_leave_request", "leave_requests", :entity_id, :old_value, :new_value, :ip_address, :user_agent, NOW())'
            );
            $auditStmt->execute([
                'user_id' => $userId,
                'entity_id' => (int) $request['id'],
                'old_value' => json_encode(['status' => 'Pending'], JSON_UNESCAPED_UNICODE),
                'new_value' => json_encode(['status' => 'Cancelled'], JSON_UNESCAPED_UNICODE),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);

            $pdo->commit();
            $_SESSION['flash_success'] = 'ຍົກເລີກຄຳຂໍ ' . $request['request_no'] . ' ສຳເລັດແລ້ວ';
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            redirectToMyRequests();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log($exception->getMessage());
            $errors[] = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'ບໍ່ສາມາດຍົກເລີກຄຳຂໍລາພັກໄດ້ ກະລຸນາລອງໃໝ່ອີກຄັ້ງ';
        }
    }
}

$pageTitle = 'ຄຳຂໍຂອງຂ້ອຍ';
$activeMenu = 'my_requests';
$appName = 'ລະບົບຈັດການການລາພັກ';
$useDataTables = true;
$requestsStmt = $pdo->prepare(
    'SELECT lr.id, lr.request_no, lr.start_date, lr.end_date, lr.total_days, lr.status, lr.created_at,
            lt.name AS leave_type_name
     FROM leave_requests lr
     INNER JOIN leave_types lt ON lt.id = lr.leave_type_id
     WHERE lr.user_id = :user_id
     ORDER BY lr.created_at DESC'
);
$requestsStmt->execute(['user_id' => $userId]);
$requests = $requestsStmt->fetchAll();

require __DIR__ . '/../views/layouts/header.php';
require __DIR__ . '/../views/layouts/sidebar.php';
?>
<div class="app-main-wrapper">
    <?php require __DIR__ . '/../views/layouts/topbar.php'; ?>
    <main class="app-content">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <p class="text-secondary mb-1">ຕິດຕາມສະຖານະ ແລະ ປະຫວັດການລາຂອງທ່ານ</p>
                <h2 class="h4 fw-bold mb-0">ຄຳຂໍຂອງຂ້ອຍ</h2>
            </div>
            <a class="btn btn-primary" href="create.php"><i class="fa-solid fa-calendar-plus me-2"></i>ຍື່ນຄຳຂໍລາພັກ</a>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <div class="fw-bold mb-1"><i class="fa-solid fa-circle-exclamation me-2"></i>ບໍ່ສາມາດດຳເນີນການໄດ້</div>
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?= h($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <section class="content-card">
            <div class="card-toolbar">
                <div>
                    <h3 class="card-title">ລາຍການຄຳຂໍລາພັກ</h3>
                    <div class="card-subtitle">ທັງໝົດ <?= h((string) count($requests)) ?> ລາຍການ</div>
                </div>
            </div>
            <div class="table-responsive">
                <table
                    id="myRequestsTable"
                    class="table align-middle app-data-table"
                    data-datatable="true"
                    data-page-size="10"
                    data-search-placeholder="ຄົ້ນຫາເລກທີຄຳຂໍ ປະເພດ ຫຼື ສະຖານະ"
                    data-empty-message="ຍັງບໍ່ມີຄຳຂໍລາພັກໃນລະບົບ"
                >
                    <thead>
                        <tr>
                            <th>ເລກທີຄຳຂໍ</th>
                            <th>ປະເພດການລາ</th>
                            <th>ຊ່ວງວັນທີ</th>
                            <th>ຈຳນວນ</th>
                            <th>ວັນທີສ້າງ</th>
                            <th>ສະຖານະ</th>
                            <th>ການດຳເນີນການ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requests as $request): ?>
                            <tr>
                                <td class="fw-bold"><?= h($request['request_no']) ?></td>
                                <td><?= h($request['leave_type_name']) ?></td>
                                <td><?= h(thaiDate($request['start_date'])) ?> - <?= h(thaiDate($request['end_date'])) ?></td>
                                <td><?= h(number_format((float) $request['total_days'], 1)) ?> ວັນ</td>
                                <td><?= h(thaiDate($request['created_at'])) ?></td>
                                <td><?= renderStatusBadge($request['status']) ?></td>
                                <td>
                                    <div class="d-flex flex-wrap align-items-center gap-2">
                                        <a class="btn btn-sm btn-outline-primary js-print-preview" href="print_leave.php?id=<?= h((string) $request['id']) ?>" data-print-title="<?= h((string) $request['request_no']) ?>">
                                            <i class="fa-solid fa-print me-1"></i>ພິມ
                                        </a>
                                        <?php if ($request['status'] === 'Pending'): ?>
                                            <button class="btn btn-sm btn-outline-danger" type="button" data-bs-toggle="modal" data-bs-target="#cancelRequestModal<?= h((string) $request['id']) ?>">
                                                <i class="fa-solid fa-ban me-1"></i>ຍົກເລີກ
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php foreach ($requests as $request): ?>
            <?php if ($request['status'] !== 'Pending') {
                continue;
            } ?>
            <div class="modal fade approval-confirm-modal" id="cancelRequestModal<?= h((string) $request['id']) ?>" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <form class="modal-content" method="post">
                        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="cancel">
                        <input type="hidden" name="request_id" value="<?= h((string) $request['id']) ?>">
                        <div class="modal-header">
                            <div>
                                <h5 class="modal-title">ຢືນຢັນການຍົກເລີກຄຳຂໍ</h5>
                                <div class="text-secondary small"><?= h($request['request_no']) ?></div>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ປິດ"></button>
                        </div>
                        <div class="modal-body">
                            ຕ້ອງການຍົກເລີກຄຳຂໍ <strong><?= h($request['request_no']) ?></strong> ແມ່ນບໍ່?
                            ຖ້າຍົກເລີກແລ້ວ ລະບົບຈະຄືນຍອດວັນລາພັກທີ່ກັນໄວ້ກັບມາເຂົ້າບັນຊີຂອງທ່ານທັນທີ
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" data-bs-dismiss="modal">ປິດ</button>
                            <button type="submit" class="btn btn-danger"><i class="fa-solid fa-ban me-2"></i>ຢືນຢັນຍົກເລີກ</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
<?php require __DIR__ . '/../views/layouts/footer.php'; ?>
