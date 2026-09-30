<?php
require __DIR__ . '/_auth.php';

const PROFILE_AVATAR_DIR = __DIR__ . '/uploads/profile_avatars';
const PROFILE_AVATAR_WEB_PATH = 'uploads/profile_avatars';
const MAX_PROFILE_AVATAR_BYTES = 2097152;

$pageTitle = 'ໂປຣໄຟລ໌';
$activeMenu = 'profile';
$appName = 'ລະບົບຈັດການການລາພັກ';

$profileStmt = $pdo->prepare(
    'SELECT u.id, u.employee_code, u.first_name, u.last_name, u.email, u.password, u.phone, u.avatar,
            u.role, u.start_date, u.status,
            d.name AS department_name,
            p.name AS position_name,
            CONCAT(COALESCE(m.first_name, ""), " ", COALESCE(m.last_name, "")) AS manager_name
     FROM users u
     LEFT JOIN departments d ON d.id = u.department_id
     LEFT JOIN positions p ON p.id = u.position_id
     LEFT JOIN users m ON m.id = u.manager_id
     WHERE u.id = :id
     LIMIT 1'
);
$profileStmt->execute(['id' => $userId]);
$profile = $profileStmt->fetch();

if (!$profile) {
    http_response_code(404);
    exit('ບໍ່ພົບຂໍ້ມູນຜູ້ໃຊ້ງານ');
}

$formValues = [
    'first_name' => (string) $profile['first_name'],
    'last_name' => (string) $profile['last_name'],
    'email' => (string) $profile['email'],
    'phone' => (string) ($profile['phone'] ?? ''),
];
$formErrors = [];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function saveProfileAvatar(array $file, array &$errors): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $errors[] = 'ບໍ່ສາມາດອັບໂຫຼດຮູບໂປຣໄຟລ໌ໄດ້';
        return null;
    }

    if (($file['size'] ?? 0) <= 0 || ($file['size'] ?? 0) > MAX_PROFILE_AVATAR_BYTES) {
        $errors[] = 'ຮູບໂປຣໄຟລ໌ຕ້ອງມີຂະໜາດບໍ່ເກີນ 2MB';
        return null;
    }

    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $allowedMimeTypes = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];
    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
    if (($allowedMimeTypes[$extension] ?? null) !== $mimeType) {
        $errors[] = 'ຮູບໂປຣໄຟລ໌ຮອງຮັບສະເພາະ JPG, PNG ຫຼື WebP';
        return null;
    }

    if (!is_dir(PROFILE_AVATAR_DIR) && !mkdir(PROFILE_AVATAR_DIR, 0775, true) && !is_dir(PROFILE_AVATAR_DIR)) {
        $errors[] = 'ບໍ່ສາມາດຈັດກຽມພື້ນທີ່ເກັບຮູບໂປຣໄຟລ໌ໄດ້';
        return null;
    }

    $fileName = bin2hex(random_bytes(16)) . '.' . $extension;
    if (!move_uploaded_file((string) $file['tmp_name'], PROFILE_AVATAR_DIR . DIRECTORY_SEPARATOR . $fileName)) {
        $errors[] = 'ບໍ່ສາມາດບັນທຶກຮູບໂປຣໄຟລ໌ໄດ້';
        return null;
    }

    return PROFILE_AVATAR_WEB_PATH . '/' . $fileName;
}

