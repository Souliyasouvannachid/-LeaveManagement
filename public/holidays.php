<?php
require __DIR__ . '/_auth.php';

if ($userRole !== 'admin') {
    http_response_code(403);
    exit('ທ່ານບໍ່ມີສິດເຂົ້າເຖິງໜ້າຈັດການວັນພັກ');
}

$pageTitle = 'ຈັດການວັນພັກ';
$activeMenu = 'holidays';
$appName = 'ລະບົບຈັດການການລາພັກ';
$useDataTables = true;
$statusOptions = [
    'active' => 'ເປີດໃຊ້ງານ',
    'inactive' => 'ປິດໃຊ້ງານ',
];
$typeOptions = [
    'public' => 'ວັນພັກລາຊະການ',
    'company' => 'ວັນພັກບໍລິສັດ',
    'special' => 'ວັນພັກພິເສດ',
];

function laoWeekday(string $date): string
{
    $days = [1 => 'ວັນຈັນ', 'ວັນອັງຄານ', 'ວັນພຸດ', 'ວັນພະຫັດ', 'ວັນສຸກ', 'ວັນເສົາ', 'ວັນອາທິດ'];
    return $days[(int) (new DateTimeImmutable($date))->format('N')];
}

function holidayInput(array $source, string $key, string $default = ''): string
{
    return trim((string) ($source[$key] ?? $default));
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$formErrors = [];
$formMode = 'create';
$editingHolidayId = null;
$formValues = [
    'name' => '',
    'holiday_date' => '',
    'type' => 'public',
    'status' => 'active',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if (!hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
        $_SESSION['flash_error'] = 'ຄຳຮ້ອງຂໍບໍ່ຖືກຕ້ອງ ກະລຸນາຣີເຟຣດໜ້າ ແລ້ວລອງໃໝ່';
        header('Location: ' . leaveContextUrl('holidays.php'));
        exit;
    }

    if (in_array($action, ['create', 'update'], true)) {
        $formMode = $action === 'update' ? 'edit' : 'create';
        $editingHolidayId = filter_input(INPUT_POST, 'holiday_id', FILTER_VALIDATE_INT) ?: null;
        $formValues = [
            'name' => holidayInput($_POST, 'name'),
            'holiday_date' => holidayInput($_POST, 'holiday_date'),
            'type' => holidayInput($_POST, 'type', 'public'),
            'status' => holidayInput($_POST, 'status', 'active'),
        ];

        if ($formValues['name'] === '') {
            $formErrors[] = 'ກະລຸນາລະບຸຊື່ວັນພັກ';
        }
        if ($formValues['holiday_date'] === '') {
            $formErrors[] = 'ກະລຸນາລະບຸວັນທີວັນພັກ';
        } else {
            $holidayDate = DateTimeImmutable::createFromFormat('Y-m-d', $formValues['holiday_date']);
            if (!$holidayDate || $holidayDate->format('Y-m-d') !== $formValues['holiday_date']) {
                $formErrors[] = 'ຮູບແບບວັນທີວັນພັກບໍ່ຖືກຕ້ອງ';
            }
        }
        if (!isset($typeOptions[$formValues['type']])) {
            $formErrors[] = 'ປະເພດວັນພັກບໍ່ຖືກຕ້ອງ';
        }
        if (!isset($statusOptions[$formValues['status']])) {
            $formErrors[] = 'ສະຖານະວັນພັກບໍ່ຖືກຕ້ອງ';
        }

        try {
            if (empty($formErrors)) {
                // ກວດສອບ 1: ກວດສອບວ່າ "ວັນທີ" ນີ້ຖືກນຳໃຊ້ໄປແລ້ວບໍ
                $checkDateStmt = $pdo->prepare(
                    'SELECT id, name FROM holidays 
                     WHERE holiday_date = :holiday_date 
                       AND (:current_id IS NULL OR id <> :current_id) 
                     LIMIT 1'
                );
                $checkDateStmt->execute([
                    'holiday_date' => $formValues['holiday_date'],
                    'current_id'   => $editingHolidayId,
                ]);
                $existingDate = $checkDateStmt->fetch();

                if ($existingDate) {
                    $formErrors[] = 'ວັນທີ ' . thaiDate($formValues['holiday_date']) . ' ຖືກກຳນົດເປັນວັນພັກ (' . h($existingDate['name']) . ') ຮຽບຮ້ອຍແລ້ວ';
                }

                // ກວດສອບ 2: ກວດສອບວ່າ "ຊື່ວັນພັກ" ນີ້ຊ້ຳກັນໃນປີດຽວກັນບໍ (ກວດສອບເພີ່ມເຕີມເພື່ອຄວາມແນ່ໃຈ)
                $holidayYear = (new DateTimeImmutable($formValues['holiday_date']))->format('Y');
                $checkNameStmt = $pdo->prepare(
                    'SELECT id FROM holidays 
                     WHERE name = :name 
                       AND STRFTIME("%Y", holiday_date) = :year 
                       AND (:current_id IS NULL OR id <> :current_id) 
                     LIMIT 1'
                );
                $checkNameStmt->execute([
                    'name'       => $formValues['name'],
                    'year'       => $holidayYear,
                    'current_id' => $editingHolidayId,
                ]);

                if ($checkNameStmt->fetch()) {
                    $formErrors[] = 'ຊື່ວັນພັກ "' . h($formValues['name']) . '" ມີຢູ່ແລ້ວໃນປີ ' . $holidayYear;
                }
            }

            if (empty($formErrors)) {
                $payload = [
                    'name' => $formValues['name'],
                    'holiday_date' => $formValues['holiday_date'],
                    'type' => $formValues['type'],
                    'status' => $formValues['status'],
                ];

                if ($action === 'create') {
                    $createStmt = $pdo->prepare(
                        'INSERT INTO holidays (name, holiday_date, type, status)
                         VALUES (:name, :holiday_date, :type, :status)'
                    );
                    $createStmt->execute($payload);
                    $_SESSION['flash_success'] = 'ເພີ່ມວັນພັກໃໝ່ສຳເລັດແລ້ວ';
                } else {
                    $payload['id'] = $editingHolidayId;
                    $updateStmt = $pdo->prepare(
                        'UPDATE holidays
                         SET name = :name,
                             holiday_date = :holiday_date,
                             type = :type,
                             status = :status
                         WHERE id = :id
                         LIMIT 1'
                    );
                    $updateStmt->execute($payload);
                    $_SESSION['flash_success'] = 'ອັບເດດຂໍ້ມູນວັນພັກສຳເລັດແລ້ວ';
                }

                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                header('Location: ' . leaveContextUrl('holidays.php'));
                exit;
            }
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            $formErrors[] = 'ບໍ່ສາມາດບັນທຶກຂໍ້ມູນວັນພັກໄດ້ ກະລຸນາລອງໃໝ່ອີກຄັ້ງ';
        }
    }

    if ($action === 'toggle_status') {
        $holidayId = filter_input(INPUT_POST, 'holiday_id', FILTER_VALIDATE_INT);
        $newStatus = (string) ($_POST['new_status'] ?? '');

        if (!$holidayId || !isset($statusOptions[$newStatus])) {
            $_SESSION['flash_error'] = 'ບໍ່ພົບວັນພັກທີ່ຕ້ອງການປ່ຽນສະຖານະ';
        } else {
            $toggleStmt = $pdo->prepare('UPDATE holidays SET status = :status WHERE id = :id LIMIT 1');
            $toggleStmt->execute([
                'status' => $newStatus,
                'id' => $holidayId,
            ]);
            $_SESSION['flash_success'] = $newStatus === 'active'
                ? 'ເປີດໃຊ້ງານວັນພັກສຳເລັດແລ້ວ'
                : 'ປິດໃຊ້ງານວັນພັກສຳເລັດແລ້ວ';
        }

        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        header('Location: ' . leaveContextUrl('holidays.php'));
        exit;
    }

    if ($action === 'delete') {
        $holidayId = filter_input(INPUT_POST, 'holiday_id', FILTER_VALIDATE_INT);

        if (!$holidayId) {
            $_SESSION['flash_error'] = 'ບໍ່ພົບວັນພັກທີ່ຕ້ອງການລຶບ';
        } else {
            $deleteStmt = $pdo->prepare('DELETE FROM holidays WHERE id = :id LIMIT 1');
            $deleteStmt->execute(['id' => $holidayId]);
            $_SESSION['flash_success'] = 'ລຶບວັນພັກສຳເລັດແລ້ວ';
        }

        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        header('Location: ' . leaveContextUrl('holidays.php'));
        exit;
    }
}

$holidaysStmt = $pdo->prepare(
    'SELECT h.id, h.name, h.holiday_date, h.type, h.status, h.updated_at
     FROM holidays h
     ORDER BY h.holiday_date ASC, h.name ASC'
);
$holidaysStmt->execute();
$holidays = $holidaysStmt->fetchAll();

require __DIR__ . '/../views/layouts/header.php';
require __DIR__ . '/../views/layouts/sidebar.php';
?>
<div class="app-main-wrapper">
    <?php require __DIR__ . '/../views/layouts/topbar.php'; ?>
    <main class="app-content">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <p class="text-secondary mb-1">ຈັດການວັນພັກລາຊະການ ວັນພັກບໍລິສັດ ແລະ ວັນພັກພິເສດທີ່ໃຊ້ຄຳນວນວັນລາ</p>
                <h2 class="h4 fw-bold mb-0">ຈັດການວັນພັກ</h2>
            </div>
            <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#holidayFormModal">
                <i class="fa-solid fa-calendar-plus me-2"></i>ເພີ່ມວັນພັກ
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
                    <h3 class="card-title">ວັນພັກທັງໝົດ</h3>
                    <div class="card-subtitle"><?= h((string) count($holidays)) ?> ລາຍການ</div>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table align-middle app-data-table holidays-table" data-datatable="true" data-page-size="10" data-search-placeholder="ຄົ້ນຫາຊື່ວັນພັກ ປະເພດ ຫຼື ວັນທີ" data-empty-message="ຍັງບໍ່ມີຂໍ້ມູນວັນພັກ">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>ວັນພັກ</th>
                            <th>ວັນທີ</th>
                            <th>ປະເພດ</th>
                            <th>ສະຖານະ</th>
                            <th>ອັບເດດຫຼ້າສຸດ</th>
                            <th width="10%">ຈັດການ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($holidays as $index => $holiday): ?>
                            <?php
                            $toggleStatus = $holiday['status'] === 'active' ? 'inactive' : 'active';
                            $typeClass = match ($holiday['type']) {
                                'public' => 'is-on',
                                'company' => 'is-neutral',
                                default => 'is-warn',
                            };
                            ?>
                            <tr>
                                <td><span class="employee-code"><?= h((string) ($index + 1)) ?></span></td>
                                <td>
                                    <div class="employee-meta">
                                        <strong><?= h($holiday['name']) ?></strong>
                                        <span><?= h($typeOptions[$holiday['type']] ?? $holiday['type']) ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="employee-meta">
                                        <strong><?= h(thaiDate((string) $holiday['holiday_date'])) ?></strong>
                                        <span><?= h(laoWeekday((string) $holiday['holiday_date'])) ?></span>
                                    </div>
                                </td>
                                <td><span class="leave-type-pill <?= h($typeClass) ?>"><?= h($typeOptions[$holiday['type']] ?? $holiday['type']) ?></span></td>
                                <td><span class="badge text-bg-<?= $holiday['status'] === 'active' ? 'success' : 'secondary' ?>"><?= $holiday['status'] === 'active' ? 'ເປີດໃຊ້ງານ' : 'ປິດໃຊ້ງານ' ?></span></td>
                                <td><?= h(thaiDate((string) $holiday['updated_at'])) ?></td>
                                <td>
                                    <div class="approval-actions employee-actions">
                                        <button
                                            class="btn btn-sm btn-link text-primary employee-icon-btn js-edit-holiday"
                                            type="button"
                                            data-bs-toggle="modal"
                                            data-bs-target="#holidayFormModal"
                                            title="ແກ້ໄຂຂໍ້ມູນ"
                                            aria-label="ແກ້ໄຂຂໍ້ມູນ"
                                            data-id="<?= h((string) $holiday['id']) ?>"
                                            data-name="<?= h($holiday['name']) ?>"
                                            data-holiday_date="<?= h((string) $holiday['holiday_date']) ?>"
                                            data-type="<?= h($holiday['type']) ?>"
                                            data-status="<?= h($holiday['status']) ?>"
                                        >
                                            <i class="fa-solid fa-pen-to-square"></i>
                                        </button>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="holiday_id" value="<?= h((string) $holiday['id']) ?>">
                                            <input type="hidden" name="new_status" value="<?= h($toggleStatus) ?>">
                                            <button
                                                class="btn btn-sm btn-link text-warning employee-icon-btn"
                                                type="submit"
                                                title="<?= $holiday['status'] === 'active' ? 'ປິດໃຊ້ງານ' : 'ເປີດໃຊ້ງານ' ?>"
                                                aria-label="<?= $holiday['status'] === 'active' ? 'ປິດໃຊ້ງານ' : 'ເປີດໃຊ້ງານ' ?>"
                                            >
                                                <i class="fa-solid fa-power-off"></i>
                                            </button>
                                        </form>
                                        <form method="post" class="d-inline" onsubmit="return confirm('ຢືນຢັນການລຶບວັນພັກນີ້?');">
                                            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="holiday_id" value="<?= h((string) $holiday['id']) ?>">
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

<div class="modal fade" id="holidayFormModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <form method="post" novalidate>
                <div class="modal-header">
                    <div>
                        <h3 class="modal-title h5 mb-1" id="holidayModalTitle"><?= $formMode === 'edit' ? 'ແກ້ໄຂຂໍ້ມູນວັນພັກ' : 'ເພີ່ມວັນພັກໃໝ່' ?></h3>
                        <div class="text-secondary small">ກຳນົດຊື່ວັນພັກ ວັນທີ ປະເພດ ແລະ ສະຖານະການໃຊ້ງານໃນລະບົບ</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ປິດ"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" id="holidayFormAction" value="<?= $formMode === 'edit' ? 'update' : 'create' ?>">
                    <input type="hidden" name="holiday_id" id="holidayId" value="<?= h((string) ($editingHolidayId ?? '')) ?>">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="holidayName">ຊື່ວັນພັກ <span class="text-danger">*</span></label>
                            <input class="form-control" type="text" name="name" id="holidayName" value="<?= h($formValues['name']) ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="holidayDate">ວັນທີ <span class="text-danger">*</span></label>
                            <input class="form-control" type="date" name="holiday_date" id="holidayDate" value="<?= h($formValues['holiday_date']) ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="holidayType">ປະເພດ</label>
                            <select class="form-select" name="type" id="holidayType">
                                <?php foreach ($typeOptions as $value => $label): ?>
                                    <option value="<?= h($value) ?>" <?= $formValues['type'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="holidayStatus">ສະຖານະ</label>
                            <select class="form-select" name="status" id="holidayStatus">
                                <?php foreach ($statusOptions as $value => $label): ?>
                                    <option value="<?= h($value) ?>" <?= $formValues['status'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
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
        const modalElement = document.getElementById('holidayFormModal');
        if (!modalElement) {
            return;
        }

        const formActionInput = document.getElementById('holidayFormAction');
        const modalTitle = document.getElementById('holidayModalTitle');
        const holidayIdInput = document.getElementById('holidayId');
        const fields = {
            name: document.getElementById('holidayName'),
            holiday_date: document.getElementById('holidayDate'),
            type: document.getElementById('holidayType'),
            status: document.getElementById('holidayStatus')
        };

        const defaults = {
            name: '',
            holiday_date: '',
            type: 'public',
            status: 'active'
        };

        function fillForm(values, mode, holidayId = '') {
            formActionInput.value = mode === 'edit' ? 'update' : 'create';
            modalTitle.textContent = mode === 'edit' ? 'ແກ້ໄຂຂໍ້ມູນວັນພັກ' : 'ເພີ່ມວັນພັກໃໝ່';
            holidayIdInput.value = holidayId;

            Object.entries(fields).forEach(([key, element]) => {
                if (!element) {
                    return;
                }
                element.value = values[key] ?? '';
            });
        }

        document.querySelectorAll('.js-edit-holiday').forEach((button) => {
            button.addEventListener('click', () => {
                fillForm(button.dataset, 'edit', button.dataset.id || '');
            });
        });

        modalElement.addEventListener('show.bs.modal', (event) => {
            if (event.relatedTarget?.classList.contains('js-edit-holiday')) {
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
