<?php
session_start();
include "../config/database.php"; 

$user_id = $_SESSION["user_id"] ?? 1;
$message = "";
          
if (isset($_POST["submit_request"])) {
    $pickup_reason    = trim($_POST["pickup_reason"]);
    $requested_date   = $_POST["requested_date"];
    $requested_time   = $_POST["requested_time"];
    $location_address = trim($_POST["location_address"]);

    if (!empty($pickup_reason) && !empty($requested_date) && !empty($requested_time) && !empty($location_address)) {
        $stmt = $conn->prepare("INSERT INTO special_request_pickup (user_id, pickup_reason, requested_date, requested_time, location_address, status) VALUES (?, ?, ?, ?, ?, 'pending')");
        $stmt->bind_param("issss", $user_id, $pickup_reason, $requested_date, $requested_time, $location_address);
        
        if ($stmt->execute()) {
            $message = "<div class='alert alert-success'>Special pickup request submitted successfully! Status is pending.</div>";
        } else {
            $message = "<div class='alert alert-danger'>Error submitting request. Please try again.</div>";
        }
        $stmt->close();
    } else {
        $message = "<div class='alert alert-warning'>Please fill out all required fields.</div>";
    }
}

$requests_result = null;
if ($stmt_fetch = $conn->prepare("SELECT * FROM special_request_pickup WHERE user_id = ? ORDER BY id DESC")) {
    $stmt_fetch->bind_param("i", $user_id);
    $stmt_fetch->execute();
    $requests_result = $stmt_fetch->get_result();
}
?>

<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Waste Collection Management System - Request Special Pickup</title>

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: Arial, sans-serif;
        }

        body {
            background-color: #f4f7f6;
            color: #333;
        }

        .navbar {
            background-color: #2e7d32;
            color: white;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .navbar-brand {
            font-size: 1.2rem;
            font-weight: bold;
        }

        .logout-btn {
            background: transparent;
            border: 1px solid white;
            color: white;
            padding: 6px 12px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 0.9rem;
        }

        .logout-btn:hover {
            background-color: white;
            color: #2e7d32;
        }

        .container {
            max-width: 1200px;
            margin: 30px auto;
            padding: 0 15px;
        }

        .header-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .back-link {
            color: #2e7d32;
            text-decoration: none;
            font-weight: bold;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        .alert {
            padding: 12px 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 0.95rem;
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .alert-warning {
            background-color: #fff3cd;
            color: #856404;
            border: 1px solid #ffeeba;
        }

        .row {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }

        .col {
            flex: 1;
            min-width: 300px;
        }

        .card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            padding: 25px;
            margin-bottom: 20px;
        }

        .card h5 {
            margin-bottom: 20px;
            font-size: 1.2rem;
            color: #2c3e50;
            border-bottom: 2px solid #eee;
            padding-bottom: 10px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-label {
            display: block;
            margin-bottom: 6px;
            font-weight: bold;
            font-size: 0.9rem;
            color: #444;
        }

        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 0.95rem;
        }

        .form-control:focus {
            border-color: #2e7d32;
            outline: none;
        }

        textarea.form-control {
            resize: vertical;
        }

        .form-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 20px;
        }

        .btn {
            padding: 10px 18px;
            border-radius: 4px;
            font-size: 0.95rem;
            cursor: pointer;
            text-decoration: none;
            border: none;
        }

        .btn-success {
            background-color: #2e7d32;
            color: white;
        }

        .btn-success:hover {
            background-color: #27692b;
        }

        .btn-secondary {
            background-color: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background-color: #5a6268;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .custom-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 0.9rem;
        }

        .custom-table th, .custom-table td {
            padding: 12px;
            border-bottom: 1px solid #ddd;
        }

        .custom-table th {
            background-color: #e8f5e9;
            color: #2e7d32;
        }

        .badge {
            padding: 5px 10px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: bold;
            text-transform: uppercase;
        }

        .badge-pending {
            background-color: #fff3cd;
            color: #856404;
        }

        .badge-approved {
            background-color: #d4edda;
            color: #155724;
        }

        .badge-rejected {
            background-color: #f8d7da;
            color: #721c24;
        }

        .text-center {
            text-align: center;
            color: #777;
        }

        .text-muted {
            font-size: 0.85rem;
            color: #6c757d;
        }
    </style>
</head>

<body>


    <div class="navbar">
        <span class="navbar-brand">Waste Collection Resident Portal</span>
        <a class="logout-btn" href="../logout.php">Logout</a>
    </div>

    
    <div class="container">

        <div class="header-section">
            <div>
                <h2>Request Special Pickup</h2>
                <p class="text-muted">Fill out the form below to request a special garbage pickup schedule.</p>
            </div>
            <a href="dashboard.php" class="back-link">&larr; Back to Dashboard</a>
        </div>

        <?= $message; ?>

        <div class="row">
          
            <div class="col" style="flex: 0.8;">
                <div class="card">
                    <h5>New Pickup Request</h5>
                    
                    <form action="" method="POST">
                        <div class="form-group">
                            <label class="form-label">Pickup Reason</label>
                            <textarea name="pickup_reason" class="form-control" rows="3" placeholder="e.g., Bulky waste, yard debris, old appliances..." required></textarea>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Requested Date</label>
                            <input type="date" name="requested_date" class="form-control" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Requested Time</label>
                            <input type="time" name="requested_time" class="form-control" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Location / Address</label>
                            <input type="text" name="location_address" class="form-control" placeholder="House No., Street, Barangay" required>
                        </div>

                        <div class="form-actions">
                            <a href="dashboard.php" class="btn btn-secondary">Cancel</a>
                            <button type="submit" name="submit_request" class="btn btn-success">Submit Request</button>
                        </div>
                    </form>
                </div>
            </div>

           
            <div class="col" style="flex: 1.2;">
                <div class="card">
                    <h5>My Request History & Admin Decisions</h5>
                    
                    <div class="table-responsive">
                        <table class="custom-table">
                            <thead>
                                <tr>
                                    <th>Date & Time</th>
                                    <th>Reason</th>
                                    <th>Location</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($requests_result && $requests_result->num_rows > 0): ?>
                                    <?php while ($row = $requests_result->fetch_assoc()): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($row['requested_date']); ?></strong><br>
                                                <span class="text-muted"><?php echo htmlspecialchars($row['requested_time']); ?></span>
                                            </td>
                                            <td><?php echo htmlspecialchars($row['pickup_reason']); ?></td>
                                            <td><?php echo htmlspecialchars($row['location_address']); ?></td>
                                            <td>
                                                <?php 
                                                    $status = strtolower($row['status']);
                                                    $badge_class = 'badge-pending';
                                                    if ($status == 'approved' || $status == 'scheduled') {
                                                        $badge_class = 'badge-approved';
                                                    } elseif ($status == 'rejected') {
                                                        $badge_class = 'badge-rejected';
                                                    }
                                                ?>
                                                <span class="badge <?php echo $badge_class; ?>">
                                                    <?php echo strtoupper($status); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" class="text-center" style="padding: 30px;">No special pickup requests found.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div>

</body>
</html>