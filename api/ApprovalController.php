<?php
/**
 * ApprovalController — ຈັດການການອະນຸມັດ/ປະຕິເສດຄຳຮ້ອງຂໍລາຜ່ານ REST API
 * ໃຊ້ logic ກົງກັບ public/approvals.php
 * ສະເພາະ manager ແລະ admin ເທົ່ານັ້ນທີ່ເຂົ້າເຖິງໄດ້
 */
declare(strict_types=1);

class ApprovalController extends BaseController
{
    /** GET /api/approvals — ສະແດງຄຳຮ້ອງຂໍລາທີ່ລໍຖ້າການອະນຸມັດຂອງທີມ */
    public function index(): void
    {
        $user = $this->user();

        if (!in_array($user['role'], ['manager', 'admin'], true)) {
            $this->error('ທ່ານບໍ່ມີສິດເຂົ້າເຖິງໜ້າລໍຖ້າການອະນຸມັດ', 403);
            return;
        }

        $stmt = $this->db->prepare(
            "SELECT lr.id, lr.request_no, lr.start_date, lr.end_date, lr.start_period,
                    lr.total_days, lr.reason, lr.attachment, lr.status, lr.created_at,
                    u.first_name, u.last_name, u.employee_code, u.email,
                    lt.name AS leave_type_name, lt.color AS leave_type_color,
                    hand.first_name AS handover_first_name, hand.last_name AS handover_last_name
             FROM leave_requests lr
             INNER JOIN users u ON u.id = lr.user_id
             INNER JOIN leave_types lt ON lt.id = lr.leave_type_id
             LEFT JOIN users hand ON hand.id = lr.handover_to_user_id
             WHERE lr.manager_id = :manager_id AND lr.status = 'Pending'
             ORDER BY lr.created_at ASC"
        );
        $stmt->execute(['manager_id' => $user['id']]);
        $requests = $stmt->fetchAll();

        $formatted = array_map(function ($row) {
            return [
                'id' => (int) $row['id'],
                'request_no' => (string) $row['request_no'],
                'employee' => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
                'employee_code' => (string) ($row['employee_code'] ?? ''),
                'email' => (string) ($row['email'] ?? ''),
                'leave_type' => (string) ($row['leave_type_name'] ?? ''),
                'leave_type_color' => (string) ($row['leave_type_color'] ?? ''),
                'start_date' => (string) $row['start_date'],
                'end_date' => (string) $row['end_date'],
                'total_days' => (float) $row['total_days'],
                'reason' => (string) ($row['reason'] ?? ''),
                'status' => (string) $row['status'],
                'created_at' => (string) $row['created_at'],
                'handover_to' => trim(($row['handover_first_name'] ?? '') . ' ' . ($row['handover_last_name'] ?? '')),
            ];
        }, $requests);

        $this->success($formatted, 'ດຶງຂໍ້ມູນຄຳຮ້ອງຂໍລາທີ່ລໍຖ້າການອະນຸມັດສຳເລັດ');
    }

