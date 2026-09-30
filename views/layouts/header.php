<?php
$pageTitle = $pageTitle ?? 'ໜ້າຫຼັກ';
$appName = $appName ?? 'ລະບົບຈັດການການລາພັກ';
$useDataTables = $useDataTables ?? false;
$baseUrl = $baseUrl ?? rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/public/index.php')), '/');
$currentUser = $currentUser ?? [
    'name' => 'ສຸລິຍາ',
    'role' => 'ພະນັກງານ',
    'department' => 'ພະແນກເຕັກໂນໂລຊີ ແລະ ການສື່ສານ',
    'avatar' => 'ສ',
];
?>
<!doctype html>
<html lang="lo">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle) ?> | <?= htmlspecialchars($appName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Lao:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <?php if ($useDataTables): ?>
        <link href="https://cdn.datatables.net/2.3.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
        <link href="https://cdn.datatables.net/responsive/3.0.8/css/responsive.bootstrap5.min.css" rel="stylesheet">
    <?php endif; ?>
    <link href="<?= htmlspecialchars($baseUrl) ?>/assets/css/style.css?v=20260929-sidebar-layout-fix" rel="stylesheet">
</head>
<body>
<div class="app-shell">
