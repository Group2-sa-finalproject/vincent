
<?php
session_start();
include "../config/database.php";

// Check if the user is logged in as a resident
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] != "residents"
) {
    header("Location: ../index.php");
    exit;
}

// Get logged-in resident ID
$user_id = (int) $_SESSION["user_id"];

// Get resident information
$sql = mysqli_prepare(
    $conn,
    "SELECT id, fullname, users, role
     FROM users
     WHERE id = ?
     LIMIT 1"
);

mysqli_stmt_bind_param($sql, "i", $user_id);
mysqli_stmt_execute($sql);

$result = mysqli_stmt_get_result($sql);
$user = mysqli_fetch_assoc($result);
mysqli_stmt_close($sql);

if (!$user) {
    session_unset();
    session_destroy();
    header("Location: ../index.php");
    exit;
}

// Get subscription and billing information
$subscription_sql = mysqli_prepare(
    $conn,
    "SELECT
        total_amount,
        amount_paid,
        balance_due,
        payment_status,
        subscription_status,
        created_at
     FROM subscriptions
     WHERE user_id = ?
     LIMIT 1"
);

if (!$subscription_sql) {
    die("Subscription query failed: " . mysqli_error($conn));
}

mysqli_stmt_bind_param($subscription_sql, "i", $user_id);
mysqli_stmt_execute($subscription_sql);

$subscription_result = mysqli_stmt_get_result($subscription_sql);
$subscription = mysqli_fetch_assoc($subscription_result);

mysqli_stmt_close($subscription_sql);

// Billing information
if (!$subscription) {
    $payment_status = "No Subscription";
    $payment_amount = 0;
    $amount_paid = 0;
    $balance_due = 0;
    $subscription_status = "Not Subscribed";
    $subscription_date = null;
} else {
    $payment_status = $subscription["payment_status"];
    $payment_amount = $subscription["total_amount"];
    $amount_paid = $subscription["amount_paid"];
    $balance_due = $subscription["balance_due"];
    $subscription_status = $subscription["subscription_status"];
    $subscription_date = $subscription["created_at"];
}

