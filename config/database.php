<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Vientiane');

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = getenv('DB_PORT') ?: '3307';
        $dbName = getenv('DB_NAME') ?: 'leave_management';
        $username = getenv('DB_USER') ?: 'root';
        $password = getenv('DB_PASS') !== false ? getenv('DB_PASS') : '';
        $charset = 'utf8mb4';

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $host,
            $port,
            $dbName,
            $charset
        );

        self::$connection = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return self::$connection;
    }
}

function db(): PDO
{
    return Database::connection();
}

function ensureLeaveBalance(PDO $pdo, int $userId, int $leaveTypeId, int $year): void
{
    $insertStmt = $pdo->prepare(
        'INSERT INTO leave_balances (
            user_id, leave_type_id, year, entitled_days, used_days, pending_days, remaining_days, adjusted_days
         )
         SELECT :user_id, lt.id, :year, lt.annual_quota, 0, 0, lt.annual_quota, 0
         FROM leave_types lt
         WHERE lt.id = :leave_type_id
         ON DUPLICATE KEY UPDATE leave_type_id = leave_balances.leave_type_id'
    );
    $insertStmt->execute([
        'user_id' => $userId,
        'leave_type_id' => $leaveTypeId,
        'year' => $year,
    ]);
}

function ensureLeaveBalancesForUser(PDO $pdo, int $userId, int $year): void
{
    $insertStmt = $pdo->prepare(
        'INSERT INTO leave_balances (
            user_id, leave_type_id, year, entitled_days, used_days, pending_days, remaining_days, adjusted_days
         )
         SELECT :user_id, lt.id, :year, lt.annual_quota, 0, 0, lt.annual_quota, 0
         FROM leave_types lt
         WHERE lt.status = "active"
         ON DUPLICATE KEY UPDATE leave_type_id = leave_balances.leave_type_id'
    );
    $insertStmt->execute([
        'user_id' => $userId,
        'year' => $year,
    ]);
}

function ensureLeaveBalancesForLeaveType(PDO $pdo, int $leaveTypeId, int $year): void
{
    $insertStmt = $pdo->prepare(
        'INSERT INTO leave_balances (
            user_id, leave_type_id, year, entitled_days, used_days, pending_days, remaining_days, adjusted_days
         )
         SELECT u.id, :leave_type_id, :year, lt.annual_quota, 0, 0, lt.annual_quota, 0
         FROM users u
         INNER JOIN leave_types lt ON lt.id = :leave_type_id_ref
         WHERE u.status = "active"
         ON DUPLICATE KEY UPDATE leave_type_id = leave_balances.leave_type_id'
    );
    $insertStmt->execute([
        'leave_type_id' => $leaveTypeId,
        'leave_type_id_ref' => $leaveTypeId,
        'year' => $year,
    ]);
}

function syncLeaveBalance(PDO $pdo, int $userId, int $leaveTypeId, int $year): void
{
    ensureLeaveBalance($pdo, $userId, $leaveTypeId, $year);

    $syncStmt = $pdo->prepare(
        'UPDATE leave_balances lb
         INNER JOIN leave_types lt ON lt.id = lb.leave_type_id
         LEFT JOIN (
             SELECT
                 user_id,
                 leave_type_id,
                 YEAR(start_date) AS year_key,
                 COALESCE(SUM(CASE WHEN status = "Approved" THEN total_days ELSE 0 END), 0) AS used_days,
                 COALESCE(SUM(CASE WHEN status = "Pending" THEN total_days ELSE 0 END), 0) AS pending_days
             FROM leave_requests
             WHERE user_id = :user_id
               AND leave_type_id = :leave_type_id
               AND YEAR(start_date) = :year
             GROUP BY user_id, leave_type_id, YEAR(start_date)
         ) req
           ON req.user_id = lb.user_id
          AND req.leave_type_id = lb.leave_type_id
          AND req.year_key = lb.year
         SET lb.used_days = COALESCE(req.used_days, 0),
             lb.pending_days = COALESCE(req.pending_days, 0),
             lb.remaining_days = GREATEST(
                 lb.entitled_days + lb.adjusted_days - COALESCE(req.used_days, 0) - COALESCE(req.pending_days, 0),
                 0
             ),
             lb.updated_at = NOW()
         WHERE lb.user_id = :user_id_ref
           AND lb.leave_type_id = :leave_type_id_ref
           AND lb.year = :year_ref'
    );
    $syncStmt->execute([
        'user_id' => $userId,
        'leave_type_id' => $leaveTypeId,
        'year' => $year,
        'user_id_ref' => $userId,
        'leave_type_id_ref' => $leaveTypeId,
        'year_ref' => $year,
    ]);
}

function normalizeNotificationLink(?string $link): string
{
    $value = trim((string) $link);
    if ($value === '') {
        return 'notifications.php';
    }

    if ($value === '/approvals') {
        return 'approvals.php';
    }

    if (preg_match('#^/leave_requests/show\.php\?id=(\d+)$#', $value, $matches)) {
        return 'print_leave.php?id=' . $matches[1];
    }

    return ltrim($value, '/');
}

function notificationTimeLabel(?string $createdAt): string
{
    if (!$createdAt) {
        return '-';
    }

    $created = new DateTimeImmutable($createdAt);
    $now = new DateTimeImmutable('now');
    $seconds = max(0, $now->getTimestamp() - $created->getTimestamp());

    if ($seconds < 60) {
        return 'ເມື່ອ​ກ່ອນ​ໜ້າ​ນີ້';
    }

    $minutes = (int) floor($seconds / 60);
    if ($minutes < 60) {
        return $minutes . ' ນາ​ທິ​ທີ່​ແລ້ວ';
    }

    $hours = (int) floor($minutes / 60);
    if ($hours < 24) {
        return $hours . ' ຊົ່ວໂມງທີ່ແລ້ວ';
    }

    $days = (int) floor($hours / 24);
    if ($days < 7) {
        return $days . ' ວັນທີ່ແລ້ວ';
    }

    return $created->format('d/m/Y H:i');
}

function fetchUserNotifications(PDO $pdo, int $userId, int $limit = 5): array
{
    $stmt = $pdo->prepare(
        'SELECT id, title, message, link, is_read, created_at
         FROM notifications
         WHERE user_id = :user_id
         ORDER BY created_at DESC, id DESC
         LIMIT :limit_rows'
    );
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':limit_rows', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return array_map(
        static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'message' => (string) $row['message'],
                'link' => normalizeNotificationLink($row['link'] ?? null),
                'is_read' => (int) ($row['is_read'] ?? 0),
                'time' => notificationTimeLabel($row['created_at'] ?? null),
            ];
        },
        $stmt->fetchAll()
    );
}
