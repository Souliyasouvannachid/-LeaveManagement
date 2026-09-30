<?php
/**
 * LeaveRequestController — ຈັດການຄຳຮ້ອງຂໍລາຜ່ານ REST API
 *
 * Business logic ຍ້າຍມາຈາກ public/create.php ແລະ public/my_requests.php
 */
declare(strict_types=1);

class LeaveRequestController extends BaseController
{
    /** GET /api/leave-requests — ສະແດງລາຍການຄຳຮ້ອງຂໍລາ */
    public function index(): void
    {
        $user = $this->user();
        $year = (int) ($this->input()['year'] ?? date('Y'));
        $where = '';
        $params = ['year' => $year];

        if (in_array($user['role'], ['employee'], true)) {
            $where = 'WHERE lr.user_id = :uid';
            $params['uid'] = $user['id'];
        } elseif (in_array($user['role'], ['manager'], true)) {
            $where = 'WHERE lr.user_id = :uid OR lr.manager_id = :mid';
            $params['uid'] = $user['id'];
            $params['mid'] = $user['id'];
        }

        $where .= ($where === '' ? ' WHERE ' : ' AND ') . 'YEAR(lr.start_date) = :year';

        $stmt = $this->db->prepare(
            "SELECT lr.id, lr.request_no, lr.start_date, lr.end_date, lr.start_period, lr.end_period,
                    lr.total_days, lr.reason, lr.status, lr.created_at, lr.updated_at,
                    lt.name AS leave_type_name, lt.color AS leave_type_color,
                    u.first_name, u.last_name
             FROM leave_requests lr
             INNER JOIN leave_types lt ON lt.id = lr.leave_type_id
             INNER JOIN users u ON u.id = lr.user_id
             {$where}
             ORDER BY lr.created_at DESC"
        );
        $stmt->execute($params);
        $requests = $stmt->fetchAll();

        $this->success(array_map([$this, 'formatRequest'], $requests), 'ດຶງຂໍ້ມູນຄຳຮ້ອງຂໍລາສຳເລັດ');
    }

