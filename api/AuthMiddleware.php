<?php
/**
 * Auth Middleware — ກວດສອບ Bearer token ແລະ ໂຫຼດຂໍ້ມູນຜູ້ໃຊ້
 */
declare(strict_types=1);

class AuthMiddleware
{
    private static ?array $currentUser = null;

    public static function requireAuth(): void
    {
        $token = self::extractBearerToken();

        if ($token === null) {
            throw new Exception('ບໍ່ພົບຂໍ້ມູນການຢືນຢັນ ຫຼື ຂໍ້ມູນບໍ່ຖືກຕ້ອງ; ກະລຸນາລະບຸ Bearer token ທີ່ຖືກຕ້ອງ', 401);
        }

        $payload = verify_api_token($token);

        if ($payload === null) {
            throw new Exception('API token ບໍ່ຖືກຕ້ອງ ຫຼື ໝົດອາຍຸແລ້ວ', 401);
        }

        self::$currentUser = self::loadUser($payload['sub'] ?? $payload['data']['user_id'] ?? null);

        if (self::$currentUser === null) {
            throw new Exception('ບໍ່ພົບຜູ້ໃຊ້ສຳລັບ token ນີ້', 401);
        }
    }

    private static function extractBearerToken(): ?string
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return trim($matches[1]);
        }

        $queryToken = $_GET['token'] ?? null;
        if ($queryToken) {
            return $queryToken;
        }

        return null;
    }

    private static function loadUser(?int $userId): ?array
    {
        if ($userId === null) {
            return null;
        }

        $pdo = db();
        $stmt = $pdo->prepare(
            'SELECT id, employee_code, first_name, last_name, email, role, department_id, status
             FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();

        if (!$user) {
            return null;
        }

        return [
            'id' => (int) $user['id'],
            'employee_code' => (string) $user['employee_code'],
            'name' => trim($user['first_name'] . ' ' . $user['last_name']),
            'email' => (string) $user['email'],
            'role' => (string) $user['role'],
            'department_id' => $user['department_id'] !== null ? (int) $user['department_id'] : null,
            'status' => (string) $user['status'],
        ];
    }

    public static function currentUser(): ?array
    {
        return self::$currentUser;
    }

    public static function requireRole(string ...$roles): void
    {
        self::requireAuth();
        $userRole = self::$currentUser['role'] ?? '';
        if (!in_array($userRole, $roles, true)) {
            throw new Exception('ທ່ານບໍ່ມີສິດເຂົ້າເຖິງຂໍ້ມູນນີ້', 403);
        }
    }
}