function deleteProfileAvatar(?string $path): void
{
    $fileName = basename((string) $path);
    if (preg_match('/^[a-f0-9]{32}\.(jpe?g|png|webp)$/i', $fileName)) {
        @unlink(PROFILE_AVATAR_DIR . DIRECTORY_SEPARATOR . $fileName);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'profile');

    if (!hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
        $formErrors[] = 'ຄຳຂໍບໍ່ຖືກຕ້ອງ ກະລຸນາຣີເຟຣສໜ້າແລ້ວລອງໃໝ່';
    }

    if ($action === 'profile' && empty($formErrors)) {
        $formValues = [
            'first_name' => trim((string) ($_POST['first_name'] ?? '')),
            'last_name' => trim((string) ($_POST['last_name'] ?? '')),
            'email' => trim((string) ($_POST['email'] ?? '')),
            'phone' => trim((string) ($_POST['phone'] ?? '')),
        ];
        $removeAvatar = (string) ($_POST['remove_avatar'] ?? '') === '1';

        if ($formValues['first_name'] === '' || $formValues['last_name'] === '') {
            $formErrors[] = 'ກະລຸນາລະບຸຊື່ ແລະ ນາມສະກຸນ';
        }

        if (!filter_var($formValues['email'], FILTER_VALIDATE_EMAIL)) {
            $formErrors[] = 'ຮູບແບບອີເມວບໍ່ຖືກຕ້ອງ';
        }

        if ($formValues['phone'] !== '' && !preg_match('/^[0-9+\-\s().]{6,30}$/', $formValues['phone'])) {
            $formErrors[] = 'ຮູບແບບເບີໂທລະສັບບໍ່ຖືກຕ້ອງ';
        }

        $duplicateStmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = :email AND id <> :id');
        $duplicateStmt->execute([
            'email' => $formValues['email'],
            'id' => $userId,
        ]);
        if ((int) $duplicateStmt->fetchColumn() > 0) {
            $formErrors[] = 'ອີເມວນີ້ຖືກໃຊ້ແລ້ວ';
        }

        $newAvatarPath = empty($formErrors) ? saveProfileAvatar($_FILES['avatar'] ?? [], $formErrors) : null;
        $avatarPath = $newAvatarPath ?? ($removeAvatar ? null : ($profile['avatar'] ?? null));

        if (empty($formErrors)) {
            $updateStmt = $pdo->prepare(
                'UPDATE users
                 SET first_name = :first_name,
                     last_name = :last_name,
                     email = :email,
                     phone = :phone,
                     avatar = :avatar
                 WHERE id = :id'
            );
            $updateStmt->execute([
                'first_name' => $formValues['first_name'],
                'last_name' => $formValues['last_name'],
                'email' => $formValues['email'],
                'phone' => $formValues['phone'] !== '' ? $formValues['phone'] : null,
                'avatar' => $avatarPath,
                'id' => $userId,
            ]);

            if (($newAvatarPath !== null || $removeAvatar) && !empty($profile['avatar'])) {
                deleteProfileAvatar((string) $profile['avatar']);
            }

            $_SESSION['user']['name'] = trim($formValues['first_name'] . ' ' . $formValues['last_name']);
            $_SESSION['user']['email'] = $formValues['email'];
            $_SESSION['user']['avatar'] = $avatarPath ?? '';
            $_SESSION['flash_success'] = 'ບັນທຶກຂໍ້ມູນສ່ວນຕົວສຳເລັດແລ້ວ';
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            header('Location: ' . leaveContextUrl('profile.php'));
            exit;
        }
    } elseif ($action === 'password' && empty($formErrors)) {
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        if (!password_verify($currentPassword, (string) $profile['password'])) {
            $formErrors[] = 'ລະຫັດຜ່ານປະຈຸບັນບໍ່ຖືກຕ້ອງ';
        }

        if (mb_strlen($newPassword) < 8) {
            $formErrors[] = 'ລະຫັດຜ່ານໃໝ່ຕ້ອງມີຢ່າງໜ້ອຍ 8 ຕົວອັກສອນ';
        }

        if ($newPassword !== $confirmPassword) {
            $formErrors[] = 'ຢືນຢັນລະຫັດຜ່ານໃໝ່ບໍ່ຖືກຕ້ອງ';
        }

        if (empty($formErrors)) {
            $passwordStmt = $pdo->prepare('UPDATE users SET password = :password WHERE id = :id');
            $passwordStmt->execute([
                'password' => password_hash($newPassword, PASSWORD_BCRYPT),
                'id' => $userId,
            ]);

            $_SESSION['flash_success'] = 'ປ່ຽນລະຫັດຜ່ານສຳເລັດແລ້ວ';
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            header('Location: ' . leaveContextUrl('profile.php#security'));
            exit;
        }
    }
}

$currentUser = [
    'name' => trim($formValues['first_name'] . ' ' . $formValues['last_name']),
    'role' => $roleLabels[$userRole] ?? 'ພະນັກງານ',
    'department' => (string) ($profile['department_name'] ?? 'ອົງກອນ'),
    'avatar' => (string) mb_substr($formValues['first_name'] !== '' ? $formValues['first_name'] : 'ຜ', 0, 1, 'UTF-8'),
    'avatar_url' => (string) ($avatarPath ?? $profile['avatar'] ?? ''),
];

$managerName = trim((string) ($profile['manager_name'] ?? ''));