    /** POST /api/leave-requests — ສ້າງຄຳຮ້ອງຂໍລາໃໝ່ */
    public function create(): void
    {
        $user = $this->user();
        $input = $this->input();

        $errors = $this->validateRequired($input, ['leave_type_id', 'start_date', 'end_date', 'period', 'reason']);

        if (!empty($errors)) {
            $this->error('ຂໍ້ມູນບໍ່ຄົບຖ້ວນ', 422, $errors);
            return;
        }

        $leaveTypeId = (int) $input['leave_type_id'];
        $startDate = $input['start_date'];
        $endDate = $input['end_date'];
        $period = $input['period'];
        $reason = trim($input['reason']);
        $handoverToUserId = !empty($input['handover_to_user_id']) ? (int) $input['handover_to_user_id'] : null;
        $currentYear = (int) date('Y');

        $ltStmt = $this->db->prepare(
            "SELECT id, name, annual_quota, allow_half_day, require_attachment,
                    min_notice_days, max_days_per_request, color
             FROM leave_types WHERE id = :id AND status = 'active'"
        );
        $ltStmt->execute(['id' => $leaveTypeId]);
        $leaveType = $ltStmt->fetch();

        if (!$leaveType) {
            $this->error('ປະເພດວັນລາບໍ່ຖືກຕ້ອງ ຫຼື ບໍ່ໄດ້ເປີດໃຊ້ງານ', 400);
            return;
        }

        $startDt = DateTimeImmutable::createFromFormat('Y-m-d', $startDate);
        $endDt = DateTimeImmutable::createFromFormat('Y-m-d', $endDate);
        $today = new DateTimeImmutable('today');

        if (!$startDt || !$endDt || $startDt->format('Y-m-d') !== $startDate || $endDt->format('Y-m-d') !== $endDate) {
            $this->error('ຮູບແບບວັນທີບໍ່ຖືກຕ້ອງ', 400);
            return;
        }

        if ($startDt < $today) {
            $this->error('ບໍ່ສາມາດລາຍ້ອນຫຼັງໄດ້', 400);
            return;
        }

        if ($endDt < $startDt) {
            $this->error('ວັນທີສິ້ນສຸດຕ້ອງບໍ່ນ້ອຍກວ່າວັນທີເລີ່ມ', 400);
            return;
        }

        $validPeriods = ['full_day', 'morning', 'afternoon'];
        if (!in_array($period, $validPeriods, true)) {
            $this->error('ໄລຍະເວລາບໍ່ຖືກຕ້ອງ', 400);
            return;
        }

        if ($period !== 'full_day' && $startDate !== $endDate) {
            $this->error('ການລາເຉພາະເວລາຕ້ອງເລືອກມື້ດຽວກັນເທົ່ານັ້ນ', 400);
            return;
        }

        if ($period !== 'full_day' && (int) $leaveType['allow_half_day'] !== 1) {
            $this->error('ປະເພດວັນລານີ້ບໍ່ຮອງຮັບການລາເຉພາະເວລາ', 400);
            return;
        }

        $holidays = $this->fetchHolidays($currentYear);
        $totalDays = $this->calculateLeaveDays($startDate, $endDate, $period, $holidays);

        if ($totalDays <= 0) {
            $this->error('ໄລຍະວັນທີທີ່ເລືອກບໍ່ມີວັນເຮັດວຽກທີ່ສາມາດລາໄດ້', 400);
            return;
        }

        if ($this->hasOverlappingLeave($user['id'], $startDate, $endDate)) {
            $this->error('ມີຄຳຮ້ອງຂໍລາທີ່ຊ້ອນກັບໄລຍະເວລາທີ່ເລືອກ', 409);
            return;
        }

        $minNoticeDays = max(0, (int) $leaveType['min_notice_days']);
        if ($minNoticeDays > 0) {
            $noticeDays = (int) $today->diff($startDt)->format('%r%a');
            if ($noticeDays < $minNoticeDays) {
                $this->error("ປະເພດວັນລານີ້ຕ້ອງລາລ່ວງໜ້າຢ່າງໜ້ອຍ {$minNoticeDays} ວັນ", 400);
                return;
            }
        }

        if ($leaveType['max_days_per_request'] !== null && $totalDays > (float) $leaveType['max_days_per_request']) {
            $this->error(
                'ປະເພດວັນລານີ້ສູງສຸດ ' . number_format((float) $leaveType['max_days_per_request'], 1) . ' ວັນຕໍ່ຄຳຮ້ອງ',
                400
            );
            return;
        }

        if ((int) $leaveType['require_attachment'] === 1) {
            $this->error('ປະເພດວັນລານີ້ຕ້ອງແນບໄຟລ໌', 400);
            return;
        }

        $this->db->beginTransaction();

        try {
            ensureLeaveBalance($this->db, $user['id'], $leaveTypeId, $currentYear);

            $balStmt = $this->db->prepare(
                "SELECT id, remaining_days FROM leave_balances
                 WHERE user_id = :uid AND leave_type_id = :ltid AND year = :year
                 FOR UPDATE"
            );
            $balStmt->execute(['uid' => $user['id'], 'ltid' => $leaveTypeId, 'year' => $currentYear]);
            $lockedBalance = $balStmt->fetch();

            if (!$lockedBalance || (float) $lockedBalance['remaining_days'] < $totalDays) {
                throw new RuntimeException('ຍອດວັນລາຄົງເຫຼືອບໍ່ພຽງພໍສຳລັບຄຳຮ້ອງນີ້');
            }

            $requestNo = $this->generateRequestNo($currentYear);
            $mgrStmt = $this->db->prepare('SELECT manager_id FROM users WHERE id = :id LIMIT 1');
            $mgrStmt->execute(['id' => $user['id']]);
            $managerId = $mgrStmt->fetchColumn() ?: null;

            $insStmt = $this->db->prepare(
                "INSERT INTO leave_requests (
                    request_no, user_id, leave_type_id, start_date, end_date,
                    start_period, end_period, total_days, reason,
                    handover_to_user_id, attachment, status, manager_id,
                    created_at, updated_at
                ) VALUES (
                    :request_no, :uid, :ltid, :start, :end,
                    :start_period, :end_period, :total, :reason,
                    :handover, :attachment, 'Pending', :manager,
                    NOW(), NOW()
                )"
            );
            $insStmt->execute([
                'request_no' => $requestNo, 'uid' => $user['id'], 'ltid' => $leaveTypeId,
                'start' => $startDate, 'end' => $endDate, 'start_period' => $period,
                'end_period' => $period, 'total' => $totalDays, 'reason' => $reason,
                'handover' => $handoverToUserId, 'attachment' => null, 'manager' => $managerId ?: null,
            ]);

            $leaveRequestId = (int) $this->db->lastInsertId();

            $updBalStmt = $this->db->prepare(
                "UPDATE leave_balances
                 SET pending_days = pending_days + :p1, remaining_days = remaining_days - :p2, updated_at = NOW()
                 WHERE id = :id"
            );
            $updBalStmt->execute(['p1' => $totalDays, 'p2' => $totalDays, 'id' => (int) $lockedBalance['id']]);

            if ($managerId) {
                $notifStmt = $this->db->prepare(
                    "INSERT INTO notifications (user_id, title, message, link, is_read, created_at)
                     VALUES (:uid, :title, :msg, :link, 0, NOW())"
                );
                $notifStmt->execute([
                    'uid' => (int) $managerId, 'title' => 'ມີຄຳຮ້ອງຂໍລາພັກລໍຖ້າການອະນຸມັດ',
                    'msg' => 'ທ່ານ ' . $user['name'] . ' ສົ່ງຄຳຮ້ອງຂໍລາ ' . $requestNo, 'link' => 'approvals.php',
                ]);
            }

            $auditStmt = $this->db->prepare(
                "INSERT INTO audit_logs (user_id, action, entity_type, entity_id, old_value, new_value, ip_address, user_agent, created_at)
                 VALUES (:uid, 'create_leave_request', 'leave_requests', :eid, NULL, :new_val, :ip, :ua, NOW())"
            );
            $auditStmt->execute([
                'uid' => $user['id'], 'eid' => $leaveRequestId,
                'new_val' => json_encode(['request_no' => $requestNo, 'status' => 'Pending', 'total_days' => $totalDays], JSON_UNESCAPED_UNICODE),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);

            $this->db->commit();

            $this->success(
                $this->formatRequest($this->fetchRequestById($leaveRequestId)),
                'ສົ່ງຄຳຮ້ອງຂໍລາສຳເລັດ ເລກທີ ' . $requestNo,
                201
            );
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log($e->getMessage());
            $this->error('ບໍ່ສາມາດສ້າງຄຳຮ້ອງຂໍລາໄດ້: ' . $e->getMessage(), 500);
        }
    }

