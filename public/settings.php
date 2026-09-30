<?php
require __DIR__ . '/_auth.php';

const LOGIN_BACKGROUND_DIR = __DIR__ . '/uploads/login_backgrounds';
const LOGIN_BACKGROUND_WEB_PATH = 'uploads/login_backgrounds';
const MAX_LOGIN_BACKGROUND_BYTES = 5242880;

if ($userRole !== 'admin') {
    http_response_code(403);
    exit('ທ່ານບໍ່ມີສິດເຂົ້າເຖິງໜ້າຕັ້ງຄ່າລະບົບ');
}

$successMsg = '';
$errorMsg = '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function saveLoginBackground(array $file, array &$errors): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $errors[] = 'ກະລຸນາເລືອກຮູບສຳລັບໜ້າເຂົ້າສູ່ລະບົບ';
        return null;
    }

    $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_OK);
    if ($uploadError !== UPLOAD_ERR_OK) {
        $errors[] = $uploadError === UPLOAD_ERR_INI_SIZE
            ? 'ຮູບມີຂະໜາດເກີນ 5MB'
            : 'ບໍ່ສາມາດອັບໂຫຼດຮູບໜ້າເຂົ້າສູ່ລະບົບໄດ້';
        return null;
    }

    if (($file['size'] ?? 0) <= 0 || ($file['size'] ?? 0) > MAX_LOGIN_BACKGROUND_BYTES) {
        $errors[] = 'ຮູບຕ້ອງມີຂະໜາດບໍ່ເກີນ 5MB';
        return null;
    }

    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $allowedMimeTypes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
    if (($allowedMimeTypes[$extension] ?? null) !== $mimeType) {
        $errors[] = 'ຮອງຮັບສະເພາະ JPG, PNG ຫຼື WebP';
        return null;
    }

    if (!is_dir(LOGIN_BACKGROUND_DIR) && !mkdir(LOGIN_BACKGROUND_DIR, 0775, true) && !is_dir(LOGIN_BACKGROUND_DIR)) {
        $errors[] = 'ບໍ່ສາມາດຈັດກຽມພື້ນທີ່ເກັບຮູບໄດ້';
        return null;
    }

    $fileName = bin2hex(random_bytes(16)) . '.' . $extension;
    if (!move_uploaded_file((string) $file['tmp_name'], LOGIN_BACKGROUND_DIR . DIRECTORY_SEPARATOR . $fileName)) {
        $errors[] = 'ບໍ່ສາມາດບັນທຶກຮູບໄດ້';
        return null;
    }

    return LOGIN_BACKGROUND_WEB_PATH . '/' . $fileName;
}

function deleteLoginBackground(?string $path): void
{
    $fileName = basename((string) $path);
    if (preg_match('/^[a-f0-9]{32}\.(jpe?g|png|webp)$/i', $fileName)) {
        @unlink(LOGIN_BACKGROUND_DIR . DIRECTORY_SEPARATOR . $fileName);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
        $errorMsg = 'ຄຳຮ້ອງຂໍບໍ່ຖືກຕ້ອງ ກະລຸນາຣີເຟຣຊໜ້າແລ້ວລອງໃໝ່';
    } elseif ($action === 'update_setting') {
        $settingKey = (string) ($_POST['setting_key'] ?? '');
        $settingValue = trim((string) ($_POST['setting_value'] ?? ''));
        $editableKeys = ['company_name', 'timezone', 'fiscal_year_start_month', 'workdays', 'exclude_weekends', 'max_upload_size_mb', 'allowed_upload_extensions'];
        if (!in_array($settingKey, $editableKeys, true)) {
            $errorMsg = 'ລາຍການຕັ້ງຄ່າບໍ່ຖືກຕ້ອງ';
        } else {
            $stmt = $pdo->prepare('UPDATE settings SET setting_value = :val, updated_at = NOW() WHERE setting_key = :key');
            $stmt->execute([':val' => $settingValue, ':key' => $settingKey]);
            $successMsg = 'ບັນທຶກການຕັ້ງຄ່າສຳເລັດແລ້ວ';
        }
    } elseif ($action === 'update_login_background') {
        $currentImageStmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = "login_background_image" LIMIT 1');
        $currentImageStmt->execute();
        $oldImagePath = (string) ($currentImageStmt->fetchColumn() ?: '');
        $removeImage = (string) ($_POST['remove_login_background'] ?? '') === '1';
        $uploadErrors = [];
        $newImagePath = $removeImage ? null : saveLoginBackground($_FILES['login_background'] ?? [], $uploadErrors);

        if (!empty($uploadErrors)) {
            $errorMsg = implode(' ', $uploadErrors);
        } else {
            $newValue = $removeImage ? '' : (string) $newImagePath;
            $upsertStmt = $pdo->prepare(
                'INSERT INTO settings (setting_key, setting_value, description, updated_at)
                 VALUES ("login_background_image", :value, "ຮູບພື້ນຫຼັງໜ້າເຂົ້າສູ່ລະບົບ", NOW())
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
            );
            $upsertStmt->execute(['value' => $newValue]);
            if ($oldImagePath !== '') {
                deleteLoginBackground($oldImagePath);
            }
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $successMsg = $removeImage ? 'ລຶບຮູບໜ້າເຂົ້າສູ່ລະບົບແລ້ວ' : 'ປ່ຽນຮູບໜ້າເຂົ້າສູ່ລະບົບແລ້ວ';
        }
    }
}

