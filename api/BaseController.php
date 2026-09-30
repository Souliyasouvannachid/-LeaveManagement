<?php
/**
 * Base Controller — ຄລາສພື້ນຖານສຳລັບທຸກ controller
 */
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

abstract class BaseController
{
    protected PDO $db;
    protected string $method;
    protected ?string $id;
    protected ?array $currentUser = null;

    public function __construct()
    {
        $this->db = db();
    }

    public function setRequestMethod(string $method): void
    {
        $this->method = strtoupper($method);
    }

    public function setId(?string $id): void
    {
        $this->id = $id;
    }

    protected function user(): array
    {
        if ($this->currentUser === null) {
            AuthMiddleware::requireAuth();
            $this->currentUser = AuthMiddleware::currentUser();
        }
        return $this->currentUser;
    }

    protected function input(): array
    {
        return get_json_input();
    }

    protected function validateRequired(array $data, array $requiredFields): array
    {
        $errors = [];
        $fieldLabels = [
            'leave_type_id' => 'ປະເພດວັນລາ',
            'start_date' => 'ວັນທີເລີ່ມ',
            'end_date' => 'ວັນທີສິ້ນສຸດ',
            'period' => 'ຊ່ວງເວລາ',
            'reason' => 'ເຫດຜົນ',
        ];
        foreach ($requiredFields as $field) {
            $value = $data[$field] ?? null;
            if ($value === null || (is_string($value) && trim($value) === '')) {
                $errors[] = 'ກະລຸນາລະບຸ' . ($fieldLabels[$field] ?? $field);
            }
        }
        return $errors;
    }

    protected function success(mixed $data, string $message = '', int $statusCode = 200): void
    {
        json_response($statusCode, [
            'success' => true,
            'message' => $message,
            'data' => $data,
        ]);
    }

    protected function error(string $message, int $statusCode = 400, array $errors = []): void
    {
        json_response($statusCode, [
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ]);
    }

    protected function paginate(array $items, int $page, int $perPage, int $total): void
    {
        $totalPages = max(1, (int) ceil($total / $perPage));
        json_response(200, [
            'success' => true,
            'data' => $items,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
            ],
        ]);
    }
}
