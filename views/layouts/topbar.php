<?php
$notifications = $notifications ?? [
    ['title' => 'ຄຳຂໍລາພັກລໍຖ້າການອະນຸມັດ', 'message' => 'ມີຄຳຂໍລາພັກໃໝ່ 4 ລາຍການ', 'time' => '5 ນາ​ທິ​ທີ່​ແລ້ວ'],
    ['title' => 'ອະນຸມັດແລ້ວ', 'message' => 'ຄຳຂໍ LV-2026-000001 ໄດ້ຮັບອະນຸມັດແລ້ວ', 'time' => 'ມື້ນີ້'],
    ['title' => 'ວັນພັກຂອງບໍລິສັດ', 'message' => 'ວັນກຳມະກອນ 1 ພ.ຄ. 2026', 'time' => 'ເມື່ອ​ວານ​ນີ້'],
];
$unreadCount = $unreadCount ?? 4;
$notificationsUrl = $notificationsUrl ?? 'notifications.php';
$avatarUrl = trim((string) ($currentUser['avatar_url'] ?? ($sessionUser['avatar'] ?? '')));
?>
<header class="app-topbar">
    <div class="topbar-left">
        <button class="btn icon-btn d-lg-none" type="button" id="sidebarToggle" aria-label="ເປີດເມນູ">
            <i class="fa-solid fa-bars"></i>
        </button>
        <div>
            <nav aria-label="ເສັ້ນທາງໜ້າ">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="<?= htmlspecialchars($baseUrl ?? '') ?>/index.php">ໜ້າຫຼັກ</a></li>
                    <li class="breadcrumb-item active" aria-current="page"><?= htmlspecialchars($pageTitle ?? 'ໜ້າຫຼັກ') ?></li>
                </ol>
            </nav>
            <h1 class="page-title"><?= htmlspecialchars($pageTitle ?? 'ໜ້າຫຼັກ') ?></h1>
        </div>
    </div>

    <div class="topbar-actions">
        <div class="topbar-search d-none d-md-flex">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" class="form-control" placeholder="ຄົ້ນຫາຄຳຂໍພະນັກງານລາຍງານ">
        </div>

        <div class="dropdown">
            <button class="btn icon-btn notification-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="ການແຈ້ງເຕືອນ">
                <i class="fa-regular fa-bell"></i>
                <?php if ($unreadCount > 0): ?>
                    <span class="notification-dot"><?= htmlspecialchars((string) $unreadCount) ?></span>
                <?php endif; ?>
            </button>
            <div class="dropdown-menu dropdown-menu-end notification-menu">
                <div class="dropdown-header-row">
                    <strong>ການແຈ້ງເຕືອນ</strong>
                    <span class="badge text-bg-primary"><?= htmlspecialchars((string) $unreadCount) ?> ໃໝ່</span>
                </div>
                <?php if (!empty($notifications)): ?>
                    <?php foreach ($notifications as $notification): ?>
                        <a class="dropdown-item notification-item" href="<?= htmlspecialchars(($baseUrl ?? '') . '/notification_open.php?id=' . (int) ($notification['id'] ?? 0)) ?>">
                            <span class="notification-icon"><i class="fa-solid fa-bell"></i></span>
                            <span>
                                <span class="notification-title"><?= htmlspecialchars($notification['title']) ?></span>
                                <span class="notification-message"><?= htmlspecialchars($notification['message']) ?></span>
                                <span class="notification-time"><?= htmlspecialchars($notification['time']) ?></span>
                            </span>
                        </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="notification-item">
                        <span class="notification-icon"><i class="fa-regular fa-bell-slash"></i></span>
                        <span>
                            <span class="notification-title">ຍັງບໍ່ມີການແຈ້ງເຕືອນ</span>
                            <span class="notification-message">ເມື່ອມີລາຍການໃໝ່ລະບົບຈະສະແດງທີ່ນີ້</span>
                            <span class="notification-time">ດຽວນີ້</span>
                        </span>
                    </div>
                <?php endif; ?>
                <div class="dropdown-footer">
                    <a href="<?= htmlspecialchars(($baseUrl ?? '') . '/' . ltrim($notificationsUrl, '/')) ?>">ເບິ່ງທັງໝົດ</a>
                </div>
            </div>
        </div>

        <div class="dropdown">
            <button class="btn profile-button" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <span class="avatar">
                    <?php if ($avatarUrl !== ''): ?>
                        <img src="<?= htmlspecialchars($avatarUrl) ?>" alt="<?= htmlspecialchars($currentUser['name']) ?>">
                    <?php else: ?>
                        <?= htmlspecialchars(mb_substr($currentUser['avatar'] ?? $currentUser['name'], 0, 1, 'UTF-8')) ?>
                    <?php endif; ?>
                </span>
                <span class="profile-text d-none d-sm-block">
                    <span class="profile-name"><?= htmlspecialchars($currentUser['name']) ?></span>
                    <span class="profile-role"><?= htmlspecialchars($currentUser['role']) ?></span>
                </span>
                <i class="fa-solid fa-chevron-down d-none d-sm-inline"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end profile-menu">
                <li class="dropdown-user">
                    <strong><?= htmlspecialchars($currentUser['name']) ?></strong>
                    <span><?= htmlspecialchars($currentUser['department']) ?></span>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="<?= htmlspecialchars($baseUrl ?? '') ?>/profile.php"><i class="fa-regular fa-user"></i> ໂປຣໄຟລ໌</a></li>
                <li><a class="dropdown-item" href="<?= htmlspecialchars($baseUrl ?? '') ?>/profile.php#security"><i class="fa-solid fa-shield-halved"></i> ຄວາມປອດໄພ</a></li>
                <li><a class="dropdown-item text-danger" href="<?= htmlspecialchars($baseUrl ?? '') ?>/logout.php"><i class="fa-solid fa-right-from-bracket"></i> ອອກຈາກລະບົບ</a></li>
            </ul>
        </div>
    </div>
</header>
