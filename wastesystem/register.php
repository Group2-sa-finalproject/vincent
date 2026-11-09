
<?php
session_start();
include "config/database.php";

$message = "";

if (isset($_POST["register"])) {

    $fullname = trim($_POST["fullname"] ?? "");
    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"] ?? "";
    $confirm_password = $_POST["confirm_password"] ?? "";

    if (
        $fullname === "" ||
        $username === "" ||
        $password === "" ||
        $confirm_password === ""
    ) {
        $message = "Please complete all required fields.";

    } elseif (strlen($password) < 8) {
        $message = "Password must be at least 8 characters.";

    } elseif ($password !== $confirm_password) {
        $message = "Passwords do not match.";

    } else {

        // Check if username already exists
        $check = mysqli_prepare(
            $conn,
            "SELECT id FROM users WHERE users = ? LIMIT 1"
        );

        if (!$check) {
            $message = "Database error: " . mysqli_error($conn);

        } else {

            mysqli_stmt_bind_param($check, "s", $username);
            mysqli_stmt_execute($check);
            mysqli_stmt_store_result($check);

            if (mysqli_stmt_num_rows($check) > 0) {

                $message = "Username already exists. Please choose another.";

            } else {

                // Hash the password
                $hashed_password = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                // Register resident account
                $sql = mysqli_prepare(
                    $conn,
                    "INSERT INTO users (fullname, users, password, role)
                     VALUES (?, ?, ?, ?)"
                );

                if (!$sql) {
                    $message = "Database error: " . mysqli_error($conn);

                } else {

                    $role = "residents";

                    mysqli_stmt_bind_param(
                        $sql,
                        "ssss",
                        $fullname,
                        $username,
                        $hashed_password,
                        $role
                    );

                    if (mysqli_stmt_execute($sql)) {
                        mysqli_stmt_close($sql);
                        mysqli_stmt_close($check);

                        header("Location: index.php?registered=1");
                        exit;

                    } else {
                        $message = "Registration failed: " . mysqli_stmt_error($sql);
                    }

                    mysqli_stmt_close($sql);
                }
            }

            mysqli_stmt_close($check);
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Register - Waste Collection Management System</title>

    <link rel="stylesheet" href="assets/vendor/bootstrap/css/bootstrap.min.css">
</head>

<body>

<div class="register-container">

    <h2>Create an Account</h2>

    <?php if ($message != "") { ?>
        <div class="alert alert-danger">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php } ?>

    <form method="POST" action="">

        <div class="mb-3">
            <label class="form-label">Full Name</label>
            <input
                type="text"
                name="fullname"
                class="form-control"
                placeholder="Enter your full name"
                required
            >
        </div>

        <div class="mb-3">
            <label class="form-label">Username</label>
            <input
                type="text"
                name="username"
                class="form-control"
                placeholder="Create a username"
                required
            >
        </div>

        <div class="mb-3">
            <label class="form-label">Password</label>
            <input
                type="password"
                name="password"
                class="form-control"
                placeholder="Create a password"
                minlength="8"
                required
            >
        </div>

        <div class="mb-3">
            <label class="form-label">Confirm Password</label>
            <input
                type="password"
                name="confirm_password"
                class="form-control"
                placeholder="Confirm your password"
                minlength="8"
                required
            >
        </div>

        <button type="submit" name="register" class="btn-register">
            Register
        </button>

    </form>

    <div class="login-link">
        Already have an account?
        <a href="index.php">Login Here</a>
    </div>

</div>

<script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

</body>
</html>
