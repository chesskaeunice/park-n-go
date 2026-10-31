<?php
declare(strict_types=1);

use ParkNGo\Controllers\ParkingController;
use ParkNGo\Models\ParkingInventory;
use ParkNGo\Services\Api\Json;
use ParkNGo\Services\Parking\AvailabilityWindow;

try {
    require dirname(__DIR__) . '/app/bootstrap.php';
    header('Content-Type: application/json; charset=UTF-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') { header('Allow: GET'); http_response_code(405); echo Json::encode(['error' => 'Only GET is supported.']); exit; }
    if (($_GET['resource'] ?? null) !== 'availability') { http_response_code(404); echo Json::encode(['error' => 'Resource not found.']); exit; }
    $window = new AvailabilityWindow($_GET);
    $data = (new ParkingInventory())->availability(ParkingController::id($_GET, 'facility_id'), ParkingController::id($_GET, 'floor_id'), $window);
    echo Json::encode(['data' => $data]);
} catch (\InvalidArgumentException $error) {
    error_log('API request rejected: ' . $error->getMessage()); http_response_code(422); echo json_encode(['error' => 'Unable to complete the request.'], JSON_THROW_ON_ERROR);
} catch (\OutOfBoundsException $error) {
    error_log('API resource not found: ' . $error->getMessage()); http_response_code(404); echo json_encode(['error' => 'The requested resource was not found.'], JSON_THROW_ON_ERROR);
} catch (\Throwable $error) {
    error_log('Park n Go availability failed: ' . get_class($error));
    http_response_code(503); header('Content-Type: application/json; charset=UTF-8'); header('Cache-Control: no-store');
    echo '{"error":"Availability is temporarily unavailable. Please try again."}';
}
