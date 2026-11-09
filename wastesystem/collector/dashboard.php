
<?php
session_start();
include "../config/database.php";


if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    !in_array($_SESSION["role"], ["collector", "collectors"])
) {
    header("Location: ../index.php");
    exit;
}

$user_id = (int) $_SESSION["user_id"];

// Get collector information from the users table
$stmt = $conn->prepare("
    SELECT id, fullname, users
    FROM users
    WHERE id = ? AND role = 'collector'
    LIMIT 1
");

if (!$stmt) {
    die("Database query failed: " . $conn->error);
}

$stmt->bind_param("i", $user_id);
$stmt->execute();

$result = $stmt->get_result();
$collector = $result->fetch_assoc();

$stmt->close();

if (!$collector) {
    session_unset();
    session_destroy();
    header("Location: ../index.php");
    exit;
}

$collector_name = $collector["fullname"];

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Collector Dashboard</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f7f6;
            padding-top: 85px;
        }

        /* Navbar */
        .navbar {
            background-color: #198754;
            height: 65px;
            padding: 12px 25px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.15);
        }

        .navbar-brand {
            color: white !important;
            font-weight: bold;
            font-size: 20px;
        }

        /* Main Container */
        .dashboard-container {
            max-width: 950px;
            margin: 30px auto;
            padding: 20px;
        }

        /* Collector Profile */
        .profile-card {
            background: white;
            border-radius: 12px;
            padding: 20px 25px;
            display: flex;
            align-items: center;
            gap: 18px;
            margin-bottom: 35px;
            box-shadow: 0 3px 12px rgba(0,0,0,0.08);
        }

        .profile-icon {
            width: 65px;
            height: 65px;
            border-radius: 50%;
            background: #d1e7dd;
            color: #198754;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 30px;
        }

        .profile-info h5 {
            margin: 0;
            font-weight: bold;
            color: #333;
        }

        .profile-info p {
            margin: 5px 0 0;
            color: #777;
            font-size: 14px;
        }

        /* Dashboard */
        .dashboard-title {
            font-weight: bold;
            color: #333;
            margin-bottom: 10px;
        }

        .dashboard-subtitle {
            color: #777;
            margin-bottom: 35px;
        }

        /* Dashboard Cards */
        .dashboard-card {
            background: white;
            border-radius: 12px;
            padding: 40px 25px;
            text-align: center;
            box-shadow: 0 3px 12px rgba(0,0,0,0.08);
            transition: 0.3s;
            height: 100%;
        }

        .dashboard-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 18px rgba(0,0,0,0.12);
        }

        .card-icon {
            font-size: 55px;
            margin-bottom: 20px;
        }

        .dashboard-card h4 {
            font-weight: bold;
            margin-bottom: 12px;
        }

        .dashboard-card p {
            color: #777;
            min-height: 45px;
        }

        .btn-green {
            background-color: #198754;
            color: white;
            border: none;
            padding: 10px 30px;
            border-radius: 6px;
            text-decoration: none;
            display: inline-block;
        }

        .btn-green:hover {
            background-color: #146c43;
            color: white;
        }

        @media (max-width: 576px) {
            .dashboard-container {
                margin-top: 15px;
            }

            .dashboard-card {
                padding: 30px 20px;
            }
        }
    </style>
</head>

<body>

<!-- NAVBAR -->
<nav class="navbar fixed-top">
    <div class="container-fluid">

        <a class="navbar-brand" href="dashboard.php">
            Waste Collection - Collector
        </a>

        <a href="../logout.php" class="btn btn-light btn-sm">
            Logout
        </a>

    </div>
</nav>


<div class="container dashboard-container">

    
    <div class="profile-card">

        <div class="profile-icon">
            
        </div>

        <div class="profile-info">

            <h5>
                <?= htmlspecialchars($collector_name) ?>
            </h5>

            <p>Collector Account</p>
<p id="locationStatus" class="text-secondary mb-0">
    Waiting for GPS permission...
</p>

        </div>

    </div>


  
    <div class="text-center">

        <h2 class="dashboard-title">
            Collector Dashboard
        </h2>

        <p class="dashboard-subtitle">
            Welcome! Select an option to begin your collection tasks.
        </p>

    </div>


   
    <div class="row justify-content-center g-4">
        <div class="col-md-6">

    <div class="dashboard-card">

        <div class="card-icon">🗺️</div>

        <h4>Regular Collection Route</h4>

        <p>
            View your admin-assigned route, household locations,
            and collection stops.
        </p>

        <a href="regular_route.php" class="btn-green">
            View Regular Route
        </a>

    </div>

</div>

       
        <div class="col-md-6">

            <div class="dashboard-card">

                <div class="card-icon"></div>

                <h4>Collection Route</h4>

                <p>
                    View your assigned garbage collection
                    route and household destinations.
                </p>

                <a href="route.php" class="btn-green">
                    View Route
                </a>

            </div>

        </div>


       
        <div class="col-md-6">

            <div class="dashboard-card">

                <div class="card-icon"></div>

                <h4>QR Code Scanner</h4>

                <p>
                    Scan household QR codes to verify
                    registered residents before collection.
                </p>

                <a href="scanner.php" class="btn-green">
                    Open Scanner
                </a>

            </div>

        </div>

    </div>

</div>

<script>
const locationStatus = document.getElementById("locationStatus");

if ("geolocation" in navigator) {

    navigator.geolocation.watchPosition(
        async function(position) {

            const latitude = position.coords.latitude;
            const longitude = position.coords.longitude;

            try {
                const response = await fetch("update_collector_location.php", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json"
                    },
                    credentials: "same-origin",
                    body: JSON.stringify({
                        latitude: latitude,
                        longitude: longitude
                    })
                });

                const result = await response.json();

                if (response.ok && result.success) {
                    locationStatus.textContent = "GPS Active — Location updated";
                    locationStatus.className = "text-success mb-0";
                } else {
                    locationStatus.textContent =
                        result.message || "Unable to update location.";
                    locationStatus.className = "text-danger mb-0";
                }

            } catch (error) {
                locationStatus.textContent = "Connection error.";
                locationStatus.className = "text-danger mb-0";
                console.error(error);
            }
        },

        function(error) {
            locationStatus.textContent = "GPS error: " + error.message;
            locationStatus.className = "text-danger mb-0";
        },

        {
            enableHighAccuracy: true,
            maximumAge: 5000,
            timeout: 15000
        }
    );

} else {
    locationStatus.textContent = "GPS is not supported by this browser.";
}
</script>
</body>
</html>

</body>
</html>
