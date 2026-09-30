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
    header('Location: login.php?timeout=1' . (leaveSessionContext() ? '&context=' . rawurlencode((string) leaveSessionContext()) : ''));
    exit;
}

$_SESSION['last_activity'] = time();

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function statusBadge(string $status): string
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

function laoDate(string $date): string
{
    return (new DateTimeImmutable($date))->format('d/m/Y');
}

function periodLabel(string $period): string
{
    return match ($period) {
        'morning' => 'ເຄິ່ງເຊົ້າ',
        'afternoon' => 'ເຄິ່ງບ່າຍ',
        default => 'ເຕັມມື້',
    };
}

function redirectToApprovals(): void
{
    header('Location: approvals.php' . (leaveSessionContext() ? roleContextQuery(leaveSessionContext()) : ''));
    exit;
}

$pdo = db();
$sessionUser = $_SESSION['user'];
$managerId = (int) $sessionUser['id'];
$role = (string) ($sessionUser['role'] ?? 'employee');
$userRole = $role;
$errors = [];
$successMessage = '';

if (!in_array($role, ['manager', 'admin'], true)) {
    http_response_code(403);
    exit('ທ່ານບໍ່ມີສິດເຂົ້າເຖິງໜ້າອະນຸມັດຄຳຂໍ');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors)) {
    $action = (string) ($_POST['action'] ?? '');
    $requestId = filter_var($_POST['request_id'] ?? null, FILTER_VALIDATE_INT);
    $managerComment = trim((string) ($_POST['manager_comment'] ?? ''));

    if (!hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'ຄຳຂໍບໍ່ຖືກຕ້ອງ ກະລຸນາຣີເຟຣຊໜ້າເວັບແລ້ວລອງໃໝ່';
    }

    if (!$requestId) {
        $errors[] = 'ບໍ່ພົບລາຍການຄຳຂໍລາທີ່ຕ້ອງການດຳເນີນການ';
    }

    if (!in_array($action, ['approve', 'reject'], true)) {
        $errors[] = 'ຄຳສັ່ງບໍ່ຖືກຕ້ອງ';
    }

    if ($action === 'reject' && $managerComment === '') {
        $errors[] = 'ກະລຸນາລະບຸເຫດຜົນທີ່ໄມ່ອະນຸມັດ';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $requestStmt = $pdo->prepare(
                'SELECT lr.*, u.first_name, u.last_name, lt.name AS leave_type_name
                 FROM leave_requests lr
                 INNER JOIN users u ON u.id = lr.user_id
                 INNER JOIN leave_types lt ON lt.id = lr.leave_type_id
                 WHERE lr.id = :id
                   AND lr.manager_id = :manager_id
                   AND lr.status = "Pending"
                 LIMIT 1
                 FOR UPDATE'
            );
            $requestStmt->execute([
                'id' => (int) $requestId,
                'manager_id' => $managerId,
            ]);
            $request = $requestStmt->fetch();

            if (!$request) {
                throw new RuntimeException('ບໍ່ພົບຄຳຂໍລໍຖ້າອະນຸມັດຂອງທີມງານລາຍການນີ້');
            }

            $totalDays = (float) $request['total_days'];
            $balanceStmt = $pdo->prepare(
                'SELECT *
                 FROM leave_balances
                 WHERE user_id = :user_id
                   AND leave_type_id = :leave_type_id
                   AND year = YEAR(:start_date)
                 LIMIT 1
                 FOR UPDATE'
            );
            $balanceStmt->execute([
                'user_id' => (int) $request['user_id'],
                'leave_type_id' => (int) $request['leave_type_id'],
                'start_date' => (string) $request['start_date'],
            ]);
            $balance = $balanceStmt->fetch();

            if (!$balance) {
                throw new RuntimeException('ບໍ່ພົບຍອດວັນລາຂອງພະນັກງານລາຍການນີ້');
            }

            if ($action === 'approve') {
                $updateRequestStmt = $pdo->prepare(
                    'UPDATE leave_requests
                     SET status = "Approved",
                         manager_comment = :manager_comment,
                         approved_at = NOW(),
                         updated_at = NOW()
                     WHERE id = :id'
                );
                $updateRequestStmt->execute([
                    'manager_comment' => $managerComment !== '' ? $managerComment : 'ອະນຸມັດ',
                    'id' => (int) $request['id'],
                ]);

                $updateBalanceStmt = $pdo->prepare(
                    'UPDATE leave_balances
                     SET used_days = used_days + :used_days,
                         pending_days = GREATEST(pending_days - :pending_days, 0),
                         updated_at = NOW()
                     WHERE id = :id'
                );
                $updateBalanceStmt->execute([
                    'used_days' => $totalDays,
                    'pending_days' => $totalDays,
                    'id' => (int) $balance['id'],
                ]);

                $notificationTitle = 'ຄຳຂໍລາໄດ້ຮັບການອະນຸມັດ';
                $notificationMessage = 'ຄຳຂໍລາ ' . $request['request_no'] . ' ໄດ້ຮັບການອະນຸມັດແລ້ວ';
                $auditAction = 'approve_leave_request';
                $newStatus = 'Approved';
                $successMessage = 'ອະນຸມັດຄຳຂໍ ' . $request['request_no'] . ' ສຳເລັດແລ້ວ';
            } else {
                $maxRemaining = max(
                    0,
                    (float) $balance['entitled_days'] + (float) $balance['adjusted_days'] - (float) $balance['used_days']
                );

                $updateRequestStmt = $pdo->prepare(
                    'UPDATE leave_requests
                     SET status = "Rejected",
                         manager_comment = :manager_comment,
                         rejected_at = NOW(),
                         updated_at = NOW()
                     WHERE id = :id'
                );
                $updateRequestStmt->execute([
                    'manager_comment' => $managerComment,
                    'id' => (int) $request['id'],
                ]);

                $updateBalanceStmt = $pdo->prepare(
                    'UPDATE leave_balances
                     SET pending_days = GREATEST(pending_days - :pending_days, 0),
                         remaining_days = LEAST(:max_remaining, remaining_days + :remaining_days),
                         updated_at = NOW()
                     WHERE id = :id'
                );
                $updateBalanceStmt->execute([
                    'pending_days' => $totalDays,
                    'remaining_days' => $totalDays,
                    'max_remaining' => $maxRemaining,
                    'id' => (int) $balance['id'],
                ]);

                $notificationTitle = 'ຄຳຂໍລາບໍ່ຜ່ານການອະນຸມັດ';
                $notificationMessage = 'ຄຳຂໍລາ ' . $request['request_no'] . ' ບໍ່ຜ່ານການອະນຸມັດ: ' . $managerComment;
                $auditAction = 'reject_leave_request';
                $newStatus = 'Rejected';
                $successMessage = 'ໄມ່ອະນຸມັດຄຳຂໍ ' . $request['request_no'] . ' ສຳເລັດແລ້ວ';
            }

            $notificationStmt = $pdo->prepare(
                'INSERT INTO notifications (user_id, title, message, link, is_read, created_at)
                 VALUES (:user_id, :title, :message, :link, 0, NOW())'
            );
            $notificationStmt->execute([
                'user_id' => (int) $request['user_id'],
                'title' => $notificationTitle,
                'message' => $notificationMessage,
                'link' => 'index.php',
            ]);

            $auditStmt = $pdo->prepare(
                'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, old_value, new_value, ip_address, user_agent, created_at)
                 VALUES (:user_id, :action, "leave_requests", :entity_id, :old_value, :new_value, :ip_address, :user_agent, NOW())'
            );
            $auditStmt->execute([
                'user_id' => $managerId,
                'action' => $auditAction,
                'entity_id' => (int) $request['id'],
                'old_value' => json_encode(['status' => 'Pending'], JSON_UNESCAPED_UNICODE),
                'new_value' => json_encode([
                    'status' => $newStatus,
                    'manager_comment' => $managerComment,
                    'total_days' => $totalDays,
                ], JSON_UNESCAPED_UNICODE),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);

            $pdo->commit();
            $_SESSION['flash_success'] = $successMessage;
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            redirectToApprovals();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log($exception->getMessage());
            $errors[] = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'ບໍ່ສາມາດດຳເນີນການຄຳຂໍໄດ້ ກະລຸນາລອງໃໝ່ອີກຄັ້ງ';
        }
    }
}

