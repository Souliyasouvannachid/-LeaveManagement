<?php
declare(strict_types=1);

/**
 * API Helper Functions
 */

function json_response(int $statusCode, mixed $data): void
{
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function get_json_input(): array
{
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        return $_POST ?? [];
    }
    return array_merge($_POST ?? [], $json);
}

function generate_api_token(string $secret = 'leave-management-secret'): string
{
    $payload = [
        'iat' => time(),
        'exp' => time() + 3600,
        'data' => [],
    ];
    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $signature = hash_hmac('sha256', $payloadJson, $secret);
    return base64_encode($payloadJson) . '.' . $signature;
}

function verify_api_token(string $token, string $secret = 'leave-management-secret'): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 2) {
        return null;
    }
    [$payloadBase64, $signature] = $parts;
    $expectedSignature = hash_hmac('sha256', $payloadBase64, $secret);
    if (!hash_equals($expectedSignature, $signature)) {
        return null;
    }
    $payload = json_decode(base64_decode($payloadBase64), true);
    if (!is_array($payload)) {
        return null;
    }
    if (empty($payload['exp']) || $payload['exp'] < time()) {
        return null;
    }
    return $payload;
}

function ensureLeaveBalance(PDO $pdo, int $userId, int $leaveTypeId, int $year): void
{
    $checkStmt = $pdo->prepare(
        "SELECT id FROM leave_balances WHERE user_id = :user_id AND leave_type_id = :leave_type_id AND year = :year LIMIT 1"
    );
    $checkStmt->execute(['user_id' => $userId, 'leave_type_id' => $leaveTypeId, 'year' => $year]);
    if ($checkStmt->fetch()) {
        return;
    }
    $ltStmt = $pdo->prepare(
        "SELECT annual_quota FROM leave_types WHERE id = :id LIMIT 1"
    );
    $ltStmt->execute(['id' => $leaveTypeId]);
    $annualQuota = (float) ($ltStmt->fetchColumn() ?: 0);
    $insertStmt = $pdo->prepare(
        "INSERT INTO leave_balances (user_id, leave_type_id, year, entitled_days, used_days, pending_days, remaining_days, adjusted_days)
         VALUES (:user_id, :leave_type_id, :year, :entitled, 0, 0, :entitled, 0)"
    );
    $insertStmt->execute([
        'user_id' => $userId,
        'leave_type_id' => $leaveTypeId,
        'year' => $year,
        'entitled' => $annualQuota,
    ]);
}

function syncLeaveBalance(PDO $pdo, int $userId, int $leaveTypeId, int $year): void
{
    $checkStmt = $pdo->prepare(
        "SELECT id FROM leave_balances WHERE user_id = :user_id AND leave_type_id = :leave_type_id AND year = :year LIMIT 1"
    );
    $checkStmt->execute(['user_id' => $userId, 'leave_type_id' => $leaveTypeId, 'year' => $year]);
    if ($checkStmt->fetch()) {
        $updateStmt = $pdo->prepare(
            "UPDATE leave_balances SET remaining_days = GREATEST(0, entitled_days + adjusted_days - used_days - pending_days) WHERE user_id = :user_id AND leave_type_id = :leave_type_id AND year = :year"
        );
        $updateStmt->execute(['user_id' => $userId, 'leave_type_id' => $leaveTypeId, 'year' => $year]);
    }
}
