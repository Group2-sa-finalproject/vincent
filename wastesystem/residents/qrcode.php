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

// Get the logged-in resident's ID
$user_id = (int) $_SESSION["user_id"];

// Get resident information
$sql = mysqli_prepare(
    $conn,
    "SELECT id, fullname, users
     FROM users
     WHERE id = ? AND role = 'residents'
     LIMIT 1"
);

if (!$sql) {
    die("Unable to retrieve resident information.");
}

mysqli_stmt_bind_param($sql, "i", $user_id);
mysqli_stmt_execute($sql);

$result = mysqli_stmt_get_result($sql);
$user = mysqli_fetch_assoc($result);

mysqli_stmt_close($sql);

// If the resident account does not exist, return to login
if (!$user) {
    session_unset();
    session_destroy();
    header("Location: ../index.php");
    exit;
}

// Create QR code data using the resident's unique ID
$qr_data = "RESIDENT - " . $user["id"];

// Generate the QR code image
$qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data="
        . urlencode($qr_data);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Resident QR Code</title>

    <link
        rel="stylesheet"
        href="../assets/vendor/bootstrap/css/bootstrap.min.css"
    >

    <style>
        body {
            background: #ffffff;
            font-family: Arial, sans-serif;
        }

        .navbar {
            background: #198754;
        }

        .qr-page {
            max-width: 500px;
            margin: 50px auto;
            padding: 20px;
        }

        .qr-card {
            background: #ffffff;
            border: 1px solid #e5e5e5;
            border-radius: 12px;
            padding: 35px 25px;
            text-align: center;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.08);
        }

        .qr-card h2 {
            color: #198754;
            margin-bottom: 15px;
        }

        .qr-image {
            width: 300px;
            max-width: 100%;
            height: auto;
            padding: 10px;
            border: 1px solid #dddddd;
            border-radius: 10px;
            margin: 15px auto;
            display: block;
        }

        .resident-code {
            font-size: 18px;
            font-weight: bold;
            margin-top: 15px;
            overflow-wrap: anywhere;
        }

        .resident-name {
            color: #555555;
            margin-bottom: 5px;
        }

        .action-buttons {
            margin-top: 25px;
        }

        @media print {
            body {
                background: #ffffff;
            }

            .navbar,
            .action-buttons {
                display: none !important;
            }

            .qr-page {
                margin: 0 auto;
                padding: 0;
            }

            .qr-card {
                border: none;
                box-shadow: none;
            }
        }
    </style>
</head>

<body>

    <!-- Navigation -->
    <nav class="navbar navbar-dark">
        <div class="container">
            <span class="navbar-brand">
                Waste Collection Management System
            </span>
        </div>
    </nav>

    <!-- QR Code Section -->
    <div class="qr-page">

        <div class="qr-card">

            <h2>My Resident QR Code</h2>

            <p>
                Show this QR code when your resident account needs
                to be identified.
            </p>

            <img
                src="<?php echo htmlspecialchars($qr_url); ?>"
                alt="Resident QR Code"
                class="qr-image"
            >

            <p class="resident-name">
                <?php echo htmlspecialchars($user["fullname"]); ?>
            </p>

            <p class="resident-code">
                <?php echo htmlspecialchars($qr_data); ?>
            </p>

            <div class="action-buttons">

                <button
                    type="button"
                    class="btn btn-success"
                    onclick="window.print()"
                >
                    Print QR Code
                </button>

                <a
                    href="dashboard.php"
                    class="btn btn-outline-success mt-2"
                >
                    Back to Dashboard
                </a>

            </div>

        </div>

    </div>

    <script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

</body>
</html>