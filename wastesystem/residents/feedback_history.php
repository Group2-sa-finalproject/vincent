
<?php
session_start();
require_once __DIR__ . '/../config/database.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection failed.");
}

$conn->set_charset("utf8mb4");

// Get logged-in resident ID
$user_id = (int)(
    $_SESSION['user_id']
    ?? $_SESSION['resident_id']
    ?? $_SESSION['household_id']
    ?? $_SESSION['id']
    ?? 0
);

if ($user_id <= 0) {
    header("Location: ../login.php");
    exit;
}

$error = "";
$feedbacks = [];

$sql = "SELECT id, feedback_type, message, created_at
        FROM feedback
        WHERE user_id = ?
        ORDER BY created_at DESC";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    $error = "Database error: " . $conn->error;

} else {

    $stmt->bind_param("i", $user_id);

    if ($stmt->execute()) {

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $feedbacks[] = $row;
        }

    } else {
        $error = "Unable to retrieve your feedback.";
    }

    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>My Feedbacks</title>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: #f4f7f6;
    color: #333;
}

.navbar {
    background: #198754;
    color: white;
    padding: 18px 25px;
}

.navbar h2 {
    margin: 0;
}

.container {
    max-width: 900px;
    margin: 40px auto;
    padding: 15px;
}

.card {
    background: white;
    padding: 30px;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,.08);
    margin-bottom: 20px;
}

h3 {
    color: #198754;
    margin-top: 0;
}

.feedback-item {
    border: 1px solid #ddd;
    border-left: 5px solid #198754;
    padding: 18px;
    border-radius: 6px;
    margin-bottom: 15px;
    background: #fff;
}

.feedback-type {
    font-weight: bold;
    color: #198754;
    margin-bottom: 10px;
}

.message {
    line-height: 1.6;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

.date {
    font-size: 13px;
    color: #777;
    margin-top: 12px;
}

.empty {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 5px;
    text-align: center;
    color: #666;
}

.error {
    background: #f8d7da;
    color: #721c24;
    padding: 12px;
    border-radius: 5px;
}

.btn {
    display: inline-block;
    background: #198754;
    color: white;
    padding: 12px 18px;
    border-radius: 5px;
    text-decoration: none;
    margin-top: 10px;
}

.btn:hover {
    background: #146c43;
}
</style>

</head>

<body>

<div class="navbar">
    <h2>Waste Collection Resident Portal</h2>
</div>

<div class="container">

<div class="card">

<h3>My Feedbacks</h3>

<p>
Here are the complaints, suggestions, and satisfaction
messages you have submitted.
</p>

<a href="feedback.php" class="btn">
    + Send New Feedback
</a>

</div>

<?php if ($error !== ''): ?>

    <div class="card">
        <p class="error">
            <?= htmlspecialchars($error) ?>
        </p>
    </div>

<?php elseif (count($feedbacks) > 0): ?>

    <?php foreach ($feedbacks as $feedback): ?>

        <div class="feedback-item">

            <div class="feedback-type">
                <?= htmlspecialchars($feedback['feedback_type']) ?>
            </div>

            <div class="message">
                <?= htmlspecialchars($feedback['message']) ?>
            </div>

            <div class="date">
                Submitted:
                <?= htmlspecialchars(date(
                    'F d, Y h:i A',
                    strtotime($feedback['created_at'])
                )) ?>
            </div>

        </div>

    <?php endforeach; ?>

<?php else: ?>

    <div class="card">
        <div class="empty">
            <h3>No Feedback Yet</h3>
            <p>You have not submitted any feedback yet.</p>
            <p>Click "Send New Feedback" to get started.</p>
        </div>
    </div>

<?php endif; ?>

</div>

</body>
</html>