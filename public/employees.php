<?php
require __DIR__ . '/_auth.php';

if ($userRole !== 'admin') {
    http_response_code(403);
    exit('ທ່ານບໍ່ມີສິດເຂົ້າເຖິງໜ້າຈັດການພະນັກງານ');
}

$pageTitle = 'ຈັດການພະນັກງານ';
$activeMenu = 'employees';
$appName = 'ລະບົບຈັດການການລາພັກ';
$useDataTables = true;
$currentYear = (int) date('Y');
$roleOptions = [
    'admin' => 'ຜູ້ດູແລລະບົບ',
    'hr' => 'ຝ່າຍບຸກຄະລາກອນ',
    'manager' => 'ຫົວໜ້າງານ',
    'employee' => 'ພະນັກງານ',
];
$statusOptions = [
    'active' => 'ເປີດໃຊ້ງານ',
    'inactive' => 'ປິດໃຊ້ງານ',
];

function employeeInput(array $source, string $key, string $default = ''): string
{
    return trim((string) ($source[$key] ?? $default));
}

function employeeCodeFromName(PDO $pdo): string
{
    $lastCode = (string) $pdo->query('SELECT employee_code FROM users ORDER BY id DESC LIMIT 1')->fetchColumn();
    if (preg_match('/(\d+)$/', $lastCode, $matches)) {
        $nextNumber = (int) $matches[1] + 1;

        return 'EMP-' . str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
    }

    return 'EMP-0001';
}

