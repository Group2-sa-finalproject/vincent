<?php
session_start();
include "../config/database.php";

// Only residents can access this page
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] != "residents"
) {
    header("Location: ../index.php");
    exit;
}

$user_id = (int) $_SESSION["user_id"];

$message = "";
$message_type = "";

// Get selected month
$month = isset($_GET["month"]) ? $_GET["month"] : date("Y-m");

if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
    $month = date("Y-m");
}

$first_day = new DateTime($month . "-01");
$last_day = clone $first_day;
$last_day->modify("last day of this month");

$today = date("Y-m-d");

// Schedule a collection
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["schedule"])) {

    $collection_date = $_POST["collection_date"] ?? "";
    $collection_address = trim($_POST["collection_address"] ?? "");
    $requested_time = trim($_POST["requested_time"] ?? "");
    $notes = trim($_POST["notes"] ?? "");

    $valid_date = DateTime::createFromFormat("!Y-m-d", $collection_date);

    if (
        !$valid_date ||
        $valid_date->format("Y-m-d") !== $collection_date
    ) {
        $message = "Please select a valid collection date.";
        $message_type = "error";

    } elseif ($collection_date < $today) {

        $message = "You cannot schedule a collection on a past date.";
        $message_type = "error";

    } elseif ($collection_address == "") {

        $message = "Please enter your collection address.";
        $message_type = "error";

    } elseif (strlen($collection_address) > 255) {

        $message = "The address must not exceed 255 characters.";
        $message_type = "error";

    } elseif (empty($requested_time)) {

        $message = "Please select your preferred collection time.";
        $message_type = "error";
    
    } else {

        // Check if the selected date is already booked
        $check = mysqli_prepare(
            $conn,
            "SELECT id
             FROM collection_schedules
             WHERE collection_date = ?
             AND status IN ('Pending', 'Approved')
             LIMIT 1"
        );

        if (!$check) {
            die("Database error: " . mysqli_error($conn));
        }

        mysqli_stmt_bind_param($check, "s", $collection_date);
        mysqli_stmt_execute($check);
        $result = mysqli_stmt_get_result($check);

        if (mysqli_num_rows($result) > 0) {

            $message = "Sorry! This date is already booked. Please choose another date.";
            $message_type = "error";

        } else {

            // Save the schedule
            $insert = mysqli_prepare(
                $conn,
                "INSERT INTO collection_schedules
                (user_id, collection_date, requested_time, collection_address, notes)
                VALUES (?, ?, ?, ?, ?)"
            );

            if (!$insert) {
                die("Database error: " . mysqli_error($conn));
            }

            mysqli_stmt_bind_param(
                $insert,
                "issss",
                $user_id,
                $collection_date,
                $requested_time,
                $collection_address,
                $notes
            );

            if (mysqli_stmt_execute($insert)) {

                $message = "Collection scheduled successfully! Your request is Pending approval.";
                $message_type = "success";

            } else {

                // Handles another resident booking the date at the same time
                if (mysqli_errno($conn) == 1062) {
                    $message = "Sorry! Another resident has already booked this date. Please choose another date.";
                } else {
                    $message = "Unable to save your schedule. Please try again.";
                }

                $message_type = "error";
            }

            mysqli_stmt_close($insert);
        }

        mysqli_stmt_close($check);
    }
}

// Get dates already booked in the selected month
$booked_dates = [];

$start_date = $first_day->format("Y-m-d");
$end_date = $last_day->format("Y-m-d");

$booked_query = mysqli_prepare(
    $conn,
    "SELECT collection_date
     FROM collection_schedules
     WHERE collection_date BETWEEN ? AND ?
     AND status IN ('Pending', 'Approved')"
);

if (!$booked_query) {
    die("Database error: " . mysqli_error($conn));
}

mysqli_stmt_bind_param($booked_query, "ss", $start_date, $end_date);
mysqli_stmt_execute($booked_query);

$booked_result = mysqli_stmt_get_result($booked_query);

while ($row = mysqli_fetch_assoc($booked_result)) {
    $booked_dates[] = $row["collection_date"];
}

mysqli_stmt_close($booked_query);

// Get the resident's collection history
$history = mysqli_prepare(
    $conn,
    "SELECT id, collection_date, requested_time,
            collection_address, notes, status
     FROM collection_schedules
     WHERE user_id = ?
     ORDER BY collection_date DESC, id DESC"
);