$pageTitle = 'ຕັ້ງຄ່າລະບົບ';
$activeMenu = 'settings';
$appName = 'ລະບົບຈັດການການລາພັກ';

// ດຶງຂໍ້ມູນຫຼ້າສຸດຫຼັງອັບເດດ
$settings = $pdo->query('SELECT setting_key, setting_value, description, updated_at FROM settings ORDER BY setting_key')->fetchAll();
$departments = $pdo->query('SELECT name, status FROM departments ORDER BY name')->fetchAll();
$leaveTypes = $pdo->query('SELECT name, code, annual_quota, status FROM leave_types ORDER BY id')->fetchAll();

$settingLabels = [
    'company_name' => 'ຊື່ບໍລິສັດ',
    'timezone' => 'ເຂດເວລາ',
    'fiscal_year_start_month' => 'ເດືອນເລີ່ມປີງົບປະມານ',
    'workdays' => 'ວັນເຮັດວຽກ',
    'exclude_weekends' => 'ບໍ່ນັບວັນທ້າຍອາທິດ',
    'max_upload_size_mb' => 'ຂະໜາດໄຟລ໌ແນບສູງສຸດ',
    'allowed_upload_extensions' => 'ປະເພດໄຟລ໌ແນບທີ່ອະນຸຍາດ',
    'login_background_image' => 'ຮູບໜ້າເຂົ້າສູ່ລະບົບ',
];
$settingsByKey = [];
foreach ($settings as $setting) {
    $settingsByKey[(string) $setting['setting_key']] = (string) ($setting['setting_value'] ?? '');
}
$loginBackgroundImage = $settingsByKey['login_background_image'] ?? '';
$displaySettings = array_values(array_filter(
    $settings,
    static fn (array $setting): bool => (string) $setting['setting_key'] !== 'login_background_image'
));

