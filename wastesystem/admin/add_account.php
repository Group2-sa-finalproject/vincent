
<?php
session_start();
include "../config/database.php";

// Check admin access
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "admin"
) {
    header("Location: ../index.php");
    exit;
}

$success = "";
$error = "";

// Generate CSRF token
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

// Process registration
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (
        !isset($_POST["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
    ) {
        $error = "Invalid request. Please try again.";

    } else {

        $fullname = trim($_POST["fullname"] ?? "");
        $username = trim($_POST["username"] ?? "");
        $password = $_POST["password"] ?? "";
        $confirm_password = $_POST["confirm_password"] ?? "";
        $role = $_POST["role"] ?? "";

        // Validate fields
        if (
            $fullname === "" ||
            $username === "" ||
            $password === "" ||
            $confirm_password === "" ||
            $role === ""
        ) {
            $error = "Please fill in all fields.";

        } elseif (!in_array($role, ["admin", "collector", "residents"], true)) {
            $error = "Invalid account type.";

        } elseif ($password !== $confirm_password) {
            $error = "Passwords do not match.";

        } else {

            // Check if username already exists
            $check = $conn->prepare(
                "SELECT id FROM users WHERE users = ? LIMIT 1"
            );

            if (!$check) {
                $error = "Database error: " . $conn->error;

            } else {

                $check->bind_param("s", $username);
                $check->execute();
                $result = $check->get_result();

                if ($result->num_rows > 0) {
                    $error = "Username already exists.";

                } else {

                    // Hash password
                    $hashed_password = password_hash(
                        $password,
                        PASSWORD_DEFAULT
                    );

                    // Insert new account
                    $stmt = $conn->prepare(
                        "INSERT INTO users (fullname, users, password, role)
                         VALUES (?, ?, ?, ?)"
                    );

                    if (!$stmt) {
                        $error = "Database error: " . $conn->error;

                    } else {

                        $stmt->bind_param(
                            "ssss",
                            $fullname,
                            $username,
                            $hashed_password,
                            $role
                        );

                        if ($stmt->execute()) {

                            $success = "Account successfully registered!";

                            $fullname = "";
                            $username = "";

                            // Generate a new CSRF token
                            $_SESSION["csrf_token"] = bin2hex(random_bytes(32));

                        } else {
                            $error = "Registration failed: " . $stmt->error;
                        }

                        $stmt->close();
                    }
                }

                $check->close();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Add Account</title>
</head>

<body>

    <h2>Register New Account</h2>

    <p>
        <a href="accounts.php">Back to Manage Accounts</a>
    </p>

    <?php if ($success !== "") { ?>
        <p style="color: green;">
            <?php echo htmlspecialchars($success); ?>
        </p>
    <?php } ?>

    <?php if ($error !== "") { ?>
        <p style="color: red;">
            <?php echo htmlspecialchars($error); ?>
        </p>
    <?php } ?>

    <form method="POST" action="add_account.php">

        <input
            type="hidden"
            name="csrf_token"
            value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>"
        >

        <label>Full Name:</label><br>
        <input
            type="text"
            name="fullname"
            value="<?php echo htmlspecialchars($fullname ?? ""); ?>"
            required
        >

        <br><br>

        <label>Username:</label><br>
        <input
            type="text"
            name="username"
            value="<?php echo htmlspecialchars($username ?? ""); ?>"
            required
        >

        <br><br>

        <label>Account Type:</label><br>

        <select name="role" required>

            <option value="">Select Account Type</option>

            <option value="admin"
                <?php echo (($_POST["role"] ?? "") === "admin") ? "selected" : ""; ?>>
                Administrator
            </option>

            <option value="collector"
                <?php echo (($_POST["role"] ?? "") === "collector") ? "selected" : ""; ?>>
                Garbage Collector
            </option>

            <option value="residents"
                <?php echo (($_POST["role"] ?? "") === "residents") ? "selected" : ""; ?>>
                Resident
            </option>

        </select>

        <br><br>

        <label>Password:</label><br>
        <input
            type="password"
            name="password" 
            required
        >

        <br><br>

        <label>Confirm Password:</label><br>
        <input
            type="password"
            name="confirm_password"
            required
        >

        <br><br>

        <button type="submit" name="register">
            Register Account
        </button>

    </form>

</body>
</html>
