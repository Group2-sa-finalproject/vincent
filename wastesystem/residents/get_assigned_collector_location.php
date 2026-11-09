
<?php
session_start();
require_once "../config/database.php";

header("Content-Type: application/json");

function respond($success, $message = "", $data = []) {
    echo json_encode(array_merge([
        "success" => $success,
        "message" => $message
    ], $data));
    exit;
}

// Check resident login
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "residents"
) {
    http_response_code(403);
    respond(false, "Unauthorized access.");
}

$resident_id = (int) $_SESSION["user_id"];


// Find the collector assigned to this resident's household
$sql = "
    SELECT
        u.id AS collector_id,
        u.fullname,
        cl.latitude,
        cl.longitude,
        cl.updated_at,
        CASE
            WHEN TIMESTAMPDIFF(SECOND, cl.updated_at, NOW()) <= 30
            THEN 1
            ELSE 0
        END AS is_online
    FROM households h
    INNER JOIN route_stops rs
        ON rs.household_id = h.id
    INNER JOIN collection_routes cr
        ON cr.id = rs.route_id
    INNER JOIN users u
        ON u.id = cr.collector_id
    INNER JOIN collector_locations cl
        ON cl.collector_id = u.id
    WHERE h.user_id = ?
      AND u.role = 'collector'
      AND cr.status IN ('assigned', 'in_progress')
      AND rs.status = 'pending'
    ORDER BY cr.created_at DESC
    LIMIT 1
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    http_response_code(500);
    respond(false, "Database query preparation failed.");
}

$stmt->bind_param("i", $resident_id);
$stmt->execute();

$result = $stmt->get_result();
$collector = $result->fetch_assoc();

$stmt->close();

if (!$collector) {
    respond(false, "No collector assigned to your household yet.");
}

respond(true, "Collector found.", $collector);
?>