    /** POST /api/approvals/{id}/approve — ອະນຸມັດຄຳຮ້ອງຂໍລາ */
    public function approve(): void
    {
        $user = $this->user();

        if (!in_array($user['role'], ['manager', 'admin'], true)) {
            $this->error('ທ່ານບໍ່ມີສິດອະນຸມັດຄຳຮ້ອງຂໍລາ', 403);
            return;
        }

        $requestId = (int) ($this->id ?? 0);
        if (!$requestId) {
            $this->error('ບໍ່ລະບຸ ID ຄຳຮ້ອງຂໍລາ', 400);
            return;
        }

        $input = $this->input();
        $managerComment = trim((string) ($input['manager_comment'] ?? ''));
        if ($managerComment === '') {
            $managerComment = 'ອະນຸມັດ';
        }

        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare(
                "SELECT lr.id, lr.request_no, lr.user_id, lr.leave_type_id, lr.total_days, lr.status
                 FROM leave_requests lr
                 WHERE lr.id = :id AND lr.manager_id = :manager_id AND lr.status = 'Pending' LIMIT 1
                 FOR UPDATE"
            );
            $stmt->execute([
                'id' => $requestId,
                'manager_id' => $user['id'],
            ]);
            $request = $stmt->fetch();

            if (!$request) {
                throw new RuntimeException('ບໍ່ພົບຄຳຮ້ອງຂໍລາທີ່ລໍຖ້າການອະນຸມັດຂອງທີມງານ');
            }

            $totalDays = (float) $request['total_days'];
            $year = (int) date('Y');

            ensureLeaveBalance($this->db, (int) $request['user_id'], (int) $request['leave_type_id'], $year);

            // ອັບເດດສະຖານະເປັນ Approved
            $updateStmt = $this->db->prepare(
                "UPDATE leave_requests SET status = 'Approved', manager_comment = :comment, approved_at = NOW(), updated_at = NOW() WHERE id = :id"
            );
            $updateStmt->execute([
                'comment' => $managerComment,
                'id' => (int) $request['id'],
            ]);

            // ອັບເດດຍອດເຫຼືອ
            $balUpdateStmt = $this->db->prepare(
                "UPDATE leave_balances SET used_days = used_days + :used, pending_days = GREATEST(pending_days - :pending, 0), updated_at = NOW()
                 WHERE user_id = :uid AND leave_type_id = :ltid AND year = :year_id"
            );
            $balUpdateStmt->execute([
                'used' => $totalDays,
                'pending' => $totalDays,
                'uid' => (int) $request['user_id'],
                'ltid' => (int) $request['leave_type_id'],
                'year_id' => $year,
            ]);

            // ແຈ້ງເຕືອນພະນັກງານ
            $notifStmt = $this->db->prepare(
                "INSERT INTO notifications (user_id, title, message, link, is_read, created_at)
                 VALUES (:uid, :title, :msg, :link, 0, NOW())"
            );
            $notifStmt->execute([
                'uid' => (int) $request['user_id'],
                'title' => 'ຄຳຮ້ອງຂໍລາໄດ້ຮັບການອະນຸມັດ',
                'msg' => 'ຄຳຮ້ອງຂໍລາ ' . $request['request_no'] . ' ໄດ້ຮັບການອະນຸມັດແລ້ວ',
                'link' => 'my_requests.php',
            ]);

            // Audit log
            $auditStmt = $this->db->prepare(
                "INSERT INTO audit_logs (user_id, action, entity_type, entity_id, old_value, new_value, ip_address, user_agent, created_at)
                 VALUES (:uid, 'approve_leave_request', 'leave_requests', :eid, :old, :new, :ip, :ua, NOW())"
            );
            $auditStmt->execute([
                'uid' => $user['id'],
                'eid' => (int) $request['id'],
                'old' => json_encode(['status' => 'Pending'], JSON_UNESCAPED_UNICODE),
                'new' => json_encode(['status' => 'Approved', 'manager_comment' => $managerComment, 'total_days' => $totalDays], JSON_UNESCAPED_UNICODE),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);

            $this->db->commit();

            $this->success([
                'request_no' => $request['request_no'],
                'status' => 'Approved',
            ], 'ອະນຸມັດຄຳຮ້ອງຂໍລາ ' . $request['request_no'] . ' ສຳເລັດ');
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log($e->getMessage());
            $msg = $e instanceof RuntimeException ? $e->getMessage() : 'ບໍ່ສາມາດອະນຸມັດຄຳຮ້ອງໄດ້';
            $code = $e instanceof RuntimeException ? 404 : 500;
            $this->error($msg, $code);
        }
    }