require __DIR__ . '/../views/layouts/header.php';
require __DIR__ . '/../views/layouts/sidebar.php';
?>
<div class="app-main-wrapper">
    <?php require __DIR__ . '/../views/layouts/topbar.php'; ?>
    <main class="app-content">
        <div class="mb-4">
            <p class="text-secondary mb-1">ຂໍ້ມູນຕັ້ງຄ່າຫຼັກຂອງລະບົບ</p>
            <h2 class="h4 fw-bold mb-0">ຕັ້ງຄ່າລະບົບ</h2>
        </div>

        <!-- ແຈ້ງສະຖານະການບັນທຶກ -->
        <?php if ($successMsg): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= h($successMsg) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($errorMsg): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= h($errorMsg) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <section class="content-card mb-4">
            <div class="card-toolbar">
                <div>
                    <h3 class="card-title">ຮູບໜ້າເຂົ້າສູ່ລະບົບ</h3>
                    <div class="card-subtitle">ຮູບນີ້ຈະສະແດງຢູ່ພາເນວດ້ານຊ້າຍຂອງໜ້າ Login</div>
                </div>
            </div>
            <div class="row align-items-center g-4">
                <div class="col-md-4">
                    <?php if ($loginBackgroundImage !== ''): ?>
                        <img class="img-fluid rounded-4 border shadow-sm login-background-preview" src="<?= h($loginBackgroundImage) ?>" alt="ຮູບໜ້າເຂົ້າສູ່ລະບົບ">
                    <?php else: ?>
                        <div class="rounded-4 p-4 text-white" style="background: linear-gradient(135deg, #4c2e8f, #8257e5); min-height: 150px;">
                            <i class="fa-solid fa-image fs-2 mb-3 d-block"></i>ຍັງໃຊ້ພື້ນຫຼັງເລີ່ມຕົ້ນ
                        </div>
                    <?php endif; ?>
                </div>
                <div class="col-md-8">
                    <form method="post" enctype="multipart/form-data" class="row g-3">
                        <input type="hidden" name="action" value="update_login_background">
                        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                        <div class="col-12">
                            <label class="form-label" for="loginBackground">ເລືອກຮູບ</label>
                            <input class="form-control" type="file" id="loginBackground" name="login_background" accept="image/jpeg,image/png,image/webp">
                            <div class="form-text">JPG, PNG ຫຼື WebP ຂະໜາດບໍ່ເກີນ 5MB · ອັດຕາສ່ວນແນະນຳ 4:5 (ເຊັ່ນ 1200 × 1500 px)</div>
                        </div>
                        <?php if ($loginBackgroundImage !== ''): ?>
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="1" id="removeLoginBackground" name="remove_login_background">
                                    <label class="form-check-label" for="removeLoginBackground">ລຶບຮູບ ແລະ ກັບໄປໃຊ້ພື້ນຫຼັງເລີ່ມຕົ້ນ</label>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="col-12"><button class="btn btn-primary" type="submit"><i class="fa-solid fa-image me-2"></i>ບັນທຶກຮູບໜ້າ Login</button></div>
                    </form>
                </div>
            </div>
        </section>

        <section class="dashboard-grid">
            <article class="content-card">
                <div class="card-toolbar">
                    <div>
                        <h3 class="card-title">ການຕັ້ງຄ່າ</h3>
                        <div class="card-subtitle">ຄ່າທີ່ໃຊ້ຄວບຄຸມລະບົບ</div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>ລາຍການຕັ້ງຄ່າ</th>
                                <th>ຄ່າ</th>
                                <th>ຄຳອະທິບາຍ</th>
                                <th class="text-end">ຈັດການ</th> <!-- ຖັນຈັດການ -->
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($displaySettings as $setting): ?>
                                <tr>
                                    <td class="fw-bold"><?= h($settingLabels[$setting['setting_key']] ?? $setting['setting_key']) ?></td>
                                    <td><?= h($setting['setting_value']) ?></td>
                                    <td><?= h($setting['description']) ?></td>
                                    <td class="text-end">
                                        <!-- ປຸ່ມເປີດໜ້າຕ່າງແກ້ໄຂ -->
                                        <button 
                                            type="button" 
                                            class="btn btn-sm btn-outline-primary edit-setting-btn"
                                            data-bs-toggle="modal" 
                                            data-bs-target="#editSettingModal"
                                            data-key="<?= h($setting['setting_key']) ?>"
                                            data-label="<?= h($settingLabels[$setting['setting_key']] ?? $setting['setting_key']) ?>"
                                            data-value="<?= h($setting['setting_value']) ?>"
                                            data-desc="<?= h($setting['description']) ?>"
                                        >
                                            ແກ້ໄຂ
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </article>

            <aside class="d-grid gap-3">
                <article class="content-card">
                    <div class="card-toolbar"><div><h3 class="card-title">ພະແນກ</h3><div class="card-subtitle">ລາຍການພະແນກໃນລະບົບ</div></div></div>
                    <div class="request-summary">
                        <?php foreach ($departments as $department): ?>
                            <div><span><?= h($department['name']) ?></span><strong><?= h($department['status'] === 'active' ? 'ເປີດໃຊ້ງານ' : 'ປິດໃຊ້ງານ') ?></strong></div>
                        <?php endforeach; ?>
                    </div>
                </article>
                <article class="content-card">
                    <div class="card-toolbar"><div><h3 class="card-title">ປະເພດວັນລາພັກ</h3><div class="card-subtitle">ຈຳນວນວັນລາຕໍ່ປີ</div></div></div>
                    <div class="request-summary">
                        <?php foreach ($leaveTypes as $leaveType): ?>
                            <div><span><?= h($leaveType['name']) ?></span><strong><?= h(number_format((float) $leaveType['annual_quota'], 1)) ?> ວັນ</strong></div>
                        <?php endforeach; ?>
                    </div>
                </article>
            </aside>
        </section>

<!-- ========================================== -->
<!-- ໜ້າຕ່າງສຳລັບແກ້ໄຂຄ່າຕັ້ງ -->
<!-- ========================================== -->
<div class="modal fade" id="editSettingModal" tabindex="-1" aria-labelledby="editSettingModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="" method="POST">
                <input type="hidden" name="action" value="update_setting">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="editSettingModalLabel">ແກ້ໄຂການຕັ້ງຄ່າ</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ປິດ"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">ລາຍການຕັ້ງຄ່າ</label>
                        <input type="text" class="form-control" id="modal_setting_key_display" disabled>
                        <input type="hidden" name="setting_key" id="modal_setting_key">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ຄຳອະທິບາຍ</label>
                        <p class="text-muted small" id="modal_setting_desc"></p>
                    </div>
                    <div class="mb-3">
                        <label for="modal_setting_value" class="form-label fw-bold">ຄ່າ</label>
                        <input type="text" class="form-control" name="setting_value" id="modal_setting_value" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ຍົກເລີກ</button>
                    <button type="submit" class="btn btn-primary">ບັນທຶກ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- JavaScript ດຶງຂໍ້ມູນຈາກປຸ່ມມາສະແດງໃນໜ້າຕ່າງ -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    const editBtns = document.querySelectorAll('.edit-setting-btn');
    editBtns.forEach(btn => {
        btn.addEventListener('click', function () {
            const key = this.getAttribute('data-key');
            const value = this.getAttribute('data-value');
            const desc = this.getAttribute('data-desc');

            document.getElementById('modal_setting_key_display').value = this.getAttribute('data-label');
            document.getElementById('modal_setting_key').value = key;
            document.getElementById('modal_setting_value').value = value;
            document.getElementById('modal_setting_desc').textContent = desc;
        });
    });
});
</script>

<?php require __DIR__ . '/../views/layouts/footer.php'; ?>