function passwordRules(?string $password): bool
{
    return $password !== null && mb_strlen($password) >= 8;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$formErrors = [];
$formMode = 'create';
$editingEmployeeId = null;
$formValues = [
    'employee_code' => '',
    'first_name' => '',
    'last_name' => '',
    'email' => '',
    'phone' => '',
    'role' => 'employee',
    'department_id' => '',
    'position_id' => '',
    'manager_id' => '',
    'start_date' => '',
    'status' => 'active',
];

$departmentsStmt = $pdo->query('SELECT id, name, status FROM departments ORDER BY name');
$departments = $departmentsStmt->fetchAll();
$positionsStmt = $pdo->query('SELECT id, name, department_id, status FROM positions ORDER BY name');
$positions = $positionsStmt->fetchAll();
$managersStmt = $pdo->query(
    'SELECT id, first_name, last_name, role
     FROM users
     WHERE role IN ("admin", "manager") AND status = "active"
     ORDER BY first_name, last_name'
);
$managerOptions = $managersStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if (!hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
        $_SESSION['flash_error'] = 'ຄຳຮ້ອງຂໍບໍ່ຖືກຕ້ອງ ກະລຸນາຣີເຟຣດໜ້າ ແລ້ວລອງໃໝ່';
        header('Location: ' . leaveContextUrl('employees.php'));
        exit;
    }

    if (in_array($action, ['create', 'update'], true)) {
        $formMode = $action === 'update' ? 'edit' : 'create';
        $editingEmployeeId = filter_input(INPUT_POST, 'employee_id', FILTER_VALIDATE_INT) ?: null;
        $formValues = [
            'employee_code' => employeeInput($_POST, 'employee_code'),
            'first_name' => employeeInput($_POST, 'first_name'),
            'last_name' => employeeInput($_POST, 'last_name'),
            'email' => employeeInput($_POST, 'email'),
            'phone' => employeeInput($_POST, 'phone'),
            'role' => employeeInput($_POST, 'role', 'employee'),
            'department_id' => employeeInput($_POST, 'department_id'),
            'position_id' => employeeInput($_POST, 'position_id'),
            'manager_id' => employeeInput($_POST, 'manager_id'),
            'start_date' => employeeInput($_POST, 'start_date'),
            'status' => employeeInput($_POST, 'status', 'active'),
        ];
        $password = (string) ($_POST['password'] ?? '');

        if ($formValues['employee_code'] === '') {
            $formErrors[] = 'ກະລຸນາລະບຸລະຫັດພະນັກງານ';
        }
        if ($formValues['first_name'] === '' || $formValues['last_name'] === '') {
            $formErrors[] = 'ກະລຸນາກອກຊື່ ແລະ ນາມສະກຸນ';
        }
        if (!filter_var($formValues['email'], FILTER_VALIDATE_EMAIL)) {
            $formErrors[] = 'ຮູບແບບອີເມວບໍ່ຖືກຕ້ອງ';
        }
        if (!isset($roleOptions[$formValues['role']])) {
            $formErrors[] = 'ບົດບາດທີ່ເລືອກບໍ່ຖືກຕ້ອງ';
        }
        if (!isset($statusOptions[$formValues['status']])) {
            $formErrors[] = 'ສະຖານະບັນຊີບໍ່ຖືກຕ້ອງ';
        }
        if ($action === 'create' && !passwordRules($password)) {
            $formErrors[] = 'ລະຫັດຜ່ານຕ້ອງມີຢ່າງໜ້ອຍ 8 ຕົວອັກສອນ';
        }
        if ($action === 'update' && $password !== '' && !passwordRules($password)) {
            $formErrors[] = 'ລະຫັດຜ່ານໃໝ່ຕ້ອງມີຢ່າງໜ້ອຍ 8 ຕົວອັກສອນ';
        }

        $departmentId = $formValues['department_id'] !== '' ? (int) $formValues['department_id'] : null;
        $positionId = $formValues['position_id'] !== '' ? (int) $formValues['position_id'] : null;
        $managerId = $formValues['manager_id'] !== '' ? (int) $formValues['manager_id'] : null;

        if ($editingEmployeeId !== null && $managerId === $editingEmployeeId) {
            $formErrors[] = 'ບໍ່ສາມາດເລືອກຫົວໜ້າເປັນຄົນດຽວກັບພະນັກງານໄດ້';
        }

        try {
            if (empty($formErrors)) {
                $duplicateStmt = $pdo->prepare(
                    'SELECT id
                     FROM users
                     WHERE (employee_code = :employee_code OR email = :email)
                       AND (:current_id_a IS NULL OR id <> :current_id_b)
                     LIMIT 1'
                );
                $duplicateStmt->execute([
                    'employee_code' => $formValues['employee_code'],
                    'email' => $formValues['email'],
                    'current_id_a' => $editingEmployeeId,
                    'current_id_b' => $editingEmployeeId,
                ]);

                if ($duplicateStmt->fetch()) {
                    $formErrors[] = 'ລະຫັດພະນັກງານ ຫຼື ອີເມວນີ້ມີຢູ່ໃນລະບົບແລ້ວ';
                }
            }

            if (empty($formErrors) && $departmentId !== null) {
                $checkDepartmentStmt = $pdo->prepare('SELECT COUNT(*) FROM departments WHERE id = :id');
                $checkDepartmentStmt->execute(['id' => $departmentId]);
                if ((int) $checkDepartmentStmt->fetchColumn() === 0) {
                    $formErrors[] = 'ບໍ່ພົບພະແນກທີ່ເລືອກ';
                }
            }

            if (empty($formErrors) && $positionId !== null) {
                $checkPositionStmt = $pdo->prepare('SELECT COUNT(*) FROM positions WHERE id = :id');
                $checkPositionStmt->execute(['id' => $positionId]);
                if ((int) $checkPositionStmt->fetchColumn() === 0) {
                    $formErrors[] = 'ບໍ່ພົບຕຳແໜ່ງທີ່ເລືອກ';
                }
            }

            if (empty($formErrors) && $managerId !== null) {
                $checkManagerStmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id = :id');
                $checkManagerStmt->execute(['id' => $managerId]);
                if ((int) $checkManagerStmt->fetchColumn() === 0) {
                    $formErrors[] = 'ບໍ່ພົບຫົວໜ້າທີ່ເລືອກ';
                }
            }

            if (empty($formErrors)) {
                if ($action === 'create') {
                    $pdo->beginTransaction();
                    $createStmt = $pdo->prepare(
                        'INSERT INTO users (
                            employee_code, first_name, last_name, email, password, phone, role,
                            department_id, position_id, manager_id, start_date, status
                         ) VALUES (
                            :employee_code, :first_name, :last_name, :email, :password, :phone, :role,
                            :department_id, :position_id, :manager_id, :start_date, :status
                         )'
                    );
                    $createStmt->execute([
                        'employee_code' => $formValues['employee_code'],
                        'first_name' => $formValues['first_name'],
                        'last_name' => $formValues['last_name'],
                        'email' => $formValues['email'],
                        'password' => password_hash($password, PASSWORD_BCRYPT),
                        'phone' => $formValues['phone'] !== '' ? $formValues['phone'] : null,
                        'role' => $formValues['role'],
                        'department_id' => $departmentId,
                        'position_id' => $positionId,
                        'manager_id' => $managerId,
                        'start_date' => $formValues['start_date'] !== '' ? $formValues['start_date'] : null,
                        'status' => $formValues['status'],
                    ]);
                    $createdUserId = (int) $pdo->lastInsertId();
                    if ($formValues['status'] === 'active') {
                        ensureLeaveBalancesForUser($pdo, $createdUserId, $currentYear);
                    }
                    $pdo->commit();
                    $_SESSION['flash_success'] = 'ເພີ່ມພະນັກງານໃໝ່ສຳເລັດແລ້ວ';
                } else {
                    $sql = 'UPDATE users
                            SET employee_code = :employee_code,
                                first_name = :first_name,
                                last_name = :last_name,
                                email = :email,
                                phone = :phone,
                                role = :role,
                                department_id = :department_id,
                                position_id = :position_id,
                                manager_id = :manager_id,
                                start_date = :start_date,
                                status = :status';
                    $params = [
                        'employee_code' => $formValues['employee_code'],
                        'first_name' => $formValues['first_name'],
                        'last_name' => $formValues['last_name'],
                        'email' => $formValues['email'],
                        'phone' => $formValues['phone'] !== '' ? $formValues['phone'] : null,
                        'role' => $formValues['role'],
                        'department_id' => $departmentId,
                        'position_id' => $positionId,
                        'manager_id' => $managerId,
                        'start_date' => $formValues['start_date'] !== '' ? $formValues['start_date'] : null,
                        'status' => $formValues['status'],
                        'id' => $editingEmployeeId,
                    ];

                    if ($password !== '') {
                        $sql .= ', password = :password';
                        $params['password'] = password_hash($password, PASSWORD_BCRYPT);
                    }

                    $sql .= ' WHERE id = :id LIMIT 1';
                    $updateStmt = $pdo->prepare($sql);
                    $updateStmt->execute($params);
                    $_SESSION['flash_success'] = 'ອັບເດດຂໍ້ມູນພະນັກງານສຳເລັດແລ້ວ';
                }

                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                header('Location: ' . leaveContextUrl('employees.php'));
                exit;
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log($exception->getMessage());
            $formErrors[] = 'ບໍ່ສາມາດບັນທຶກຂໍ້ມູນພະນັກງານໄດ້ ກະລຸນາລອງໃໝ່ອີກຄັ້ງ';
        }
    }

    if ($action === 'toggle_status') {
        $employeeId = filter_input(INPUT_POST, 'employee_id', FILTER_VALIDATE_INT);
        $newStatus = (string) ($_POST['new_status'] ?? '');

        if (!$employeeId || !isset($statusOptions[$newStatus])) {
            $_SESSION['flash_error'] = 'ບໍ່ພົບຂໍ້ມູນພະນັກງານທີ່ຕ້ອງການປ່ຽນສະຖານະ';
        } elseif ($employeeId === $userId && $newStatus !== 'active') {
            $_SESSION['flash_error'] = 'ບໍ່ສາມາດປິດໃຊ້ງານບັນຊີທີ່ກຳລັງໃຊ້ງານຢູ່ໄດ້';
        } else {
            try {
                $pdo->beginTransaction();
                $toggleStmt = $pdo->prepare('UPDATE users SET status = :status WHERE id = :id LIMIT 1');
                $toggleStmt->execute([
                    'status' => $newStatus,
                    'id' => $employeeId,
                ]);
                if ($newStatus === 'active') {
                    ensureLeaveBalancesForUser($pdo, $employeeId, $currentYear);
                }
                $pdo->commit();
                $_SESSION['flash_success'] = $newStatus === 'active' ? 'ເປີດໃຊ້ງານບັນຊີສຳເລັດແລ້ວ' : 'ປິດໃຊ້ງານບັນຊີສຳເລັດແລ້ວ';
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log($exception->getMessage());
                $_SESSION['flash_error'] = 'ບໍ່ສາມາດປ່ຽນສະຖານະພະນັກງານໄດ້ ກະລຸນາລອງໃໝ່ອີກຄັ້ງ';
            }
        }

        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        header('Location: ' . leaveContextUrl('employees.php'));
        exit;
    }

    if ($action === 'delete') {
        $employeeId = filter_input(INPUT_POST, 'employee_id', FILTER_VALIDATE_INT);

        if (!$employeeId) {
            $_SESSION['flash_error'] = 'ບໍ່ພົບພະນັກງານທີ່ຕ້ອງການລຶບ';
        } elseif ($employeeId === $userId) {
            $_SESSION['flash_error'] = 'ບໍ່ສາມາດລຶບບັນຊີຜູ້ດູແລທີ່ກຳລັງໃຊ້ງານຢູ່ໄດ້';
        } else {
            $referenceStmt = $pdo->prepare(
                'SELECT
                    (SELECT COUNT(*) FROM leave_requests WHERE user_id = :id1 OR manager_id = :id2 OR handover_to_user_id = :id3) AS leave_requests_count,
                    (SELECT COUNT(*) FROM leave_balances WHERE user_id = :id4) AS leave_balances_count,
                    (SELECT COUNT(*) FROM notifications WHERE user_id = :id5) AS notifications_count,
                    (SELECT COUNT(*) FROM audit_logs WHERE user_id = :id6) AS audit_logs_count'
            );
            $referenceStmt->execute([
                'id1' => $employeeId,
                'id2' => $employeeId,
                'id3' => $employeeId,
                'id4' => $employeeId,
                'id5' => $employeeId,
                'id6' => $employeeId,
            ]);
            $references = $referenceStmt->fetch();

            $totalReferences = array_sum(array_map('intval', $references ?: []));

            if ($totalReferences > 0) {
                $_SESSION['flash_error'] = 'ບໍ່ສາມາດລຶບພະນັກງານຄົນນີ້ໄດ້ ເນື່ອງຈາກມີຂໍ້ມູນທີ່ອ້າງອີງຢູ່ໃນລະບົບ';
            } else {
                $deleteStmt = $pdo->prepare('DELETE FROM users WHERE id = :id LIMIT 1');
                $deleteStmt->execute(['id' => $employeeId]);
                $_SESSION['flash_success'] = 'ລຶບຂໍ້ມູນພະນັກງານສຳເລັດແລ້ວ';
            }
        }

        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        header('Location: ' . leaveContextUrl('employees.php'));
        exit;
    }
}

