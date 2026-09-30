<?php
require __DIR__ . '/_auth.php';

if ($userRole !== 'admin') {
    http_response_code(403);
    exit('ທ່ານບໍ່ມີສິດເຂົ້າເຖິງໜ້າຈັດການພະແນກ');
}

$pageTitle = 'ຈັດການພະແນກ';
$activeMenu = 'departments';
$appName = 'ລະບົບຈັດການການລາພັກ';
$useDataTables = true;
$statusOptions = [
    'active' => 'ເປີດໃຊ້ງານ',
    'inactive' => 'ປິດໃຊ້ງານ',
];

function departmentInput(array $source, string $key, string $default = ''): string
{
    return trim((string) ($source[$key] ?? $default));
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$formErrors = [];
$formMode = 'create';
$editingDepartmentId = null;
$formValues = [
    'name' => '',
    'description' => '',
    'status' => 'active',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if (!hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
        $_SESSION['flash_error'] = 'ຄຳຮ້ອງຂໍບໍ່ຖືກຕ້ອງ ກະລຸນາຣີເຟຣດໜ້າ ແລ້ວລອງໃໝ່';
        header('Location: ' . leaveContextUrl('departments.php'));
        exit;
    }

    if (in_array($action, ['create', 'update'], true)) {
        $formMode = $action === 'update' ? 'edit' : 'create';
        $editingDepartmentId = filter_input(INPUT_POST, 'department_id', FILTER_VALIDATE_INT) ?: null;
        $formValues = [
            'name' => departmentInput($_POST, 'name'),
            'description' => departmentInput($_POST, 'description'),
            'status' => departmentInput($_POST, 'status', 'active'),
        ];

        if ($formValues['name'] === '') {
            $formErrors[] = 'ກະລຸນາລະບຸຊື່ພະແນກ';
        }
        if (!isset($statusOptions[$formValues['status']])) {
            $formErrors[] = 'ສະຖານະພະແນກບໍ່ຖືກຕ້ອງ';
        }

        try {
            if (empty($formErrors)) {
                $duplicateStmt = $pdo->prepare(
                    'SELECT id
                     FROM departments
                     WHERE name = :name
                       AND (:current_id_a IS NULL OR id <> :current_id_b)
                     LIMIT 1'
                );
                $duplicateStmt->execute([
                    'name' => $formValues['name'],
                    'current_id_a' => $editingDepartmentId,
                    'current_id_b' => $editingDepartmentId,
                ]);

                if ($duplicateStmt->fetch()) {
                    $formErrors[] = 'ຊື່ພະແນກນີ້ມີຢູ່ໃນລະບົບແລ້ວ';
                }
            }

            if (empty($formErrors)) {
                if ($action === 'create') {
                    $createStmt = $pdo->prepare(
                        'INSERT INTO departments (name, description, status)
                         VALUES (:name, :description, :status)'
                    );
                    $createStmt->execute([
                        'name' => $formValues['name'],
                        'description' => $formValues['description'] !== '' ? $formValues['description'] : null,
                        'status' => $formValues['status'],
                    ]);
                    $_SESSION['flash_success'] = 'ເພີ່ມພະແນກໃໝ່ສຳເລັດແລ້ວ';
                } else {
                    $updateStmt = $pdo->prepare(
                        'UPDATE departments
                         SET name = :name,
                             description = :description,
                             status = :status
                         WHERE id = :id
                         LIMIT 1'
                    );
                    $updateStmt->execute([
                        'name' => $formValues['name'],
                        'description' => $formValues['description'] !== '' ? $formValues['description'] : null,
                        'status' => $formValues['status'],
                        'id' => $editingDepartmentId,
                    ]);
                    $_SESSION['flash_success'] = 'ອັບເດດຂໍ້ມູນພະແນກສຳເລັດແລ້ວ';
                }

                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                header('Location: ' . leaveContextUrl('departments.php'));
                exit;
            }
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            $formErrors[] = 'ບໍ່ສາມາດບັນທຶກຂໍ້ມູນພະແນກໄດ້ ກະລຸນາລອງໃໝ່ອີກຄັ້ງ';
        }
    }

    if ($action === 'toggle_status') {
        $departmentId = filter_input(INPUT_POST, 'department_id', FILTER_VALIDATE_INT);
        $newStatus = (string) ($_POST['new_status'] ?? '');

        if (!$departmentId || !isset($statusOptions[$newStatus])) {
            $_SESSION['flash_error'] = 'ບໍ່ພົບພະແນກທີ່ຕ້ອງການປ່ຽນສະຖານະ';
        } else {
            $toggleStmt = $pdo->prepare('UPDATE departments SET status = :status WHERE id = :id LIMIT 1');
            $toggleStmt->execute([
                'status' => $newStatus,
                'id' => $departmentId,
            ]);
            $_SESSION['flash_success'] = $newStatus === 'active' ? 'ເປີດໃຊ້ງານພະແນກສຳເລັດແລ້ວ' : 'ປິດໃຊ້ງານພະແນກສຳເລັດແລ້ວ';
        }

        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        header('Location: ' . leaveContextUrl('departments.php'));
        exit;
    }

    if ($action === 'delete') {
        $departmentId = filter_input(INPUT_POST, 'department_id', FILTER_VALIDATE_INT);

        if (!$departmentId) {
            $_SESSION['flash_error'] = 'ບໍ່ພົບພະແນກທີ່ຕ້ອງການລຶບ';
        } else {
            $referenceStmt = $pdo->prepare(
                'SELECT
                    (SELECT COUNT(*) FROM users WHERE department_id = :id1) AS users_count,
                    (SELECT COUNT(*) FROM positions WHERE department_id = :id2) AS positions_count'
            );
            $referenceStmt->execute([
                'id1' => $departmentId,
                'id2' => $departmentId,
            ]);
            $references = $referenceStmt->fetch();
            $totalReferences = array_sum(array_map('intval', $references ?: []));

            if ($totalReferences > 0) {
                $_SESSION['flash_error'] = 'ບໍ່ສາມາດລຶບພະແນກນີ້ໄດ້ ເນື່ອງຈາກຍັງມີຕຳແໜ່ງ ຫຼື ພະນັກງານທີ່ອ້າງອີງຢູ່';
            } else {
                $deleteStmt = $pdo->prepare('DELETE FROM departments WHERE id = :id LIMIT 1');
                $deleteStmt->execute(['id' => $departmentId]);
                $_SESSION['flash_success'] = 'ລຶບຂໍ້ມູນພະແນກສຳເລັດແລ້ວ';
            }
        }

        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        header('Location: ' . leaveContextUrl('departments.php'));
        exit;
    }
}

$departmentsStmt = $pdo->prepare(
    'SELECT d.id, d.name, d.description, d.status, d.updated_at,
            (SELECT COUNT(*) FROM users u WHERE u.department_id = d.id) AS users_count,
            (SELECT COUNT(*) FROM positions p WHERE p.department_id = d.id) AS positions_count
     FROM departments d
     ORDER BY d.name'
);
$departmentsStmt->execute();
$departments = $departmentsStmt->fetchAll();

require __DIR__ . '/../views/layouts/header.php';
require __DIR__ . '/../views/layouts/sidebar.php';
?>
<div class="app-main-wrapper">
    <?php require __DIR__ . '/../views/layouts/topbar.php'; ?>
    <main class="app-content">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <p class="text-secondary mb-1">ຈັດການຂໍ້ມູນພະແນກ ໂຄງສ້າງຫົວໜ່ວຍງານ ແລະ ສະຖານະການໃຊ້ງານ</p>
                <h2 class="h4 fw-bold mb-0">ຈັດການພະແນກ</h2>
            </div>
            <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#departmentFormModal">
                <i class="fa-solid fa-building-circle-check me-2"></i>ເພີ່ມພະແນກ
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
                    <h3 class="card-title">ພະແນກທັງໝົດ</h3>
                    <div class="card-subtitle"><?= h((string) count($departments)) ?> ລາຍການ</div>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table align-middle app-data-table departments-table" data-datatable="true" data-page-size="10" data-search-placeholder="ຄົ້ນຫາຊື່ພະແນກ ຫຼື ລາຍລະອຽດ" data-empty-message="ຍັງບໍ່ມີຂໍ້ມູນພະແນກ">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>ຊື່ພະແນກ</th>
                            <th>ລາຍລະອຽດ</th>
                            <th>ພະນັກງານ</th>
                            <th>ຕຳແໜ່ງ</th>
                            <th>ສະຖານະ</th>
                            <th>ອັບເດດຫຼ້າສຸດ</th>
                            <th>ຈັດການ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($departments as $index => $department): ?>
                            <?php $toggleStatus = $department['status'] === 'active' ? 'inactive' : 'active'; ?>
                            <tr>
                                <td><span class="employee-code"><?= h((string) ($index + 1)) ?></span></td>
                                <td>
                                    <div class="employee-meta">
                                        <strong><?= h($department['name']) ?></strong>
                                    </div>
                                </td>
                                <td><span class="employee-position"><?= h((string) ($department['description'] ?: '-')) ?></span></td>
                                <td><span class="badge text-bg-light text-dark"><?= h((string) $department['users_count']) ?> ຄົນ</span></td>
                                <td><span class="badge text-bg-light text-dark"><?= h((string) $department['positions_count']) ?> ຕຳແໜ່ງ</span></td>
                                <td><span class="badge text-bg-<?= $department['status'] === 'active' ? 'success' : 'secondary' ?>"><?= $department['status'] === 'active' ? 'ເປີດໃຊ້ງານ' : 'ປິດໃຊ້ງານ' ?></span></td>
                                <td><?= h(thaiDate((string) $department['updated_at'])) ?></td>
                                <td>
                                    <div class="approval-actions employee-actions">
                                        <button
                                            class="btn btn-sm btn-link text-primary employee-icon-btn js-edit-department"
                                            type="button"
                                            data-bs-toggle="modal"
                                            data-bs-target="#departmentFormModal"
                                            title="ແກ້ໄຂຂໍ້ມູນ"
                                            aria-label="ແກ້ໄຂຂໍ້ມູນ"
                                            data-id="<?= h((string) $department['id']) ?>"
                                            data-name="<?= h($department['name']) ?>"
                                            data-description="<?= h((string) ($department['description'] ?? '')) ?>"
                                            data-status="<?= h($department['status']) ?>"
                                        >
                                            <i class="fa-solid fa-pen-to-square"></i>
                                        </button>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="department_id" value="<?= h((string) $department['id']) ?>">
                                            <input type="hidden" name="new_status" value="<?= h($toggleStatus) ?>">
                                            <button
                                                class="btn btn-sm btn-link text-warning employee-icon-btn"
                                                type="submit"
                                                title="<?= $department['status'] === 'active' ? 'ປິດໃຊ້ງານ' : 'ເປີດໃຊ້ງານ' ?>"
                                                aria-label="<?= $department['status'] === 'active' ? 'ປິດໃຊ້ງານ' : 'ເປີດໃຊ້ງານ' ?>"
                                            >
                                                <i class="fa-solid fa-power-off"></i>
                                            </button>
                                        </form>
                                        <form method="post" class="d-inline" onsubmit="return confirm('ຢືນຢັນການລຶບພະແນກນີ້?');">
                                            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="department_id" value="<?= h((string) $department['id']) ?>">
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

<div class="modal fade" id="departmentFormModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <form method="post" novalidate>
                <div class="modal-header">
                    <div>
                        <h3 class="modal-title h5 mb-1" id="departmentModalTitle"><?= $formMode === 'edit' ? 'ແກ້ໄຂຂໍ້ມູນພະແນກ' : 'ເພີ່ມພະແນກໃໝ່' ?></h3>
                        <div class="text-secondary small">ຈັດການຊື່ພະແນກ ລາຍລະອຽດ ແລະ ສະຖານະການໃຊ້ງານ</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ປິດ"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" id="departmentFormAction" value="<?= $formMode === 'edit' ? 'update' : 'create' ?>">
                    <input type="hidden" name="department_id" id="departmentId" value="<?= h((string) ($editingDepartmentId ?? '')) ?>">

                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label">ຊື່ພະແນກ</label>
                            <input class="form-control" type="text" name="name" id="departmentName" value="<?= h($formValues['name']) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">ສະຖານະ</label>
                            <select class="form-select" name="status" id="departmentStatus">
                                <?php foreach ($statusOptions as $value => $label): ?>
                                    <option value="<?= h($value) ?>" <?= $formValues['status'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">ລາຍລະອຽດ</label>
                            <textarea class="form-control" name="description" id="departmentDescription" rows="4" placeholder="ອະທິບາຍຂອບເຂດວຽກ ຫຼື ບົດບາດຂອງພະແນກ"><?= h($formValues['description']) ?></textarea>
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
        const modalElement = document.getElementById('departmentFormModal');
        if (!modalElement) {
            return;
        }

        const formActionInput = document.getElementById('departmentFormAction');
        const modalTitle = document.getElementById('departmentModalTitle');
        const departmentIdInput = document.getElementById('departmentId');
        const fields = {
            name: document.getElementById('departmentName'),
            description: document.getElementById('departmentDescription'),
            status: document.getElementById('departmentStatus')
        };

        const defaults = {
            name: '',
            description: '',
            status: 'active'
        };

        function fillForm(values, mode, departmentId = '') {
            formActionInput.value = mode === 'edit' ? 'update' : 'create';
            modalTitle.textContent = mode === 'edit' ? 'ແກ້ໄຂຂໍ້ມູນພະແນກ' : 'ເພີ່ມພະແນກໃໝ່';
            departmentIdInput.value = departmentId;

            Object.entries(fields).forEach(([key, element]) => {
                if (!element) {
                    return;
                }
                element.value = values[key] ?? '';
            });
        }

        document.querySelectorAll('.js-edit-department').forEach((button) => {
            button.addEventListener('click', () => {
                fillForm(button.dataset, 'edit', button.dataset.id || '');
            });
        });

        modalElement.addEventListener('show.bs.modal', (event) => {
            if (event.relatedTarget?.classList.contains('js-edit-department')) {
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
