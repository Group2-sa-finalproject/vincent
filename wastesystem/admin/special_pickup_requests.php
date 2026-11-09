
<?php
session_start();
include "../config/database.php";

// ADMIN LOGIN CHECK
if (!isset($_SESSION["user_id"]) || ($_SESSION["role"] ?? "") !== "admin") {
    header("Location: ../login.php");
    exit;
}

$message = "";
$message_class = "";

// ACCEPT OR DENY REQUEST
if (isset($_POST["decision"])) {

    $request_id = intval($_POST["request_id"]);
    $decision = $_POST["decision"];

    if ($request_id > 0 && in_array($decision, ["approved", "rejected"])) {

        $stmt = $conn->prepare("
            UPDATE special_request_pickup
            SET status = ?
            WHERE id = ? AND status = 'pending'
        ");

        $stmt->bind_param("si", $decision, $request_id);

        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $message = "Request successfully " .
                ($decision == "approved" ? "accepted!" : "denied!");
            $message_class = "success";
        } else {
            $message = "Request already reviewed or not found.";
            $message_class = "warning";
        }

        $stmt->close();

    } else {
        $message = "Invalid request.";
        $message_class = "error";
    }
}
// DELETE PICKUP REQUEST
if (isset($_POST["delete_request"])) {

    $request_id = intval($_POST["request_id"]);

    if ($request_id > 0) {

        $stmt = $conn->prepare("
            DELETE FROM special_request_pickup
            WHERE id = ?
        ");

        $stmt->bind_param("i", $request_id);

        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $message = "Pickup request successfully deleted!";
            $message_class = "success";
        } else {
            $message = "Request not found or already deleted.";
            $message_class = "warning";
        }

        $stmt->close();

    } else {
        $message = "Invalid request.";
        $message_class = "error";
    }
}

// DISPLAY ALL PICKUP REQUESTS
$requests_result = $conn->query("
    SELECT *
    FROM special_request_pickup
    ORDER BY id DESC
");
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Admin Pickup Requests</title>

    <style>

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: Arial, sans-serif;
        }

        body {
            background-color: #f4f7f6;
            padding: 30px;
            color: #333;
        }

        .card {
            background: white;
            max-width: 1200px;
            margin: auto;
            padding: 25px 30px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }

        /* HEADER */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #eee;
            gap: 10px;
        }

        h2 {
            color: #2c3e50;
            font-size: 22px;
        }

        /* BACK TO DASHBOARD */
        .back-btn {
            color: #2e7d32;
            text-decoration: none;
            font-weight: bold;
            padding: 8px 12px;
            border-radius: 5px;
            white-space: nowrap;
        }

        .back-btn:hover {
            background: #e8f5e9;
        }

        /* MESSAGE */
        .message {
            padding: 12px;
            margin-bottom: 15px;
            border-radius: 5px;
        }

        .success {
            background: #d4edda;
            color: #155724;
        }

        .warning {
            background: #fff3cd;
            color: #856404;
        }

        .error {
            background: #f8d7da;
            color: #721c24;
        }

        /* TABLE */
        .table-responsive {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
            text-align: left;
        }

        th, td {
            padding: 14px 12px;
            border-bottom: 1px solid #ddd;
        }

        th {
            background: #e8f5e9;
            color: #2e7d32;
        }

        tbody tr:hover {
            background: #fafdfb;
        }

        /* STATUS */
        .badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }

        .pending {
            background: #fff3cd;
            color: #856404;
        }

        .approved {
            background: #d4edda;
            color: #155724;
        }

        .rejected {
            background: #f8d7da;
            color: #721c24;
        }

        /* ACTION BUTTONS */
        .delete {
    background: #e53935;
}