if (!$history) {
    die("Database error: " . mysqli_error($conn));
}

mysqli_stmt_bind_param($history, "i", $user_id);
mysqli_stmt_execute($history);
$history_result = mysqli_stmt_get_result($history);

// Calendar navigation
$previous_month = clone $first_day;
$previous_month->modify("-1 month");

$next_month = clone $first_day;
$next_month->modify("+1 month");

// Calendar starting position: Sunday = 0
$starting_day = (int) $first_day->format("w");
$days_in_month = (int) $first_day->format("t");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Collection Schedule</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f4f6f9;
            margin: 0;
            padding: 20px;
            color: #333;
        }

        .container {
            max-width: 1000px;
            margin: auto;
        }

        .card {
            background: white;
            padding: 22px;
            margin-bottom: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        h1, h2 {
            margin-top: 0;
        }

        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
        }

        .calendar-header h2 {
            margin: 0;
            text-align: center;
        }

        .nav-button {
            background: #198754;
            color: white;
            text-decoration: none;
            padding: 10px 14px;
            border-radius: 5px;
        }

        .calendar {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
            gap: 5px;
        }

        .day-name {
            text-align: center;
            font-weight: bold;
            padding: 10px 0;
            background: #e9ecef;
            border-radius: 4px;
        }

        .day {
            min-height: 85px;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 5px;
            background: white;
        }

        .day-number {
            font-weight: bold;
            display: block;
            margin-bottom: 8px;
        }

        .available {
            background: #e8f7ee;
            border-color: #8bd2a5;
        }

        .booked {
            background: #fdeaea;
            border-color: #e8aaaa;
        }

        .past {
            background: #eeeeee;
            color: #888;
        }

        .available-button {
            display: block;
            width: 100%;
            border: none;
            padding: 6px 2px;
            background: #198754;
            color: white;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
        }

        .booked-label,
        .past-label {
            font-size: 11px;
            display: block;
            line-height: 1.4;
        }

        .booked-label {
            color: #b02a37;
        }

        .past-label {
            color: #666;
        }

        .legend {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 18px;
            font-size: 13px;
        }

        .message {
            padding: 12px;
            margin-bottom: 18px;
            border-radius: 5px;
        }

        .success {
            background: #d1e7dd;
            color: #0f5132;
        }

        .error {
            background: #f8d7da;
            color: #842029;
        }

        label {
            display: block;
            margin: 15px 0 6px;
            font-weight: bold;
        }

        input, textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 5px;
            font: inherit;
        }

        textarea {
            min-height: 80px;
            resize: vertical;
        }

        .submit-button {
            margin-top: 18px;
            padding: 12px 18px;
            background: #198754;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 600px;
        }

        th, td {
            padding: 12px;
            border: 1px solid #ddd;
            text-align: left;
        }

        th {
            background: #198754;
            color: white;
        }

        .status {
            font-weight: bold;
        }

        .status-pending {
            color: #b58100;
        }

        .status-approved {
            color: #198754;
        }

        .status-rejected {
            color: #dc3545;
        }

        .status-completed {
            color: #0d6efd;
        }

        .small-note {
            color: #666;
            font-size: 13px;
            line-height: 1.5;
        }

        @media (max-width: 600px) {
            body {
                padding: 10px;
            }

            .card {
                padding: 12px;
            }

            .day {
                min-height: 65px;
                padding: 4px;
            }

            .day-name {
                font-size: 11px;
            }

            .day-number {
                font-size: 12px;
            }

            .available-button {
                font-size: 10px;
            }

            .booked-label,
            .past-label {
                font-size: 9px;
            }
        }
    </style>
</head>

<body>
 <a href="dashboard.php" class="nav-button">← Back to Dashboard</a>

