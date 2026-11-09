
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

// Account categories
$roles = [
    "admin" => "Administrators",
    "collector" => "Garbage Collectors",
    "residents" => "Residents"
];

// Prepare account arrays
$accounts = [
    "admin" => [],
    "collector" => [],
    "residents" => []
];

// Get all accounts from users table
$sql = "SELECT id, fullname, users, role
        FROM users
        ORDER BY fullname ASC";

$result = mysqli_query($conn, $sql);

if (!$result) {
    die("Database query failed: " . mysqli_error($conn));
}

// Separate accounts according to their roles
while ($row = mysqli_fetch_assoc($result)) {
    $role = $row["role"];

    if (isset($accounts[$role])) {
        $accounts[$role][] = $row;
    }
}

// Total number of accounts
$total_accounts = 0;

foreach ($accounts as $account_list) {
    $total_accounts += count($account_list);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Manage Accounts - Waste Collection Management System</title>

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
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            width: 100%;
            min-height: 65px;
            z-index: 9999;
        }

        .navbar-brand {
            font-weight: bold;
            font-size: 20px;
        }

        /* Main Container */
        .main-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 35px 20px;
        }

        /* Page Header */
        .page-header {
            margin-bottom: 30px;
        }

        .page-header h2 {
            font-weight: 700;
            color: #263b32;
            margin-bottom: 8px;
        }

        .page-header p {
            color: #777;
            margin-bottom: 0;
        }

        /* Summary Card */
        .summary-card {
            background: white;
            border: 1px solid #e8eeea;
            border-radius: 12px;
            padding: 22px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.04);
            margin-bottom: 30px;
        }

        .summary-icon {
            width: 50px;
            height: 50px;
            background-color: #e6f4ec;
            color: #198754;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 12px;
        }

        .summary-card h3 {
            font-weight: bold;
            color: #198754;
            margin-bottom: 5px;
        }

        .summary-card p {
            color: #777;
            margin-bottom: 0;
        }

        /* Account Sections */
        .account-section {
            background: white;
            border: 1px solid #e8eeea;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.04);
        }

        .section-title {
            font-size: 19px;
            font-weight: 700;
            color: #263b32;
            margin-bottom: 20px;
        }

        .section-title i {
            color: #198754;
            margin-right: 8px;
        }

        .account-count {
            background-color: #e6f4ec;
            color: #198754;
            font-size: 13px;
            padding: 5px 12px;
            border-radius: 20px;
            font-weight: 600;
            margin-left: 8px;
        }

        /* Table */
        .table {
            vertical-align: middle;
            margin-bottom: 0;
        }

        .table thead th {
            background-color: #f4f7f6;
            color: #45564d;
            font-weight: 600;
            border-bottom: 2px solid #e2e9e5;
            padding: 13px;
            white-space: nowrap;
        }

        .table tbody td {
            padding: 13px;
        }

        .table tbody tr:hover {
            background-color: #f8fbf9;
        }

        .role-badge {
            background-color: #e6f4ec;
            color: #198754;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .empty-message {
            text-align: center;
            color: #888;
            padding: 25px;
        }

        /* Footer */
        .page-footer {
            margin-top: 35px;
            padding: 15px 0;
            border-top: 1px solid #e2e9e5;
            color: #929b96;
            font-size: 12px;
            text-align: center;
        }

        @media (max-width: 576px) {
            .account-section {
                padding: 15px;
            }

            .page-header h2 {
                font-size: 23px;
            }
        }
    </style>

</head>

<body>

    <!-- Navigation Bar -->
    <nav class="navbar navbar-dark">

        <div class="container-fluid px-4">

            <a class="navbar-brand" href="dashboard.php">
                <i class="bi bi-recycle me-2"></i>
                Waste Collection Admin
            </a>

            <a href="../logout.php" class="btn btn-outline-light btn-sm">
                <i class="bi bi-box-arrow-right me-1"></i>
                Logout
            </a>

        </div>

    </nav>


    <div class="main-container">

        <!-- Page Header -->
        <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">

            <div>
                <h2>Manage Accounts</h2>

                <p>
                    View and monitor all registered accounts in the system.
                </p>
            </div>

            <a href="add_account.php" class="btn btn-success px-4">
                <i class="bi bi-person-plus me-2"></i>
                Add Account
            </a>

        </div>


        <!-- Account Tables -->

        <?php foreach ($roles as $role => $title) { ?>

            <div class="account-section">

                <h5 class="section-title">

                    <?php if ($role === "admin") { ?>
                        <i class="bi bi-person-gear"></i>
                    <?php } elseif ($role === "collector") { ?>
                        <i class="bi bi-truck"></i>
                    <?php } else { ?>
                        <i class="bi bi-people"></i>
                    <?php } ?>

                    <?php echo htmlspecialchars($title); ?>

                    <span class="account-count">
                        <?php echo count($accounts[$role]); ?>
                    </span>

                </h5>


                <div class="table-responsive">

                    <table class="table table-bordered table-hover">

                        <thead>

                            <tr>
                                <th>Account ID</th>
                                <th>Full Name</th>
                                <th>Username</th>
                                <th>Account Type</th>
                            </tr>

                        </thead>

                        <tbody>

                            <?php if (count($accounts[$role]) > 0) { ?>

                                <?php foreach ($accounts[$role] as $account) { ?>

                                    <tr>

                                        <td>
                                            <?php echo htmlspecialchars((string) $account["id"]); ?>
                                        </td>

                                        <td>
                                            <?php echo htmlspecialchars($account["fullname"]); ?>
                                        </td>

                                        <td>
                                            <?php echo htmlspecialchars($account["users"]); ?>
                                        </td>

                                        <td>
                                            <span class="role-badge">
                                                <?php echo htmlspecialchars(ucfirst($account["role"])); ?>
                                            </span>
                                        </td>

                                    </tr>

                                <?php } ?>

                            <?php } else { ?>

                                <tr>
                                    <td colspan="4" class="empty-message">
                                        No <?php echo htmlspecialchars(strtolower($title)); ?> accounts found.
                                    </td>
                                </tr>

                            <?php } ?>

                        </tbody>

                    </table>

                </div>

            </div>

        <?php } ?>


        <!-- Back Button -->
        <div class="text-end mb-4">

            <a href="dashboard.php" class="btn btn-success px-4">
                <i class="bi bi-arrow-left me-1"></i>
                Back to Dashboard
            </a>

        </div>


        <!-- Footer -->
        <div class="page-footer">

            Waste Collection Management System by Group 2
            &copy; <?php echo date("Y"); ?>

            <br>

            Admin - Manage Accounts

        </div>

    </div>


    <!-- Bootstrap JavaScript -->
    <script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

</body>
</html>
