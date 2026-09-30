<?php
/**
 * NotificationController — ຈັດການການແຈ້ງເຕືອນຜ່ານ REST API
 *
 * GET  /api/notifications              → ສະແດງການແຈ້ງເຕືອນທັງໝົດ
 * GET  /api/notifications/unread       → ສະແດງການແຈ້ງເຕືອນທີ່ຍັງບໍ່ໄດ້ອ່ານ
 * POST /api/notifications/{id}/read    → ເຮັດເຄື່ອງໝາຍວ່າອ່ານແລ້ວ
 */
declare(strict_types=1);

class NotificationController extends BaseController
{
    /** ຈັດການເສັ້ນທາງ: index, unread, ຫຼື ສະແດງລາຍການທັງໝົດ */
    public function index(): void
    {
        $user = $this->user();

        // ຖ້າມີ second segment ເປັນ sub-action (ເຊັ່ນ unread)
        if ($this->id !== null && !is_numeric($this->id)) {
            switch ($this->id) {
                case 'unread':
                    $this->listUnread($user['id']);
                    break;
                default:
                    $this->error('ຄຳສັ່ງຍ່ອຍບໍ່ຖືກຕ້ອງ', 400);
                    break;
            }
        } else {
            $this->listAll($user['id']);
        }
    }

    private function listAll(int $userId): void
    {
        $limit = (int) ($this->input()['limit'] ?? 50);
        $limit = max(1, min($limit, 100));

        $stmt = $this->db->prepare(
            "SELECT id, title, message, link, is_read, created_at
             FROM notifications
             WHERE user_id = :uid
             ORDER BY created_at DESC, id DESC
             LIMIT :limit_rows"
        );
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit_rows', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $notifications = $stmt->fetchAll();

        $formatted = array_map(function ($row) {
            return [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'message' => (string) $row['message'],
                'link' => (string) ($row['link'] ?? ''),
                'is_read' => (int) $row['is_read'],
                'created_at' => (string) $row['created_at'],
            ];
        }, $notifications);

        $this->success($formatted, 'ດຶງຂໍ້ມູນການແຈ້ງເຕືອນສຳເລັດ');
    }

    private function listUnread(int $userId): void
    {
        $stmt = $this->db->prepare(
            "SELECT id, title, message, link, is_read, created_at
             FROM notifications
             WHERE user_id = :uid AND is_read = 0
             ORDER BY created_at DESC, id DESC"
        );
        $stmt->execute(['uid' => $userId]);
        $notifications = $stmt->fetchAll();

        $formatted = array_map(function ($row) {
            return [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'message' => (string) $row['message'],
                'link' => (string) ($row['link'] ?? ''),
                'is_read' => 0,
                'created_at' => (string) $row['created_at'],
            ];
        }, $notifications);

        $this->success($formatted, 'ດຶງຂໍ້ມູນການແຈ້ງເຕືອນທີ່ຍັງບໍ່ໄດ້ອ່ານສຳເລັດ');
    }

    /** POST /api/notifications/{id}/read */
    public function read(): void
    {
        $user = $this->user();
        $notificationId = (int) ($this->id ?? 0);

        if (!$notificationId) {
            $this->error('ບໍ່ລະບຸ ID ການແຈ້ງເຕືອນ', 400);
            return;
        }

        $stmt = $this->db->prepare(
            "UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :uid"
        );
        $stmt->execute([
            'id' => $notificationId,
            'uid' => $user['id'],
        ]);

        if ($stmt->rowCount() === 0) {
            $this->error('ບໍ່ພົບການແຈ້ງເຕືອນທີ່ລະບຸ', 404);
            return;
        }

        $this->success(['id' => $notificationId, 'is_read' => 1], 'ເຮັດເຄື່ອງໝາຍວ່າອ່ານແລ້ວສຳເລັດ');
    }

    /** POST /api/notifications/{id}/unread */
    public function unread(): void
    {
        $user = $this->user();
        $notificationId = (int) ($this->id ?? 0);

        if (!$notificationId) {
            $this->error('ບໍ່ລະບຸ ID ການແຈ້ງເຕືອນ', 400);
            return;
        }

        $stmt = $this->db->prepare(
            "UPDATE notifications SET is_read = 0 WHERE id = :id AND user_id = :uid"
        );
        $stmt->execute([
            'id' => $notificationId,
            'uid' => $user['id'],
        ]);

        if ($stmt->rowCount() === 0) {
            $this->error('ບໍ່ພົບການແຈ້ງເຕືອນທີ່ລະບຸ', 404);
            return;
        }

        $this->success(['id' => $notificationId, 'is_read' => 0], 'ເຮັດເຄື່ອງໝາຍວ່າຍັງບໍ່ໄດ້ອ່ານສຳເລັດ');
    }
}
