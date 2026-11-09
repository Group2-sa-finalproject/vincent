<?php
session_start();
include "../config/database.php";

// Only collectors can access this page
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    !in_array($_SESSION["role"], ["collector", "collectors"])
) {
    header("Location: ../index.php");
    exit;
}

$collector_id = (int) $_SESSION["user_id"];

// Create CSRF token
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

// Handle scanner requests
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    header("Content-Type: application/json");

    function respond($success, $message, $data = []) {
        echo json_encode([
            "success" => $success,
            "message" => $message,
            "data" => $data
        ]);
        exit;
    }

    // Verify CSRF token
    $token = $_POST["csrf_token"] ?? "";

    if (!hash_equals($_SESSION["csrf_token"], $token)) {
        respond(false, "Invalid request. Please refresh the page.");
    }

    $action = $_POST["action"] ?? "";

    // Verify resident QR code
    if ($action === "verify") {

        $qr_data = trim($_POST["qr_data"] ?? "");

        // Accept QR codes such as RESIDENT - 2 or RESIDENT-2
        if (!preg_match('/^RESIDENT\s*-\s*([1-9][0-9]*)$/i', $qr_data, $matches)) {
            respond(false, "Invalid resident QR code.");
        }

        $resident_id = (int) $matches[1];

        // Get resident information
        $stmt = mysqli_prepare($conn, "
            SELECT
                u.id,
                u.fullname,
                u.users,
                h.id AS household_id,
                h.house_address,
                s.subscription_status,
                s.payment_status,
                s.total_amount,
                s.amount_paid,
                s.balance_due
            FROM users u
            LEFT JOIN households h ON h.user_id = u.id
            LEFT JOIN subscriptions s ON s.user_id = u.id
            WHERE u.id = ?
              AND u.role = 'residents'
            LIMIT 1
            ");

            if (!$stmt) {
                respond(false, "Database error while checking resident: " . mysqli_error($conn));
            }

            mysqli_stmt_bind_param($stmt, "i", $resident_id);
            mysqli_stmt_execute($stmt);

            $result = mysqli_stmt_get_result($stmt);
            $resident = mysqli_fetch_assoc($result);

            mysqli_stmt_close($stmt);

            if (!$resident) {
                respond(false, "Resident account not found.");
            }

        // Set default payment information
        $payment_status = "UNPAID";
        $payment_amount = "0.00";
        $amount_paid = "0.00";
        $balance_due = "0.00";
        $paid_at = "No payment recorded";

        if ($resident["payment_status"] !== null) {

            $payment_status = strtoupper(
                trim((string)$resident["payment_status"])
            );

            $payment_amount = number_format(
                (float)$resident["total_amount"],
                2
            );

            $amount_paid = number_format(
                (float)$resident["amount_paid"],
                2
            );

            $balance_due = number_format(
                (float)$resident["balance_due"],
                2
            );

            if ($payment_status === "PAID") {
                $paid_at = "Payment recorded in subscription";
            }
        }

        // Check if the resident can proceed with collection
        $can_collect = (
            !empty($resident["household_id"]) &&
            ($resident["subscription_status"] ?? "") === "Active" &&
            $payment_status === "PAID" &&
            $resident["balance_due"] !== null &&
            is_numeric($resident["balance_due"]) &&
            (float)$resident["balance_due"] <= 0
        );

        respond(true, "Resident QR code recognized successfully.", [
            "resident_id" => $resident["id"],
            "fullname" => $resident["fullname"],
            "username" => $resident["users"],
            "household_id" => $resident["household_id"],
            "house_address" => $resident["house_address"] ?? "No household address registered",
            "subscription_status" => $resident["subscription_status"] ?? "No subscription",

            "payment_status" => $payment_status,
            "payment_amount" => $payment_amount,
            "amount_paid" => $amount_paid,
            "balance_due" => $balance_due,
            "paid_at" => $paid_at,

            "can_collect" => $can_collect
        ]);
    }

    // Record waste collection
    if ($action === "collect") {

        $resident_id = (int) ($_POST["resident_id"] ?? 0);
        $segregation = $_POST["segregation"] ?? "";

        if ($resident_id <= 0) {
            respond(false, "Invalid resident.");
        }

        if (!in_array($segregation, [
            "Properly Segregated",
            "Not Properly Segregated"
        ])) {
            respond(false, "Invalid segregation status.");
        }

        mysqli_begin_transaction($conn);

        try {

            // Recheck resident and subscription
            $stmt = mysqli_prepare($conn, "
                SELECT
                    u.id,
                    h.id AS household_id,
                    s.subscription_status,
                    s.payment_status,
                    s.total_amount,
                    s.amount_paid,
                    s.balance_due
                FROM users u
                INNER JOIN households h ON h.user_id = u.id
                INNER JOIN subscriptions s ON s.user_id = u.id
                WHERE u.id = ?
                AND u.role = 'residents'
                LIMIT 1
                FOR UPDATE
            ");

            if (!$stmt) {
                throw new Exception("Unable to verify resident.");
            }

            mysqli_stmt_bind_param($stmt, "i", $resident_id);
            mysqli_stmt_execute($stmt);

            $result = mysqli_stmt_get_result($stmt);
            $resident = mysqli_fetch_assoc($result);

            mysqli_stmt_close($stmt);

            if (
                !$resident ||
                $resident["subscription_status"] !== "Active" ||
                strtoupper(trim($resident["payment_status"])) !== "PAID" ||
                $resident["balance_due"] === null ||
                !is_numeric($resident["balance_due"]) ||
                (float)$resident["balance_due"] > 0
            ) {
                throw new Exception(
                    "Resident subscription is inactive or has an outstanding balance. Collection is blocked."
                );
            }

            $household_id = (int) $resident["household_id"];

            // Award 10 points only for proper segregation
            $points = ($segregation === "Properly Segregated") ? 5 : 0;

            // Insert collection record
            $stmt = mysqli_prepare($conn, "
                INSERT INTO waste_collections (
                    household_id,
                    collector_id,
                    collection_date,
                    segregation_status,
                    collection_status,
                    eco_points_awarded,
                    collected_at
                )
                VALUES (?, ?, CURDATE(), ?, 'Collected', ?, NOW())
            ");

            if (!$stmt) {
                throw new Exception("Unable to create collection record.");
            }

            mysqli_stmt_bind_param(
                $stmt,
                "iisi",
                $household_id,
                $collector_id,
                $segregation,
                $points
            );

            if (!mysqli_stmt_execute($stmt)) {
                if (mysqli_stmt_errno($stmt) == 1062) {
                    throw new Exception("This household has already been recorded as collected today.");
                }

                throw new Exception("Unable to save collection record.");
            }

            $collection_id = mysqli_insert_id($conn);
            mysqli_stmt_close($stmt);

            // Add eco-points only when properly segregated
            if ($points > 0) {

                $description = "Properly segregated household waste";

                $stmt = mysqli_prepare($conn, "
                    INSERT INTO eco_points (
                        user_id,
                        collection_id,
                        points,
                        description
                    )
                    VALUES (?, ?, ?, ?)
                ");

                if (!$stmt) {
                    throw new Exception("Unable to award eco-points.");
                }

                mysqli_stmt_bind_param(
                    $stmt,
                    "iiis",
                    $resident_id,
                    $collection_id,
                    $points,
                    $description
                );

                if (!mysqli_stmt_execute($stmt)) {
                    throw new Exception("Unable to save eco-points.");
                }

                mysqli_stmt_close($stmt);
            }

            mysqli_commit($conn);

            $message = $points > 0
                ? "Collection recorded! 5 eco-points awarded."
                : "Collection recorded. No eco-points awarded.";

            respond(true, $message, [
                "points" => $points
            ]);

        } catch (Throwable $e) {
            mysqli_rollback($conn);
            respond(false, $e->getMessage());
        }
    }

    respond(false, "Unknown request.");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Collector QR Scanner</title>

    <link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css">

    <style>
        body {
            background: #f4f7f6;
            font-family: Arial, sans-serif;
        }

        .navbar {
            background: #198754;
        }

        .scanner-container {
            max-width: 650px;
            margin: 40px auto;
        }

        .scanner-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(0,0,0,.08);
        }

        #reader {
            width: 100%;
            margin: 20px auto;
        }

        #resident-result {
            display: none;
            text-align: left;
        }

        .payment-box {
            padding: 15px;
            border-radius: 10px;
            margin: 15px 0;
        }

        .paid {
            background: #d1e7dd;
            color: #0f5132;
        }

        .unpaid {
            background: #f8d7da;
            color: #842029;
        }

        .info-table th {
            width: 40%;
        }
    </style>
</head>

<body>

<nav class="navbar navbar-dark">
    <div class="container">
        <span class="navbar-brand">
            Waste Collection - Collector
        </span>
    </div>
</nav>

<div class="container scanner-container">

    <div class="scanner-card text-center">

        <h3 class="text-success">Resident QR Scanner</h3>

        <p class="text-muted">
            Scan the resident's QR code to view resident and payment information.
        </p>

        <div id="reader"></div>

        <div id="scan-message" class="alert alert-info">
            Waiting for QR code...
        </div>

        <div id="resident-result">

            <hr>

            <h4 class="text-success text-center">Resident Information</h4>

            <table class="table table-bordered info-table">
                <tr>
                    <th>Resident ID</th>
                    <td id="resident-id"></td>
                </tr>
                <tr>
                    <th>Full Name</th>
                    <td id="resident-name"></td>
                </tr>
                <tr>
                    <th>Username</th>
                    <td id="resident-username"></td>
                </tr>
                <tr>
                    <th>Household ID</th>
                    <td id="household-id"></td>
                </tr>
                <tr>
                    <th>House Address</th>
                    <td id="resident-address"></td>
                </tr>
                <tr>
                    <th>Subscription Status</th>
                    <td id="subscription-status"></td>
                </tr>
            </table>

            <h4 class="text-success text-center mt-4">Payment Information</h4>

            <div id="payment-box" class="payment-box unpaid text-center">

                <h3 id="payment-status">UNPAID</h3>

                <p class="mb-1">Total Subscription Amount</p>
                <h2 id="payment-amount">₱0.00</h2>

                <hr>

                <p class="mb-1">Amount Paid</p>
                <h5 id="amount-paid">₱0.00</h5>

                <p class="mb-1">Balance Due</p>
                <h5 id="balance-due">₱0.00</h5>

                <p class="mb-0">
                    Payment Record: <span id="payment-date">No payment recorded</span>
                </p>

            </div>

            <div id="collection-section" style="display:none;">

                <hr>

                <h5 class="text-success text-center">Waste Segregation Assessment</h5>

                <button
                    type="button"
                    class="btn btn-success w-100 mb-2"
                    onclick="recordCollection('Properly Segregated')"
                >
                    Properly Segregated (+5 Eco-Points)
                </button>

                <button
                    type="button"
                    class="btn btn-warning w-100 mb-2"
                    onclick="recordCollection('Not Properly Segregated')"
                >
                    Not Properly Segregated (No Points)
                </button>

            </div>

            <div id="collection-blocked" class="alert alert-warning">
                Collection cannot proceed because the subscription is inactive or the payment is unpaid.
            </div>

            <button
                type="button"
                class="btn btn-outline-success w-100 mt-2"
                onclick="resetScanner()"
            >
                Back to Scanner
            </button>

        </div>

        <div class="mt-4">
            <a href="../admin/dashboard.php" class="btn btn-secondary w-100">
                Back to Dashboard
            </a>
        </div>

    </div>

</div>

<script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/html5-qrcode"></script>

<script>
    const csrfToken = <?php echo json_encode($_SESSION["csrf_token"]); ?>;

    let residentId = null;
    let scanner = null;
    let scannerRunning = false;
    let busy = false;

    const message = document.getElementById("scan-message");
    const result = document.getElementById("resident-result");

    function showMessage(text, type = "info") {
        message.className = "alert alert-" + type;
        message.textContent = text;
        message.style.display = "block";
    }

    async function sendRequest(data) {
        const formData = new FormData();

        formData.append("csrf_token", csrfToken);

        for (const key in data) {
            formData.append(key, data[key]);
        }

        const response = await fetch("scanner.php", {
            method: "POST",
            body: formData,
            credentials: "same-origin"
        });

        return await response.json();
    }

    async function startScanner() {
        try {
            scanner = new Html5Qrcode("reader");

            await scanner.start(
                { facingMode: "environment" },
                {
                    fps: 10,
                    qrbox: { width: 250, height: 250 }
                },
                handleScan
            );

            scannerRunning = true;
            showMessage("Camera active. Scan a resident QR code.", "info");

        } catch (error) {
            showMessage(
                "Unable to access camera. Please allow camera permission.",
                "danger"
            );
        }
    }

    async function handleScan(decodedText) {
        if (busy) return;

        busy = true;

        try {
            if (scannerRunning) {
                await scanner.stop();
                scannerRunning = false;
            }

            showMessage("Recognizing resident QR code...", "info");

            const response = await sendRequest({
                action: "verify",
                qr_data: decodedText
            });

            if (!response.success) {
                showMessage(response.message, "danger");
                busy = false;
                setTimeout(resetScanner, 2500);
                return;
            }

            const data = response.data;

            residentId = data.resident_id;

            document.getElementById("resident-id").textContent = data.resident_id;
            document.getElementById("resident-name").textContent = data.fullname;
            document.getElementById("resident-username").textContent = data.username || "Not available";
            document.getElementById("household-id").textContent = data.household_id || "Not registered";
            document.getElementById("resident-address").textContent = data.house_address;
            document.getElementById("subscription-status").textContent = data.subscription_status;

            document.getElementById("payment-status").textContent = data.payment_status;

            document.getElementById("payment-amount").textContent =
                "₱" + data.payment_amount;

            document.getElementById("amount-paid").textContent =
                "₱" + data.amount_paid;

            document.getElementById("balance-due").textContent =
                "₱" + data.balance_due;

            document.getElementById("payment-date").textContent = data.paid_at;

            const paymentBox = document.getElementById("payment-box");

            if (data.payment_status === "PAID") {
                paymentBox.className = "payment-box paid text-center";
            } else {
                paymentBox.className = "payment-box unpaid text-center";
            }

            document.getElementById("collection-section").style.display =
                data.can_collect ? "block" : "none";

            document.getElementById("collection-blocked").style.display =
                data.can_collect ? "none" : "block";

            busy = false;

            result.style.display = "block";
            message.style.display = "none";

        } catch (error) {
            showMessage(
                "Unable to verify resident. Please check your database and try again.",
                "danger"
            );

            busy = false;
            setTimeout(resetScanner, 2500);
        }
    }

    async function recordCollection(status) {
        if (!residentId || busy) return;

        busy = true;

        const buttons = document.querySelectorAll("#collection-section button");
        buttons.forEach(button => button.disabled = true);

        showMessage("Saving collection record...", "info");
        message.style.display = "block";

        try {
            const response = await sendRequest({
                action: "collect",
                resident_id: residentId,
                segregation: status
            });

            if (response.success) {
                showMessage(response.message, "success");
                result.style.display = "none";

                setTimeout(resetScanner, 2500);

            } else {
                showMessage(response.message, "danger");
                buttons.forEach(button => button.disabled = false);
                busy = false;
            }

        } catch (error) {
            showMessage("Unable to save collection. Please try again.", "danger");
            buttons.forEach(button => button.disabled = false);
            busy = false;
        }
    }

    async function resetScanner() {
        residentId = null;
        busy = false;

        result.style.display = "none";
        message.style.display = "block";
        document.getElementById("reader").innerHTML = "";

        if (scanner && scannerRunning) {
            try {
                await scanner.stop();
            } catch (error) {}
        }

        scannerRunning = false;

        if (scanner) {
            try {
                await scanner.clear();
            } catch (error) {}
        }

        startScanner();
    }

    startScanner();
</script>

</body>
</html>