<?php
/**
 * REST API Router (Pure PHP)
 * --------------------------
 * Entry point: /api/index.php/{controller}/{action}[/{id}]
 *
 * ຕົວຢ່າງ URL:
 *   GET    /api/leave-requests        → ລາຍການຄຳຮ້ອງຂໍລາ
 *   POST   /api/leave-requests        → ສ້າງຄຳຮ້ອງຂໍລາ
 *   GET    /api/leave-requests/12     → ເບິ່ງຄຳຮ້ອງຂໍລາ ID 12
 *   POST   /api/leave-requests/12/cancel → ຍົກເລີກຄຳຮ້ອງຂໍລາ
 *   GET    /api/approvals             → ລາຍການຄຳຮ້ອງທີ່ລໍຖ້າ
 *   POST   /api/approvals/12/approve  → ອະນຸມັດຄຳຮ້ອງ
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/AuthMiddleware.php';
require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/LeaveRequestController.php';
require_once __DIR__ . '/ApprovalController.php';
require_once __DIR__ . '/LeaveBalanceController.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Credentials: true');

// Handle preflight (CORS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    echo json_encode(['status' => 'ok']);
    exit;
}

$requestMethod = $_SERVER['REQUEST_METHOD'];
$path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$segments = array_values(array_filter(explode('/', $path)));

// ຫາຕຳແໜ່ງຂອງ 'api' ໃນ path ເພື່ອຮອງຮັບທັງ /api/... ແລະ public/api/...
$apiIndex = array_search('api', $segments);
if ($apiIndex === false) {
    json_response(404, ['error' => 'ບໍ່ພົບຈຸດເຊື່ອມຕໍ່ API']);
    exit;
}

$controllerName = $segments[$apiIndex + 1] ?? '';
$action = $segments[$apiIndex + 2] ?? 'index';
$id = $segments[$apiIndex + 3] ?? null;

// ແປງຊື່ controller ເປັນ PascalCase ແລ້ວເພີ່ມ 'Controller'
$controllerName = ucfirst(strtolower($controllerName)) . 'Controller';
$controllerFile = __DIR__ . '/' . $controllerName . '.php';

if (!file_exists($controllerFile)) {
    json_response(404, ['error' => 'ບໍ່ພົບຕົວຈັດການ: ' . $controllerName]);
    exit;
}

/** @var BaseController $controller */
$controller = new $controllerName();
$controller->setRequestMethod($requestMethod);
$controller->setId($id);

// ກວດສອບວ່າ method ມີຢູ່ໃນ controller ຫຼືບໍ່
if (!method_exists($controller, $action)) {
    json_response(404, ['error' => 'ບໍ່ພົບຄຳສັ່ງ: ' . $action]);
    exit;
}

// ກວດສອບ auth (ຍົກເວັ້ນສຳລັບບາງ endpoint ຫາກຈຳເປັນ ເຊັ່ນ login)
$publicActions = [
    // ຍັງບໍ່ມີ auth endpoint ແຕ່ຮອງຮັບໃນອະນາຄົດ
    'LeaveRequestController' => ['public' => []],
];

$requiresAuth = true;
if (isset($publicActions[$controllerName])) {
    $requiresAuth = !in_array($action, $publicActions[$controllerName]['public'], true);
}

if ($requiresAuth) {
    try {
        AuthMiddleware::requireAuth();
    } catch (Exception $e) {
        json_response(401, ['error' => $e->getMessage(), 'code' => 'UNAUTHORIZED']);
        exit;
    }
}

// ເອີ້ນໃຊ້ action
try {
    $result = $controller->$action();
    json_response(200, $result);
} catch (Exception $e) {
    json_response(500, ['error' => $e->getMessage()]);
}