require __DIR__ . '/../views/layouts/header.php';
require __DIR__ . '/../views/layouts/sidebar.php';
?>
<div class="app-main-wrapper">
    <?php require __DIR__ . '/../views/layouts/topbar.php'; ?>
    <main class="app-content">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <p class="text-secondary mb-1">ຂໍ້ມູນບັນຊີຜູ້ໃຊ້ງານແລະຂໍ້ມູນຕິດຕໍ່ສ່ວນຕົວ</p>
                <h2 class="h4 fw-bold mb-0">ໂປຣໄຟລ໌ຂອງຂ້ອຍ</h2>
            </div>
        </div>

        <?php if (!empty($formErrors)): ?>
            <div class="alert alert-danger employee-form-alert">
                <strong>ບໍ່ສາມາດບັນທຶກຂໍ້ມູນໄດ້</strong>
                <ul class="mb-0 mt-2">
                    <?php foreach ($formErrors as $error): ?>
                        <li><?= h($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <section class="profile-grid">
            <aside class="content-card profile-summary-card">
                <div class="profile-summary-head">
                    <div class="avatar profile-avatar">
                        <?php if ($currentUser['avatar_url'] !== ''): ?>
                            <img src="<?= h($currentUser['avatar_url']) ?>" alt="<?= h($currentUser['name']) ?>">
                        <?php else: ?>
                            <?= h(mb_substr($currentUser['name'], 0, 1, 'UTF-8')) ?>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h3><?= h($currentUser['name']) ?></h3>
                        <p><?= h((string) $profile['employee_code']) ?> · <?= h($currentUser['role']) ?></p>
                    </div>
                </div>
                <div class="profile-detail-list">
                    <div><span>ແຜນກ</span><strong><?= h((string) ($profile['department_name'] ?? '-')) ?></strong></div>
                    <div><span>ຕຳແໜ່ງ</span><strong><?= h((string) ($profile['position_name'] ?? '-')) ?></strong></div>
                    <div><span>ຫົວໜ້າ</span><strong><?= h($managerName !== '' ? $managerName : '-') ?></strong></div>
                    <div><span>ວັນທີເລີ່ມງານ</span><strong><?= h(thaiDate((string) ($profile['start_date'] ?? ''))) ?></strong></div>
                </div>
            </aside>

            <div class="d-grid gap-3">
                <section class="content-card">
                    <div class="card-toolbar">
                        <div>
                            <h3 class="card-title">ຂໍ້ມູນສ່ວນຕົວ</h3>
                            <div class="card-subtitle">ແກ້ໄຂຊື່ ອີເມວ ແລະຂໍ້ມູນຕິດຕໍ່ຂອງທ່ານ</div>
                        </div>
                    </div>
                    <form class="profile-form" method="post" action="profile.php" enctype="multipart/form-data" novalidate>
                        <input type="hidden" name="action" value="profile">
                        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label" for="avatar">ຮູບໂປຣໄຟລ໌</label>
                                <input class="form-control" type="file" id="avatar" name="avatar" accept="image/jpeg,image/png,image/webp">
                                <div class="form-text">ຮອງຮັບ JPG, PNG ຫຼື WebP ຂະໜາດບໍ່ເກີນ 2MB</div>
                                <?php if (!empty($profile['avatar'])): ?>
                                    <div class="form-check mt-2">
                                        <input class="form-check-input" type="checkbox" value="1" id="removeAvatar" name="remove_avatar">
                                        <label class="form-check-label" for="removeAvatar">ລຶບຮູບໂປຣໄຟລ໌ປະຈຸບັນ</label>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="firstName">ຊື່</label>
                                <input class="form-control" type="text" id="firstName" name="first_name" value="<?= h($formValues['first_name']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="lastName">ນາມສະກຸນ</label>
                                <input class="form-control" type="text" id="lastName" name="last_name" value="<?= h($formValues['last_name']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="email">ອີເມວ</label>
                                <input class="form-control" type="email" id="email" name="email" value="<?= h($formValues['email']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="phone">ເບີໂທລະສັບ</label>
                                <input class="form-control" type="tel" id="phone" name="phone" value="<?= h($formValues['phone']) ?>">
                            </div>
                        </div>
                        <div class="profile-form-actions">
                            <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>ບັນທຶກຂໍ້ມູນ</button>
                        </div>
                    </form>
                </section>

                <section class="content-card" id="security">
                    <div class="card-toolbar">
                        <div>
                            <h3 class="card-title">ຄວາມປອດໄພ</h3>
                            <div class="card-subtitle">ປ່ຽນລະຫັດຜ່ານສຳລັບເຂົ້າສູ່ລະບົບ</div>
                        </div>
                    </div>
                    <form class="profile-form" method="post" action="profile.php#security" novalidate>
                        <input type="hidden" name="action" value="password">
                        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label" for="currentPassword">ລະຫັດຜ່ານປະຈຸບັນ</label>
                                <input class="form-control" type="password" id="currentPassword" name="current_password" autocomplete="current-password" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="newPassword">ລະຫັດຜ່ານໃໝ່</label>
                                <input class="form-control" type="password" id="newPassword" name="new_password" autocomplete="new-password" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="confirmPassword">ຢືນຢັນລະຫັດຜ່ານໃໝ່</label>
                                <input class="form-control" type="password" id="confirmPassword" name="confirm_password" autocomplete="new-password" required>
                            </div>
                        </div>
                        <div class="profile-form-actions">
                            <button class="btn btn-outline-primary" type="submit"><i class="fa-solid fa-shield-halved me-2"></i>ປ່ຽນລະຫັດຜ່ານ</button>
                        </div>
                    </form>
                </section>
            </div>
        </section>
<?php require __DIR__ . '/../views/layouts/footer.php'; ?>
