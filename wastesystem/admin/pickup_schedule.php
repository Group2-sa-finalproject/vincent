
<?php
session_start();
include "../config/database.php";

// Only admin can access this page
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "admin"
) {
    header("Location: ../index.php");
    exit;
}

// CSRF protection
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$message = "";
$error = "";


// Handle admin actions
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $token = $_POST["csrf_token"] ?? "";

    if (!hash_equals($_SESSION["csrf_token"], $token)) {
        $error = "Invalid request. Please try again.";
    } else {

        $action = $_POST["action"] ?? "";

        $schedule_id = isset($_POST["schedule_id"])
            ? (int) $_POST["schedule_id"]
            : 0;

        // APPROVE, COMPLETE, OR REJECT REQUEST
        if ($action === "update_status") {

            $new_status = $_POST["status"] ?? "";

            if (
                $schedule_id > 0 &&
                in_array($new_status, ["Approved", "Completed", "Rejected"], true)
            ) {
                $stmt = $conn->prepare("
                    UPDATE collection_schedules
                    SET status = ?
                    WHERE id = ?
                ");

                if ($stmt) {
                    $stmt->bind_param("si", $new_status, $schedule_id);

                    if ($stmt->execute()) {
                        $message = "Collection schedule status updated successfully.";
                    } else {
                        $error = "Unable to update the collection schedule.";
                    }

                    $stmt->close();
                } else {
                    $error = "Database error: " . $conn->error;
                }

            } else {
                $error = "Invalid schedule or status.";
            }

        }

        // ASSIGN COLLECTOR
        elseif ($action === "assign_collector") {

            $collector_id = isset($_POST["collector_id"])
                ? (int) $_POST["collector_id"]
                : 0;

            if ($schedule_id <= 0 || $collector_id <= 0) {
                $error = "Please select a valid collector.";
            } else {

                // Verify that the request is approved
                $check = $conn->prepare("
                    SELECT id
                    FROM collection_schedules
                    WHERE id = ? AND status = 'Approved'
                ");

                $check->bind_param("i", $schedule_id);
                $check->execute();
                $approved_request = $check->get_result()->fetch_assoc();
                $check->close();

                // Verify that the selected account is a collector
                $collector_check = $conn->prepare("
                    SELECT id
                    FROM users
                    WHERE id = ? AND role = 'collector'
                ");

                $collector_check->bind_param("i", $collector_id);
                $collector_check->execute();
                $valid_collector = $collector_check->get_result()->fetch_assoc();
                $collector_check->close();

                if (!$approved_request) {
                    $error = "Only approved requests can be assigned.";
                } elseif (!$valid_collector) {
                    $error = "Invalid collector account.";
                } else {

                    $stmt = $conn->prepare("
                        UPDATE collection_schedules
                        SET collector_id = ?
                        WHERE id = ? AND status = 'Approved'
                    ");

                    $stmt->bind_param("ii", $collector_id, $schedule_id);

                    if ($stmt->execute()) {
                        $message = "Collector assigned successfully.";
                    } else {
                        $error = "Unable to assign collector.";
                    }

                    $stmt->close();
                }
            }

        } else {
            $error = "Invalid action.";
        }
    }
}
// Get selected calendar month
$month = isset($_GET["month"]) ? $_GET["month"] : date("Y-m");

if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date("Y-m");
}

$month_start = $month . "-01";

$date_object = DateTime::createFromFormat("!Y-m-d", $month_start);

if (!$date_object || $date_object->format("Y-m-d") !== $month_start) {
    $month = date("Y-m");
    $month_start = $month . "-01";
    $date_object = new DateTime($month_start);
}

$month_end = date("Y-m-t", strtotime($month_start));
$days_in_month = (int) date("t", strtotime($month_start));
$first_day = (int) date("w", strtotime($month_start));

$previous_month = date("Y-m", strtotime($month_start . " -1 month"));
$next_month = date("Y-m", strtotime($month_start . " +1 month"));

// Get schedules for the selected month
$schedules = [];

$stmt = $conn->prepare("
   SELECT
    cs.id,
    cs.user_id,
    cs.collection_date,
    cs.requested_time,
    cs.collection_address,
    cs.notes,
    cs.status,
    cs.created_at,
    u.fullname,
    u.users
    FROM collection_schedules cs
    LEFT JOIN users u ON cs.user_id = u.id
    WHERE cs.collection_date BETWEEN ? AND ?
    ORDER BY cs.collection_date ASC, cs.id DESC
");

if (!$stmt) {
    die("Database query failed: " . $conn->error);
}

$stmt->bind_param("ss", $month_start, $month_end);
$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $schedules[] = $row;
}

$stmt->close();

// Count active bookings for each date
$booked_dates = [];

foreach ($schedules as $schedule) {
    if (
        $schedule["status"] === "Pending" ||
        $schedule["status"] === "Approved"
    ) {
        $date = $schedule["collection_date"];

        if (!isset($booked_dates[$date])) {
            $booked_dates[$date] = [];
        }

        $booked_dates[$date][] = $schedule;
    }
}

// Get all pending schedules
$pending_result = $conn->query("
   SELECT
    cs.id,
    cs.collection_date,
    cs.requested_time,
    cs.collection_address,
    cs.notes,
    cs.status,
    cs.created_at,
    u.fullname,
    u.users
    FROM collection_schedules cs
    LEFT JOIN users u ON cs.user_id = u.id
    WHERE cs.status = 'Pending'
    ORDER BY cs.collection_date ASC
");

if (!$pending_result) {
    die("Unable to load pending schedules: " . $conn->error);
}

$pending_schedules = [];

while ($row = $pending_result->fetch_assoc()) {
    $pending_schedules[] = $row;
}
// Get all collector accounts
$collectors = [];

$collector_result = $conn->query("
    SELECT id, fullname, users
    FROM users
    WHERE role = 'collector'
    ORDER BY fullname ASC
");
// Get approved collection requests
$approved_result = $conn->query("
    SELECT
        cs.id,
        cs.collection_date,
        cs.requested_time,
        cs.collection_address,
        cs.notes,
        cs.collector_id,
        u.fullname,
        u.users,
        c.fullname AS collector_name
    FROM collection_schedules cs
    LEFT JOIN users u ON cs.user_id = u.id
    LEFT JOIN users c ON cs.collector_id = c.id
    WHERE cs.status = 'Approved'
    ORDER BY cs.collection_date ASC, cs.requested_time ASC
");

if (!$approved_result) {
    die("Unable to load approved requests: " . $conn->error);
}

$approved_schedules = [];

while ($row = $approved_result->fetch_assoc()) {
    $approved_schedules[] = $row;
}
if (!$collector_result) {
    die("Unable to load collectors: " . $conn->error);
}

while ($row = $collector_result->fetch_assoc()) {
    $collectors[] = $row;
}

// Get today's date
$today = date("Y-m-d");

// Display selected month
$month_label = $date_object->format("F Y");
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Collection Schedule - Admin</title>

    <!-- Bootstrap CSS -->
    <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">

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
            max-width: 1200px;
            margin: 25px auto;
        }

        .schedule-card {
            background: white;
            border: 1px solid #e8eeea;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.04);
            padding: 25px;
            margin-bottom: 25px;
        }

        .page-title {
            color: #263b32;
            font-weight: 700;
        }

        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
            gap: 5px;
        }

        .weekday {
            text-align: center;
            padding: 10px 2px;
            background: #e9ecef;
            border-radius: 4px;
            font-size: 13px;
            font-weight: bold;
        }

        .calendar-day {
            min-height: 100px;
            padding: 7px;
            border: 1px solid #dfe7e2;
            border-radius: 5px;
            background: #fff;
            font-size: 12px;
            overflow-wrap: anywhere;
        }

        .calendar-day.available {
            background: #e9f7ef;
            border-color: #8bd3a7;
        }

        .calendar-day.booked {
            background: #ffe8e8;
            border-color: #ef9a9a;
        }

        .calendar-day.past {
            background: #eeeeee;
            color: #888;
        }

        .day-number {
            font-weight: bold;
            margin-bottom: 5px;
        }

        .calendar-name {
            display: block;
            margin-top: 4px;
            font-size: 11px;
            line-height: 1.4;
        }

        .legend {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            font-size: 12px;
            margin-top: 15px;
        }

        .legend span {
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .legend-box {
            width: 13px;
            height: 13px;
            display: inline-block;
            border: 1px solid #bbb;
        }

        .table th {
            background-color: #e9f5ee;
            white-space: nowrap;
        }

        .table td {
            vertical-align: middle;
        }

        .status-badge {
            padding: 5px 9px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
            display: inline-block;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-approved {
            background: #cfe2ff;
            color: #084298;
        }

        .status-completed {
            background: #d1e7dd;
            color: #0f5132;
        }

        .status-rejected {
            background: #f8d7da;
            color: #842029;
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
            .schedule-card {
                padding: 12px;
            }

            .calendar-grid {
                gap: 3px;
            }

            .calendar-day {
                min-height: 65px;
                padding: 3px;
                font-size: 10px;
            }

            .weekday {
                font-size: 10px;
                padding: 6px 1px;
            }

            .calendar-name {
                font-size: 9px;
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
                Waste Collection Admin
            </span>

            <a href="../logout.php" class="btn btn-outline-light btn-sm">
                <i class="bi bi-box-arrow-right me-1"></i>
                Logout
            </a>

        </div>
    </nav>

    <main class="container page-container">

        <!-- Back Button -->
        <a href="dashboard.php" class="btn btn-success btn-sm mb-3">
            <i class="bi bi-arrow-left me-1"></i>
            Back to Dashboard
        </a>

        <h2 class="page-title">Collection Schedule</h2>

        <p class="text-muted">
            View residents' collection bookings and manage their request status.
        </p>

        <!-- Messages -->
        <?php if ($message !== ""): ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ""): ?>
            <div class="alert alert-danger">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Calendar -->
        <section class="schedule-card">

            <div class="d-flex justify-content-between align-items-center mb-3">

                <a href="?month=<?php echo htmlspecialchars($previous_month); ?>"
                   class="btn btn-success btn-sm">
                    &laquo; Previous
                </a>

                <h4 class="mb-0 text-center">
                    <?php echo htmlspecialchars($month_label); ?>
                </h4>

                <a href="?month=<?php echo htmlspecialchars($next_month); ?>"
                   class="btn btn-success btn-sm">
                    Next &raquo;
                </a>

            </div>

            <div class="calendar-grid">

                <?php
                $weekdays = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];

                foreach ($weekdays as $weekday):
                ?>
                    <div class="weekday">
                        <?php echo $weekday; ?>
                    </div>
                <?php endforeach; ?>

                <!-- Empty spaces before the first day -->
                <?php for ($i = 0; $i < $first_day; $i++): ?>
                    <div></div>
                <?php endfor; ?>

                <!-- Calendar dates -->
                <?php for ($day = 1; $day <= $days_in_month; $day++): ?>

                    <?php
                    $calendar_date = $month . "-" . str_pad($day, 2, "0", STR_PAD_LEFT);

                    $is_past = $calendar_date < $today;
                    $is_booked = isset($booked_dates[$calendar_date]);

                    if ($is_past) {
                        $day_class = "past";
                    } elseif ($is_booked) {
                        $day_class = "booked";
                    } else {
                        $day_class = "available";
                    }
                    ?>

                    <div class="calendar-day <?php echo $day_class; ?>">

                        <div class="day-number">
                            <?php echo $day; ?>
                        </div>

                        <?php if ($is_booked): ?>

                            <?php foreach ($booked_dates[$calendar_date] as $booking): ?>

                                <span class="calendar-name">
                                    <strong>
                                        <?php echo htmlspecialchars($booking["fullname"] ?? "Unknown Resident"); ?>
                                    </strong>

                                    <br>

                                    <span class="status-badge status-<?php echo strtolower($booking["status"]); ?>">
                                        <?php echo htmlspecialchars($booking["status"]); ?>
                                    </span>
                                </span>

                            <?php endforeach; ?>

                        <?php elseif ($is_past): ?>

                            <small>Past date</small>

                        <?php else: ?>

                            <small>Available</small>

                        <?php endif; ?>

                    </div>

                <?php endfor; ?>

            </div>

            <!-- Calendar Legend -->
            <div class="legend">

                <div class="legend">
                    <span>🟩 Available</span>
                    <span>🟥 Already booked</span>
                    <span>⬜ Past date</span>
                </div>

            </div>

            <p class="text-muted small mt-3 mb-0">
                Pending and approved requests reserve their dates.
                Completed or rejected requests no longer reserve a date.
            </p>

        </section>

        <!-- Pending Requests -->
        <section class="schedule-card">

            <h4 class="mb-3">
                <i class="bi bi-clock-history text-success me-2"></i>
                Pending Collection Requests
            </h4>

            <p class="text-muted small">
                These requests were submitted by residents and are waiting for admin action.
            </p>

            <div class="table-responsive">

                <table class="table table-bordered table-hover">

                    <thead>
                        <tr>
                            <th>Resident</th>
                            <th>Username</th>
                            <th>Collection Date</th>
                            <th>Requested Time</th> 
                            <th>Address</th>
                            <th>Notes</th>
                            <th>Status</th>
                            <th>Admin Action</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php if (count($pending_schedules) > 0): ?>

                            <?php foreach ($pending_schedules as $schedule): ?>

                                <tr>

                                    <td>
                                        <?php echo htmlspecialchars($schedule["fullname"] ?? "Unknown Resident"); ?>
                                    </td>

                                    <td>
                                        <?php echo htmlspecialchars($schedule["users"] ?? "N/A"); ?>
                                    </td>

                                    <td>
                                        <?php
                                        echo htmlspecialchars(
                                            date("F d, Y", strtotime($schedule["collection_date"]))
                                        );
                                        ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars($schedule["requested_time"] ?? ""); ?>
                                    </td>

                                    <td>
                                        <?php echo htmlspecialchars($schedule["collection_address"] ?? ""); ?>
                                    </td>

                                    <td>
                                        <?php echo htmlspecialchars($schedule["notes"] ?? ""); ?>
                                    </td>

                                    <td>
                                        <span class="status-badge status-pending">
                                            Pending
                                        </span>
                                    </td>

                                    <td>

                                        <form method="POST" action="" class="d-flex flex-column gap-2">
                                            <input type="hidden" name="action" value="update_status">

                                            <input type="hidden"
                                                   name="csrf_token"
                                                   value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>">

                                            <input type="hidden"
                                                   name="schedule_id"
                                                   value="<?php echo (int) $schedule["id"]; ?>">

                                            <select name="status"
                                                    class="form-select form-select-sm"
                                                    required>

                                                <option value="">Choose Action</option>
                                                <option value="Approved">Approve</option>
                                                <option value="Completed">Mark Completed</option>
                                                <option value="Rejected">Reject</option>

                                            </select>

                                            <button type="submit"
                                                    class="btn btn-success btn-sm">
                                                Update Status
                                            </button>

                                        </form>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php else: ?>

                            <tr>
                                <td colspan="7" class="text-center text-muted">
                                    There are no pending collection requests.
                                </td>
                            </tr>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>
        <!-- Approved Requests - Assign Collector -->
<section class="schedule-card">

    <h4 class="mb-3">
        <i class="bi bi-truck text-success me-2"></i>
        Approved Requests - Assign Collector
    </h4>

    <p class="text-muted small">
        Assign a collector to each approved collection request.
    </p>

    <div class="table-responsive">
        <table class="table table-bordered table-hover">

            <thead>
                <tr>
                    <th>Resident</th>
                    <th>Collection Date</th>
                    <th>Requested Time</th>
                    <th>Address</th>
                    <th>Assigned Collector</th>
                    <th>Action</th>
                </tr>
            </thead>

            <tbody>

                <?php if (count($approved_schedules) > 0): ?>

                    <?php foreach ($approved_schedules as $schedule): ?>

                        <tr>
                            <td>
                                <?= htmlspecialchars($schedule["fullname"] ?? "Unknown Resident"); ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(date("F d, Y", strtotime($schedule["collection_date"]))); ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($schedule["requested_time"] ?? ""); ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($schedule["collection_address"] ?? ""); ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($schedule["collector_name"] ?? "Not assigned"); ?>
                            </td>

                            <td>
                                <form method="POST" class="d-flex flex-column gap-2">

                                    <input type="hidden" name="action" value="assign_collector">

                                    <input type="hidden"
                                           name="csrf_token"
                                           value="<?= htmlspecialchars($_SESSION["csrf_token"]); ?>">

                                    <input type="hidden"
                                           name="schedule_id"
                                           value="<?= (int) $schedule["id"]; ?>">

                                    <select name="collector_id"
                                            class="form-select form-select-sm"
                                            required>

                                        <option value="">Choose Collector</option>

                                        <?php foreach ($collectors as $collector): ?>

                                            <option value="<?= (int) $collector["id"]; ?>"
                                                <?= ((int) $schedule["collector_id"] === (int) $collector["id"]) ? "selected" : ""; ?>>

                                                <?= htmlspecialchars($collector["fullname"]); ?>

                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                    <button type="submit" class="btn btn-success btn-sm">
                                        <i class="bi bi-person-check me-1"></i>
                                        Assign Collector
                                    </button>

                                </form>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="6" class="text-center text-muted">
                            No approved collection requests available for assignment.
                        </td>
                    </tr>

                <?php endif; ?>

            </tbody>

        </table>
    </div>

</section>

        <!-- All Schedules -->
        <section class="schedule-card">

            <h4 class="mb-3">
                <i class="bi bi-calendar-week text-success me-2"></i>
                All Collection Schedules
            </h4>

            <p class="text-muted small">
                All collection requests for <?php echo htmlspecialchars($month_label); ?>.
            </p>

            <div class="table-responsive">

                <table class="table table-bordered table-hover">

                    <thead>
                        <tr>
                            <th>Resident</th>
                            <th>Collection Date</th>
                            <th>Address</th>
                            <th>Status</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php if (count($schedules) > 0): ?>

                            <?php foreach ($schedules as $schedule): ?>

                                <tr>

                                    <td>
                                        <?php echo htmlspecialchars($schedule["fullname"] ?? "Unknown Resident"); ?>
                                    </td>

                                    <td>
                                        <?php
                                        echo htmlspecialchars(
                                            date("F d, Y", strtotime($schedule["collection_date"]))
                                        );
                                        ?>
                                    </td>

                                    <td>
                                        <?php echo htmlspecialchars($schedule["collection_address"] ?? ""); ?>
                                    </td>

                                    <td>
                                        <?php
                                        $status_class = "status-pending";

                                        if ($schedule["status"] === "Approved") {
                                            $status_class = "status-approved";
                                        } elseif ($schedule["status"] === "Completed") {
                                            $status_class = "status-completed";
                                        } elseif ($schedule["status"] === "Rejected") {
                                            $status_class = "status-rejected";
                                        }
                                        ?>

                                        <span class="status-badge <?php echo $status_class; ?>">
                                            <?php echo htmlspecialchars($schedule["status"]); ?>
                                        </span>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php else: ?>

                            <tr>
                                <td colspan="4" class="text-center text-muted">
                                    No collection schedules found for this month.
                                </td>
                            </tr>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>

        <!-- Footer -->
        <footer class="dashboard-footer">
            Waste Collection Management System by Group 2 &copy; <?php echo date("Y"); ?>
            <br>
            Admin Collection Schedule
        </footer>

    </main>

    <script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

</body>
</html>