if ($formValues['employee_code'] === '') {
    $formValues['employee_code'] = employeeCodeFromName($pdo);
}

$employeesStmt = $pdo->prepare(
    'SELECT u.id, u.employee_code, u.first_name, u.last_name, u.email, u.phone, u.role, u.status,
            u.department_id, u.position_id, u.manager_id, u.start_date,
            d.name AS department_name, p.name AS position_name,
            CONCAT(COALESCE(m.first_name, ""), " ", COALESCE(m.last_name, "")) AS manager_name
     FROM users u
     LEFT JOIN departments d ON d.id = u.department_id
     LEFT JOIN positions p ON p.id = u.position_id
     LEFT JOIN users m ON m.id = u.manager_id
     ORDER BY u.id'
);
$employeesStmt->execute();
$employees = $employeesStmt->fetchAll();

require __DIR__ . '/../views/layouts/header.php';
require __DIR__ . '/../views/layouts/sidebar.php';
?>
<div class="app-main-wrapper">
    <?php require __DIR__ . '/../views/layouts/topbar.php'; ?>
    <main class="app-content">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <p class="text-secondary mb-1">ຂໍ້ມູນພະນັກງານ ພະແນກ ຕຳແໜ່ງ ຫົວໜ້າ ແລະ ສະຖານະບັນຊີ</p>
                <h2 class="h4 fw-bold mb-0">ຈັດການພະນັກງານ</h2>
            </div>
            <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#employeeFormModal">
                <i class="fa-solid fa-user-plus me-2"></i>ເພີ່ມພະນັກງານ
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
                    <h3 class="card-title">ພະນັກງານທັງໝົດ</h3>
                    <div class="card-subtitle"><?= h((string) count($employees)) ?> ລາຍການ</div>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table align-middle app-data-table employees-table" data-datatable="true" data-page-size="10" data-search-placeholder="ຄົ້ນຫາຊື່ ອີເມວ Role ຫຼື ພະແນກ" data-empty-message="ຍັງບໍ່ມີຂໍ້ມູນພະນັກງານ">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th width="20%">ຊື່</th>
                            <th>ອີເມວ</th>
                            <th>ບົດບາດ</th>
                            <th width="15%">ພະແນກ</th>
                            <th>ຕຳແໜ່ງ</th>
                            <th>ສະຖານະ</th>
                            <th>ຈັດການ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($employees as $index => $employee): ?>
                            <?php
                            $fullName = trim((string) $employee['first_name'] . ' ' . (string) $employee['last_name']);
                            $toggleStatus = $employee['status'] === 'active' ? 'inactive' : 'active';
                            ?>
                            <tr>
                                <td>
                                    <span class="employee-code"><?= h((string) ($index + 1)) ?></span>
                                </td>
                                <td>
                                    <div class="employee-cell">
                                        <div class="avatar"><?= h((string) mb_substr($employee['first_name'], 0, 1, 'UTF-8')) ?></div>
                                        <div class="employee-meta">
                                            <strong><?= h($fullName) ?></strong>
                                            <span>ຫົວໜ້າ: <?= h((string) ($employee['manager_name'] !== '' ? $employee['manager_name'] : 'ບໍ່ລະບຸ')) ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="employee-email"><?= h($employee['email']) ?></span></td>
                                <td><span class="badge text-bg-primary"><?= h($employee['role']) ?></span></td>
                                <td><span class="employee-department"><?= h($employee['department_name'] ?? '-') ?></span></td>
                                <td><span class="employee-position"><?= h($employee['position_name'] ?? '-') ?></span></td>
                                <td><span class="badge text-bg-<?= $employee['status'] === 'active' ? 'success' : 'secondary' ?>"><?= $employee['status'] === 'active' ? 'ເປີດໃຊ້ງານ' : 'ປິດໃຊ້ງານ' ?></span></td>
                                <td>
                                    <div class="approval-actions employee-actions">
                                        <button
                                            class="btn btn-sm btn-link text-primary employee-icon-btn js-edit-employee"
                                            type="button"
                                            data-bs-toggle="modal"
                                            data-bs-target="#employeeFormModal"
                                            title="ແກ້ໄຂຂໍ້ມູນ"
                                            aria-label="ແກ້ໄຂຂໍ້ມູນ"
                                            data-id="<?= h((string) $employee['id']) ?>"
                                            data-employee_code="<?= h($employee['employee_code']) ?>"
                                            data-first_name="<?= h($employee['first_name']) ?>"
                                            data-last_name="<?= h($employee['last_name']) ?>"
                                            data-email="<?= h($employee['email']) ?>"
                                            data-phone="<?= h((string) ($employee['phone'] ?? '')) ?>"
                                            data-role="<?= h($employee['role']) ?>"
                                            data-department_id="<?= h((string) ($employee['department_id'] ?? '')) ?>"
                                            data-position_id="<?= h((string) ($employee['position_id'] ?? '')) ?>"
                                            data-manager_id="<?= h((string) ($employee['manager_id'] ?? '')) ?>"
                                            data-start_date="<?= h((string) ($employee['start_date'] ?? '')) ?>"
                                            data-status="<?= h($employee['status']) ?>"
                                        >
                                            <i class="fa-solid fa-pen-to-square"></i>
                                        </button>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="employee_id" value="<?= h((string) $employee['id']) ?>">
                                            <input type="hidden" name="new_status" value="<?= h($toggleStatus) ?>">
                                            <button
                                                class="btn btn-sm btn-link text-warning employee-icon-btn"
                                                type="submit"
                                                title="<?= $employee['status'] === 'active' ? 'ປິດໃຊ້ງານ' : 'ເປີດໃຊ້ງານ' ?>"
                                                aria-label="<?= $employee['status'] === 'active' ? 'ປິດໃຊ້ງານ' : 'ເປີດໃຊ້ງານ' ?>"
                                            >
                                                <i class="fa-solid fa-power-off"></i>
                                            </button>
                                        </form>
                                        <form method="post" class="d-inline" onsubmit="return confirm('ຢືນຢັນການລຶບພະນັກງານຄົນນີ້?');">
                                            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="employee_id" value="<?= h((string) $employee['id']) ?>">
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
<div class="modal fade" id="employeeFormModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <form method="post" novalidate>
                <div class="modal-header">
                    <div>
                        <h3 class="modal-title h5 mb-1" id="employeeModalTitle"><?= $formMode === 'edit' ? 'ແກ້ໄຂຂໍ້ມູນພະນັກງານ' : 'ເພີ່ມພະນັກງານໃໝ່' ?></h3>
                        <div class="text-secondary small">ຈັດການຂໍ້ມູນບັນຊີ ພະແນກ ຕຳແໜ່ງ ແລະ ສິດການໃຊ້ງານ</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ປິດ"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" id="employeeFormAction" value="<?= $formMode === 'edit' ? 'update' : 'create' ?>">
                    <input type="hidden" name="employee_id" id="employeeId" value="<?= h((string) ($editingEmployeeId ?? '')) ?>">

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">ລະຫັດພະນັກງານ</label>
                            <input class="form-control" type="text" name="employee_code" id="employeeCode" value="<?= h($formValues['employee_code']) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">ຊື່</label>
                            <input class="form-control" type="text" name="first_name" id="firstName" value="<?= h($formValues['first_name']) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">ນາມສະກຸນ</label>
                            <input class="form-control" type="text" name="last_name" id="lastName" value="<?= h($formValues['last_name']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">ອີເມວ</label>
                            <input class="form-control" type="email" name="email" id="email" value="<?= h($formValues['email']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">ເບີໂທ</label>
                            <input class="form-control" type="text" name="phone" id="phone" value="<?= h($formValues['phone']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">ລະຫັດຜ່ານ</label>
                            <input class="form-control" type="password" name="password" id="password" placeholder="<?= $formMode === 'edit' ? 'ວ່າງໄວ້ຖ້າບໍ່ຕ້ອງການປ່ຽນ' : 'ຢ່າງໜ້ອຍ 8 ຕົວອັກສອນ' ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">ບົດບາດ</label>
                            <select class="form-select" name="role" id="role">
                                <?php foreach ($roleOptions as $value => $label): ?>
                                    <option value="<?= h($value) ?>" <?= $formValues['role'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">ສະຖານະ</label>
                            <select class="form-select" name="status" id="status">
                                <?php foreach ($statusOptions as $value => $label): ?>
                                    <option value="<?= h($value) ?>" <?= $formValues['status'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">ພະແນກ</label>
                            <select class="form-select" name="department_id" id="departmentId">
                                <option value="">ບໍ່ລະບຸ</option>
                                <?php foreach ($departments as $department): ?>
                                    <option value="<?= h((string) $department['id']) ?>" <?= $formValues['department_id'] === (string) $department['id'] ? 'selected' : '' ?>>
                                        <?= h($department['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">ຕຳແໜ່ງ</label>
                            <select class="form-select" name="position_id" id="positionId">
                                <option value="">ບໍ່ລະບຸ</option>
                                <?php foreach ($positions as $position): ?>
                                    <option value="<?= h((string) $position['id']) ?>" <?= $formValues['position_id'] === (string) $position['id'] ? 'selected' : '' ?>>
                                        <?= h($position['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">ຫົວໜ້າ</label>
                            <select class="form-select" name="manager_id" id="managerId">
                                <option value="">ບໍ່ລະບຸ</option>
                                <?php foreach ($managerOptions as $manager): ?>
                                    <option value="<?= h((string) $manager['id']) ?>" <?= $formValues['manager_id'] === (string) $manager['id'] ? 'selected' : '' ?>>
                                        <?= h(trim($manager['first_name'] . ' ' . $manager['last_name'])) ?> (<?= h($manager['role']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">ວັນທີເລີ່ມງານ</label>
                            <input class="form-control" type="date" name="start_date" id="startDate" value="<?= h($formValues['start_date']) ?>">
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
        const modalElement = document.getElementById('employeeFormModal');
        if (!modalElement) {
            return;
        }

        const formActionInput = document.getElementById('employeeFormAction');
        const modalTitle = document.getElementById('employeeModalTitle');
        const employeeIdInput = document.getElementById('employeeId');
        const fields = {
            employee_code: document.getElementById('employeeCode'),
            first_name: document.getElementById('firstName'),
            last_name: document.getElementById('lastName'),
            email: document.getElementById('email'),
            phone: document.getElementById('phone'),
            role: document.getElementById('role'),
            department_id: document.getElementById('departmentId'),
            position_id: document.getElementById('positionId'),
            manager_id: document.getElementById('managerId'),
            start_date: document.getElementById('startDate'),
            status: document.getElementById('status')
        };

        const defaults = {
            employee_code: '<?= h(employeeCodeFromName($pdo)) ?>',
            first_name: '',
            last_name: '',
            email: '',
            phone: '',
            role: 'employee',
            department_id: '',
            position_id: '',
            manager_id: '',
            start_date: '',
            status: 'active'
        };

        function fillForm(values, mode, employeeId = '') {
            formActionInput.value = mode === 'edit' ? 'update' : 'create';
            modalTitle.textContent = mode === 'edit' ? 'ແກ້ໄຂຂໍ້ມູນພະນັກງານ' : 'ເພີ່ມພະນັກງານໃໝ່';
            employeeIdInput.value = employeeId;

            Object.entries(fields).forEach(([key, element]) => {
                if (!element) {
                    return;
                }
                element.value = values[key] ?? '';
            });

            const passwordField = document.getElementById('password');
            if (passwordField) {
                passwordField.value = '';
                passwordField.placeholder = mode === 'edit' ? 'ວ່າງໄວ້ຖ້າບໍ່ຕ້ອງການປ່ຽນ' : 'ຢ່າງໜ້ອຍ 8 ຕົວອັກສອນ';
            }
        }

        document.querySelectorAll('.js-edit-employee').forEach((button) => {
            button.addEventListener('click', () => {
                fillForm(button.dataset, 'edit', button.dataset.id || '');
            });
        });

        modalElement.addEventListener('show.bs.modal', (event) => {
            if (event.relatedTarget?.classList.contains('js-edit-employee')) {
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
