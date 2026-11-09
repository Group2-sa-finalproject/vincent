
<?php
session_start();
require_once __DIR__ . "/../config/db_system.php";

// Check resident login
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'residents'
) {
    header("Location: index.php");
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$message = "";
$message_type = "";

// Check if resident already has a household
$check = $pdo->prepare(
    "SELECT id FROM households WHERE user_id = ? LIMIT 1"
);
$check->execute([$user_id]);

$alreadyRegistered = (bool) $check->fetch();

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if ($alreadyRegistered) {
        $message = "You have already registered a household.";
        $message_type = "warning";

    } else {
        $house_address = trim($_POST['house_address'] ?? '');
        $latitude = $_POST['latitude'] ?? '';
        $longitude = $_POST['longitude'] ?? '';

        if (
            $house_address === '' ||
            !is_numeric($latitude) ||
            !is_numeric($longitude) ||
            $latitude < -90 || $latitude > 90 ||
            $longitude < -180 || $longitude > 180
        ) {
            $message = "Please enter a valid address and select your house location on the map.";
            $message_type = "danger";

        } else {
            try {
                $sql = "INSERT INTO households
                        (user_id, house_address, latitude, longitude)
                        VALUES (?, ?, ?, ?)";

                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $user_id,
                    $house_address,
                    $latitude,
                    $longitude
                ]);

                $message = "Household registered successfully!";
                $message_type = "success";
                $alreadyRegistered = true;

            } catch (PDOException $e) {
                $message = "Unable to register household. Please try again.";
                $message_type = "danger";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Register Household</title>

    <!-- Bootstrap CSS -->
    <link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">

    <!-- Bootstrap Icons -->
    <link rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <style>
        body {
            background-color: #f4f7f6;
            font-family: Arial, sans-serif;
            color: #333;
            padding-top: 85px;
        }

        .navbar {
            background-color: #198754;
            padding: 12px 25px;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            width: 100%;
            min-height: 65px;
            z-index: 9999;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .page-container {
            max-width: 1000px;
            margin: 25px auto;
        }

        .page-title {
            color: #263b32;
            font-weight: 700;
        }

        .page-subtitle {
            color: #7a8580;
            font-size: 14px;
        }

        .form-card {
            background: #fff;
            border: 1px solid #e8eeea;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.04);
            padding: 28px;
            margin-bottom: 25px;
        }

        .section-heading {
            color: #2d4036;
            font-weight: 700;
            font-size: 18px;
            margin-bottom: 18px;
        }

        .form-label {
            font-weight: 600;
            color: #34483d;
            font-size: 14px;
        }

        .form-control {
            border: 1px solid #dce5df;
            border-radius: 7px;
            padding: 11px 12px;
        }

        .form-control:focus {
            border-color: #198754;
            box-shadow: 0 0 0 0.2rem rgba(25, 135, 84, 0.12);
        }

        .input-group-text {
            background: #e6f4ec;
            color: #198754;
            border-color: #dce5df;
        }

        .map-container {
            border: 1px solid #dce5df;
            border-radius: 10px;
            overflow: hidden;
            background: #f8faf9;
        }

        #map {
            width: 100%;
            height: 400px;
        }

        .map-instructions {
            background: #e9f7ef;
            color: #276644;
            padding: 12px 15px;
            font-size: 13px;
            border-radius: 8px;
            margin-bottom: 15px;
        }

        .coordinate-box {
            background: #f7faf8;
            border: 1px solid #e8eeea;
            border-radius: 8px;
            padding: 13px;
            font-size: 13px;
            color: #65736a;
        }

        .coordinate-box strong {
            color: #2d4036;
        }

        .success-icon {
            width: 75px;
            height: 75px;
            border-radius: 50%;
            background: #e6f4ec;
            color: #198754;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 35px;
            margin: 0 auto 20px;
        }

        .dashboard-footer {
            margin-top: 30px;
            padding: 15px 0;
            border-top: 1px solid #e2e9e5;
            color: #929b96;
            font-size: 12px;
            text-align: center;
        }

        @media (max-width: 600px) {
            .form-card {
                padding: 18px;
            }

            #map {
                height: 300px;
            }

            .page-container {
                padding-left: 10px;
                padding-right: 10px;
            }
        }
    </style>
</head>

<body>

    <!-- Navigation Bar -->
    <nav class="navbar navbar-dark">
        <div class="container-fluid d-flex justify-content-between align-items-center">

            <span class="navbar-brand mb-0">
                <i class="bi bi-recycle me-2"></i>
                Waste Collection System
            </span>

            <a href="dashboard.php" class="btn btn-outline-light btn-sm">
                <i class="bi bi-arrow-left me-1"></i>
                Dashboard
            </a>

        </div>
    </nav>

    <main class="container page-container">

        <!-- Page Heading -->
        <div class="mb-4">
            <h2 class="page-title">
                <i class="bi bi-house-add text-success me-2"></i>
                Register Your Household
            </h2>

            <p class="page-subtitle">
                Register your household address and mark its exact location
                to help our garbage collection team find your residence.
            </p>
        </div>

        <!-- Message -->
        <?php if ($message !== ""): ?>
            <div class="alert alert-<?php echo htmlspecialchars($message_type); ?> alert-dismissible fade show">
                <?php echo htmlspecialchars($message); ?>

                <button type="button" class="btn-close"
                    data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (!$alreadyRegistered): ?>

            <form method="POST" id="household-form">

                <!-- Household Information -->
                <section class="form-card">

                    <h5 class="section-heading">
                        <i class="bi bi-house-door text-success me-2"></i>
                        Household Information
                    </h5>

                    <div class="mb-3">

                        <label for="house_address" class="form-label">
                            House Address
                        </label>

                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="bi bi-geo-alt"></i>
                            </span>

                            <input
                                type="text"
                                name="house_address"
                                id="house_address"
                                class="form-control"
                                placeholder="Enter your complete household address"
                                value="<?php echo htmlspecialchars($_POST['house_address'] ?? ''); ?>"
                                required
                            >
                        </div>

                        <small class="text-muted">
                            Include your street, barangay, and other address details.
                        </small>

                    </div>

                </section>

                <!-- Map Location -->
                <section class="form-card">

                    <h5 class="section-heading">
                        <i class="bi bi-map text-success me-2"></i>
                        Select Your House Location
                    </h5>

                    <div class="map-instructions">
                        <i class="bi bi-info-circle me-1"></i>
                        Click on the map where your house is located.
                        A marker will appear at your selected location.
                    </div>

                    <div class="map-container mb-3">
                        <div id="map"></div>
                    </div>

                    <!-- Coordinates -->
                    <div class="row g-3">

                        <div class="col-md-6">

                            <label for="latitude" class="form-label">
                                Latitude
                            </label>

                            <input
                                type="number"
                                step="any"
                                name="latitude"
                                id="latitude"
                                class="form-control"
                                placeholder="Select location on map"
                                value="<?php echo htmlspecialchars($_POST['latitude'] ?? ''); ?>"
                                required
                                readonly
                            >

                        </div>

                        <div class="col-md-6">

                            <label for="longitude" class="form-label">
                                Longitude
                            </label>

                            <input
                                type="number"
                                step="any"
                                name="longitude"
                                id="longitude"
                                class="form-control"
                                placeholder="Select location on map"
                                value="<?php echo htmlspecialchars($_POST['longitude'] ?? ''); ?>"
                                required
                                readonly
                            >

                        </div>

                    </div>

                    <div class="coordinate-box mt-3">
                        <i class="bi bi-pin-map text-success me-1"></i>
                        <strong>Selected Location:</strong>
                        <span id="location-status">No location selected yet.</span>
                    </div>

                </section>

                <!-- Submit -->
                <div class="d-flex flex-wrap justify-content-end gap-2 mb-4">

                    <a href="dashboard.php"
                        class="btn btn-outline-secondary px-4">
                        Cancel
                    </a>

                    <button type="submit" class="btn btn-success px-4">
                        <i class="bi bi-check-circle me-1"></i>
                        Register Household
                    </button>

                </div>

            </form>

        <?php else: ?>

            <!-- Already Registered -->
            <section class="form-card text-center py-5">

                <div class="success-icon">
                    <i class="bi bi-house-check"></i>
                </div>

                <h4 class="page-title mb-2">
                    Household Already Registered
                </h4>

                <p class="text-muted mb-4">
                    Your household is already registered in the system.
                    You can now access the available resident services.
                </p>

                <a href="dashboard.php" class="btn btn-success px-4">
                    <i class="bi bi-speedometer2 me-1"></i>
                    Back to Dashboard
                </a>

            </section>

        <?php endif; ?>

        <!-- Footer -->
        <footer class="dashboard-footer">
            Waste Collection Management System by Group 2
            &copy; <?php echo date("Y"); ?>
            <br>
            Household Registration
        </footer>

    </main>

    <script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

    <script>
        let map;
        let marker;

        function initMap() {

            const defaultLocation = {
                lat: 15.2200,
                lng: 120.5700
            };

            map = new google.maps.Map(document.getElementById("map"), {
                center: defaultLocation,
                zoom: 15,
                mapTypeControl: true,
                streetViewControl: false,
                fullscreenControl: true
            });

            // Restore previously selected location after validation errors
            const savedLat = document.getElementById("latitude").value;
            const savedLng = document.getElementById("longitude").value;

            if (savedLat !== "" && savedLng !== "") {

                const savedLocation = {
                    lat: parseFloat(savedLat),
                    lng: parseFloat(savedLng)
                };

                marker = new google.maps.Marker({
                    position: savedLocation,
                    map: map,
                    title: "Your Household Location"
                });

                map.setCenter(savedLocation);

                document.getElementById("location-status").textContent =
                    "Location selected successfully.";

            }

            // Select location by clicking the map
            map.addListener("click", function(event) {

                const latitude = event.latLng.lat();
                const longitude = event.latLng.lng();

                const selectedLocation = {
                    lat: latitude,
                    lng: longitude
                };

                if (marker) {
                    marker.setPosition(selectedLocation);
                } else {
                    marker = new google.maps.Marker({
                        position: selectedLocation,
                        map: map,
                        title: "Your Household Location"
                    });
                }

                document.getElementById("latitude").value = latitude.toFixed(8);
                document.getElementById("longitude").value = longitude.toFixed(8);

                document.getElementById("location-status").textContent =
                    latitude.toFixed(6) + ", " + longitude.toFixed(6);
            });
        }

        // Prevent submission without selecting a map location
        document.getElementById("household-form")?.addEventListener("submit", function(event) {

            const latitude = document.getElementById("latitude").value;
            const longitude = document.getElementById("longitude").value;

            if (latitude === "" || longitude === "") {
                event.preventDefault();
                alert("Please select your house location on the map first.");
            }
        });
    </script>

    <?php if (!$alreadyRegistered): ?>
        <script
            src="https://maps.googleapis.com/maps/api/js?key=AIzaSyCz8mz80N6FN03Q1q1nQFGeZLduXsZpNHY&callback=initMap"
            async defer>
        </script>
    <?php endif; ?>

</body>
</html>
