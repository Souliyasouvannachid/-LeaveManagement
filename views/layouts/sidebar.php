<?php
$activeMenu = $activeMenu ?? 'dashboard';
$baseUrl = $baseUrl ?? rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/public/index.php')), '/');
$sidebarRole = $userRole ?? strtolower((string) ($currentUser['role'] ?? 'employee'));
$menuItems = [
    ['key' => 'dashboard', 'label' => 'ໜ້າຫຼັກ', 'icon' => 'fa-gauge-high', 'href' => $baseUrl . '/index.php', 'group' => null, 'roles' => ['admin', 'hr', 'manager', 'employee']],
    ['key' => 'request', 'label' => 'ຍື່ນຄຳຂໍລາພັກ', 'icon' => 'fa-calendar-plus', 'href' => $baseUrl . '/create.php', 'group' => 'ການລາພັກ', 'roles' => ['admin', 'hr', 'manager', 'employee']],
    ['key' => 'my_requests', 'label' => 'ຄຳຂໍຂອງຂ້ອຍ', 'icon' => 'fa-file-lines', 'href' => $baseUrl . '/my_requests.php', 'group' => 'ການລາພັກ', 'roles' => ['admin', 'hr', 'manager', 'employee']],
    ['key' => 'handover_tasks', 'label' => 'ວຽກທີ່ໄດ້ຮັບມອບໝາຍ', 'icon' => 'fa-people-arrows-left-right', 'href' => $baseUrl . '/handover_tasks.php', 'group' => 'ການລາພັກ', 'roles' => ['admin', 'hr', 'manager', 'employee']],
    ['key' => 'approvals', 'label' => 'ອະນຸມັດຄຳຂໍ', 'icon' => 'fa-circle-check', 'href' => $baseUrl . '/approvals.php', 'group' => 'ການລາພັກ', 'badge' => $approvalBadge ?? null, 'roles' => ['admin', 'manager']],
    ['key' => 'calendar', 'label' => 'ປະຕິທິນການລາພັກ', 'icon' => 'fa-calendar-days', 'href' => $baseUrl . '/calendar.php', 'group' => 'ການລາພັກ', 'roles' => ['admin', 'hr', 'manager', 'employee']],
    ['key' => 'reports', 'label' => 'ລາຍງານ', 'icon' => 'fa-chart-column', 'href' => $baseUrl . '/reports.php', 'group' => 'ລາຍງານ', 'roles' => ['admin', 'hr']],
    ['key' => 'employees', 'label' => 'ຈັດການພະນັກງານ', 'icon' => 'fa-users', 'href' => $baseUrl . '/employees.php', 'group' => 'ການຄຸ້ມຄອງລະບົບ', 'roles' => ['admin']],
    ['key' => 'departments', 'label' => 'ຈັດການພະແນກ', 'icon' => 'fa-building', 'href' => $baseUrl . '/departments.php', 'group' => 'ການຄຸ້ມຄອງລະບົບ', 'roles' => ['admin']],
    ['key' => 'leave_types', 'label' => 'ຈັດການປະເພດວັນລາ', 'icon' => 'fa-list-check', 'href' => $baseUrl . '/leave_types.php', 'group' => 'ການຄຸ້ມຄອງລະບົບ', 'roles' => ['admin']],
    ['key' => 'holidays', 'label' => 'ຈັດການວັນພັກ', 'icon' => 'fa-calendar-day', 'href' => $baseUrl . '/holidays.php', 'group' => 'ການຄຸ້ມຄອງລະບົບ', 'roles' => ['admin']],
    ['key' => 'activity_logs', 'label' => 'ບັນທຶກກິດຈະກຳ', 'icon' => 'fa-clock-rotate-left', 'href' => $baseUrl . '/activity_logs.php', 'group' => 'ການຄຸ້ມຄອງລະບົບ', 'roles' => ['admin']],
    ['key' => 'settings', 'label' => 'ຕັ້ງຄ່າລະບົບ', 'icon' => 'fa-gear', 'href' => $baseUrl . '/settings.php', 'group' => 'ການຄຸ້ມຄອງລະບົບ', 'roles' => ['admin']],
];
$menuItems = array_values(array_filter(
    $menuItems,
    static fn (array $item): bool => in_array($sidebarRole, $item['roles'], true)
));
$avatarUrl = trim((string) ($currentUser['avatar_url'] ?? ($sessionUser['avatar'] ?? '')));
?>
<aside class="app-sidebar" id="appSidebar" data-sidebar-role="<?= htmlspecialchars($sidebarRole) ?>">
    <div class="sidebar-brand">
        <div class="brand-mark">
            <i class="fa-solid fa-briefcase"></i>
        </div>
        <div>
            <div class="brand-title">ລະບົບລາພັກ</div>
            <div class="brand-subtitle">ການຄຸ້ມຄອງຊັບພະຍາກອນມະນຸດ</div>
        </div>
    </div>

    <nav class="sidebar-nav" aria-label="ເມນູຫຼັກ">
        <?php $currentGroup = null; ?>
        <?php foreach ($menuItems as $item): ?>
            <?php if ($item['group'] !== null && $item['group'] !== $currentGroup): ?>
                <div class="sidebar-section-title"><?= htmlspecialchars($item['group']) ?></div>
                <?php $currentGroup = $item['group']; ?>
            <?php endif; ?>
            <a class="sidebar-link <?= $activeMenu === $item['key'] ? 'active' : '' ?>" href="<?= htmlspecialchars($item['href']) ?>">
                <span class="sidebar-icon"><i class="fa-solid <?= htmlspecialchars($item['icon']) ?>"></i></span>
                <span class="sidebar-text"><?= htmlspecialchars($item['label']) ?></span>
                <?php if (!empty($item['badge'])): ?>
                    <span class="sidebar-badge"><?= htmlspecialchars((string) $item['badge']) ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-profile">
        <div class="avatar avatar-sm">
            <?php if ($avatarUrl !== ''): ?>
                <img src="<?= htmlspecialchars($avatarUrl) ?>" alt="<?= htmlspecialchars($currentUser['name']) ?>">
            <?php else: ?>
                <?= htmlspecialchars(mb_substr($currentUser['avatar'] ?? $currentUser['name'], 0, 1, 'UTF-8')) ?>
            <?php endif; ?>
        </div>
        <div class="profile-meta">
            <div class="profile-name"><?= htmlspecialchars($currentUser['name']) ?></div>
            <div class="profile-role"><?= htmlspecialchars($currentUser['role']) ?></div>
        </div>
    </div>
</aside>
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>
