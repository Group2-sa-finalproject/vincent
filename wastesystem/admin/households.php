
<?php
session_start();
require_once __DIR__ . "/../config/db_system.php";

// Allow admin access only
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'admin'
) {
    header("Location: ../index.php");
    exit;
}

// Get registered households with coordinates
try {
    $sql = "
        SELECT id, house_address, latitude, longitude
        FROM households
        WHERE latitude IS NOT NULL
        AND longitude IS NOT NULL
        ORDER BY id ASC
    ";

    $stmt = $pdo->query($sql);
    $households = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Database error: " . htmlspecialchars($e->getMessage()));
}

$householdsJson = json_encode(
    $households,
    JSON_HEX_TAG |
    JSON_HEX_APOS |
    JSON_HEX_QUOT |
    JSON_HEX_AMP
);

if ($householdsJson === false) {
    die("Unable to process household location data.");
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Admin Household Monitoring</title>

    <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <style>
        body {
            background-color: #f4f7f6;
            font-family: Arial, sans-serif;
            color: #333;
            padding-top: 85px;
        }

        /* NAVBAR */
        .navbar {
            background-color: #198754;
            padding: 12px 25px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            width: 100%;
            min-height: 65px;
            z-index: 9999;
        }

        .navbar .container-fluid {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .navbar-brand {
            color: white !important;
            font-weight: 600;
            font-size: 19px;
        }

        /* MAIN CONTENT */
        .main-container {
            max-width: 1250px;
            margin: auto;
            padding: 25px 20px;
        }

        .page-header {
            margin-bottom: 30px;
        }

        .page-header h2 {
            font-weight: 700;
            color: #263b32;
            margin-bottom: 5px;
        }

        .page-header p {
            color: #777;
            font-size: 14px;
            margin-bottom: 0;
        }

        /* SUMMARY CARD */
        .summary-card {
            background: white;
            border: 1px solid #e8eeea;
            border-radius: 12px;
            padding: 22px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.04);
            display: flex;
            align-items: center;
            gap: 17px;
            margin-bottom: 25px;
        }

        .summary-icon {
            width: 52px;
            height: 52px;
            background-color: #e6f4ec;
            color: #198754;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 25px;
        }

        .summary-card h3 {
            font-size: 24px;
            font-weight: 700;
            color: #2d4036;
            margin-bottom: 4px;
        }

        .summary-card p {
            color: #7a8580;
            font-size: 13px;
            margin-bottom: 0;
        }

        /* CONTENT CARD */
        .content-card {
            background: white;
            border: 1px solid #e8eeea;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.04);
            padding: 25px;
            margin-bottom: 25px;
        }

        .card-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 20px;
        }

        .card-heading h5 {
            font-size: 18px;
            font-weight: 700;
            color: #2d4036;
            margin-bottom: 5px;
        }

        .card-heading p {
            color: #7a8580;
            font-size: 13px;
            margin-bottom: 0;
        }

        /* MAP */
        #map {
            width: 100%;
            height: 500px;
            border-radius: 10px;
            overflow: hidden;
        }

        .map-note {
            background: #e6f4ec;
            color: #198754;
            padding: 12px 15px;
            border-radius: 7px;
            font-size: 13px;
            margin-bottom: 15px;
        }

        /* TABLE */
        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 500px;
        }

        th, td {
            padding: 13px;
            text-align: left;
            border-bottom: 1px solid #edf0ed;
            font-size: 13px;
        }

        th {
            background: #e6f4ec;
            color: #198754;
            font-weight: 600;
        }

        tbody tr:hover {
            background: #f9fbf9;
        }

        .household-id {
            color: #198754;
            font-weight: 700;
        }

        /* FOOTER */
        .dashboard-footer {
            margin-top: 30px;
            padding: 20px 0;
            border-top: 1px solid #e2e9e5;
            color: #929b96;
            font-size: 12px;
            text-align: center;
        }

        @media(max-width: 576px) {
            .page-header h2 {
                font-size: 23px;
            }

            .content-card {
                padding: 18px;
            }

            #map {
                height: 350px;
            }
        }
    </style>
</head>

