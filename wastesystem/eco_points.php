
<?php
session_start();

require_once __DIR__ . '/../config/database.php';

// Check database connection
if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection error. Please check database.php.");
}

// Check resident login
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Get logged-in resident ID
$user_id = (int) $_SESSION['user_id'];

$sql = "SELECT
            (
                COALESCE(
                    (SELECT SUM(points)
                     FROM eco_points
                     WHERE user_id = ?), 0
                )
                -
                COALESCE(
                    (SELECT SUM(points_redeemed)
                     FROM subscription_point_redemptions
                     WHERE user_id = ?), 0
                )
            ) AS total";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("SQL Prepare Error: " . $conn->error);
}

$stmt->bind_param("ii", $user_id, $user_id);

if (!$stmt->execute()) {
    die("SQL Execute Error: " . $stmt->error);
}

$result = $stmt->get_result();

if (!$result) {
    die("SQL Result Error: " . $stmt->error);
}

$row = $result->fetch_assoc();

$balance = (int) ($row['total'] ?? 0);

$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Eco Points</title>

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: #f5f5f5;
    color: #333;
}

.header {
    background: #198754;
    color: white;
    padding: 18px 25px;
}

.header h2 {
    margin: 0;
    font-size: 22px;
}

.container {
    max-width: 950px;
    margin: 30px auto;
    padding: 0 15px;
}

.back-dashboard {
    display: inline-block;
    margin-bottom: 22px;
    padding: 9px 15px;
    background: #6c757d;
    color: white;
    text-decoration: none;
    border-radius: 4px;
    font-size: 14px;
}

.back-dashboard:hover {
    background: #565e64;
}

.page-title {
    margin-bottom: 20px;
}

.page-title h2 {
    margin-bottom: 5px;
}

.page-title p {
    color: #666;
    font-size: 14px;
}

.points-box {
    background: white;
    border: 1px solid #ddd;
    border-radius: 5px;
    padding: 25px;
    margin-bottom: 25px;
}

.points-box p {
    margin: 0;
    color: #666;
}

.points-box h1 {
    color: #198754;
    font-size: 35px;
    margin: 10px 0 18px;
}

.redeem-btn {
    display: inline-block;
    background: #198754;
    color: white;
    text-decoration: none;
    padding: 10px 20px;
    border-radius: 4px;
    font-size: 14px;
}

.redeem-btn:hover {
    background: #146c43;
}

.info-box {
    background: white;
    border: 1px solid #ddd;
    border-radius: 5px;
    padding: 22px;
    margin-bottom: 20px;
}

.info-box h3 {
    margin-top: 0;
    color: #198754;
    font-size: 19px;
}

.info-box p {
    color: #555;
    font-size: 14px;
    line-height: 1.6;
}

.info-box ul {
    padding-left: 22px;
    margin-bottom: 0;
}

.info-box li {
    margin-bottom: 12px;
    font-size: 14px;
    line-height: 1.5;
}

.info-box strong {
    color: #333;
}

@media(max-width: 600px) {

    .container {
        margin-top: 20px;
    }

    .points-box h1 {
        font-size: 30px;
    }

}

</style>

</head>

<body>

<div class="header">
    <h2>Eco Points</h2>
</div>

<div class="container">

    <a href="dashboard.php" class="back-dashboard">
        ← Back to Dashboard
    </a>

    <div class="page-title">

        <h2>My Eco Points</h2>

        <p>
            Earn points by participating in proper waste management.
        </p>

    </div>

    <div class="points-box">

        <p>My Total Available Points</p>

        <h1>
            <?= number_format($balance) ?> Points
        </h1>

        <a href="redeem.php" class="redeem-btn">
            Redeem Points
        </a>

    </div>

    <div class="info-box">

        <h3>How to Earn Eco Points</h3>

        <p>
            Residents can earn Eco Points by following proper
            waste management practices and participating in
            community environmental activities.
        </p>

        <ul>

            <li>
                <strong>Proper Waste Segregation:</strong>
                Separate biodegradable, recyclable, and residual waste.
            </li>

            <li>
                <strong>Recycling Activities:</strong>
                Bring recyclable materials to the designated collection area.
            </li>

            <li>
                <strong>Scheduled Waste Collection:</strong>
                Follow the community waste collection schedule.
            </li>

            <li>
                <strong>Community Clean-Up:</strong>
                Participate in approved community clean-up activities.
            </li>

            <li>
                <strong>Environmental Programs:</strong>
                Join official environmental programs organized by the community.
            </li>

        </ul>

    </div>

    <div class="info-box">

        <h3>How to Use Eco Points</h3>

        <ul>

            <li>Check your available points on this page.</li>

            <li>Click the Redeem Points button.</li>

            <li>Choose your preferred available reward.</li>

            <li>Make sure you have enough points for your selected reward.</li>

            <li>Click Redeem Now to use your points.</li>

            <li>Your redeemed points will be deducted from your balance.</li>

        </ul>

    </div>

    <div class="info-box">

        <h3>Eco Points Redemption Guide</h3>

        <ul>

            <li><strong>10 Points:</strong> 10% Reward</li>

            <li><strong>30 Points:</strong> 30% Reward</li>

            <li><strong>50 Points:</strong> 50% Reward</li>

        </ul>

    </div>

</div>

</body>
</html>