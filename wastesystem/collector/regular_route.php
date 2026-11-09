<?php
session_start();
require_once "../config/database.php";

// Allow logged-in collectors only
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    !in_array($_SESSION["role"], ["collector", "collectors"])
) {
    header("Location: ../index.php");
    exit;
}

$collector_id = (int) $_SESSION["user_id"];

// Get regular routes assigned to this collector
$stmt = $conn->prepare("
    SELECT
        cr.id AS route_id,
        cr.route_name,
        cr.status AS route_status,
        rs.id AS stop_id,
        rs.stop_order,
        rs.status AS stop_status,
        h.id AS household_id,
        h.house_address,
        h.latitude,
        h.longitude,
        u.fullname
    FROM collection_routes cr
    INNER JOIN route_stops rs
        ON rs.route_id = cr.id
    INNER JOIN households h
        ON h.id = rs.household_id
    LEFT JOIN users u
        ON u.id = h.user_id
    WHERE cr.collector_id = ?
      AND cr.route_type = 'regular'
      AND cr.status IN ('assigned', 'in_progress')
    ORDER BY cr.id DESC, rs.stop_order ASC
");

if (!$stmt) {
    die("Database query failed: " . $conn->error);
}

$stmt->bind_param("i", $collector_id);
$stmt->execute();

$result = $stmt->get_result();

$routes = [];

while ($row = $result->fetch_assoc()) {
    $routes[] = $row;
}

$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Regular Collection Route</title>

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

        .stop-item {
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
        &larr; Back to Dashboard
    </a>

    <h2 class="fw-bold">Regular Collection Route</h2>

    <p class="text-secondary">
        View the regular collection routes assigned to you by the admin.
    </p>

    <?php if (count($routes) > 0): ?>

        <?php
        $groupedRoutes = [];

        foreach ($routes as $route) {
            $groupedRoutes[$route["route_id"]][] = $route;
        }
        ?>

        <?php foreach ($groupedRoutes as $routeId => $stops): ?>

            <div class="route-card">

                <h5 class="fw-bold">
                    <?= htmlspecialchars($stops[0]["route_name"]); ?>
                </h5>

                <p class="text-secondary">
                    Route Status:
                    <?= htmlspecialchars($stops[0]["route_status"]); ?>
                </p>
                <button
    type="button"
    class="btn btn-green btn-sm mb-3"
    onclick="showRegularRoute(<?= (int) $routeId ?>)">
    View Driving Route
</button>

                <h6 class="fw-bold mb-3">Household Stops</h6>

                <?php foreach ($stops as $stop): ?>

                    <div class="stop-item">

                        <h6 class="fw-bold">
                            Stop <?= (int) $stop["stop_order"]; ?> —
                            <?= htmlspecialchars($stop["fullname"] ?? "Resident"); ?>
                        </h6>

                        <p class="mb-1">
                            <strong>Address:</strong>
                            <?= htmlspecialchars($stop["house_address"] ?? "No address"); ?>
                        </p>

                        <p class="mb-1">
                            <strong>Collection Status:</strong>
                            <?= htmlspecialchars($stop["stop_status"]); ?>
                        </p>

                        <p class="mb-0 text-secondary">
                            Household ID: <?= (int) $stop["household_id"]; ?>
                        </p>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endforeach; ?>

    <?php else: ?>

        <div class="route-card">
            <p class="text-muted mb-0">
                No regular collection routes have been assigned to you yet.
            </p>
        </div>

    <?php endif; ?>

    <div class="route-card">
        <h5 class="fw-bold mb-3">Route Map</h5>
        <div id="map"></div>

        <p id="routeStatus" class="text-secondary mt-3 mb-0">
            Loading route map...
        </p>
    </div>

</main>
<script>
let map;
let Route;
let currentPosition = null;
let routePolylines = [];

const regularStops = <?= json_encode(array_map(function ($stop) {
    return [
        "route_id" => (int) $stop["route_id"],
        "stop_order" => (int) $stop["stop_order"],
        "name" => $stop["fullname"] ?? "Resident",
        "address" => $stop["house_address"] ?? "",
        "latitude" => (float) $stop["latitude"],
        "longitude" => (float) $stop["longitude"]
    ];
}, $routes), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

async function initMap() {

    const routesLibrary = await google.maps.importLibrary("routes");
    Route = routesLibrary.Route;

    map = new google.maps.Map(document.getElementById("map"), {
        center: { lat: 15.4802, lng: 120.5979 },
        zoom: 12
    });

    const bounds = new google.maps.LatLngBounds();

    regularStops.forEach(stop => {

        const position = {
            lat: stop.latitude,
            lng: stop.longitude
        };

        new google.maps.Marker({
            position: position,
            map: map,
            title: "Stop " + stop.stop_order + " - " + stop.name
        });

        bounds.extend(position);
    });

    if (regularStops.length > 0) {
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
                "GPS ready. Select View Driving Route.";

        },

        function(error) {

            document.getElementById("routeStatus").textContent =
                "Unable to get GPS location: " + error.message;

        },

        {
            enableHighAccuracy: true,
            timeout: 15000,
            maximumAge: 0
        }
    );
}

async function showRegularRoute(routeId) {

    const message = document.getElementById("routeStatus");

    if (!currentPosition) {
        message.textContent = "Your current GPS location is not available.";
        return;
    }

    // Get only the stops belonging to the selected route
    const stops = regularStops
        .filter(stop => stop.route_id === Number(routeId))
        .sort((a, b) => a.stop_order - b.stop_order);

    if (stops.length === 0) {
        message.textContent = "No household stops found for this route.";
        return;
    }

    message.textContent = "Calculating regular driving route...";

    const destinationStop = stops[stops.length - 1];

    const request = {
        origin: currentPosition,

        destination: {
            lat: destinationStop.latitude,
            lng: destinationStop.longitude
        },

        intermediates: stops.slice(0, -1).map(stop => ({
            location: {
                lat: stop.latitude,
                lng: stop.longitude
            }
        })),

        travelMode: "DRIVING",

        fields: ["path"]
    };

    try {

        const { routes } = await Route.computeRoutes(request);

        if (!routes || routes.length === 0) {
            message.textContent = "No driving route found.";
            return;
        }

        // Remove the previous route line
        routePolylines.forEach(polyline => polyline.setMap(null));
        routePolylines = [];

        const polylines = routes[0].createPolylines();

        polylines.forEach(polyline => {
            polyline.setMap(map);
            routePolylines.push(polyline);
        });

        message.textContent =
            "Regular driving route displayed successfully!";

    } catch (error) {

        message.textContent = "Route error: " + error.message;
        console.error("Regular Routes API error:", error);

    }
}
</script>
<script async
    src="https://maps.googleapis.com/maps/api/js?key=AIzaSyCz8mz80N6FN03Q1q1nQFGeZLduXsZpNHY&loading=async&callback=initMap">
</script>