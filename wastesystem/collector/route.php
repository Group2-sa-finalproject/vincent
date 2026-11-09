<?php
session_start();
require_once "../config/database.php";

// Only logged-in collectors can access this page
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "collector"
) {
    header("Location: ../index.php");
    exit;
}

$collector_id = (int) $_SESSION["user_id"];

// Get approved requests assigned to this collector
$stmt = $conn->prepare("
    SELECT
        cs.id AS schedule_id,
        cs.collection_date,
        cs.requested_time,
        cs.collection_address,
        cs.notes,
        h.id AS household_id,
        h.latitude,
        h.longitude,
        u.fullname
    FROM collection_schedules cs
    INNER JOIN households h ON h.user_id = cs.user_id
    INNER JOIN users u ON u.id = cs.user_id
    WHERE cs.collector_id = ?
      AND cs.status = 'Approved'
      AND h.latitude IS NOT NULL
      AND h.longitude IS NOT NULL
      AND h.latitude != ''
      AND h.longitude != ''
    ORDER BY cs.collection_date ASC, cs.requested_time ASC
");

if (!$stmt) {
    die("Database query failed: " . $conn->error);
}

$stmt->bind_param("i", $collector_id);
$stmt->execute();

$result = $stmt->get_result();

$requests = [];

while ($row = $result->fetch_assoc()) {
    $requests[] = $row;
}

$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>My Collection Route</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <style>
        body {
            background: #f4f7f6;
            font-family: Arial, sans-serif;
            padding-top: 85px;
        }

        .navbar {
            background: #198754;
            min-height: 65px;
        }

        .navbar-brand {
            color: white !important;
            font-weight: bold;
        }

        .page-container {
            max-width: 1200px;
            margin: 25px auto;
        }

        .route-card {
            background: white;
            padding: 22px;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(0,0,0,.07);
            margin-bottom: 20px;
        }

        #map {
            width: 100%;
            height: 500px;
            border-radius: 10px;
        }

        .request-item {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 12px;
        }

        .btn-green {
            background: #198754;
            color: white;
        }

        .btn-green:hover {
            background: #146c43;
            color: white;
        }
    </style>
</head>

<body>

<nav class="navbar fixed-top navbar-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="dashboard.php">
            Waste Collection - Collector
        </a>

        <a href="../logout.php" class="btn btn-light btn-sm">
            Logout
        </a>
    </div>
</nav>

<main class="container page-container">

    <a href="dashboard.php" class="btn btn-success btn-sm mb-3">
        <i class="bi bi-arrow-left"></i>
        Back to Dashboard
    </a>

    <h2 class="fw-bold">My Collection Route</h2>

    <p class="text-secondary">
        View approved collection requests assigned to your account.
    </p>

    <div class="route-card">

        <h5 class="fw-bold mb-3">Assigned Collection Requests</h5>

        <?php if (count($requests) > 0): ?>

            <?php foreach ($requests as $request): ?>

                <div class="request-item">

                    <h6 class="fw-bold">
                        <?= htmlspecialchars($request["fullname"]); ?>
                    </h6>

                    <p class="mb-1">
                        <strong>Date:</strong>
                        <?= htmlspecialchars($request["collection_date"]); ?>
                    </p>

                    <p class="mb-1">
                        <strong>Time:</strong>
                        <?= htmlspecialchars($request["requested_time"]); ?>
                    </p>

                    <p class="mb-2">
                        <strong>Address:</strong>
                        <?= htmlspecialchars($request["collection_address"]); ?>
                    </p>

                    <button
                        type="button"
                        class="btn btn-green btn-sm"
                        onclick="showRoute(
                            <?= (float) $request["latitude"]; ?>,
                            <?= (float) $request["longitude"]; ?>,
                            <?= (int) $request["schedule_id"]; ?>
                        )">
                        Get Directions
                    </button>

                </div>

            <?php endforeach; ?>

        <?php else: ?>

            <p class="text-muted mb-0">
                No approved collection requests have been assigned to you yet.
            </p>

        <?php endif; ?>

    </div>

    <div class="route-card">

        <h5 class="fw-bold mb-3">Google Maps</h5>

        <div id="map"></div>

        <div id="routeStatus" class="text-secondary mt-3">
            Allow location access to get directions from your current position.
        </div>

    </div>

</main>
<script>
let map;
let Route;
let currentPosition = null;
let markers = {};

const requests = <?= json_encode(array_map(function ($request) {
    return [
        "id" => (int) $request["schedule_id"],
        "name" => $request["fullname"],
        "address" => $request["collection_address"],
        "latitude" => (float) $request["latitude"],
        "longitude" => (float) $request["longitude"]
    ];
}, $requests), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

async function initMap() {

    const routesLibrary = await google.maps.importLibrary("routes");
    Route = routesLibrary.Route;

    map = new google.maps.Map(document.getElementById("map"), {
        center: { lat: 15.4802, lng: 120.5979 },
        zoom: 12
    });

    const bounds = new google.maps.LatLngBounds();

    requests.forEach(request => {

        const position = {
            lat: request.latitude,
            lng: request.longitude
        };

        markers[request.id] = new google.maps.Marker({
            position: position,
            map: map,
            title: request.name + " - " + request.address
        });

        bounds.extend(position);
    });

    if (requests.length > 0) {
        map.fitBounds(bounds);
    }

    if (!navigator.geolocation) {
        document.getElementById("routeStatus").textContent =
            "Your browser does not support GPS.";
        return;
    }

    navigator.geolocation.getCurrentPosition(

        function(position) {

            currentPosition = {
                lat: position.coords.latitude,
                lng: position.coords.longitude
            };

            new google.maps.Marker({
                position: currentPosition,
                map: map,
                title: "Your Current Location",
                icon: {
                    url: "https://maps.google.com/mapfiles/ms/icons/blue-dot.png"
                }
            });

            map.setCenter(currentPosition);

            document.getElementById("routeStatus").textContent =
                "Your current GPS location is ready. Select Get Directions.";

        },

        function(error) {

            document.getElementById("routeStatus").textContent =
                "Unable to get your GPS location: " + error.message;

        },

        {
            enableHighAccuracy: true,
            timeout: 15000,
            maximumAge: 0
        }
    );
}

async function showRoute(latitude, longitude, scheduleId) {

    const message = document.getElementById("routeStatus");

    if (!currentPosition) {
        message.textContent =
            "Your current GPS location is not available. Allow location access.";
        return;
    }

    message.textContent = "Calculating driving route...";

    const request = {
        origin: currentPosition,

        destination: {
            lat: Number(latitude),
            lng: Number(longitude)
        },

        travelMode: "DRIVING",

        fields: ["path"]
    };

    try {

        const { routes } = await Route.computeRoutes(request);

        if (!routes || routes.length === 0) {
            message.textContent = "No driving route found.";
            return;
        }

        const polylines = routes[0].createPolylines();

        polylines.forEach(polyline => {
            polyline.setMap(map);
        });

        message.textContent =
            "Driving route displayed for request #" + scheduleId;

    } catch (error) {

        message.textContent = "Route error: " + error.message;

        console.error("Routes API error:", error);

    }
}
</script>
<script async
    src="https://maps.googleapis.com/maps/api/js?key=AIzaSyCz8mz80N6FN03Q1q1nQFGeZLduXsZpNHY&loading=async&callback=initMap">
</script>