<div class="container">
    <div class="card">

    
    <div class="card">
        <h1>Garbage Collection Schedule</h1>

        <p>
            Select an available date to request garbage collection.
            Only one resident can reserve each date.
        </p>

        <?php if ($message != ""): ?>
            <div class="message <?= htmlspecialchars($message_type) ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <div class="calendar-header">

            <a class="nav-button"
               href="?month=<?= $previous_month->format('Y-m') ?>">
                &laquo; Previous
            </a>

            <h2><?= $first_day->format("F Y") ?></h2>

            <a class="nav-button"
               href="?month=<?= $next_month->format('Y-m') ?>">
                Next &raquo;
            </a>

        </div>

        <div class="calendar">

            <?php
            $weekdays = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];

            foreach ($weekdays as $weekday):
            ?>
                <div class="day-name">
                    <?= $weekday ?>
                </div>
            <?php endforeach; ?>

            <?php
            // Empty cells before the first day
            for ($i = 0; $i < $starting_day; $i++):
            ?>
                <div class="day"></div>
            <?php endfor; ?>

            <?php
            // Display every day of the month
            for ($day = 1; $day <= $days_in_month; $day++):

                $date = $first_day->format("Y-m-") . str_pad(
                    $day,
                    2,
                    "0",
                    STR_PAD_LEFT
                );

                $is_past = $date < $today;
                $is_booked = in_array($date, $booked_dates);

                if ($is_past) {
                    $class = "past";
                } elseif ($is_booked) {
                    $class = "booked";
                } else {
                    $class = "available";
                }
            ?>

                <div class="day <?= $class ?>">

                    <span class="day-number">
                        <?= $day ?>
                    </span>

                    <?php if ($is_past): ?>

                        <span class="past-label">Past date</span>

                    <?php elseif ($is_booked): ?>

                        <span class="booked-label">
                            Already booked
                        </span>

                    <?php else: ?>

                        <button
                            type="button"
                            class="available-button"
                            onclick="selectDate('<?= $date ?>')">
                            Select date
                        </button>

                    <?php endif; ?>

                </div>

            <?php endfor; ?>

        </div>

        <div class="legend">
            <span>🟩 Available</span>
            <span>🟥 Already booked</span>
            <span>⬜ Past date</span>
        </div>

        <p class="small-note">
            A date becomes available again after the previous request
            is marked Completed or Rejected by the authorized staff.
        </p>

    </div>

   

    <h1 style="margin-top: 20px;">Garbage Collection Schedule</h1>

        <form method="POST" action="" id="schedule-form">

            <label for="collection_date">Collection Date</label>

            <input
                type="date"
                id="collection_date"
                name="collection_date"
                min="<?= htmlspecialchars($today) ?>"
                value="<?= htmlspecialchars($_POST['collection_date'] ?? '') ?>"
                required
            >

            <div class="form-group">
                <label class="form-label" for="requested_time">
                    Requested Time
                </label>

                <input
                    type="time"
                    id="requested_time"
                    name="requested_time"
                    class="form-control"
                    value="<?= htmlspecialchars($_POST['requested_time'] ?? '') ?>"
                    required
                >
            </div>

            <label for="collection_address">Collection Address</label>

            <input
                type="text"
                id="collection_address"
                name="collection_address"
                maxlength="255"
                value="<?= htmlspecialchars($_POST['collection_address'] ?? '') ?>"
                placeholder="Enter your complete collection address"
                required
            >

            <label for="notes">Additional Notes (Optional)</label>

            <textarea
                id="notes"
                name="notes"
                placeholder="Example: Garbage is placed outside the gate."
            ><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>

            <button
                type="submit"
                name="schedule"
                class="submit-button">
                Submit Collection Request
            </button>

        </form>

    </div>

    <div class="card">

        <h2>My Collection Requests</h2>

        <div class="table-wrapper">

            <table>

                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Address</th>
                        <th>Notes</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if (mysqli_num_rows($history_result) > 0): ?>

                        <?php while ($row = mysqli_fetch_assoc($history_result)): ?>

                            <?php
                            $status_class = "status-" .
                                strtolower($row["status"]);
                            ?>

                            <tr>
                                <td>
                                    <?= htmlspecialchars($row["collection_date"]) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($row["requested_time"]) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($row["collection_address"]) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($row["notes"] ?? "") ?>
                                </td>

                                <td class="status <?= htmlspecialchars($status_class) ?>">
                                    <?= htmlspecialchars($row["status"]) ?>
                                </td>
                            </tr>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="4">
                                You have no collection requests yet.
                            </td>
                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>

<script>
function selectDate(date) {
    document.getElementById("collection_date").value = date;

    document.getElementById("schedule-form").scrollIntoView({
        behavior: "smooth"
    });
}
</script>

</body>
</html>

<?php
mysqli_stmt_close($history);
?>