$is_paid = strtolower($payment_status) == "paid";  
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Resident Dashboard - Waste Collection Management System</title>

    <!-- Bootstrap CSS -->
    <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">

    <!-- Bootstrap Icons -->
    <link rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <!-- Custom CSS -->
    <link href="../assets/css/style.css" rel="stylesheet">

    <style>

        body {
    background-color: #f4f7f6;
    font-family: Arial, sans-serif;
    color: #333;
    padding-top: 85px;
}

        /* Navigation Bar */
        .navbar {
    background-color: #198754 !important;
    padding: 12px 25px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);

    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    right: 0 !important;
    width: 100% !important;
    min-height: 65px;
    z-index: 9999;
}

        .navbar-brand {
            font-weight: bold;
            font-size: 20px;
            letter-spacing: 0.3px;
        }

        /* Main Container */
        .dashboard-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 35px 20px;
        }

        /* Dashboard Header */
        .dashboard-header {
            margin-bottom: 30px;
        }

        .dashboard-header h2 {
            font-weight: 700;
            color: #263b32;
            margin-bottom: 8px;
        }

        .dashboard-header p {
            color: #777;
            margin-bottom: 0;
        }

        .section-title {
            font-size: 18px;
            font-weight: 600;
            color: #45564d;
            margin-bottom: 18px;
        }

        /* Dashboard Cards */
        .dashboard-card {
            background: #fff;
            border: 1px solid #e8eeea;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.04);
            height: 100%;
            transition: all 0.25s ease;
        }

        .dashboard-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.08);
            border-color: #c9e5d5;
        }

        .dashboard-card .card-body {
            padding: 25px;
        }

        /* Service Cards */
        .service-card {
            display: flex;
            flex-direction: column;
        }

        .service-card .card-body {
            display: flex;
            flex-direction: column;
            flex: 1;
        }

        /* Dashboard Icons */
        .dashboard-icon {
            width: 52px;
            height: 52px;
            background-color: #e6f4ec;
            color: #198754;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 25px;
            margin-bottom: 18px;
        }

        /* Card Text */
        .dashboard-card h5 {
            font-weight: 700;
            font-size: 17px;
            color: #2d4036;
            margin-bottom: 10px;
        }

        .dashboard-card p {
            color: #7a8580;
            font-size: 13px;
            line-height: 1.6;
        }

        .service-card p {
            flex-grow: 1;
            margin-bottom: 20px;
        }

        /* Buttons */
        .dashboard-card .btn {
            border-radius: 6px;
            padding: 9px 12px;
            font-size: 13px;
            font-weight: 600;
        }

        .btn-success {
            background-color: #198754;
            border-color: #198754;
        }

        .btn-success:hover {
            background-color: #146c43;
            border-color: #146c43;
        }

        /* Account Information */
        .info-table th {
            width: 35%;
            color: #52635a;
            font-weight: 600;
            font-size: 14px;
        }

        .info-table td {
            color: #555;
            font-size: 14px;
        }

        /* Payment Status */
        .payment-status {
            font-size: 14px;
            font-weight: 700;
            padding: 7px 14px;
            border-radius: 20px;
            display: inline-block;
        }

        .status-paid {
            background: #e6f4ec;
            color: #198754;
        }

        .status-unpaid {
            background: #fce8e8;
            color: #dc3545;
        }

        .payment-amount {
            font-size: 28px;
            font-weight: 700;
            color: #263b32;
        }

        /* Footer */
        .dashboard-footer {
            margin-top: 35px;
            padding: 20px 0;
            border-top: 1px solid #e2e9e5;
            color: #929b96;
            font-size: 12px;
            text-align: center;
        }

        /* Mobile Responsive */
        @media (max-width: 576px) {

            .navbar-brand {
                font-size: 15px;
            }

            .dashboard-container {
                padding: 25px 15px;
            }

            .dashboard-header h2 {
                font-size: 23px;
            }

            .dashboard-card .card-body {
                padding: 20px;
            }

        }

    </style>

</head>

<body>

    <!-- Navigation Bar -->
    <!-- Navigation Bar -->
<nav class="navbar navbar-dark">

    <div class="container-fluid px-4">

        <span class="navbar-brand mb-0">
            <i class="bi bi-recycle me-2"></i>
            Waste Collection Management System
        </span>

        <a href="../logout.php" class="btn btn-outline-light btn-sm">
            <i class="bi bi-box-arrow-right me-1"></i>
            Logout
        </a>

    </div>

