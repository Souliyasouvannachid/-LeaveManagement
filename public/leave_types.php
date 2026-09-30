<?php
require __DIR__ . '/_auth.php';

if ($userRole !== 'admin') {
    http_response_code(403);
    exit('ທ່ານບໍ່ມີສິດເຂົ້າເຖິງໜ້າຈັດການປະເພດວັນລາ');
}

$pageTitle = 'ຈັດການປະເພດວັນລາ';
$activeMenu = 'leave_types';
$appName = 'ລະບົບຈັດການການລາພັກ';
$useDataTables = true;
$currentYear = (int) date('Y');
$statusOptions = [
    'active' => 'ເປີດໃຊ້ງານ',
    'inactive' => 'ປິດໃຊ້ງານ',
];

function leaveTypeInput(array $source, string $key, string $default = ''): string
{
    return trim((string) ($source[$key] ?? $default));
}

function leaveTypeCheckbox(array $source, string $key, int $default = 0): int
{
    return isset($source[$key]) ? 1 : $default;
}

function leaveTypeNumber(array $source, string $key, ?float $default = 0): ?float
{
    $value = trim((string) ($source[$key] ?? ''));
    if ($value === '') {
        return $default;
    }

    return is_numeric($value) ? (float) $value : null;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$formErrors = [];
$formMode = 'create';
$editingLeaveTypeId = null;
$formValues = [
    'name' => '',
    'code' => '',
    'description' => '',
    'annual_quota' => '0',
    'min_notice_days' => '0',
    'max_days_per_request' => '',
    'color' => '#0d6efd',
    'status' => 'active',
    'is_paid' => 1,
    'allow_half_day' => 1,
    'require_attachment' => 0,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if (!hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
        $_SESSION['flash_error'] = 'ຄຳຮ້ອງຂໍບໍ່ຖືກຕ້ອງ ກະລຸນາຣີເຟຣດໜ້າ ແລ້ວລອງໃໝ່';
        header('Location: ' . leaveContextUrl('leave_types.php'));
        exit;
    }

    if (in_array($action, ['create', 'update'], true)) {
        $formMode = $action === 'update' ? 'edit' : 'create';
        $editingLeaveTypeId = filter_input(INPUT_POST, 'leave_type_id', FILTER_VALIDATE_INT) ?: null;

        $rawCode = strtoupper(leaveTypeInput($_POST, 'code'));
        $annualQuota = leaveTypeNumber($_POST, 'annual_quota', 0);
        $minNoticeDays = leaveTypeNumber($_POST, 'min_notice_days', 0);
        $maxDaysPerRequest = leaveTypeNumber($_POST, 'max_days_per_request', null);

        $formValues = [
            'name' => leaveTypeInput($_POST, 'name'),
            'code' => $rawCode,
            'description' => leaveTypeInput($_POST, 'description'),
            'annual_quota' => leaveTypeInput($_POST, 'annual_quota', '0'),
            'min_notice_days' => leaveTypeInput($_POST, 'min_notice_days', '0'),
            'max_days_per_request' => leaveTypeInput($_POST, 'max_days_per_request'),
            'color' => leaveTypeInput($_POST, 'color', '#0d6efd'),
            'status' => leaveTypeInput($_POST, 'status', 'active'),
            'is_paid' => leaveTypeCheckbox($_POST, 'is_paid'),
            'allow_half_day' => leaveTypeCheckbox($_POST, 'allow_half_day'),
            'require_attachment' => leaveTypeCheckbox($_POST, 'require_attachment'),
        ];

        if ($formValues['name'] === '') {
            $formErrors[] = 'ກະລຸນາລະບຸຊື່ປະເພດວັນລາພັກ';
        }
        if ($rawCode === '') {
            $formErrors[] = 'ກະລຸນາລະບຸລະຫັດປະເພດວັນລາພັກ';
        } elseif (!preg_match('/^[A-Z0-9_-]+$/', $rawCode)) {
            $formErrors[] = 'ລະຫັດປະເພດວັນລາພັກຕ້ອງເປັນຕົວອັກສອນອັງກິດພິມໃຫຍ່ ຕົວເລກ ຫຼື _ - ເທົ່ານັ້ນ';
        }
        if (!isset($statusOptions[$formValues['status']])) {
            $formErrors[] = 'ສະຖານະປະເພດວັນລາພັກບໍ່ຖືກຕ້ອງ';
        }
        if ($annualQuota === null || $annualQuota < 0) {
            $formErrors[] = 'ໂຄວຕ້າຕໍ່ປີຕ້ອງເປັນຕົວເລກ 0 ຂຶ້ນໄປ';
        }
        if ($minNoticeDays === null || $minNoticeDays < 0 || floor($minNoticeDays) != $minNoticeDays) {
            $formErrors[] = 'ຈຳນວນວັນແຈ້ງລ່ວງໜ້າຕ້ອງເປັນເລກຈຳນວນເຕັມ 0 ຂຶ້ນໄປ';
        }
        if ($maxDaysPerRequest !== null && $maxDaysPerRequest < 0) {
            $formErrors[] = 'ຈຳນວນວັນລາພັກສູງສຸດຕໍ່ຄັ້ງຕ້ອງເປັນຕົວເລກ 0 ຂຶ້ນໄປ';
        }
        if (!preg_match('/^#([A-Fa-f0-9]{6})$/', $formValues['color'])) {
            $formErrors[] = 'ສີປະຈຳປະເພດວັນລາພັກບໍ່ຖືກຕ້ອງ';
        }

        try {
            if (empty($formErrors)) {
                $duplicateStmt = $pdo->prepare(
                    'SELECT id
                     FROM leave_types
                     WHERE (name = :name OR code = :code)
                       AND (:current_id_a IS NULL OR id <> :current_id_b)
                     LIMIT 1'
                );
                $duplicateStmt->execute([
                    'name' => $formValues['name'],
                    'code' => $rawCode,
                    'current_id_a' => $editingLeaveTypeId,
                    'current_id_b' => $editingLeaveTypeId,
                ]);

                $duplicateLeaveType = $duplicateStmt->fetch();
                if ($duplicateLeaveType) {
                    $formErrors[] = 'ຊື່ ຫຼື ລະຫັດປະເພດວັນລາພັກນີ້ມີຢູ່ໃນລະບົບແລ້ວ';
                }
            }

            if (empty($formErrors)) {
                $payload = [
                    'name' => $formValues['name'],
                    'code' => $rawCode,
                    'description' => $formValues['description'] !== '' ? $formValues['description'] : null,
                    'annual_quota' => number_format((float) $annualQuota, 2, '.', ''),
                    'is_paid' => $formValues['is_paid'],
                    'allow_half_day' => $formValues['allow_half_day'],
                    'require_attachment' => $formValues['require_attachment'],
                    'min_notice_days' => (int) $minNoticeDays,
                    'max_days_per_request' => $maxDaysPerRequest !== null && $formValues['max_days_per_request'] !== ''
                        ? number_format((float) $maxDaysPerRequest, 2, '.', '')
                        : null,
                    'color' => $formValues['color'],
                    'status' => $formValues['status'],
                ];

                if ($action === 'create') {
                    $pdo->beginTransaction();
                    $createStmt = $pdo->prepare(
                        'INSERT INTO leave_types (
                            name, code, description, annual_quota, is_paid, allow_half_day,
                            require_attachment, min_notice_days, max_days_per_request, color, status
                         ) VALUES (
                            :name, :code, :description, :annual_quota, :is_paid, :allow_half_day,
                            :require_attachment, :min_notice_days, :max_days_per_request, :color, :status
                         )'
                    );
                    $createStmt->execute($payload);
                    $createdLeaveTypeId = (int) $pdo->lastInsertId();
                    if ($formValues['status'] === 'active') {
                        ensureLeaveBalancesForLeaveType($pdo, $createdLeaveTypeId, $currentYear);
                    }
                    $pdo->commit();
                    $_SESSION['flash_success'] = 'ເພີ່ມປະເພດວັນລາພັກໃໝ່ສຳເລັດແລ້ວ';
                } else {
                    $payload['id'] = $editingLeaveTypeId;
                    $updateStmt = $pdo->prepare(
                        'UPDATE leave_types
                         SET name = :name,
                             code = :code,
                             description = :description,
                             annual_quota = :annual_quota,
                             is_paid = :is_paid,
                             allow_half_day = :allow_half_day,
                             require_attachment = :require_attachment,
                             min_notice_days = :min_notice_days,
                             max_days_per_request = :max_days_per_request,
                             color = :color,
                             status = :status
                         WHERE id = :id
                         LIMIT 1'
                    );
                    $updateStmt->execute($payload);
                    if ($formValues['status'] === 'active') {
                        ensureLeaveBalancesForLeaveType($pdo, (int) $editingLeaveTypeId, $currentYear);
                    }
                    $_SESSION['flash_success'] = 'ອັບເດດປະເພດວັນລາພັກສຳເລັດແລ້ວ';
                }

                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                header('Location: ' . leaveContextUrl('leave_types.php'));
                exit;
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log($exception->getMessage());
            $formErrors[] = 'ບໍ່ສາມາດບັນທຶກຂໍ້ມູນປະເພດວັນລາພັກໄດ້ ກະລຸນາລອງໃໝ່ອີກຄັ້ງ';
        }
    }

    if ($action === 'toggle_status') {
        $leaveTypeId = filter_input(INPUT_POST, 'leave_type_id', FILTER_VALIDATE_INT);
        $newStatus = (string) ($_POST['new_status'] ?? '');

        if (!$leaveTypeId || !isset($statusOptions[$newStatus])) {
            $_SESSION['flash_error'] = 'ບໍ່ພົບປະເພດວັນລາພັກທີ່ຕ້ອງການປ່ຽນສະຖານະ';
        } else {
            try {
                $pdo->beginTransaction();
                $toggleStmt = $pdo->prepare('UPDATE leave_types SET status = :status WHERE id = :id LIMIT 1');
                $toggleStmt->execute([
                    'status' => $newStatus,
                    'id' => $leaveTypeId,
                ]);
                if ($newStatus === 'active') {
                    ensureLeaveBalancesForLeaveType($pdo, $leaveTypeId, $currentYear);
                }
                $pdo->commit();
                $_SESSION['flash_success'] = $newStatus === 'active'
                    ? 'ເປີດໃຊ້ງານປະເພດວັນລາພັກສຳເລັດແລ້ວ'
                    : 'ປິດໃຊ້ງານປະເພດວັນລາພັກສຳເລັດແລ້ວ';
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log($exception->getMessage());
                $_SESSION['flash_error'] = 'ບໍ່ສາມາດປ່ຽນສະຖານະປະເພດວັນລາພັກໄດ້ ກະລຸນາລອງໃໝ່ອີກຄັ້ງ';
            }
        }

        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        header('Location: ' . leaveContextUrl('leave_types.php'));
        exit;
    }

    if ($action === 'delete') {
        $leaveTypeId = filter_input(INPUT_POST, 'leave_type_id', FILTER_VALIDATE_INT);

        if (!$leaveTypeId) {
            $_SESSION['flash_error'] = 'ບໍ່ພົບປະເພດວັນລາພັກທີ່ຕ້ອງການລຶບ';
        } else {
            $referenceStmt = $pdo->prepare(
                'SELECT
                    (SELECT COUNT(*) FROM leave_requests WHERE leave_type_id = :id1) AS requests_count,
                    (SELECT COUNT(*) FROM leave_balances WHERE leave_type_id = :id2) AS balances_count'
            );
            $referenceStmt->execute([
                'id1' => $leaveTypeId,
                'id2' => $leaveTypeId,
            ]);
            $references = $referenceStmt->fetch();
            $totalReferences = array_sum(array_map('intval', $references ?: []));

            if ($totalReferences > 0) {
                $_SESSION['flash_error'] = 'ບໍ່ສາມາດລຶບປະເພດວັນລານີ້ໄດ້ ເນື່ອງຈາກຍັງມີຂໍ້ມູນອ້າງອີງຢູ່';
            } else {
                $deleteStmt = $pdo->prepare('DELETE FROM leave_types WHERE id = :id LIMIT 1');
                $deleteStmt->execute(['id' => $leaveTypeId]);
                $_SESSION['flash_success'] = 'ລຶບປະເພດວັນລາສຳເລັດແລ້ວ';
            }
        }

        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        header('Location: ' . leaveContextUrl('leave_types.php'));
        exit;
    }
}

$leaveTypesStmt = $pdo->prepare(
    'SELECT lt.id, lt.name, lt.code, lt.description, lt.annual_quota, lt.is_paid, lt.allow_half_day,
            lt.require_attachment, lt.min_notice_days, lt.max_days_per_request, lt.color, lt.status, lt.updated_at,
            (SELECT COUNT(*) FROM leave_requests lr WHERE lr.leave_type_id = lt.id) AS requests_count,
            (SELECT COUNT(*) FROM leave_balances lb WHERE lb.leave_type_id = lt.id) AS balances_count
     FROM leave_types lt
     ORDER BY lt.name'
);
$leaveTypesStmt->execute();
$leaveTypes = $leaveTypesStmt->fetchAll();

require __DIR__ . '/../views/layouts/header.php';
require __DIR__ . '/../views/layouts/sidebar.php';
?>
<div class="app-main-wrapper">
    <?php require __DIR__ . '/../views/layouts/topbar.php'; ?>
    <main class="app-content">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <p class="text-secondary mb-1">ຈັດການປະເພດວັນລາພັກ ໂຄຕ້າ ສີປະຈຳປະເພດ ແລະ ເງື່ອນໄຂການຍື່ນລາ</p>
                <h2 class="h4 fw-bold mb-0">ຈັດການປະເພດວັນລາພັກ</h2>
            </div>
            <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#leaveTypeFormModal">
                <i class="fa-solid fa-list-check me-2"></i>ເພີ່ມປະເພດວັນລາພັກ
            </button>
        </div>

        <?php if (!empty($formErrors)): ?>
            <div class="alert alert-danger employee-form-alert">
                <div class="fw-bold mb-2">ບໍ່ສາມາດບັນທຶກຂໍ້ມູນໄດ້</div>
                <ul class="mb-0 ps-3">
                    <?php foreach ($formErrors as $error): ?>
                        <li><?= h($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <section class="content-card employee-admin-card">
            <div class="card-toolbar">
                <div>
                    <h3 class="card-title">ປະເພດວັນລາພັກທັງໝົດ</h3>
                    <div class="card-subtitle"><?= h((string) count($leaveTypes)) ?> ລາຍການ</div>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table align-middle app-data-table leave-types-table" data-datatable="true" data-page-size="10" data-search-placeholder="ຄົ້ນຫາຊື່ ລະຫັດ ຫຼື ຄຳອະທິບາຍປະເພດວັນລາ" data-empty-message="ຍັງບໍ່ມີຂໍ້ມູນປະເພດວັນລາ">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>ປະເພດວັນລາ</th>
                            <th>ໂຄຕ້າ</th>
                            <th>ເງື່ອນໄຂ</th>
                            <th>ສະຖານະ</th>
                            <th>ອັບເດດຫຼ້າສຸດ</th>
                            <th width="10%">ຈັດການ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($leaveTypes as $index => $leaveType): ?>
                            <?php $toggleStatus = $leaveType['status'] === 'active' ? 'inactive' : 'active'; ?>
                            <tr>
                                <td><span class="employee-code"><?= h((string) ($index + 1)) ?></span></td>
                                <td>
                                    <div class="employee-meta">
                                        <strong class="leave-type-title">
                                            <span class="leave-type-color-dot" style="background: <?= h($leaveType['color']) ?>;"></span>
                                            <?= h($leaveType['name']) ?>
                                        </strong>
                                        <span><?= h($leaveType['code']) ?><?php if (!empty($leaveType['description'])): ?> · <?= h($leaveType['description']) ?><?php endif; ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="employee-meta">
                                        <strong><?= h(number_format((float) $leaveType['annual_quota'], 1)) ?> ວັນ/ປີ</strong>
                                        <span>
                                            <?php if ($leaveType['max_days_per_request'] !== null): ?>
                                                ສູງສຸດ <?= h(number_format((float) $leaveType['max_days_per_request'], 1)) ?> ວັນ/ຄັ້ງ
                                            <?php else: ?>
                                                ບໍ່ຈຳກັດຈຳນວນວັນຕໍ່ຄັ້ງ
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <div class="leave-type-options">
                                        <span class="leave-type-pill <?= (int) $leaveType['is_paid'] === 1 ? 'is-on' : 'is-off' ?>">ຄ່າຈ້າງ<?= (int) $leaveType['is_paid'] === 1 ? 'ລວມ' : 'ບໍ່ລວມ' ?></span>
                                        <span class="leave-type-pill <?= (int) $leaveType['allow_half_day'] === 1 ? 'is-on' : 'is-off' ?>">ເຄິ່ງວັນ<?= (int) $leaveType['allow_half_day'] === 1 ? 'ໄດ້' : 'ບໍ່ໄດ້' ?></span>
                                        <span class="leave-type-pill <?= (int) $leaveType['require_attachment'] === 1 ? 'is-warn' : 'is-off' ?>">ແນບໄຟລ໌<?= (int) $leaveType['require_attachment'] === 1 ? 'ຈຳເປັນ' : 'ບໍ່ບັງຄັບ' ?></span>
                                        <span class="leave-type-pill is-neutral">ແຈ້ງລ່ວງໜ້າ <?= h((string) $leaveType['min_notice_days']) ?> ວັນ</span>
                                    </div>
                                </td>
                                <td><span class="badge text-bg-<?= $leaveType['status'] === 'active' ? 'success' : 'secondary' ?>"><?= $leaveType['status'] === 'active' ? 'ເປີດໃຊ້ງານ' : 'ປິດໃຊ້ງານ' ?></span></td>
                                <td><?= h(thaiDate((string) $leaveType['updated_at'])) ?></td>
                                <td>
                                    <div class="approval-actions employee-actions">
                                        <button
                                            class="btn btn-sm btn-link text-primary employee-icon-btn js-edit-leave-type"
                                            type="button"
                                            data-bs-toggle="modal"
                                            data-bs-target="#leaveTypeFormModal"
                                            title="ແກ້ໄຂຂໍ້ມູນ"
                                            aria-label="ແກ້ໄຂຂໍ້ມູນ"
                                            data-id="<?= h((string) $leaveType['id']) ?>"
                                            data-name="<?= h($leaveType['name']) ?>"
                                            data-code="<?= h($leaveType['code']) ?>"
                                            data-description="<?= h((string) ($leaveType['description'] ?? '')) ?>"
                                            data-annual_quota="<?= h(number_format((float) $leaveType['annual_quota'], 2, '.', '')) ?>"
                                            data-min_notice_days="<?= h((string) $leaveType['min_notice_days']) ?>"
                                            data-max_days_per_request="<?= h($leaveType['max_days_per_request'] !== null ? number_format((float) $leaveType['max_days_per_request'], 2, '.', '') : '') ?>"
                                            data-color="<?= h($leaveType['color']) ?>"
                                            data-status="<?= h($leaveType['status']) ?>"
                                            data-is_paid="<?= h((string) $leaveType['is_paid']) ?>"
                                            data-allow_half_day="<?= h((string) $leaveType['allow_half_day']) ?>"
                                            data-require_attachment="<?= h((string) $leaveType['require_attachment']) ?>"
                                        >
                                            <i class="fa-solid fa-pen-to-square"></i>
                                        </button>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="leave_type_id" value="<?= h((string) $leaveType['id']) ?>">
                                            <input type="hidden" name="new_status" value="<?= h($toggleStatus) ?>">
                                            <button
                                                class="btn btn-sm btn-link text-warning employee-icon-btn"
                                                type="submit"
                                                title="<?= $leaveType['status'] === 'active' ? 'ປິດໃຊ້ງານ' : 'ເປີດໃຊ້ງານ' ?>"
                                                aria-label="<?= $leaveType['status'] === 'active' ? 'ປິດໃຊ້ງານ' : 'ເປີດໃຊ້ງານ' ?>"
                                            >
                                                <i class="fa-solid fa-power-off"></i>
                                            </button>
                                        </form>
                                        <form method="post" class="d-inline" onsubmit="return confirm('ຢືນຢັນການລຶບປະເພດວັນລາພັກນີ້?');">
                                            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="leave_type_id" value="<?= h((string) $leaveType['id']) ?>">
                                            <button
                                                class="btn btn-sm btn-link text-danger employee-icon-btn"
                                                type="submit"
                                                title="ລຶບຂໍ້ມູນ"
                                                aria-label="ລຶບຂໍ້ມູນ"
                                            >
                                                <i class="fa-solid fa-trash-can"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>

<div class="modal fade" id="leaveTypeFormModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <form method="post" novalidate>
                <div class="modal-header">
                    <div>
                        <h3 class="modal-title h5 mb-1" id="leaveTypeModalTitle"><?= $formMode === 'edit' ? 'ແກ້ໄຂປະເພດວັນລາພັກ' : 'ເພີ່ມປະເພດວັນລາພັກໃໝ່' ?></h3>
                        <div class="text-secondary small">ກຳນົດລະຫັດ ໂຄຕ້າ ສິດທິການລາພັກ ແລະ ເງື່ອນໄຂການໃຊ້ງານ</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ປິດ"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" id="leaveTypeFormAction" value="<?= $formMode === 'edit' ? 'update' : 'create' ?>">
                    <input type="hidden" name="leave_type_id" id="leaveTypeId" value="<?= h((string) ($editingLeaveTypeId ?? '')) ?>">

                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label">ຊື່ປະເພດວັນລາ</label>
                            <input class="form-control" type="text" name="name" id="leaveTypeName" value="<?= h($formValues['name']) ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">ລະຫັດ</label>
                            <input class="form-control text-uppercase" type="text" name="code" id="leaveTypeCode" value="<?= h($formValues['code']) ?>" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">ສີ</label>
                            <input class="form-control form-control-color leave-type-color-input" type="color" name="color" id="leaveTypeColor" value="<?= h($formValues['color']) ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">ສະຖານະ</label>
                            <select class="form-select" name="status" id="leaveTypeStatus">
                                <?php foreach ($statusOptions as $value => $label): ?>
                                    <option value="<?= h($value) ?>" <?= $formValues['status'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">ໂຄວຕ້າຕໍ່ປີ</label>
                            <div class="input-group">
                                <input class="form-control" type="number" min="0" step="0.5" name="annual_quota" id="leaveTypeAnnualQuota" value="<?= h($formValues['annual_quota']) ?>">
                                <span class="input-group-text">ວັນ</span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">ແຈ້ງລ່ວງໜ້າ</label>
                            <div class="input-group">
                                <input class="form-control" type="number" min="0" step="1" name="min_notice_days" id="leaveTypeMinNoticeDays" value="<?= h($formValues['min_notice_days']) ?>">
                                <span class="input-group-text">ວັນ</span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">ສູງສຸດຕໍ່ຄັ້ງ</label>
                            <div class="input-group">
                                <input class="form-control" type="number" min="0" step="0.5" name="max_days_per_request" id="leaveTypeMaxDaysPerRequest" value="<?= h($formValues['max_days_per_request']) ?>" placeholder="ວ່າງໄວ້ = ບໍ່ຈຳກັດ">
                                <span class="input-group-text">ວັນ</span>
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="form-label">ລາຍລະອຽດ</label>
                            <textarea class="form-control" name="description" id="leaveTypeDescription" rows="4" placeholder="ອະທິບາຍເງື່ອນໄຂ ຫຼື ວັດຖຸປະສົງຂອງປະເພດວັນລາ"><?= h($formValues['description']) ?></textarea>
                        </div>

                        <div class="col-12">
                            <label class="form-label d-block mb-2">ຕົວເລືອກການໃຊ້ງານ</label>
                            <div class="leave-type-check-grid">
                                <label class="leave-type-check-card">
                                    <input class="form-check-input" type="checkbox" name="is_paid" id="leaveTypeIsPaid" value="1" <?= (int) $formValues['is_paid'] === 1 ? 'checked' : '' ?>>
                                    <span>
                                        <strong>ລວມຄ່າຈ້າງ</strong>
                                        <small>ວັນລາພັກປະເພດນີ້ຍັງໄດ້ຮັບຄ່າຈ້າງຕາມປົກກະຕິ</small>
                                    </span>
                                </label>
                                <label class="leave-type-check-card">
                                    <input class="form-check-input" type="checkbox" name="allow_half_day" id="leaveTypeAllowHalfDay" value="1" <?= (int) $formValues['allow_half_day'] === 1 ? 'checked' : '' ?>>
                                    <span>
                                        <strong>ອະນຸຍາດເຄິ່ງວັນ</strong>
                                        <small>ພະນັກງານສາມາດເລືອກລາແບບເຄິ່ງວັນໄດ້</small>
                                    </span>
                                </label>
                                <label class="leave-type-check-card">
                                    <input class="form-check-input" type="checkbox" name="require_attachment" id="leaveTypeRequireAttachment" value="1" <?= (int) $formValues['require_attachment'] === 1 ? 'checked' : '' ?>>
                                    <span>
                                        <strong>ບັງຄັບແນບໄຟລ໌</strong>
                                        <small>ເຊັ່ນ ໃບຢັ້ງຢືນແພດ ຫຼື ເອກະສານປະກອບ</small>
                                    </span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-light" type="button" data-bs-dismiss="modal">ຍົກເລີກ</button>
                    <button class="btn btn-primary" type="submit">
                        <i class="fa-solid fa-floppy-disk me-2"></i>ບັນທຶກຂໍ້ມູນ
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const modalElement = document.getElementById('leaveTypeFormModal');
        if (!modalElement) {
            return;
        }

        const formActionInput = document.getElementById('leaveTypeFormAction');
        const modalTitle = document.getElementById('leaveTypeModalTitle');
        const leaveTypeIdInput = document.getElementById('leaveTypeId');
        const fields = {
            name: document.getElementById('leaveTypeName'),
            code: document.getElementById('leaveTypeCode'),
            description: document.getElementById('leaveTypeDescription'),
            annual_quota: document.getElementById('leaveTypeAnnualQuota'),
            min_notice_days: document.getElementById('leaveTypeMinNoticeDays'),
            max_days_per_request: document.getElementById('leaveTypeMaxDaysPerRequest'),
            color: document.getElementById('leaveTypeColor'),
            status: document.getElementById('leaveTypeStatus')
        };
        const checks = {
            is_paid: document.getElementById('leaveTypeIsPaid'),
            allow_half_day: document.getElementById('leaveTypeAllowHalfDay'),
            require_attachment: document.getElementById('leaveTypeRequireAttachment')
        };

        const defaults = {
            name: '',
            code: '',
            description: '',
            annual_quota: '0',
            min_notice_days: '0',
            max_days_per_request: '',
            color: '#0d6efd',
            status: 'active',
            is_paid: '1',
            allow_half_day: '1',
            require_attachment: '0'
        };

        function fillForm(values, mode, leaveTypeId = '') {
            formActionInput.value = mode === 'edit' ? 'update' : 'create';
            modalTitle.textContent = mode === 'edit' ? 'ແກ້ໄຂປະເພດວັນລາພັກ' : 'ເພີ່ມປະເພດວັນລາພັກໃໝ່';
            leaveTypeIdInput.value = leaveTypeId;

            Object.entries(fields).forEach(([key, element]) => {
                if (element) {
                    element.value = values[key] ?? '';
                }
            });

            Object.entries(checks).forEach(([key, element]) => {
                if (element) {
                    element.checked = String(values[key] ?? '0') === '1';
                }
            });
        }

        document.querySelectorAll('.js-edit-leave-type').forEach((button) => {
            button.addEventListener('click', () => {
                fillForm(button.dataset, 'edit', button.dataset.id || '');
            });
        });

        modalElement.addEventListener('show.bs.modal', (event) => {
            if (event.relatedTarget?.classList.contains('js-edit-leave-type')) {
                return;
            }
            fillForm(defaults, 'create');
        });

        <?php if (!empty($formErrors)): ?>
        bootstrap.Modal.getOrCreateInstance(modalElement).show();
        <?php endif; ?>
    });
</script>
<?php require __DIR__ . '/../views/layouts/footer.php'; ?>
