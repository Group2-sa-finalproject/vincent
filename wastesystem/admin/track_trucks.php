
<?php
session_start();
require_once "../config/database.php";

// Admin access only
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "admin"
) {
    header("Location: ../index.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Collector Tracking</title>

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
            height: 65px;
        }

        .navbar-brand {
            color: white !important;
            font-weight: bold;
        }

        #map {
            height: 550px;
            width: 100%;
            border-radius: 12px;
        }

        .tracking-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(0,0,0,.08);
        }
    </style>
</head>

<body>

<nav class="navbar fixed-top">
    <div class="container-fluid">
        <a class="navbar-brand" href="dashboard.php">
            Waste Collection - Admin
        </a>

        <a href="../logout.php" class="btn btn-light btn-sm">
            Logout
        </a>
    </div>
</nav>

<div class="container mt-4 mb-5">

    <div class="mb-4">
        <h2 class="fw-bold">Live Collector Tracking</h2>
        <p class="text-secondary">
            Monitor the current GPS locations of your collectors.
        </p>
    </div>

    <div class="tracking-card">

        <div class="d-flex justify-content-between mb-3">
            <h5 class="fw-bold">Collector Locations</h5>

            <span id="lastUpdated" class="text-secondary">
                Loading locations...
            </span>
        </div>

        <div id="map"></div>

        <div id="collectorList" class="mt-3">
            Loading collectors...
        </div>

    </div>
</div>

<script>
let map;
let markers = {};

async function loadCollectors() {

    try {

        const response = await fetch("get_collector_locations.php");

        if (!response.ok) {
            throw new Error("HTTP error: " + response.status);
        }

        const collectors = await response.json();

        const list = document.getElementById("collectorList");

        list.innerHTML = "";

        collectors.forEach(collector => {

            const id = Number(collector.id);

            const position = {
                lat: Number(collector.latitude),
                lng: Number(collector.longitude)
            };

            if (
                !Number.isFinite(position.lat) ||
                !Number.isFinite(position.lng)
            ) {
                return;
            }

            // Create or update map marker
            if (markers[id]) {

                markers[id].setPosition(position);

            } else {

                markers[id] = new google.maps.Marker({
                    position: position,
                    map: map,
                    title: collector.fullname
                });

            }

            // Collector name
            const item = document.createElement("div");

            item.className =
                "d-flex justify-content-between align-items-center border-bottom py-3";

            const name = document.createElement("strong");

            name.textContent = collector.fullname;

            // Online / Offline status
            const status = document.createElement("span");

            if (Number(collector.is_online) === 1) {

                status.textContent = "Online";
                status.className = "badge bg-success";

            } else {

                status.textContent = "Offline";
                status.className = "badge bg-secondary";

            }

            item.appendChild(name);
            item.appendChild(status);

            list.appendChild(item);

        });

        if (collectors.length === 0) {
            list.textContent = "No collector locations available.";
        }

        document.getElementById("lastUpdated").textContent =
            "Last updated: " + new Date().toLocaleTimeString();

    } catch (error) {

        console.error("Tracking error:", error);

        document.getElementById("collectorList").textContent =
            "Unable to load collector locations.";

    }

}

// Initialize Google Map
function initMap() {

    map = new google.maps.Map(document.getElementById("map"), {

        center: {
            lat: 15.4802,
            lng: 120.5979
        },

        zoom: 12

    });

    // Load immediately
    loadCollectors();

    // Refresh every 5 seconds
    setInterval(loadCollectors, 5000);

}
</script>

<script async
    src="https://maps.googleapis.com/maps/api/js?key=AIzaSyCz8mz80N6FN03Q1q1nQFGeZLduXsZpNHY&callback=initMap">
</script>

</body>
</html>
