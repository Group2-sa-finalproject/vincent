
<?php
session_start();
require_once __DIR__ . "/../config/db_system.php";

// Admin authentication
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'admin'
) {
    header("Location: ../index.php");
    exit;
}

$errorMessage = "";


$assignmentSuccess = "";

// ASSIGN COLLECTOR
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["assign_collector"])) {
    try {
        $routeId = (int) $_POST["route_id"];
        $collectorId = (int) $_POST["collector_id"];

        if ($routeId <= 0 || $collectorId <= 0) {
            throw new Exception("Please select a valid route and collector.");
        }

        $collectorCheck = $pdo->prepare("
            SELECT id FROM users
            WHERE id = ? AND role = 'collector'
        ");
        $collectorCheck->execute([$collectorId]);

        if (!$collectorCheck->fetch()) {
            throw new Exception("Invalid collector selected.");
        }

        $stmt = $pdo->prepare("
            UPDATE collection_routes
            SET collector_id = ?, status = 'assigned'
            WHERE id = ? AND status IN ('pending', 'assigned')
        ");
        $stmt->execute([$collectorId, $routeId]);

        if ($stmt->rowCount() === 0) {
            throw new Exception("Route was not updated. Check its status or collector assignment.");
        }

        header("Location: admin_routes.php?assigned=1");
        exit;

    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
    }
}

// EDIT REGULAR ROUTE
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["edit_route"])) {
    try {
        $routeId = (int) $_POST["route_id"];
        $routeName = trim($_POST["route_name"]);

        if ($routeId <= 0 || $routeName === "") {
            throw new Exception("Please enter a valid route name.");
        }

        $stmt = $pdo->prepare("
            UPDATE collection_routes
            SET route_name = ?
            WHERE id = ? AND route_type = 'regular'
        ");
        $stmt->execute([$routeName, $routeId]);

        header("Location: admin_routes.php?edited=1");
        exit;

    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
    }
}

// DELETE REGULAR ROUTE
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["delete_route"])) {
    try {
        $routeId = (int) $_POST["route_id"];

        if ($routeId <= 0) {
            throw new Exception("Invalid route selected.");
        }

        $pdo->beginTransaction();

        $check = $pdo->prepare("
            SELECT id FROM collection_routes
            WHERE id = ? AND route_type = 'regular'
        ");
        $check->execute([$routeId]);

        if (!$check->fetch()) {
            throw new Exception("Regular route not found.");
        }

        $deleteStops = $pdo->prepare("
            DELETE FROM route_stops
            WHERE route_id = ?
        ");
        $deleteStops->execute([$routeId]);

        $deleteRoute = $pdo->prepare("
            DELETE FROM collection_routes
            WHERE id = ? AND route_type = 'regular'
        ");
        $deleteRoute->execute([$routeId]);

        $pdo->commit();

        header("Location: admin_routes.php?deleted=1");
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $errorMessage = $e->getMessage();
    }
}

// GENERATE REGULAR ROUTE
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["generate_regular_route"])) {
    try {
        $pdo->beginTransaction();

        $routeHouseholds = $pdo->query("
            SELECT id FROM households
            WHERE latitude IS NOT NULL
              AND longitude IS NOT NULL
            ORDER BY id ASC
        ")->fetchAll(PDO::FETCH_COLUMN);

        if (count($routeHouseholds) === 0) {
            throw new Exception("No registered households with coordinates found.");
        }

        $routeStmt = $pdo->prepare("
            INSERT INTO collection_routes
                (route_name, route_type, status, created_by)
            VALUES (?, 'regular', 'pending', ?)
        ");

        $routeStmt->execute([
            "Regular Collection Route - " . date("Y-m-d H:i"),
            $_SESSION['user_id']
        ]);

        $routeId = $pdo->lastInsertId();

        $stopStmt = $pdo->prepare("
            INSERT INTO route_stops (route_id, household_id, stop_order)
            VALUES (?, ?, ?)
        ");

        foreach ($routeHouseholds as $index => $householdId) {
            $stopStmt->execute([
                $routeId,
                $householdId,
                $index + 1
            ]);
        }

        $pdo->commit();

        header("Location: admin_routes.php?success=1");
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $errorMessage = $e->getMessage();
    }
}

// Retrieve collectors for the assignment dropdown
$collectorStmt = $pdo->query("
    SELECT id, fullname
    FROM users
    WHERE role = 'collector'
    ORDER BY fullname ASC
");
$collectors = $collectorStmt->fetchAll(PDO::FETCH_ASSOC);

// Retrieve routes and their assigned collectors
$allRoutesStmt = $pdo->query("
    SELECT
        cr.id,
        cr.route_name,
        cr.route_type,
        cr.status,
        cr.collector_id,
        u.fullname AS collector_name
    FROM collection_routes cr
    LEFT JOIN users u ON cr.collector_id = u.id
    ORDER BY cr.created_at DESC
");
$allRoutes = $allRoutesStmt->fetchAll(PDO::FETCH_ASSOC);


// Retrieve household and route data
try {
    $stmt = $pdo->query("
        SELECT
            h.id,
            h.house_address,
            h.latitude,
            h.longitude,
            u.fullname
        FROM households h
        INNER JOIN users u ON h.user_id = u.id
        WHERE h.latitude IS NOT NULL
          AND h.longitude IS NOT NULL
        ORDER BY h.id ASC
    ");

    $households = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $routeStmt = $pdo->query("
        SELECT
            h.id,
            h.house_address,
            h.latitude,
            h.longitude,
            u.fullname,
            rs.stop_order
        FROM collection_routes cr
        INNER JOIN route_stops rs ON cr.id = rs.route_id
        INNER JOIN households h ON rs.household_id = h.id
        INNER JOIN users u ON h.user_id = u.id
        WHERE cr.id = (
            SELECT MAX(id)
            FROM collection_routes
            WHERE route_type = 'regular'
        )
        ORDER BY rs.stop_order ASC
    ");

    $routeStops = $routeStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Database error: " . htmlspecialchars($e->getMessage()));
}

$jsonFlags =
    JSON_HEX_TAG |
    JSON_HEX_APOS |
    JSON_HEX_QUOT |
    JSON_HEX_AMP;

$householdsJson = json_encode($households, $jsonFlags);
$routeStopsJson = json_encode($routeStops, $jsonFlags);

if ($householdsJson === false || $routeStopsJson === false) {
    die("Unable to process location data.");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Collection Route Management</title>

    <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f4f6f5;
            color: #263238;
        }

        /* NAVBAR */
       .navbar {
    background: #198754;
    color: white;
    padding: 17px 35px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
}

        .navbar h2 {
            font-size: 21px;
        }

        .navbar a {
            color: white;
            text-decoration: none;
            font-size: 14px;
            font-weight: bold;
        }

        .navbar a:hover {
            opacity: 0.8;
        }

        /* MAIN CONTENT */
        .main {
            max-width: 1250px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .page-header {
            margin-bottom: 25px;
        }

        .page-header h1 {
            font-size: 27px;
            margin-bottom: 8px;
        }

        .page-header p {
            color: #718096;
            font-size: 14px;
        }

        /* SUMMARY CARDS */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 18px;
            margin-bottom: 25px;
        }

        .summary-card {
            background: white;
            padding: 22px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
            gap: 15px;
        }

      .summary-icon {
         width: 50px;
         height: 50px;
         background: #e6f4ec;
         color: #198754;
         display: flex;
         align-items: center;
         justify-content: center;
         border-radius: 10px;
         font-size: 21px;
}

        .summary-card h3 {
            font-size: 23px;
            margin-bottom: 4px;
        }

        .summary-card p {
            color: #718096;
            font-size: 13px;
        }

        /* WHITE CONTENT CARDS */
        .card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            margin-bottom: 25px;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 20px;
        }

        .card-header h2 {
            font-size: 19px;
        }

        .card-header p {
            color: #718096;
            font-size: 13px;
            margin-top: 5px;
        }

        /* BUTTONS */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 11px 17px;
            border: none;
            border-radius: 7px;
            font-size: 13px;
            font-weight: bold;
            cursor: pointer;
            text-decoration: none;
            transition: 0.2s;
        }

       .btn-green {
       background: #198754;
       color: white;
}
        .btn-green:hover {
    background: #146c43;
}

        .btn-blue {
            background: #1976d2;
            color: white;
        }

        .btn-blue:hover {
            background: #125ba1;
        }

        /* ALERTS */
        .alert {
            padding: 13px 16px;
            border-radius: 7px;
            margin-bottom: 18px;
            font-size: 14px;
        }

        .success {
            background: #e8f5e9;
            color: #2e7d32;
        }

        .error {
            background: #ffebee;
            color: #c62828;
        }

        /* TABLE */
        .table-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 650px;
        }

        th, td {
            padding: 14px 12px;
            border-bottom: 1px solid #edf0ed;
            text-align: left;
            font-size: 13px;
        }

       th {
    background: #e6f4ec;
    color: #198754;
    font-weight: bold;
}

        tbody tr:hover {
            background: #f9fbf9;
        }

       .household-id {
    font-weight: bold;
    color: #198754;
}

        /* MAP */
        #map {
            width: 100%;
            height: 480px;
            border-radius: 10px;
            margin-top: 15px;
        }

        .map-controls {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }

        #routeMessage {
            margin-top: 14px;
            font-size: 13px;
            color: #2e7d32;
        }

        .empty {
            text-align: center;
            padding: 25px;
            color: #718096;
        }

        @media(max-width: 600px) {
            .navbar {
                padding: 15px 20px;
            }

            .main {
                margin-top: 20px;
            }

            .card {
                padding: 18px;
            }

            .page-header h1 {
                font-size: 23px;
            }

            #map {
                height: 350px;
            }
        }
    </style>
</head>

<body>

<nav class="navbar">
    <h2><i class="fas fa-recycle"></i> Waste Management System</h2>

    <a href="dashboard.php">
        <i class="fas fa-arrow-left"></i> Back to Dashboard
    </a>
</nav>

<main class="main">

    <div class="page-header">
        <h1>Collection Route Management</h1>
        <p>Manage registered household locations and generate collection routes.</p>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert success">
            <i class="fas fa-check-circle"></i>
            Regular collection route generated successfully!
        </div>
    <?php endif; ?>

    <?php if (!empty($errorMessage)): ?>
        <div class="alert error">
            <i class="fas fa-exclamation-circle"></i>
            <?php echo htmlspecialchars($errorMessage); ?>
        </div>
    <?php endif; ?>
    
<!-- ROUTE ASSIGNMENT -->
<section class="card">

    <div class="card-header">
        <div>
            <h2><i class="fas fa-user-plus"></i> Assign Collectors to Routes</h2>
            <p>Select a collector for each generated collection route.</p>
        </div>
    </div>

    <?php if (isset($_GET['assigned'])): ?>
        <div class="alert success">
            <i class="fas fa-check-circle"></i>
            Collector assigned successfully!
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['edited'])): ?>
    <div class="alert success">
        Route name updated successfully!
    </div>
