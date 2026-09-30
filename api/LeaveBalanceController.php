<?php
/**
 * LeaveBalanceController — ຈັດການຍອດວັນລາຜ່ານ REST API
 *
 * GET /api/leave-balances              → ສະແດງຍອດວັນລາທັງໝົດຂອງຜູ້ໃຊ້
 * GET /api/leave-balances/{year}       → ສະແດງຍອດວັນລາຕາມປີ
 */
declare(strict_types=1);

class LeaveBalanceController extends BaseController
{
    public function index(): void
    {
        $user = $this->user();
        $year = $this->id !== null && is_numeric($this->id)
            ? (int) $this->id
            : (int) ($this->input()['year'] ?? date('Y'));

        $stmt = $this->db->prepare(
            "SELECT lb.id, lb.user_id, lb.leave_type_id, lb.year,
                    lb.entitled_days, lb.used_days, lb.pending_days,
                    lb.remaining_days, lb.adjusted_days,
                    lt.name AS leave_type_name, lt.code AS leave_type_code,
                    lt.color AS leave_type_color, lt.annual_quota
             FROM leave_balances lb
             INNER JOIN leave_types lt ON lt.id = lb.leave_type_id
             WHERE lb.user_id = :user_id AND lb.year = :year
             ORDER BY lt.id"
        );
        $stmt->execute(['user_id' => $user['id'], 'year' => $year]);
        $balances = $stmt->fetchAll();

        $formatted = array_map(function ($row) {
            return [
                'id' => (int) $row['id'],
                'leave_type_id' => (int) $row['leave_type_id'],
                'leave_type_name' => (string) $row['leave_type_name'],
                'leave_type_code' => (string) $row['leave_type_code'],
                'color' => (string) $row['leave_type_color'],
                'year' => (int) $row['year'],
                'entitled_days' => (float) $row['entitled_days'],
                'used_days' => (float) $row['used_days'],
                'pending_days' => (float) $row['pending_days'],
                'remaining_days' => (float) $row['remaining_days'],
                'adjusted_days' => (float) $row['adjusted_days'],
                'annual_quota' => (float) $row['annual_quota'],
            ];
        }, $balances);

        $this->success($formatted, 'ດຶງຂໍ້ມູນຍອດວັນລາສຳເລັດ');
    }
}
