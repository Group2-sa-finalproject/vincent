
<?php
session_start();
require_once "../config/database.php";

// Check resident login
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "residents"
) {
    header("Location: ../index.php");
    exit;
}

$resident_id = (int) $_SESSION["user_id"];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Track Garbage Truck</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        body {
            background: #f4f7f5;
        }

        .navbar {
            background: #198754;
        }

        #map {
            width: 100%;
            height: 500px;
            border-radius: 10px;
        }

        .tracking-card {
            border: none;
            border-radius: 12px;
        }
    </style>
</head>
<body>

<nav class="navbar navbar-dark">
    <div class="container">
        <a class="navbar-brand fw-bold" href="dashboard.php">
            Waste Management System
        </a>
        <a href="dashboard.php" class="btn btn-light btn-sm">
            Back to Dashboard
        </a>
    </div>
</nav>

<div class="container py-4">
    <h3 class="mb-3">Track Garbage Truck</h3>

    <div class="card tracking-card shadow-sm mb-3">
        <div class="card-body">
            <h5>Collector Information</h5>
            <p id="collectorName" class="mb-1">Searching for assigned collector...</p>
            <p id="collectorStatus" class="text-secondary mb-0">
                Waiting for location...
            </p>
        </div>
    </div>

    <div class="card tracking-card shadow-sm">
        <div class="card-body">
            <div id="map"></div>
        </div>
    </div>
</div>

<script>

let map;
let collectorMarker = null;
let firstLocationLoaded = false;

async function loadCollectorLocation() {
    const nameElement = document.getElementById("collectorName");
    const statusElement = document.getElementById("collectorStatus");

    try {
        const response = await fetch(
            "get_assigned_collector_location.php",
            { cache: "no-store" }
        );

        const collector = await response.json();

        if (!response.ok) {
            throw new Error(collector.message || "Unable to load collector.");
        }

        if (!collector.success) {
            nameElement.textContent = collector.message;
            statusElement.textContent = "Waiting for an assigned collector.";
            statusElement.className = "text-secondary mb-0";

            if (collectorMarker) {
                collectorMarker.setMap(null);
                collectorMarker = null;
            }

            firstLocationLoaded = false;
            return;
        }

        nameElement.textContent = "Collector: " + collector.fullname;

        const position = {
            lat: Number(collector.latitude),
            lng: Number(collector.longitude)
        };

        if (
            !Number.isFinite(position.lat) ||
            !Number.isFinite(position.lng)
        ) {
            throw new Error("Invalid collector coordinates.");
        }

        const isOnline = Number(collector.is_online) === 1;

        statusElement.textContent = isOnline
            ? "Online • GPS location updating"
            : "Offline • Showing last known location";

        statusElement.className = isOnline
            ? "text-success mb-0"
            : "text-danger mb-0";

        if (!collectorMarker) {
            collectorMarker = new google.maps.Marker({
                position: position,
                map: map,
                title: collector.fullname,
                label: "T"
            });
        } else {
            collectorMarker.setPosition(position);
        }

        // Center the map only when the first location is received.
        if (!firstLocationLoaded) {
            map.setCenter(position);
            map.setZoom(16);
            firstLocationLoaded = true;
        }

    } catch (error) {
        console.error("Tracking error:", error);

        statusElement.textContent =
            "Unable to retrieve collector location. Retrying...";

        statusElement.className = "text-danger mb-0";
    }
}

function initMap() {
    map = new google.maps.Map(
        document.getElementById("map"),
        {
            center: { lat: 15.4802, lng: 120.5979 },
            zoom: 13,
            mapTypeControl: true,
            streetViewControl: false,
            fullscreenControl: true
        }
    );

    loadCollectorLocation();

    // Refresh the collector's location every 5 seconds.
    setInterval(loadCollectorLocation, 5000);
}

</script>

<script async defer
    src="https://maps.googleapis.com/maps/api/js?key=AIzaSyCz8mz80N6FN03Q1q1nQFGeZLduXsZpNHY&callback=initMap">
</script>

</body>
</html>
