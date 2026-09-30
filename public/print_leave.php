<?php
require __DIR__ . '/_auth.php';

$requestId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$isEmbeddedPreview = filter_input(INPUT_GET, 'embed', FILTER_VALIDATE_INT) === 1;

if (!$requestId) {
    http_response_code(400);
    exit('ຄຳຂໍລາພັກບໍ່ຖືກຕ້ອງ');
}

$requestStmt = $pdo->prepare(
    'SELECT lr.*,
            lt.name AS leave_type_name,
            lt.code AS leave_type_code,
            u.employee_code,
            u.first_name,
            u.last_name,
            u.email,
            u.phone,
            d.name AS department_name,
            p.name AS position_name,
            m.first_name AS manager_first_name,
            m.last_name AS manager_last_name,
            h.first_name AS handover_first_name,
            h.last_name AS handover_last_name
     FROM leave_requests lr
     INNER JOIN leave_types lt ON lt.id = lr.leave_type_id
     INNER JOIN users u ON u.id = lr.user_id
     LEFT JOIN departments d ON d.id = u.department_id
     LEFT JOIN positions p ON p.id = u.position_id
     LEFT JOIN users m ON m.id = lr.manager_id
     LEFT JOIN users h ON h.id = lr.handover_to_user_id
     WHERE lr.id = :id
       AND (
            (:current_role = "employee" AND lr.user_id = :current_user_id)
            OR (:current_role_manager = "manager" AND lr.manager_id = :current_manager_id)
            OR :allowed_role IN ("admin", "hr")
       )
     LIMIT 1'
);
$requestStmt->execute([
    'id' => $requestId,
    'current_user_id' => $userId,
    'current_role' => $userRole,
    'current_role_manager' => $userRole,
    'current_manager_id' => $userId,
    'allowed_role' => $userRole,
]);
$request = $requestStmt->fetch();

if (!$request) {
    http_response_code(403);
    exit('ທ່ານບໍ່ມີສິດພິມໃບລາພັກລາຍການນີ້');
}

function formatLeaveDays(float|int|string|null $days): string
{
    $value = (float) $days;

    return fmod($value, 1.0) === 0.0 ? number_format($value, 0) : number_format($value, 1);
}

function laoDateParts(?string $date): array
{
    if (!$date) {
        return ['day' => '', 'month' => '', 'year' => ''];
    }

    $dateTime = new DateTimeImmutable($date);
    $months = [
        1 => 'ມັງກອນ',
        2 => 'ກຸມພາ',
        3 => 'ມີນາຄົມ',
        4 => 'ເມສາ',
        5 => 'ພຶດສະພາ',
        6 => 'ມິຖຸນາ',
        7 => 'ກໍລະກົດ',
        8 => 'ສິງຫາ',
        9 => 'ກັນຍາ',
        10 => 'ຕຸລາ',
        11 => 'ພະຈິກ',
        12 => 'ທັນວາ',
    ];

    return [
        'day' => $dateTime->format('j'),
        'month' => $months[(int) $dateTime->format('n')],
        'year' => $dateTime->format('Y'),
    ];
}

function checkedCircle(bool $checked): string
{
    return '<span class="circle' . ($checked ? ' checked' : '') . '">' . ($checked ? '&#10003;' : '') . '</span>';
}

function lineValue(?string $value, string $class = ''): string
{
    $text = trim((string) $value);

    return '<span class="line-value ' . h($class) . '">' . h($text !== '' ? $text : ' ') . '</span>';
}

$employeeName = trim((string) $request['first_name'] . ' ' . (string) $request['last_name']);
$managerName = trim((string) ($request['manager_first_name'] ?? '') . ' ' . (string) ($request['manager_last_name'] ?? ''));
$handoverName = trim((string) ($request['handover_first_name'] ?? '') . ' ' . (string) ($request['handover_last_name'] ?? ''));
$createdParts = laoDateParts((string) $request['created_at']);
$startDate = thaiDate((string) $request['start_date']);
$endDate = thaiDate((string) $request['end_date']);
$approvedDate = $request['approved_at'] ? thaiDate((string) $request['approved_at']) : '';
$status = (string) $request['status'];
$leaveTypeCode = (string) $request['leave_type_code'];
$leaveTypeName = (string) $request['leave_type_name'];
$printedAt = (new DateTimeImmutable())->format('d/m/Y H:i');
$returnUrl = match ($userRole) {
    'admin', 'hr' => 'index.php',
    'manager' => 'approvals.php',
    default => 'my_requests.php',
};
$context = leaveSessionContext();
if ($context) {
    $returnUrl .= '?context=' . rawurlencode($context);
}

