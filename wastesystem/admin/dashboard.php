<?php
session_start();
include "../config/database.php";

// Check if the user is logged in as admin
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "admin"
) {
    header("Location: ../index.php");
    exit;
}

$user_id = (int) $_SESSION["user_id"];

// Get admin information from users table
$stmt = $conn->prepare("
    SELECT id, fullname, users
    FROM users
    WHERE id = ? AND role = 'admin'
    LIMIT 1
");

if (!$stmt) {
    die("Database query failed: " . $conn->error);
}

$stmt->bind_param("i", $user_id);
$stmt->execute();

$result = $stmt->get_result();
$admin = $result->fetch_assoc();

$stmt->close();

if (!$admin) {
    session_unset();
    session_destroy();
    header("Location: ../index.php");
    exit;
}

$admin_name = $admin["fullname"];
?>

<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Waste Collection Management System - Admin Dashboard</title>

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

.navbar .container-fluid {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

        
        .dashboard-header {
            margin-bottom: 30px;
        }

        .dashboard-header h2 {
            font-weight: 700;
            color: #263b32;
            margin-bottom: 5px;
        }

        .dashboard-header p {
            color: #777;
            margin-bottom: 0;
        }

        .section-title {
            font-size: 17px;
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
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.09);
            border-color: #c9e5d5;
        }

        .dashboard-card .card-body {
            padding: 25px;
            display: flex;
            flex-direction: column;
        }

        /* Card Icons */
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
            margin-bottom: 20px;
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
            flex-grow: 1;
            margin-bottom: 20px;
        }

        /* Buttons */
        .dashboard-card .btn {
            border-radius: 6px;
            padding: 8px 12px;
            font-size: 12px;
            font-weight: 600;
            text-align: center;
            margin-top: 7px;
        }

        .dashboard-card .btn-success {
            background-color: #198754;
            border-color: #198754;
        }

        .dashboard-card .btn-success:hover {
            background-color: #146c43;
        }

        .dashboard-card .btn-outline-success:hover {
            background-color: #198754;
        }

        /* Footer */
        .dashboard-footer {
            margin-top: 35px;
            padding: 15px 0;
            border-top: 1px solid #e2e9e5;
            color: #929b96;
            font-size: 12px;
            text-align: center;
        }

        @media (max-width: 576px) {
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
    <nav class="navbar navbar-dark">
        <div class="container-fluid px-4">

            <span class="navbar-brand">
                <i class="bi bi-recycle me-2"></i>
                Waste Collection Admin
            </span>

            <a class="btn btn-outline-light btn-sm" href="../logout.php">
                <i class="bi bi-box-arrow-right me-1"></i>
                Logout
            </a>

        </div>
    </nav>

    <!-- Main Content -->
    <div class="container py-4">

        <!-- Dashboard Header -->
        <div class="dashboard-header">

            <h2>Admin Dashboard</h2>

            <p>
                Welcome back,
                <strong><?php echo htmlspecialchars($_SESSION["full_name"]); ?></strong>!
                Here's an overview of your system management.
            </p>

        </div>

        <h6 class="section-title">
            <i class="bi bi-grid me-2"></i>
            Management Modules
        </h6>

        <div class="row g-4">

          
            <div class="col-md-6 col-lg-4">
                <div class="card dashboard-card">
                    <div class="card-body">

                        <div class="dashboard-icon">
                            <i class="bi bi-clipboard-check"></i>
                        </div>

                        <h5>Feedback</h5>

                        <p>
                            Review resident feedback.
                        </p>

                        <a href="feedback.php" class="btn btn-success">
                            <i class="bi bi-chat-left-text me-1"></i>
                            Review Feedback
                        </a>

                    </div>
                </div>
            </div>
            
<!-- Manage Pickup Schedule -->
<div class="col-md-6 col-lg-4">
    <div class="card dashboard-card">
        <div class="card-body">

            <div class="dashboard-icon">
                <i class="bi bi-calendar-week"></i>
            </div>

            <h5>Pickup Schedule</h5>

            <p>
                Create, update, and manage garbage collection schedules
                for registered households.
            </p>

            <a href="pickup_schedule.php" class="btn btn-success">
                <i class="bi bi-calendar-check me-1"></i>
                Manage Pickup Schedule
            </a>

        </div>
    </div>
</div>


            <!-- Subscriptions and Payments -->
            <div class="col-md-6 col-lg-4">
                <div class="card dashboard-card">
                    <div class="card-body">

                        <div class="dashboard-icon">
                            <i class="bi bi-credit-card"></i>
                        </div>

                        <h5>Subscriptions and Payments</h5>

                        <p>
                            Manage resident subscriptions, payment records, and payment status.
                        </p>

                        <a href="subscriptions/index.php" class="btn btn-success">
                            <i class="bi bi-calendar-check me-1"></i>
                            Manage Subscriptions
                        </a>

                    </div>
                </div>
            </div>

            <!-- Residents and Households -->
            <div class="col-md-6 col-lg-4">
                <div class="card dashboard-card">
                    <div class="card-body">

                        <div class="dashboard-icon">
                            <i class="bi bi-house-door"></i>
                        </div>

                        <h5>Residents and Households</h5>

                        <p>
                            View, register, and manage resident information and household records.
                        </p>

                        <a href="households.php"
                            class="btn btn-success">
                            <i class="bi bi-houses me-1"></i>
                            Manage Households
                        </a>

                    </div>
                </div>
            </div>

            <!-- Truck Locations -->
            <div class="col-md-6 col-lg-4">
                <div class="card dashboard-card">
                    <div class="card-body">

                        <div class="dashboard-icon">
                            <i class="bi bi-geo-alt"></i>
                        </div>

                        <h5>Truck Locations</h5>

                        <p>
                            Monitor garbage truck locations and track collection routes in real time.
                        </p>

                        <a href="track_trucks.php" class="btn btn-success">
                            <i class="bi bi-pin-map me-1"></i>
                            Monitor Trucks
                        </a>
                        <a href="admin_routes.php" class="btn btn-success">
                            <i class="bi bi-pin-map me-1"></i>
                            Routes
                        </a>

                    </div>
                </div>
            </div>

            <!-- Manage Accounts -->
            <div class="col-md-6 col-lg-4">
                <div class="card dashboard-card">
                    <div class="card-body">

                        <div class="dashboard-icon">
                            <i class="bi bi-person-gear"></i>
                        </div>

                        <h5>Manage Accounts</h5>

                        <p>
                            Create, view, update, and manage administrator and garbage collector accounts.
                        </p>

                        <a href="accounts.php" class="btn btn-success">
                            <i class="bi bi-person-plus me-1"></i>
                            Manage Accounts
                        </a>

                    </div>
                </div>
            </div>

        </div>

        <!-- Footer -->
        <div class="dashboard-footer">
            Waste Collection Management System by Group 2&copy; <?php echo date("Y"); ?>
            <br>
            Admin Dashboard
        </div>

    </div>

    <!-- Bootstrap JavaScript -->
    <script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

</body>
</html>
