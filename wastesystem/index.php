<?php
session_start();
include "config/database.php";

// Redirect users who are already logged in
if (isset($_SESSION["role"])) {
    if ($_SESSION["role"] == "admin") {
        header("Location: admin/dashboard.php");
        exit;
    } elseif ($_SESSION["role"] == "residents") {
        header("Location: residents/dashboard.php");
        exit;
    } elseif ($_SESSION["role"] == "collector") {
        header("Location: collector/dashboard.php");
        exit;
    }
}

$error = "";
$success = "";

// Show success message after registration
if (isset($_GET["registered"])) {
    $success = "Registration successful! You can now log in.";
}

// Login process
if (isset($_POST["login"])) {
    $username = mysqli_real_escape_string($conn, $_POST["username"]);
    $password = $_POST["password"];

    $sql = "SELECT * FROM users WHERE users='$username' LIMIT 1";
    $result = mysqli_query($conn, $sql);

    if ($result && mysqli_num_rows($result)== 1) {
        $user = mysqli_fetch_assoc($result);

        if (password_verify($password, $user["password"])) {
            $_SESSION["user_id"] = $user["id"];
            $_SESSION["full_name"] = $user["fullname"];
            $_SESSION["role"] = $user["role"];

            if ($user["role"] == "admin") {
                header("Location: admin/dashboard.php");
                exit;
            } elseif ($user["role"] == "residents") {
                header("Location: residents/dashboard.php");
                exit;
            } elseif ($user["role"] == "collector") {
                header("Location: collector/dashboard.php");
                exit;
            } else {
                $error = "Invalid user role.";
            }
        } else {
            $error = "Incorrect username or password.";
        }
    } else {
        $error = "Incorrect username or password.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Waste Collection Management System - Login</title>

    <link rel="stylesheet" href="assets/vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/style.css">

    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #198754, #0d6efd);
        }

        .login-container {
            width: 100%;
            max-width: 420px;
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
        }

        .register-link {
            text-align: right;
            margin-bottom: 15px;
        }

        .register-link a {
            color: #198754;
            font-weight: bold;
            text-decoration: none;
        }

        .register-link a:hover {
            text-decoration: underline;
        }

        .login-title {
            text-align: center;
            color: #198754;
            margin-bottom: 25px;
            font-weight: bold;
        }

        .form-label {
            font-weight: bold;
        }

        .btn-login {
            width: 100%;
            background-color: #198754;
            color: white;
            padding: 10px;
            border: none;
            border-radius: 6px;
            font-weight: bold;
        }

        .btn-login:hover {
            background-color: #146c43;
            color: white;
        }
    </style>
</head>

<body>

    <div class="login-container">

        <h2 class="login-title">Waste Collection Management System</h2>

        <h4 class="text-center mb-4">Login</h4>

        <?php if ($success != "") { ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php } ?>

        <?php if ($error != "") { ?>
            <div class="alert alert-danger">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php } ?>

        <form method="POST" action="">

            <div class="mb-3">
                <label class="form-label">Username</label>
                <input
                    type="text"
                    name="username"
                    class="form-control"
                    placeholder="Enter your username"
                    required
                >
            </div>

            <div class="mb-3">
                <label class="form-label">Password</label>
                <input
                    type="password"
                    name="password"
                    class="form-control"
                    placeholder="Enter your password"
                    required
                >
            </div>
                    <!-- Register link at the upper part -->
        <div class="register-link">
            <a href="register.php">Register Here</a>
        </div>



            <button type="submit" name="login" class="btn btn-login">
                Login
            </button>

        </form>

    </div>

    <script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

</body>
</html>