    /** GET /api/leave-requests/{id} — ສະແດງລາຍລະອຽດຄຳຮ້ອງຂໍລາ */
    public function show(): void
    {
        $user = $this->user();
        $requestId = (int) ($this->id ?? 0);

        if (!$requestId) {
            $this->error('ບໍ່ລະບຸ ID ຄຳຮ້ອງຂໍລາ', 400);
            return;
        }

        $stmt = $this->db->prepare(
            "SELECT lr.id, lr.request_no, lr.start_date, lr.end_date, lr.start_period, lr.end_period,
                    lr.total_days, lr.reason, lr.attachment, lr.status, lr.manager_comment,
                    lr.approved_at, lr.rejected_at, lr.cancelled_at, lr.created_at, lr.updated_at,
                    lt.name AS leave_type_name, lt.color AS leave_type_color,
                    u.first_name, u.last_name, u.employee_code, u.email,
                    d.name AS department_name,
                    hand.first_name AS handover_first_name, hand.last_name AS handover_last_name
             FROM leave_requests lr
             INNER JOIN leave_types lt ON lt.id = lr.leave_type_id
             INNER JOIN users u ON u.id = lr.user_id
             LEFT JOIN departments d ON d.id = u.department_id
             LEFT JOIN users hand ON hand.id = lr.handover_to_user_id
             WHERE lr.id = :id"
        );
        $stmt->execute(['id' => $requestId]);
        $request = $stmt->fetch();

        if (!$request) {
            $this->error('ບໍ່ພົບຄຳຮ້ອງຂໍລາທີ່ລະບຸ', 404);
            return;
        }

        $this->success($this->formatRequestFull($request), 'ດຶງຂໍ້ມູນຄຳຮ້ອງຂໍລາສຳເລັດ');
    }