$balanceStmt = $pdo->prepare(
    'SELECT lt.name, lt.code, lb.used_days
     FROM leave_types lt
     LEFT JOIN leave_balances lb
       ON lb.leave_type_id = lt.id
      AND lb.user_id = :user_id
      AND lb.year = YEAR(:start_date)
     WHERE lt.code IN ("SICK", "PERSONAL", "MATERNITY")
     ORDER BY FIELD(lt.code, "SICK", "PERSONAL", "MATERNITY")'
);
$balanceStmt->execute([
    'user_id' => (int) $request['user_id'],
    'start_date' => (string) $request['start_date'],
]);
$leaveStats = $balanceStmt->fetchAll();

$lastLeaveStmt = $pdo->prepare(
    'SELECT lr.start_date, lr.end_date, lr.total_days, lt.name, lt.code
     FROM leave_requests lr
     INNER JOIN leave_types lt ON lt.id = lr.leave_type_id
     WHERE lr.user_id = :user_id
       AND lr.id <> :request_id
       AND lr.status = "Approved"
       AND lt.code IN ("SICK", "PERSONAL", "MATERNITY")
     ORDER BY lr.start_date DESC
     LIMIT 1'
);
$lastLeaveStmt->execute([
    'user_id' => (int) $request['user_id'],
    'request_id' => (int) $request['id'],
]);
$lastLeave = $lastLeaveStmt->fetch();
?>
<!doctype html>
<html lang="lo">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ໃບລາພັກ <?= h((string) $request['request_no']) ?></title>
    <style>
        @import url("https://fonts.googleapis.com/css2?family=Noto+Sans+Lao:wght@300;400;500;600;700;800&display=swap");

        @page {
            size: A4;
            margin: 10mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #e8edf5;
            color: #111827;
            font-family: "Noto Sans Lao", "Phetsarath OT", sans-serif;
            font-size: 14px;
            line-height: 1.45;
            text-rendering: optimizeLegibility;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        .print-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            width: 210mm;
            margin: 16px auto;
        }

        .print-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #1f4ed8;
            border-radius: 6px;
            background: #1f4ed8;
            color: #fff;
            padding: 9px 14px;
            font: inherit;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
        }

        .print-btn.secondary {
            background: #fff;
            color: #1f4ed8;
        }

        .sheet {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto 24px;
            padding: 13mm 16mm;
            background: #fff;
            box-shadow: 0 18px 55px rgba(15, 23, 42, 0.18);
        }

        .doc-top {
            display: grid;
            grid-template-columns: 1fr 72mm;
            align-items: start;
            gap: 10mm;
        }

        .title {
            grid-column: 1 / -1;
            margin: 4mm 0 5mm;
            text-align: center;
            font-size: 22px;
            font-weight: 800;
        }

        .doc-code {
            color: #475569;
            font-size: 12px;
            font-weight: 700;
        }

        .write-at {
            text-align: right;
        }

        .row {
            display: flex;
            align-items: baseline;
            gap: 7px;
            min-height: 8mm;
            margin-top: 2mm;
        }

        .row.indent {
            padding-left: 28mm;
        }

        .row.compact {
            min-height: 7mm;
            margin-top: 1mm;
        }

        .line-value {
            display: inline-block;
            min-width: 30mm;
            padding: 0 3px 1px;
            border-bottom: 1px dotted #4b5563;
            text-align: center;
            font-weight: 700;
        }

        .line-value.left {
            text-align: left;
        }

        .line-value.sm {
            min-width: 18mm;
        }

        .line-value.md {
            min-width: 42mm;
        }

        .line-value.lg {
            min-width: 72mm;
        }

        .line-value.xl {
            min-width: 115mm;
        }

        .line-value.reason {
            min-width: 108mm;
            text-align: left;
        }

        .choice-row {
            display: flex;
            align-items: center;
            gap: 11mm;
            margin: 2mm 0 0 24mm;
        }

        .choice {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            white-space: nowrap;
        }

        .circle {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 4.8mm;
            height: 4.8mm;
            border: 1.5px solid #111827;
            border-radius: 50%;
            font-size: 11px;
            font-weight: 800;
            line-height: 1;
        }

        .circle.checked {
            color: #0f5bd8;
        }

        .main-grid {
            display: grid;
            grid-template-columns: 1fr 72mm;
            gap: 10mm;
            margin-top: 8mm;
            align-items: start;
        }

        .leave-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 4mm;
            font-size: 13px;
        }

        .leave-table th,
        .leave-table td {
            border: 1px solid #111827;
            padding: 7px 6px;
            text-align: center;
            vertical-align: middle;
        }

        .leave-table th {
            background: #f8fafc;
            font-weight: 800;
        }

        .section-caption {
            margin-top: 0;
            font-weight: 800;
        }

        .signature-block {
            margin-top: 8mm;
            text-align: center;
            page-break-inside: avoid;
        }

        .signature-block.tight {
            margin-top: 5mm;
        }

        .signature-spacer-lg {
            height: 9mm;
        }

        .signature-spacer-md {
            height: 7mm;
        }

        .signature-line {
            display: inline-block;
            min-width: 58mm;
            border-bottom: 1px dotted #4b5563;
            padding: 0 4px 1px;
            font-weight: 700;
        }

        .position-line {
            display: inline-block;
            min-width: 58mm;
            border-bottom: 1px dotted #4b5563;
            padding-bottom: 1px;
            color: #334155;
        }

        .approval-box {
            margin-top: 5mm;
            padding-top: 3mm;
            border-top: 1px solid #cbd5e1;
            text-align: left;
        }

        .approval-choice {
            display: flex;
            flex-direction: column;
            gap: 2mm;
            margin: 3mm 0 5mm 12mm;
        }

        .footer-note {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            margin-top: 7mm;
            padding-top: 3mm;
            border-top: 1px solid #cbd5e1;
            color: #64748b;
            font-size: 11px;
        }

        @media print {
            @page {
                margin: 6mm;
            }

            body {
                background: #fff;
                font-size: 13px;
            }

            .print-actions {
                display: none;
            }

            .sheet {
                width: auto;
                min-height: auto;
                margin: 0;
                padding: 0;
                box-shadow: none;
            }

            .doc-top {
                gap: 6mm;
            }

            .title {
                margin: 2mm 0 3mm;
                font-size: 20px;
            }

            .row {
                min-height: 6.2mm;
                margin-top: 1mm;
                gap: 5px;
            }

            .row.compact {
                min-height: 5.6mm;
                margin-top: 0.5mm;
            }

            .choice-row {
                gap: 8mm;
                margin: 1.5mm 0 0 18mm;
            }

            .main-grid {
                gap: 6mm;
                margin-top: 5mm;
            }

            .leave-table {
                margin-top: 3mm;
                font-size: 12px;
            }

            .leave-table th,
            .leave-table td {
                padding: 5px 4px;
            }

            .signature-block {
                margin-top: 5mm;
            }

            .signature-block.tight {
                margin-top: 3mm;
            }

            .signature-spacer-lg {
                height: 6mm;
            }

            .signature-spacer-md {
                height: 4.5mm;
            }

            .approval-box {
                margin-top: 3mm;
                padding-top: 2mm;
            }

            .approval-choice {
                gap: 1.2mm;
                margin: 2mm 0 3mm 10mm;
            }

            .footer-note {
                display: none;
            }
        }
    </style>