.delete:hover {
    background: #b71c1c;
}
        .actions {
            display: flex;
            gap: 7px;
        }

        button {
            border: none;
            padding: 8px 13px;
            border-radius: 5px;
            color: white;
            cursor: pointer;
            font-weight: bold;
        }

        .accept {
            background: #2e7d32;
        }

        .deny {
            background: #c62828;
        }

        .accept:hover {
            background: #256629;
        }

        .deny:hover {
            background: #a91f1f;
        }

        .reviewed {
            color: #777;
            font-size: 13px;
        }

        .empty {
            text-align: center;
            padding: 25px;
            color: #777;
        }

        @media(max-width: 600px) {
            body {
                padding: 15px;
            }

            .card {
                padding: 18px;
            }

            .header {
                align-items: flex-start;
            }

            h2 {
                font-size: 18px;
            }
        }

    </style>
</head>

<body>

    <div class="card">

        <!-- HEADER AND BACK BUTTON -->
        <div class="header">

            <h2>Pickup Requests & Admin Decisions</h2>

            <a href="dashboard.php" class="back-btn">
                &larr; Back to Dashboard
            </a>

        </div>

        <!-- SUCCESS OR ERROR MESSAGE -->
        <?php if (!empty($message)): ?>

            <div class="message <?= htmlspecialchars($message_class) ?>">
                <?= htmlspecialchars($message) ?>
            </div>

        <?php endif; ?>


        <!-- PICKUP REQUEST TABLE -->
        <div class="table-responsive">

            <table>

                <thead>
                    <tr>
                        <th>Date & Time</th>
                        <th>Reason</th>
                        <th>Location</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if ($requests_result && $requests_result->num_rows > 0): ?>

                        <?php while ($row = $requests_result->fetch_assoc()): ?>

                            <?php
                                $status = strtolower($row["status"]);

                                $badge_class = "pending";

                                if ($status == "approved" || $status == "scheduled") {
                                    $badge_class = "approved";
                                } elseif ($status == "rejected") {
                                    $badge_class = "rejected";
                                }
                            ?>

                            <tr>

                                <!-- DATE AND TIME -->
                                <td>
                                    <strong>
                                        <?= htmlspecialchars($row["requested_date"]) ?>
                                    </strong>

                                    <br>

                                    <small>
                                        <?= htmlspecialchars($row["requested_time"]) ?>
                                    </small>
                                </td>

                                <!-- REASON -->
                                <td>
                                    <?= htmlspecialchars($row["pickup_reason"]) ?>
                                </td>

                                <!-- LOCATION -->
                                <td>
                                    <?= htmlspecialchars($row["location_address"]) ?>
                                </td>

                                <!-- STATUS -->
                                <td>
                                    <span class="badge <?= $badge_class ?>">
                                        <?= htmlspecialchars(strtoupper($status)) ?>
                                    </span>
                                </td>

                                <!-- ADMIN ACTION -->
<td>

    <div class="actions">

        <?php if ($status == "pending"): ?>

            <!-- ACCEPT -->
            <form method="POST"
                onsubmit="return confirm('Accept this request?');">

                <input type="hidden"
                    name="request_id"
                    value="<?= (int)$row["id"] ?>">

                <button type="submit"
                    name="decision"
                    value="approved"
                    class="accept">

                    Accept

                </button>
            </form>

            <!-- DENY -->
            <form method="POST"
                onsubmit="return confirm('Deny this request?');">

                <input type="hidden"
                    name="request_id"
                    value="<?= (int)$row["id"] ?>">

                <button type="submit"
                    name="decision"
                    value="rejected"
                    class="deny">

                    Deny

                </button>
            </form>

        <?php else: ?>

            <span class="reviewed">
                Already Reviewed
            </span>

        <?php endif; ?>

        <!-- DELETE -->
        <form method="POST"
            onsubmit="return confirm('Are you sure you want to permanently delete this pickup request?');">

            <input type="hidden"
                name="request_id"
                value="<?= (int)$row["id"] ?>">

            <button type="submit"
                name="delete_request"
                value="1"
                class="delete">

                Delete

            </button>

        </form>

    </div>

</td>

                            </tr>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="5" class="empty">
                                No pickup requests found.
                            </td>
                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</body>
</html>
