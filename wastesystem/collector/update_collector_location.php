
<?php
session_start();
require_once __DIR__ . "/../config/database.php";

header("Content-Type: application/json");

// Only logged-in collectors can send GPS locations
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "collector"
) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

$latitude = $data["latitude"] ?? null;
$longitude = $data["longitude"] ?? null;

if (
    !is_numeric($latitude) ||
    !is_numeric($longitude) ||
    $latitude < -90 || $latitude > 90 ||
    $longitude < -180 || $longitude > 180
) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Invalid coordinates"]);
    exit;
}

$collector_id = (int) $_SESSION["user_id"];

$stmt = $conn->prepare("
    INSERT INTO collector_locations
        (collector_id, latitude, longitude)
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE
        latitude = VALUES(latitude),
        longitude = VALUES(longitude),
        updated_at = CURRENT_TIMESTAMP
");

$stmt->bind_param("idd", $collector_id, $latitude, $longitude);

if ($stmt->execute()) {
    echo json_encode(["success" => true]);
} else {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database update failed"]);
}

$stmt->close();
?>