    /** POST /api/leave-requests/{id}/cancel — ຍົກເລີກຄຳຮ້ອງຂໍລາ */
    public function cancel(): void
    {
        $user = $this->user();
        $requestId = (int) ($this->id ?? 0);

        if (!$requestId) {
            $this->error('ບໍ່ລະບຸ ID ຄຳຮ້ອງຂໍລາ', 400);
            return;
        }

        $this->db->beginTransaction();

        try {
            $reqStmt = $this->db->prepare(
                "SELECT lr.id, lr.request_no, lr.user_id, lr.leave_type_id, lr.manager_id,
                        lr.start_date, lr.total_days, lr.status
                 FROM leave_requests lr
                 WHERE lr.id = :id AND lr.user_id = :uid AND lr.status = 'Pending' LIMIT 1
                 FOR UPDATE"
            );
            $reqStmt->execute(['id' => $requestId, 'uid' => $user['id']]);
            $request = $reqStmt->fetch();

            if (!$request) {
                throw new RuntimeException('ບໍ່ພົບຄຳຮ້ອງຂໍລາທີ່ລໍຖ້າການອະນຸມັດຂອງທ່ານ');
            }

            $year = (int) (new DateTimeImmutable($request['start_date']))->format('Y');
            ensureLeaveBalance($this->db, (int) $request['user_id'], (int) $request['leave_type_id'], $year);

            $cancelStmt = $this->db->prepare(
                "UPDATE leave_requests SET status = 'Cancelled', cancelled_at = NOW(), updated_at = NOW() WHERE id = :id"
            );
            $cancelStmt->execute(['id' => (int) $request['id']]);

            syncLeaveBalance($this->db, (int) $request['user_id'], (int) $request['leave_type_id'], $year);

            if (!empty($request['manager_id'])) {
                $notifStmt = $this->db->prepare(
                    "INSERT INTO notifications (user_id, title, message, link, is_read, created_at)
                     VALUES (:uid, :title, :msg, :link, 0, NOW())"
                );
                $notifStmt->execute([
                    'uid' => (int) $request['manager_id'], 'title' => 'ຄຳຮ້ອງຂໍລາຖືກຍົກເລີກ',
                    'msg' => 'ທ່ານ ' . $user['name'] . ' ຍົກເລີກຄຳຮ້ອງຂໍລາ ' . $request['request_no'], 'link' => 'approvals.php',
                ]);
            }

            $auditStmt = $this->db->prepare(
                "INSERT INTO audit_logs (user_id, action, entity_type, entity_id, old_value, new_value, ip_address, user_agent, created_at)
                 VALUES (:uid, 'cancel_leave_request', 'leave_requests', :eid, :old, :new, :ip, :ua, NOW())"
            );
            $auditStmt->execute([
                'uid' => $user['id'], 'eid' => (int) $request['id'],
                'old' => json_encode(['status' => 'Pending'], JSON_UNESCAPED_UNICODE),
                'new' => json_encode(['status' => 'Cancelled'], JSON_UNESCAPED_UNICODE),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);

            $this->db->commit();

            $this->success(
                ['request_no' => $request['request_no'], 'status' => 'Cancelled'],
                'ຍົກເລີກຄຳຮ້ອງຂໍລາ ' . $request['request_no'] . ' ສຳເລັດ'
            );
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log($e->getMessage());
            $msg = $e instanceof RuntimeException ? $e->getMessage() : 'ບໍ່ສາມາດຍົກເລີກຄຳຮ້ອງຂໍລາໄດ້';
            $code = $e instanceof RuntimeException ? 404 : 500;
            $this->error($msg, $code);
        }
    }

    // ======================== ຟັງຊັນຊ່ວຍ ========================

    private function fetchHolidays(int $year): array
    {
        $stmt = $this->db->prepare(
            "SELECT holiday_date FROM holidays WHERE status = 'active' AND YEAR(holiday_date) IN (:y1, :y2)"
        );
        $stmt->execute(['y1' => $year, 'y2' => $year + 1]);
        return array_map(static fn ($row) => (string) $row['holiday_date'], $stmt->fetchAll());
    }

    private function calculateLeaveDays(string $startDate, string $endDate, string $period, array $holidays): float
    {
        $businessDays = $this->businessDaysBetween($startDate, $endDate, $holidays);
        if ($businessDays <= 0) {
            return 0.0;
        }
        if ($period !== 'full_day' && $startDate === $endDate) {
            return 0.5;
        }
        return (float) $businessDays;
    }

    private function businessDaysBetween(string $startDate, string $endDate, array $holidays): int
    {
        $start = new DateTimeImmutable($startDate);
        $end = new DateTimeImmutable($endDate);
        $days = 0;
        for ($date = $start; $date <= $end; $date = $date->modify('+1 day')) {
            $dayOfWeek = (int) $date->format('N');
            $dateKey = $date->format('Y-m-d');
            if ($dayOfWeek >= 6 || in_array($dateKey, $holidays, true)) {
                continue;
            }
            $days++;
        }
        return $days;
    }

    private function hasOverlappingLeave(int $userId, string $startDate, string $endDate): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM leave_requests
             WHERE user_id = :uid AND status IN ('Pending', 'Approved')
               AND start_date <= :end AND end_date >= :start"
        );
        $stmt->execute(['uid' => $userId, 'start' => $startDate, 'end' => $endDate]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function generateRequestNo(int $year): string
    {
        $pdo = $this->db;
        $prefix = sprintf('LV-%d-', $year);
        $stmt = $pdo->prepare(
            "SELECT request_no FROM leave_requests WHERE request_no LIKE :prefix ORDER BY request_no DESC LIMIT 1 FOR UPDATE"
        );
        $stmt->execute(['prefix' => $prefix . '%']);
        $lastRequestNo = $stmt->fetchColumn();
        $nextNumber = 1;
        if (is_string($lastRequestNo) && preg_match('/^LV-\d{4}-(\d{6})$/', $lastRequestNo, $matches)) {
            $nextNumber = (int) $matches[1] + 1;
        }
        return $prefix . str_pad((string) $nextNumber, 6, '0', STR_PAD_LEFT);
    }

    private function fetchRequestById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT lr.id, lr.request_no, lr.start_date, lr.end_date, lr.total_days,
                    lr.status, lr.created_at, lr.updated_at,
                    lt.name AS leave_type_name, lt.color AS leave_type_color,
                    u.first_name, u.last_name
             FROM leave_requests lr
             INNER JOIN leave_types lt ON lt.id = lr.leave_type_id
             INNER JOIN users u ON u.id = lr.user_id
             WHERE lr.id = :id"
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    private function formatRequest(?array $row): array
    {
        if (!$row) return [];
        return [
            'id' => (int) $row['id'],
            'request_no' => (string) $row['request_no'],
            'leave_type' => (string) $row['leave_type_name'],
            'leave_type_color' => (string) $row['leave_type_color'],
            'employee' => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
            'start_date' => (string) $row['start_date'],
            'end_date' => (string) $row['end_date'],
            'total_days' => (float) $row['total_days'],
            'status' => (string) $row['status'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    private function formatRequestFull(array $row): array
    {
        $base = $this->formatRequest($row);
        return array_merge($base, [
            'reason' => (string) ($row['reason'] ?? ''),
            'start_period' => (string) ($row['start_period'] ?? ''),
            'end_period' => (string) ($row['end_period'] ?? ''),
            'attachment' => (string) ($row['attachment'] ?? ''),
            'manager_comment' => (string) ($row['manager_comment'] ?? ''),
            'approved_at' => (string) ($row['approved_at'] ?? ''),
            'rejected_at' => (string) ($row['rejected_at'] ?? ''),
            'cancelled_at' => (string) ($row['cancelled_at'] ?? ''),
            'employee_code' => (string) ($row['employee_code'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'department' => (string) ($row['department_name'] ?? ''),
            'handover_to' => trim(($row['handover_first_name'] ?? '') . ' ' . ($row['handover_last_name'] ?? '')),
        ]);
    }
}