<?php endif; ?>

<?php if (isset($_GET['deleted'])): ?>
    <div class="alert success">
        Regular route deleted successfully!
    </div>
<?php endif; ?>

    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Route ID</th>
                    <th>Route Name</th>
                    <th>Route Type</th>
                    <th>Status</th>
                    <th>Assigned Collector</th>
                    <th>Action</th>
                </tr>
            </thead>

            <tbody>
                <?php if (!empty($allRoutes)): ?>

                    <?php foreach ($allRoutes as $route): ?>
                        <tr>
                            <td>#<?php echo htmlspecialchars($route['id']); ?></td>

                            <td>
                                <?php echo htmlspecialchars($route['route_name']); ?>
                            </td>

                            <td>
                                <?php echo htmlspecialchars(ucfirst($route['route_type'])); ?>
                            </td>

                            <td>
                                <?php echo htmlspecialchars(ucfirst($route['status'])); ?>
                            </td>

                            <td>
                                <?php echo htmlspecialchars(
                                    $route['collector_name'] ?? 'Not assigned'
                                ); ?>
                            </td>

                            <td>
                                <?php if (in_array($route['status'], ['pending', 'assigned'])): ?>

                                    <form method="POST" action="admin_routes.php"
                                          style="display:flex; gap:8px; min-width:250px;">

                                        <input type="hidden" name="route_id"
                                               value="<?php echo (int)$route['id']; ?>">

                                        <select name="collector_id" required
                                                style="padding:8px; border:1px solid #ccc; border-radius:5px;">
                                            <option value="">Select Collector</option>

                                            <?php foreach ($collectors as $collector): ?>
                                                <option
                                                    value="<?php echo (int)$collector['id']; ?>"
                                                    <?php echo ((int)$route['collector_id'] === (int)$collector['id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($collector['fullname']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>

                                        <button type="submit"
                                                name="assign_collector"
                                                value="1"
                                                class="btn btn-green"
                                                onclick="return confirm('Assign this collector to the route?');">
                                            Assign
                                        </button>
                                    </form>

                                <?php else: ?>
                                    <span>Route is not assignable</span>
                                
<?php endif; ?>

<?php if ($route['route_type'] === 'regular'): ?>

    <!-- EDIT ROUTE NAME -->
    <form method="POST" action="admin_routes.php"
          style="display:flex; gap:8px; margin-top:8px;">

        <input type="hidden" name="route_id"
               value="<?php echo (int)$route['id']; ?>">

        <input type="text" name="route_name"
               value="<?php echo htmlspecialchars($route['route_name']); ?>"
               required
               style="width:150px; padding:8px; border:1px solid #ccc; border-radius:5px;">

        <button type="submit" name="edit_route" value="1"
                class="btn btn-blue">
            <i class="fas fa-edit"></i> Edit
        </button>
    </form>

    <!-- DELETE ROUTE -->
    <form method="POST" action="admin_routes.php"
          style="margin-top:8px;">

        <input type="hidden" name="route_id"
               value="<?php echo (int)$route['id']; ?>">

        <button type="submit" name="delete_route" value="1"
                class="btn"
                style="background:#dc3545; color:white;"
                onclick="return confirm('Are you sure you want to delete this regular route and all its stops?');">
            <i class="fas fa-trash"></i> Delete
        </button>
    </form>

<?php endif; ?>

</td>

                            </td>
                        </tr>
                    <?php endforeach; ?>

                <?php else: ?>
                    <tr>
                        <td colspan="6" class="empty">
                            No collection routes have been generated yet.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</section>


    <!-- SUMMARY -->
    <div class="summary-grid">

        <div class="summary-card">
            <div class="summary-icon">
                <i class="fas fa-house"></i>
            </div>
            <div>
                <h3><?php echo count($households); ?></h3>
                <p>Registered Households</p>
            </div>
        </div>

        <div class="summary-card">
            <div class="summary-icon">
                <i class="fas fa-map-location-dot"></i>
            </div>
            <div>
                <h3><?php echo count($routeStops); ?></h3>
                <p>Stops in Latest Regular Route</p>
            </div>
        </div>

        <div class="summary-card">
            <div class="summary-icon">
                <i class="fas fa-location-crosshairs"></i>
            </div>
            <div>
                <h3><?php echo count(array_filter($households, function($h) {
                    return !empty($h['latitude']) && !empty($h['longitude']);
                })); ?></h3>
                <p>Households with Coordinates</p>
            </div>
        </div>

    </div>

    <!-- HOUSEHOLDS -->
    <section class="card">

        <div class="card-header">
            <div>
                <h2><i class="fas fa-house-user"></i> Registered Households</h2>
                <p>View households with registered map coordinates.</p>
            </div>

            <form method="POST" action="admin_routes.php">
                <button
                    type="submit"
                    name="generate_regular_route"
                    value="1"
                    class="btn btn-green"
                    onclick="return confirm('Generate a new regular collection route?');"
                >
                    <i class="fas fa-route"></i>
                    Generate Regular Route
                </button>
            </form>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Household ID</th>
                        <th>Resident Name</th>
                        <th>House Address</th>
                        <th>Latitude</th>
                        <th>Longitude</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (count($households) > 0): ?>

                        <?php foreach ($households as $household): ?>
                            <tr>
                                <td class="household-id">
                                    #<?php echo htmlspecialchars($household['id']); ?>
                                </td>

                                <td>
                                    <?php echo htmlspecialchars($household['fullname']); ?>
                                </td>

                                <td>
                                    <?php echo htmlspecialchars($household['house_address']); ?>
                                </td>

                                <td>
                                    <?php echo htmlspecialchars($household['latitude']); ?>
                                </td>

                                <td>
                                    <?php echo htmlspecialchars($household['longitude']); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="empty">
                                No registered households with coordinates found.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </section>

    <!-- MAP -->
    <section class="card">

        <div class="card-header">
            <div>
                <h2><i class="fas fa-map-marked-alt"></i> Household Locations</h2>
                <p>View registered households and calculate a driving route.</p>
            </div>
        </div>

        <div class="map-controls">
            <button
                type="button"
                class="btn btn-blue"
                onclick="startRouteFromMyLocation()"
            >
                <i class="fas fa-location-arrow"></i>
                Start Route from My Location
            </button>
        </div>

        <p id="routeMessage"></p>

        <div id="map"></div>

    </section>

</main>

<script>
    const households = <?php echo $householdsJson; ?>;
    const routeStops = <?php echo $routeStopsJson; ?>;

    let map;
    let Route;

    async function initMap() {

        const routesLibrary = await google.maps.importLibrary("routes");
        Route = routesLibrary.Route;

        const defaultLocation = {
            lat: 15.2200,
            lng: 120.5700
        };

        map = new google.maps.Map(
            document.getElementById("map"),
            {
                zoom: 13,
                center: defaultLocation,
                mapTypeControl: false,
                streetViewControl: false,
                fullscreenControl: true
            }
        );

        const bounds = new google.maps.LatLngBounds();
        const infoWindow = new google.maps.InfoWindow();

        households.forEach(household => {

            const position = {
                lat: parseFloat(household.latitude),
                lng: parseFloat(household.longitude)
            };

            const marker = new google.maps.Marker({
                position: position,
                map: map,
                title: household.fullname
            });

            const content = `
                <div>
                    <strong>${escapeHTML(household.fullname)}</strong>
                    <br>
                    ${escapeHTML(household.house_address)}
                    <br>
                    <small>Household ID: ${escapeHTML(household.id)}</small>
                </div>
            `;

            marker.addListener("click", () => {
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

    function escapeHTML(value) {
        return String(value).replace(/[&<>"']/g, char => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        })[char]);
    }

    function startRouteFromMyLocation() {

        const message = document.getElementById("routeMessage");

        if (!navigator.geolocation) {
            message.textContent = "Your browser does not support GPS.";
            return;
        }

        message.textContent = "Getting your current location...";

        navigator.geolocation.getCurrentPosition(

            async function(position) {

                const startLocation = {
                    lat: position.coords.latitude,
                    lng: position.coords.longitude
                };

                if (routeStops.length === 0) {
                    message.textContent =
                        "No households found in the latest regular route.";
                    return;
                }

                message.textContent =
                    "Location detected. Calculating driving route...";

                const lastStop = routeStops[routeStops.length - 1];

                const destination = {
                    lat: Number(lastStop.latitude),
                    lng: Number(lastStop.longitude)
                };

                const request = {
                    origin: startLocation,
                    destination: destination,

                    intermediates: routeStops.slice(0, -1).map(stop => ({
                        location: {
                            lat: Number(stop.latitude),
                            lng: Number(stop.longitude)
                        }
                    })),

                    travelMode: "DRIVING",
                    optimizeWaypointOrder: true,

                    fields: [
                        "path",
                        "optimizedIntermediateWaypointIndices"
                    ]
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
                        "Driving route displayed successfully!";

                } catch (error) {

                    message.textContent = "Route error: " + error.message;
                    console.error("Routes API error:", error);

                }
            },

            function(error) {

                message.textContent =
                    "Unable to get your location. Please allow location access.";

                console.error(error);

            },

            {
                enableHighAccuracy: true,
                timeout: 15000,
                maximumAge: 0
            }
        );
    }
</script>

<script async
    src="https://maps.googleapis.com/maps/api/js?key=AIzaSyCz8mz80N6FN03Q1q1nQFGeZLduXsZpNHY&callback=initMap">
</script>

</body>
</html>