if (!empty($_SESSION['flash_success'])) {
    $successMessage = (string) $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

$pendingRequests = [];
if (in_array($role, ['manager', 'admin'], true)) {
    $pendingStmt = $pdo->prepare(
        'SELECT lr.id, lr.request_no, lr.start_date, lr.end_date, lr.start_period,
                lr.total_days, lr.reason, lr.attachment, lr.status, lr.created_at,
                u.first_name, u.last_name, u.employee_code, u.email,
                lt.name AS leave_type_name, lt.color AS leave_type_color,
                handover.first_name AS handover_first_name,
                handover.last_name AS handover_last_name
         FROM leave_requests lr
         INNER JOIN users u ON u.id = lr.user_id
         INNER JOIN leave_types lt ON lt.id = lr.leave_type_id
         LEFT JOIN users handover ON handover.id = lr.handover_to_user_id
         WHERE lr.manager_id = :manager_id
           AND lr.status = "Pending"
         ORDER BY lr.created_at ASC'
    );
    $pendingStmt->execute(['manager_id' => $managerId]);
    $pendingRequests = $pendingStmt->fetchAll();
}

$roleLabels = [
    'admin' => 'ຜູ້ດູແລລະບົບ',
    'hr' => 'ຝ່າຍບຸກຄະລາກອນ',
    'manager' => 'ຫົວໜ້າ / ຜູ້ຈັດການ',
    'employee' => 'ພະນັກງານ',
];
$pageTitle = 'ອະນຸມັດຄຳຂໍລາພັກ';
$activeMenu = 'approvals';
$appName = 'ລະບົບຈັດການການລາພັກ';
$currentUser = [
    'name' => (string) ($sessionUser['name'] ?? 'ຜູ້ໃຊ້ງານ'),
    'role' => $roleLabels[$role] ?? 'ພະນັກງານ',
    'department' => 'ອົງກອນ',
    'avatar' => (string) mb_substr((string) ($sessionUser['name'] ?? 'ຜ'), 0, 1, 'UTF-8'),
];
$unreadStmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND is_read = 0');
$unreadStmt->execute(['user_id' => $managerId]);
$unreadCount = (int) $unreadStmt->fetchColumn();
$notifications = fetchUserNotifications($pdo, $managerId, 5);
$notificationsUrl = 'notifications.php';

require __DIR__ . '/../views/layouts/header.php';
require __DIR__ . '/../views/layouts/sidebar.php';
?>
<div class="app-main-wrapper">
    <?php require __DIR__ . '/../views/layouts/topbar.php'; ?>
    <main class="app-content">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <p class="text-secondary mb-1">ສະແດງສະເພາະຄຳຂໍລໍຖ້າອະນຸມັດຂອງທີມງານທ່ານ</p>
                <h2 class="h4 fw-bold mb-0">ອະນຸມັດຄຳຂໍລາພັກ</h2>
            </div>
            <span class="badge text-bg-warning fs-6"><?= h((string) count($pendingRequests)) ?> ລາຍການລໍຖ້າອະນຸມັດ</span>
        </div>

        <?php if ($successMessage !== ''): ?>
            <div class="alert alert-success">
                <i class="fa-solid fa-circle-check me-2"></i><?= h($successMessage) ?>
            </div>
        <?php endif; ?>

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
                    <h3 class="card-title">ຄຳຂໍລໍຖ້າອະນຸມັດ</h3>
                    <div class="card-subtitle">ກວດສອບລາຍລະອຽດກ່ອນອະນຸມັດ ຫຼື ໄມ່ອະນຸມັດ</div>
                </div>
            </div>

            <?php if (empty($pendingRequests)): ?>
                <div class="empty-state">
                    <div class="empty-icon"><i class="fa-regular fa-circle-check"></i></div>
                    <h3>ບໍ່ມີຄຳຂໍລໍຖ້າອະນຸມັດ</h3>
                    <p>ເມື່ອມີຄຳຂໍລາຈາກທີມງານ ລາຍການຈະສະແດງຢູ່ໜ້ານີ້</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle approval-table">
                        <colgroup>
                            <col class="approval-col-no">
                            <col class="approval-col-employee">
                            <col class="approval-col-type">
                            <col class="approval-col-date">
                            <col class="approval-col-days">
                            <col class="approval-col-reason">
                            <col class="approval-col-status">
                            <col class="approval-col-actions">
                        </colgroup>
                        <thead>
                            <tr>
                                <th>ເລກທີຄຳຂໍ</th>
                                <th>ພະນັກງານ</th>
                                <th>ປະເພດ</th>
                                <th>ຊ່ວງວັນທີ</th>
                                <th>ຈຳນວນ</th>
                                <th>ເຫດຜົນ</th>
                                <th>ສະຖານະ</th>
                                <th class="text-end">ດຳເນີນການ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pendingRequests as $request): ?>
                                <?php
                                $employeeName = trim($request['first_name'] . ' ' . $request['last_name']);
                                $handoverName = trim((string) $request['handover_first_name'] . ' ' . (string) $request['handover_last_name']);
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= h($request['request_no']) ?></strong>
                                        <span class="d-block text-secondary small"><?= h(laoDate((string) $request['created_at'])) ?></span>
                                    </td>
                                    <td>
                                        <div class="employee-cell">
                                            <span class="avatar avatar-sm"><?= h(mb_substr($employeeName, 0, 1, 'UTF-8')) ?></span>
                                            <div>
                                                <strong><?= h($employeeName) ?></strong>
                                                <span><?= h($request['employee_code']) ?> · <?= h($request['email']) ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="leave-type-chip" style="--chip-color: <?= h($request['leave_type_color']) ?>">
                                            <?= h($request['leave_type_name']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?= h(laoDate((string) $request['start_date'])) ?> - <?= h(laoDate((string) $request['end_date'])) ?>
                                        <span class="d-block text-secondary small"><?= h(periodLabel((string) $request['start_period'])) ?></span>
                                    </td>
                                    <td><strong><?= h(number_format((float) $request['total_days'], 1)) ?></strong> ມື້</td>
                                    <td class="approval-reason">
                                        <?= h((string) $request['reason']) ?>
                                        <?php if ($handoverName !== ''): ?>
                                            <span class="d-block text-secondary small">ມອບໝາຍ: <?= h($handoverName) ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($request['attachment'])): ?>
                                            <div class="d-flex flex-wrap gap-2 mt-2">
                                                <a class="btn btn-sm btn-outline-primary js-attachment-preview" href="attachment.php?id=<?= h((string) $request['id']) ?>" data-attachment-title="<?= h((string) $request['request_no']) ?>">
                                                    <i class="fa-solid fa-eye me-1"></i>ເບິ່ງໄຟລ໌ແນບ
                                                </a>
                                                <a class="btn btn-sm btn-outline-secondary" href="attachment.php?id=<?= h((string) $request['id']) ?>&amp;download=1">
                                                    <i class="fa-solid fa-download me-1"></i>ບັນທຶກໄຟລ໌
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= statusBadge((string) $request['status']) ?></td>
                                    <td class="text-end">
                                        <div class="approval-actions">
                                            <button class="btn btn-sm btn-success" type="button" data-bs-toggle="modal" data-bs-target="#approveModal<?= h((string) $request['id']) ?>">
                                                <i class="fa-solid fa-check me-1"></i>ອະນຸມັດ
                                            </button>
                                            <button class="btn btn-sm btn-outline-danger" type="button" data-bs-toggle="modal" data-bs-target="#rejectModal<?= h((string) $request['id']) ?>">
                                                <i class="fa-solid fa-xmark me-1"></i>ບໍ່ອະນຸມັດ
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
        <?php if (!empty($pendingRequests)): ?>
            <?php foreach ($pendingRequests as $request): ?>
                <?php
                $employeeName = trim($request['first_name'] . ' ' . $request['last_name']);
                $handoverName = trim((string) $request['handover_first_name'] . ' ' . (string) $request['handover_last_name']);
                $dateRange = laoDate((string) $request['start_date']) . ' - ' . laoDate((string) $request['end_date']);
                ?>
                <div class="modal fade approval-confirm-modal" id="approveModal<?= h((string) $request['id']) ?>" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <form class="modal-content" method="post">
                            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="request_id" value="<?= h((string) $request['id']) ?>">
                            <input type="hidden" name="action" value="approve">
                            <div class="modal-header">
                                <div>
                                    <h5 class="modal-title">ຢືນຢັນການອະນຸມັດຄຳຂໍລາພັກ</h5>
                                    <div class="text-secondary small">ກວດສອບຂໍ້ມູນກ່ອນກົດອະນຸມັດລາຍການນີ້</div>
                                </div>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ປິດ"></button>
                            </div>
                            <div class="modal-body">
                                <div class="approval-confirm-summary">
                                    <div>
                                        <span>ເລກທີຄຳຂໍ</span>
                                        <strong><?= h($request['request_no']) ?></strong>
                                    </div>
                                    <div>
                                        <span>ພະນັກງານ</span>
                                        <strong><?= h($employeeName) ?></strong>
                                    </div>
                                    <div>
                                        <span>ປະເພດ</span>
                                        <strong><?= h($request['leave_type_name']) ?></strong>
                                    </div>
                                    <div>
                                        <span>ຊ່ວງວັນທີ</span>
                                        <strong><?= h($dateRange) ?></strong>
                                    </div>
                                    <div>
                                        <span>ຈຳນວນ</span>
                                        <strong><?= h(number_format((float) $request['total_days'], 1)) ?> ມື້</strong>
                                    </div>
                                    <div>
                                        <span>ເຫດຜົນ</span>
                                        <strong><?= h((string) $request['reason']) ?></strong>
                                    </div>
                                    <?php if ($handoverName !== ''): ?>
                                        <div>
                                            <span>ມອບໝາຍງານ</span>
                                            <strong><?= h($handoverName) ?></strong>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <label class="form-label mt-3" for="approveComment<?= h((string) $request['id']) ?>">ໝາຍເຫດເຖິງພະນັກງານ</label>
                                <textarea class="form-control" id="approveComment<?= h((string) $request['id']) ?>" name="manager_comment" rows="3" placeholder="ເຊັ່ນ: ອະນຸມັດ"></textarea>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-light" data-bs-dismiss="modal">ຍົກເລີກ</button>
                                <button type="submit" class="btn btn-success"><i class="fa-solid fa-check me-2"></i>ຢືນຢັນອະນຸມັດ</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="modal fade approval-confirm-modal" id="rejectModal<?= h((string) $request['id']) ?>" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <form class="modal-content" method="post">
                            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="request_id" value="<?= h((string) $request['id']) ?>">
                            <input type="hidden" name="action" value="reject">
                            <div class="modal-header">
                                <div>
                                    <h5 class="modal-title">ລະບຸເຫດຜົນທີ່ບໍ່ອະນຸມັດ</h5>
                                    <div class="text-secondary small"><?= h($request['request_no']) ?> · <?= h($employeeName) ?></div>
                                </div>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ປິດ"></button>
                            </div>
                            <div class="modal-body">
                                <label class="form-label" for="rejectComment<?= h((string) $request['id']) ?>">ເຫດຜົນ <span class="text-danger">*</span></label>
                                <textarea class="form-control" id="rejectComment<?= h((string) $request['id']) ?>" name="manager_comment" rows="4" required placeholder="ກະລຸນາລະບຸເຫດຜົນໃຫ້ພະນັກງານຊາບ"></textarea>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-light" data-bs-dismiss="modal">ຍົກເລີກ</button>
                                <button type="submit" class="btn btn-danger"><i class="fa-solid fa-xmark me-2"></i>ຢືນຢັນບໍ່ອະນຸມັດ</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
<?php require __DIR__ . '/../views/layouts/footer.php'; ?>
