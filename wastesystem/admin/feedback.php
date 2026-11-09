<?php
session_start();
require_once "../config/database.php";

// Check admin access
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "admin"
) {
    header("Location: ../index.php");
    exit;
}

$conn->set_charset("utf8mb4");

// Get all feedback
$sql = "SELECT id, user_id, feedback_type, message, created_at
        FROM feedback
        ORDER BY created_at DESC";

$result = $conn->query($sql);

if (!$result) {
    die("Database error: " . $conn->error);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Admin - Resident Feedback</title>

<link href="../assets/vendor/bootstrap/css/bootstrap.min.css"
      rel="stylesheet">

<link rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<link href="../assets/css/style.css" rel="stylesheet">

<style>
body {
    background: #f4f7f6;
    font-family: Arial, sans-serif;
    padding-top: 85px;
}

.navbar {
    background: #198754;
    padding: 12px 25px;
    position: fixed;
    top: 0;
    width: 100%;
    z-index: 9999;
}

.container {
    max-width: 1100px;
}

.card {
    border: none;
    border-radius: 12px;
    box-shadow: 0 3px 12px rgba(0,0,0,.05);
}

.table th {
    background: #198754;
    color: white;
}

.table td {
    vertical-align: middle;
}

.page-title {
    color: #263b32;
    font-weight: bold;
}

.feedback-message {
    min-width: 200px;
    max-width: 400px;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

.type-badge {
    display: inline-block;
    padding: 6px 10px;
    border-radius: 5px;
    font-size: 12px;
    font-weight: bold;
}

.complaint {
    background: #f8d7da;
    color: #842029;
}

.suggestion {
    background: #cff4fc;
    color: #055160;
}

.satisfaction {
    background: #d1e7dd;
    color: #0f5132;
}

</style>
</head>

<body>

<nav class="navbar navbar-dark">
    <div class="container-fluid">

        <span class="navbar-brand">
            <i class="bi bi-recycle me-2"></i>
            Waste Collection Admin
        </span>

        <a href="dashboard.php" class="btn btn-outline-light btn-sm">
            <i class="bi bi-arrow-left me-1"></i>
            Dashboard
        </a>

    </div>
</nav>

<div class="container py-4">

    <h2 class="page-title mb-2">
        <i class="bi bi-chat-left-text me-2"></i>
        Resident Feedback
    </h2>

    <p class="text-muted mb-4">
        View all complaints, suggestions, and satisfaction
        messages submitted by residents.
    </p>

    <div class="card">
        <div class="card-body">

            <div class="table-responsive">

                <table class="table table-bordered table-hover">

                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Resident ID</th>
                            <th>Feedback Type</th>
                            <th>Message</th>
                            <th>Date Submitted</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if ($result->num_rows > 0): ?>

                        <?php while ($row = $result->fetch_assoc()): ?>

                            <?php
                            $type = $row['feedback_type'];

                            $class = strtolower($type);
                            ?>

                            <tr>

                                <td>
                                    <?= htmlspecialchars($row['id']) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($row['user_id']) ?>
                                </td>

                                <td>
                                    <span class="type-badge <?= htmlspecialchars($class) ?>">
                                        <?= htmlspecialchars($type) ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="feedback-message">
                                        <?= htmlspecialchars($row['message']) ?>
                                    </div>
                                </td>

                                <td>
                                    <?= htmlspecialchars(date(
                                        'M d, Y h:i A',
                                        strtotime($row['created_at'])
                                    )) ?>
                                </td>

                            </tr>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="5" class="text-center text-muted p-4">
                                <i class="bi bi-inbox fs-2"></i>
                                <br>
                                No feedback submitted yet.
                            </td>
                        </tr>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>
    </div>

</div>

<script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

</body>
</html>