</nav>


    <!-- Main Dashboard -->
    <div class="dashboard-container">

        <!-- Dashboard Header -->
        <div class="dashboard-header">

            <h2>Resident Dashboard</h2>

            <p>
                Welcome back,
                <strong>
                    <?php echo htmlspecialchars($user["fullname"]); ?>
                </strong>!

                Here's an overview of your resident account and available services.
            </p>

        </div>
                <!-- My Account -->
        <h6 class="section-title mt-4">
            <i class="bi bi-person-circle me-2"></i>
            My Account
        </h6>

        <div class="row g-4 mb-4">


            <!-- Account Information -->
            <div class="col-lg-7">

                <div class="card dashboard-card">

                    <div class="card-body">

                        <div class="dashboard-icon">
                            <i class="bi bi-person-vcard"></i>
                        </div>

                        <h5>Account Information</h5>

                        <p>
                            Your registered resident account details.
                        </p>

                        <div class="table-responsive">

                            <table class="table table-bordered info-table mt-3">

                                <tr>
                                    <th>Resident ID</th>

                                    <td>
                                        <?php echo htmlspecialchars((string) $user["id"]); ?>
                                    </td>
                                </tr>

                                <tr>
                                    <th>Full Name</th>

                                    <td>
                                        <?php echo htmlspecialchars($user["fullname"]); ?>
                                    </td>
                                </tr>

                                <tr>
                                    <th>Username</th>

                                    <td>
                                        <?php echo htmlspecialchars($user["users"]); ?>
                                    </td>
                                </tr>

                                <tr>
                                    <th>Account Type</th>

                                    <td>Resident</td>
                                </tr>

                            </table>

                        </div>

                    </div>

                </div>

            </div>


            <!-- Payment Status -->
            <div class="col-lg-5">

                <div class="card dashboard-card">

                    <div class="card-body">

                        <div class="dashboard-icon">
                            <i class="bi bi-wallet2"></i>
                        </div>

                        <h5>My Subscription Billing</h5>

                        <p>Your current subscription and payment information.</p>

                        <hr>

                        <p class="mb-2">Payment Status</p>

                        <?php if (!$subscription) { ?>

                            <span class="payment-status status-unpaid">
                                NO SUBSCRIPTION
                            </span>

                        <?php } elseif ($is_paid) { ?>

                            <span class="payment-status status-paid">
                                <i class="bi bi-check-circle-fill me-1"></i>
                                PAID
                            </span>

                        <?php } else { ?>

                            <span class="payment-status status-unpaid">
                                <i class="bi bi-exclamation-circle-fill me-1"></i>
                                <?= htmlspecialchars(strtoupper($payment_status)); ?>
                            </span>

                        <?php } ?>

                        <p class="mt-4 mb-1">Subscription Status</p>

                        <strong>
                            <?= htmlspecialchars($subscription_status); ?>
                        </strong>

                        <hr>

                        <p class="mb-1">Subscription Amount</p>

                        <div class="payment-amount">
                            ₱<?= number_format((float)$payment_amount, 2); ?>
                        </div>

                        <p class="mt-3 mb-1">Amount Paid</p>

                        <strong>
                            ₱<?= number_format((float)$amount_paid, 2); ?>
                        </strong>

                        <p class="mt-3 mb-1">Remaining Balance</p>

                        <strong>
                            ₱<?= number_format((float)$balance_due, 2); ?>
                        </strong>

                        <p class="mt-3 mb-1">Subscription Date</p>

                        <strong>
                            <?php
                            echo $subscription_date
                                ? htmlspecialchars(date("F d, Y", strtotime($subscription_date)))
                                : "No subscription yet";
                            ?>
                        </strong>

                        <?php if (!$subscription) { ?>

                            <div class="alert alert-warning mt-4 mb-0 small">
                                You have not subscribed yet.
                                Please visit the Subscription page to register.
                            </div>

                        <?php } elseif (!$is_paid) { ?>

                            <div class="alert alert-warning mt-4 mb-0 small">
                                Your subscription payment is not yet fully recorded.
                                Please come to the physical office and pay your subscription
                                at the cashier.
                            </div>

                        <?php } else { ?>

                            <div class="alert alert-success mt-4 mb-0 small">
                                <i class="bi bi-check-circle me-1"></i>
                                Your subscription payment is recorded as paid.
                            </div>

                        <?php } ?>

                    </div>

                </div>

            </div>


        </div>


        <!-- Resident Services -->
        <h6 class="section-title">
            <i class="bi bi-grid me-2"></i>
            Resident Services
        </h6>

        <div class="row g-4 mb-4">


            <!-- Eco Points -->
            <div class="col-md-6 col-lg-4">

                <div class="card dashboard-card service-card">

                    <div class="card-body">

                        <div class="dashboard-icon">
                            <i class="bi bi-recycle"></i>
                        </div>

                        <h5>Eco Points</h5>

                        <p>
                            Check your earned eco points and monitor
                            your environmental contributions.
                        </p>

                        <a href="eco_points.php" class="btn btn-success w-100">
                            <i class="bi bi-stars me-1"></i>
                            View Eco Points
                        </a>

                    </div>

                </div>

            </div>


            <!-- Collection Schedule -->
            <div class="col-md-6 col-lg-4">

                <div class="card dashboard-card service-card">

                    <div class="card-body">

                        <div class="dashboard-icon">
                            <i class="bi bi-calendar-week"></i>
                        </div>

                        <h5>Collection Schedule</h5>

                        <p>
                            View your garbage collection schedule
                            and stay updated on upcoming collection dates.
                        </p>

                        <a href="collection_schedule.php" class="btn btn-success w-100">
                            <i class="bi bi-calendar-check me-1"></i>
                            Check Schedule
                        </a>

                    </div>

                </div>

            </div>


            <!-- Track Garbage Truck -->
            <div class="col-md-6 col-lg-4">

                <div class="card dashboard-card service-card">

                    <div class="card-body">

                        <div class="dashboard-icon">
                            <i class="bi bi-geo-alt"></i>
                        </div>

                        <h5>Track Garbage Truck</h5>

                        <p>
                            Monitor the garbage truck's location
                            and check its collection progress.
                        </p>

                        <a href="track_truck.php" class="btn btn-success w-100">
                            <i class="bi bi-pin-map me-1"></i>
                            Track Truck
                        </a>

                    </div>

                </div>

            </div>


            <!-- Subscription -->
            <div class="col-md-6 col-lg-4">

                <div class="card dashboard-card service-card">

                    <div class="card-body">

                        <div class="dashboard-icon">
                            <i class="bi bi-credit-card"></i>
                        </div>

                        <h5>Subscription</h5>

                        <p>
                            Manage your garbage collection subscription
                            and view available subscription services.
                        </p>

                        <a href="subscription.php" class="btn btn-success w-100">
                            <i class="bi bi-wallet2 me-1"></i>
                            Manage Subscription
                        </a>

                    </div>

                </div>

            </div>
            <!-- Register Household -->
