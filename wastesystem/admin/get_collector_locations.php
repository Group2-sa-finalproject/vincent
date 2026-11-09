
<?php
session_start();
require_once "../config/database.php";

header("Content-Type: application/json");

// Only admins can access collector locations
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "admin"
) {
    http_response_code(403);
    echo json_encode([]);
    exit;
}

$sql = "
    SELECT
        u.id,
        u.fullname,
        cl.latitude,
        cl.longitude,
        cl.updated_at,
TIMESTAMPDIFF(SECOND, cl.updated_at, NOW()) <= 30 AS is_online
    FROM collector_locations cl
    INNER JOIN users u ON cl.collector_id = u.id
    WHERE u.role = 'collector'
    ORDER BY cl.updated_at DESC
";

$result = $conn->query($sql);

if (!$result) {
    http_response_code(500);
    echo json_encode([
        "error" => "Database query failed"
    ]);
    exit;
}

$collectors = [];

while ($row = $result->fetch_assoc()) {
    $collectors[] = $row;
}

echo json_encode($collectors);
?>