<body>

    <!-- NAVBAR -->
    <nav class="navbar navbar-dark">
        <div class="container-fluid px-4">

            <span class="navbar-brand">
                <i class="bi bi-recycle me-2"></i>
                Waste Collection Admin
            </span>

            <a href="dashboard.php" class="btn btn-outline-light btn-sm">
                <i class="bi bi-arrow-left me-1"></i>
                Dashboard
            </a>

        </div>
    </nav>

    <!-- MAIN CONTENT -->
    <main class="main-container">

        <div class="page-header">
            <h2>Household Monitoring</h2>

            <p>
                Welcome back,
                <strong>
                    <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Admin'); ?>
                </strong>!
                Monitor registered household locations on the map.
            </p>
        </div>

        <!-- SUMMARY -->
        <div class="summary-card">

            <div class="summary-icon">
                <i class="bi bi-house-door"></i>
            </div>

            <div>
                <h3><?php echo count($households); ?></h3>
                <p>Registered Households with Coordinates</p>
            </div>

        </div>

        <!-- MAP CARD -->
        <section class="content-card">

            <div class="card-heading">
                <div>
                    <h5>
                        <i class="bi bi-geo-alt-fill me-2"
                            style="color:#198754;"></i>
                        Registered Household Locations
                    </h5>

                    <p>
                        Click a map marker to view household information.
                    </p>
                </div>
            </div>

            <div class="map-note">
                <i class="bi bi-info-circle me-1"></i>
                The map displays households that have registered valid
                latitude and longitude coordinates.
            </div>

            <div id="map"></div>

        </section>

        <!-- HOUSEHOLD TABLE -->
        <section class="content-card">

            <div class="card-heading">
                <div>
                    <h5>
                        <i class="bi bi-list-ul me-2"
                            style="color:#198754;"></i>
                        Household Location Records
                    </h5>

                    <p>
                        Overview of registered households and their addresses.
                    </p>
                </div>
            </div>

            <div class="table-wrapper">

                <table>
                    <thead>
                        <tr>
                            <th>Household ID</th>
                            <th>House Address</th>
                            <th>Latitude</th>
                            <th>Longitude</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php if (count($households) > 0): ?>

                            <?php foreach ($households as $house): ?>

                                <tr>
                                    <td class="household-id">
                                        #<?php echo htmlspecialchars($house['id']); ?>
                                    </td>

                                    <td>
                                        <?php echo htmlspecialchars($house['house_address']); ?>
                                    </td>

                                    <td>
                                        <?php echo htmlspecialchars($house['latitude']); ?>
                                    </td>

                                    <td>
                                        <?php echo htmlspecialchars($house['longitude']); ?>
                                    </td>
                                </tr>

                            <?php endforeach; ?>

                        <?php else: ?>

                            <tr>
                                <td colspan="4"
                                    class="text-center text-muted py-4">
                                    No household locations available.
                                </td>
                            </tr>

                        <?php endif; ?>

                    </tbody>
                </table>

            </div>

        </section>

        <!-- FOOTER -->
        <div class="dashboard-footer">
            Waste Collection Management System by Group 2
            &copy; <?php echo date("Y"); ?>
            <br>
            Admin Household Monitoring
        </div>

    </main>

    <!-- GOOGLE MAPS -->
    <script>
        const households = <?php echo $householdsJson; ?>;

        function escapeHTML(value) {
            return String(value).replace(/[&<>"']/g, char => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            })[char]);
        }

        function initMap() {

            const defaultLocation = {
                lat: 15.2200,
                lng: 120.5700
            };

            const map = new google.maps.Map(
                document.getElementById("map"),
                {
                    center: defaultLocation,
                    zoom: 13,
                    mapTypeControl: false,
                    streetViewControl: false,
                    fullscreenControl: true
                }
            );

            const bounds = new google.maps.LatLngBounds();
            const infoWindow = new google.maps.InfoWindow();

            households.forEach(function(house) {

                const position = {
                    lat: parseFloat(house.latitude),
                    lng: parseFloat(house.longitude)
                };

                const marker = new google.maps.Marker({
                    position: position,
                    map: map,
                    title: house.house_address
                });

                const content = `
                    <div style="padding:5px; min-width:180px;">
                        <strong style="color:#198754;">
                            Household #${escapeHTML(house.id)}
                        </strong>
                        <br><br>
                        <span>${escapeHTML(house.house_address)}</span>
                    </div>
                `;

                marker.addListener("click", function() {
                    infoWindow.setContent(content);
                    infoWindow.open(map, marker);
                });

                bounds.extend(position);
            });

            if (households.length === 1) {
                map.setCenter(bounds.getCenter());
                map.setZoom(16);
            } else if (households.length > 1) {
                map.fitBounds(bounds);
            }
        }
    </script>

    <script
        src="https://maps.googleapis.com/maps/api/js?key=AIzaSyCz8mz80N6FN03Q1q1nQFGeZLduXsZpNHY&callback=initMap"
        async defer>
    </script>

    <script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

</body>
</html>