<div class="col-md-6 col-lg-4">

    <div class="card dashboard-card service-card">

        <div class="card-body">

            <div class="dashboard-icon">
                <i class="bi bi-house-add"></i>
            </div>

            <h5>Register Household</h5>

            <p>
                Register your household address and mark your
                house location on the map for garbage collection.
            </p>

            <a href="register_household.php" class="btn btn-success w-100">
                <i class="bi bi-house-check me-1"></i>
                Register Household
            </a>

        </div>

    </div>

</div>


            <!-- Feedback -->
            <div class="col-md-6 col-lg-4">

                <div class="card dashboard-card service-card">

                    <div class="card-body">

                        <div class="dashboard-icon">
                            <i class="bi bi-chat-square-text"></i>
                        </div>

                        <h5>Send Feedback</h5>

                        <p>
                            Share your suggestions, comments, and
                            feedback to help improve garbage collection services.
                        </p>

                        <a href="feedback.php" class="btn btn-success w-100">
                            <i class="bi bi-send me-1"></i>
                            Send Feedback
                        </a>

                    </div>

                </div>

            </div>


            <!-- QR Code -->
            <div class="col-md-6 col-lg-4">

                <div class="card dashboard-card service-card">

                    <div class="card-body">

                        <div class="dashboard-icon">
                            <i class="bi bi-qr-code"></i>
                        </div>

                        <h5>Resident QR Code</h5>

                        <p>
                            View your unique resident QR code for
                            quick identification and verification.
                        </p>

                        <a href="qrcode.php" class="btn btn-success w-100">
                            <i class="bi bi-upc-scan me-1"></i>
                            View QR Code
                        </a>

                    </div>

                </div>

            </div>


        </div>





        <!-- Footer -->
        <div class="dashboard-footer">

            Waste Collection Management System group 2
            &copy; <?php echo date("Y"); ?>

            <br>

            Resident Dashboard

        </div>


    </div>


    <!-- Bootstrap JavaScript -->
    <script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

</body>
</html>
