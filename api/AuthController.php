<?php
/**
 * AuthController — ຈັດການການເຂົ້າສູ່ລະບົບ ແລະ ອອກ token ສຳລັບ API
 */
declare(strict_types=1);

class AuthController extends BaseController
{
    public function login(): void
    {
        $input = $this->input();
        $email = trim((string) ($input['email'] ?? ''));
        $password = (string) ($input['password'] ?? '');

        if ($email === '' || $password === '') {
            $this->error('ກະລຸນາລະບຸອີເມວ ແລະ ລະຫັດຜ່ານ', 422);
            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('ຮູບແບບອີເມວບໍ່ຖືກຕ້ອງ', 422);
            return;
        }

        try {
            $stmt = $this->db->prepare(
                "SELECT id, employee_code, first_name, last_name, email, password, role, department_id, status
                 FROM users WHERE email = :email LIMIT 1"
            );
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($password, (string) $user['password'])) {
                $this->error('ອີເມວ ຫຼື ລະຫັດຜ່ານບໍ່ຖືກຕ້ອງ', 401);
                return;
            }

            if ($user['status'] !== 'active') {
                $this->error('ບັນຊີຜູ້ໃຊ້ນີ້ຖືກປິດໃຊ້ງານ', 403);
                return;
            }

            $token = $this->generateUserToken((int) $user['id'], (string) $user['email']);

            $this->success([
                'token' => $token,
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'user' => [
                    'id' => (int) $user['id'],
                    'employee_code' => (string) $user['employee_code'],
                    'name' => trim($user['first_name'] . ' ' . $user['last_name']),
                    'email' => (string) $user['email'],
                    'role' => (string) $user['role'],
                    'department_id' => $user['department_id'] !== null ? (int) $user['department_id'] : null,
                ],
            ], 'ເຂົ້າສູ່ລະບົບສຳເລັດ', 200);
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $this->error('ບໍ່ສາມາດເຊື່ອມຕໍ່ຖານຂໍ້ມູນໄດ້', 500);
        }
    }

    private function generateUserToken(int $userId, string $email): string
    {
        $secret = getenv('API_TOKEN_SECRET') ?: 'leave-management-secret';
        $payload = [
            'sub' => $userId,
            'email' => $email,
            'iat' => time(),
            'exp' => time() + 3600,
            'data' => ['user_id' => $userId],
        ];
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', $payloadJson, $secret);
        return base64_encode($payloadJson) . '.' . $signature;
    }
}