</head>
<body>
    <?php if (!$isEmbeddedPreview): ?>
        <div class="print-actions">
            <a class="print-btn secondary" href="<?= h($returnUrl) ?>">ກັບ</a>
            <button class="print-btn" type="button" onclick="window.print()">ພິມໃບລາ</button>
        </div>
    <?php endif; ?>

    <main class="sheet">
        <header class="doc-top">
            <div class="doc-code">ເລກທີຄຳຂໍ <?= h((string) $request['request_no']) ?></div>
            <div class="write-at">ຂຽນທີ່ <?= lineValue('ຝ່າຍຊັບພະຍາກອນມະນຸດ', 'md') ?></div>
            <h1 class="title">ແບບໃບລາພັກ ລາຄອດລູກ ລາກິດສ່ວນຕົວ</h1>
            <div></div>
            <div class="write-at">
                ວັນທີ່ <?= lineValue($createdParts['day'], 'sm') ?>
                ເດືອນ <?= lineValue($createdParts['month'], 'md') ?>
                ຄ.ສ. <?= lineValue($createdParts['year'], 'sm') ?>
            </div>
        </header>

        <section>
            <div class="row compact">ເລື້ອງ <span>ຂໍອະນຸຍາດລາ</span><?= lineValue($leaveTypeName, 'lg') ?></div>
            <div class="row compact">ຮຽນ <span>ຜູ້ບັງຄັບບັນຊາ / ຝ່າຍຊັບພະຍາກອນມະນຸດ</span></div>
            <div class="row indent">ຂ້າພເຈົ້າ <?= lineValue($employeeName, 'lg') ?> ຕຳແໜ່ງ <?= lineValue((string) ($request['position_name'] ?? ''), 'md') ?></div>
            <div class="row">ສັງກັດ <?= lineValue((string) ($request['department_name'] ?? ''), 'lg') ?> ລະຫັດພະນັກງານ <?= lineValue((string) $request['employee_code'], 'md') ?></div>
        </section>

        <section>
            <div class="row">ຂໍລາພັກ</div>
            <div class="choice-row">
                <span class="choice"><?= checkedCircle($leaveTypeCode === 'SICK') ?> ບໍ່ສະບາຍ</span>
                <span class="choice"><?= checkedCircle($leaveTypeCode === 'MATERNITY') ?> ຄອດລູກ</span>
                <span class="choice"><?= checkedCircle($leaveTypeCode === 'PERSONAL') ?> ກິດສ່ວນຕົວ</span>
            </div>
            <div class="row">ເນື່ອງຈາກ <?= lineValue((string) $request['reason'], 'reason') ?></div>
            <div class="row">
                ແຕ່ວັນທີ <?= lineValue($startDate, 'md') ?>
                ເຖິງວັນທີ <?= lineValue($endDate, 'md') ?>
                ມີກຳນົດ <?= lineValue(formatLeaveDays($request['total_days']), 'sm') ?> ວັນ
            </div>
        </section>

        <section>
            <div class="row">ຂ້າພະເຈົ້າໄດ້ລາພັກ</div>
            <div class="choice-row">
                <span class="choice"><?= checkedCircle((string) ($lastLeave['code'] ?? '') === 'SICK') ?> ບໍ່ສະບາຍ</span>
                <span class="choice"><?= checkedCircle((string) ($lastLeave['code'] ?? '') === 'PERSONAL') ?> ກິດສ່ວນຕົວ</span>
                <span class="choice"><?= checkedCircle((string) ($lastLeave['code'] ?? '') === 'MATERNITY') ?> ຄອດລູກ</span>
            </div>
            <div class="row">
                ຄັ້ງສຸດທ້າຍແຕ່ວັນທີ <?= lineValue($lastLeave ? thaiDate((string) $lastLeave['start_date']) : '', 'md') ?>
                ເຖິງວັນທີ <?= lineValue($lastLeave ? thaiDate((string) $lastLeave['end_date']) : '', 'md') ?>
                ມີກຳນົດ <?= lineValue($lastLeave ? formatLeaveDays($lastLeave['total_days']) : '', 'sm') ?> ວັນ
            </div>
            <div class="row">
                ໃນລະຫວ່າງລາຕິດຕໍ່ຂ້າພະເຈົ້າໄດ້ທີ່ <?= lineValue((string) ($request['email'] ?? ''), 'lg') ?>
                ເບີໂທລະສັບ <?= lineValue((string) ($request['phone'] ?: ''), 'md') ?>
            </div>
            <div class="row">
                ຜູ້ຮັບມອບໝາຍວຽກແທນ <?= lineValue($handoverName, 'lg') ?>
            </div>
        </section>

        <section class="main-grid">
            <div>
                <p class="section-caption">ປະຫວັດການລາພັກປີງົບປະມານນີ້</p>
                <table class="leave-table">
                    <thead>
                        <tr>
                            <th>ປະເພດລາພັກ</th>
                            <th>ລາພັກໄປແລ້ວ</th>
                            <th>ລາພັກຄັ້ງນີ້</th>
                            <th>ລວມເປັນ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($leaveStats as $stat): ?>
                            <?php
                            $usedDays = (float) ($stat['used_days'] ?? 0);
                            $currentDays = (string) $stat['code'] === $leaveTypeCode ? (float) $request['total_days'] : 0.0;
                            ?>
                            <tr>
                                <td><?= h((string) $stat['name']) ?><br><span>(ວັນເຮັດການ)</span></td>
                                <td><?= h(formatLeaveDays($usedDays)) ?></td>
                                <td><?= h($currentDays > 0 ? formatLeaveDays($currentDays) : '-') ?></td>
                                <td><?= h(formatLeaveDays($usedDays + $currentDays)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="signature-block">
                    <div>(ລາຍເຊັນ) <span class="signature-line"></span> ຜູ້ກວດສອບ</div>
                    <div>(<span class="signature-line"></span>)</div>
                    <div>ຕຳແໜ່ງ <span class="position-line"></span></div>
                    <div>ວັນທີ <span class="signature-line"></span></div>
                </div>
            </div>

            <div>
                <div class="signature-block tight">
                    <div>ຂໍສະແດງຄວາມນັບຖື</div>
                    <div class="signature-spacer-lg"></div>
                    <div>(ລາຍເຊັນ) <span class="signature-line"><?= h($employeeName) ?></span></div>
                    <div>ຜູ້ຂໍລາພັກ</div>
                </div>

                <div class="signature-block">
                    <div>ຄວາມເຫັນຜູ້ບັງຄັບບັນຊາ</div>
                    <div class="row compact"><?= lineValue((string) ($request['manager_comment'] ?: ''), 'lg') ?></div>
                    <div class="signature-spacer-md"></div>
                    <div>(ລາຍເຊັນ) <span class="signature-line"><?= h($managerName) ?></span></div>
                    <div>ຕຳແໜ່ງ <span class="position-line">ຫົວໜ້າ</span></div>
                    <div>ວັນທີ <span class="signature-line"><?= h($approvedDate) ?></span></div>
                </div>

                <div class="approval-box">
                    <strong>ຄຳສັ່ງ</strong>
                    <div class="approval-choice">
                        <span class="choice"><?= checkedCircle($status === 'Approved') ?> ອະນຸມັດ</span>
                        <span class="choice"><?= checkedCircle($status === 'Rejected') ?> ບໍ່ອະນຸມັດ <?= lineValue($status === 'Rejected' ? (string) ($request['manager_comment'] ?: '') : '', 'md') ?></span>
                    </div>
                    <div class="signature-block tight">
                        <div>(ລາຍເຊັນ) <span class="signature-line"></span></div>
                        <div>(<span class="signature-line"></span>)</div>
                        <div>ຕຳແໜ່ງ <span class="position-line"></span></div>
                        <div>ວັນທີ <span class="signature-line"></span></div>
                    </div>
                </div>
            </div>
        </section>

        <div class="footer-note">
            <span>ເອກະສານສ້າງຈາກລະບົບຈັດການການລາພັກ</span>
            <span>ສະຖານະ: <?= h($status) ?> · ພິມໂດຍ <?= h((string) $currentUser['name']) ?> · <?= h($printedAt) ?></span>
        </div>
    </main>
</body>
</html>
