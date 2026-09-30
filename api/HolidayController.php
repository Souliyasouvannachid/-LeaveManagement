<?php
/**
 * HolidayController — ຈັດການວັນພັກຜ່ານ REST API
 *
 * GET /api/holidays                    → ສະແດງລາຍຊື່ວັນພັກທັງໝົດ
 * GET /api/holidays/{year}             → ສະແດງວັນພັກຕາມປີ
 */
declare(strict_types=1);

class HolidayController extends BaseController
{
    /**
     * GET /api/holidays ຫຼື GET /api/holidays/{year}
     */
    public function index(): void
    {
        $user = $this->user();

        // ຖ້າ ID ເປັນປີ ໃຫ້ກອງຂໍ້ມູນ
        $year = null;
        if ($this->id !== null && is_numeric($this->id)) {
            $year = (int) $this->id;
        }

        $params = [];
        $yearClause = '';
        if ($year !== null) {
            $yearClause = 'WHERE YEAR(h.holiday_date) = :year';
            $params['year'] = $year;
        }

        $stmt = $this->db->prepare(
            "SELECT h.id, h.name, h.holiday_date, h.type, h.status, h.created_at, h.updated_at
             FROM holidays h
             {$yearClause}
             ORDER BY h.holiday_date ASC"
        );
        $stmt->execute($params);
        $holidays = $stmt->fetchAll();

        $formatted = array_map(function ($row) {
            return [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'holiday_date' => (string) $row['holiday_date'],
                'type' => (string) $row['type'],
                'status' => (string) $row['status'],
                'created_at' => (string) $row['created_at'],
                'updated_at' => (string) $row['updated_at'],
            ];
        }, $holidays);

        $this->success($formatted, 'ເບິ່ງຄືນມື້ພັກສໍາເລັດ');
    }
}