    /** POST /api/approvals/{id}/reject — ບໍ່ອະນຸມັດຄຳຮ້ອງຂໍລາ */
    public function reject(): void
    {
        $user = $this->user();

        if (!in_array($user['role'], ['manager', 'admin'], true)) {
            $this->error('ທ່ານບໍ່ມີສິດປະຕິເສດຄຳຮ້ອງຂໍລາ', 403);
            return;
        }

        $requestId = (int) ($this->id ?? 0);
        if (!$requestId) {
            $this->error('ບໍ່ລະບຸ ID ຄຳຮ້ອງຂໍລາ', 400);
            return;
        }

        $input = $this->input();
        $managerComment = trim((string) ($input['manager_comment'] ?? ''));

        if ($managerComment === '') {
            $this->error('ກະລຸນາລະບຸເຫດຜົນທີ່ບໍ່ອະນຸມັດ', 422);
            return;
        }

        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare(
                "SELECT lr.id, lr.request_no, lr.user_id, lr.leave_type_id, lr.total_days, lr.status
                 FROM leave_requests lr
                 WHERE lr.id = :id AND lr.manager_id = :manager_id AND lr.status = 'Pending' LIMIT 1
                 FOR UPDATE"
            );
            $stmt->execute([
                'id' => $requestId,
                'manager_id' => $user['id'],
            ]);
            $request = $stmt->fetch();

            if (!$request) {
                throw new RuntimeException('ບໍ່ພົບຄຳຮ້ອງຂໍລາທີ່ລໍຖ້າການອະນຸມັດຂອງທີມງານ');
            }

            $totalDays = (float) $request['total_days'];
            $year = (int) date('Y');

            ensureLeaveBalance($this->db, (int) $request['user_id'], (int) $request['leave_type_id'], $year);

            // ອັບເດດສະຖານະເປັນ Rejected
            $updateStmt = $this->db->prepare(
                "UPDATE leave_requests SET status = 'Rejected', manager_comment = :comment, rejected_at = NOW(), updated_at = NOW() WHERE id = :id"
            );
            $updateStmt->execute([
                'comment' => $managerComment,
                'id' => (int) $request['id'],
            ]);

            // ຄືນຍອດທີ່ຖືກລັອກໄປ
            $balUpdateStmt = $this->db->prepare(
                "UPDATE leave_balances
                 SET pending_days = GREATEST(pending_days - :pending_days, 0),
                     remaining_days = LEAST(
                         GREATEST(0, entitled_days + adjusted_days - used_days),
                         remaining_days + :remaining_days
                     ),
                     updated_at = NOW()
                 WHERE user_id = :uid AND leave_type_id = :ltid AND year = :year_id"
            );
            $balUpdateStmt->execute([
                'pending_days' => $totalDays,
                'remaining_days' => $totalDays,
                'uid' => (int) $request['user_id'],
                'ltid' => (int) $request['leave_type_id'],
                'year_id' => $year,
            ]);

            // ແຈ້ງເຕືອນພະນັກງານ
            $notifStmt = $this->db->prepare(
                "INSERT INTO notifications (user_id, title, message, link, is_read, created_at)
                 VALUES (:uid, :title, :msg, :link, 0, NOW())"
            );
            $notifStmt->execute([
                'uid' => (int) $request['user_id'],
                'title' => 'ຄຳຮ້ອງຂໍລາບໍ່ໄດ້ຮັບການອະນຸມັດ',
                'msg' => 'ຄຳຮ້ອງຂໍລາ ' . $request['request_no'] . ' ບໍ່ຜ່ານການອະນຸມັດ: ' . $managerComment,
                'link' => 'my_requests.php',
            ]);

            // Audit log
            $auditStmt = $this->db->prepare(
                "INSERT INTO audit_logs (user_id, action, entity_type, entity_id, old_value, new_value, ip_address, user_agent, created_at)
                 VALUES (:uid, 'reject_leave_request', 'leave_requests', :eid, :old, :new, :ip, :ua, NOW())"
            );
            $auditStmt->execute([
                'uid' => $user['id'],
                'eid' => (int) $request['id'],
                'old' => json_encode(['status' => 'Pending'], JSON_UNESCAPED_UNICODE),
                'new' => json_encode(['status' => 'Rejected', 'manager_comment' => $managerComment, 'total_days' => $totalDays], JSON_UNESCAPED_UNICODE),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);

            $this->db->commit();

            $this->success([
                'request_no' => $request['request_no'],
                'status' => 'Rejected',
            ], 'ປະຕິເສດຄຳຮ້ອງຂໍລາ ' . $request['request_no'] . ' ສຳເລັດ');
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log($e->getMessage());
            $msg = $e instanceof RuntimeException ? $e->getMessage() : 'ບໍ່ສາມາດປະຕິເສດຄຳຮ້ອງໄດ້';
            $code = $e instanceof RuntimeException ? 404 : 500;
            $this->error($msg, $code);
        }
    }
}
