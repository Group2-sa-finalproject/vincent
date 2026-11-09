<?php
session_start();

require_once __DIR__ . '/../config/database.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection error. Please check config/database.php.");
}

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

if (($_SESSION['role'] ?? '') !== 'residents') {
    http_response_code(403);
    exit("Access denied. This page is for residents only.");
}

$user_id = (int) $_SESSION['user_id'];

const MONTHLY_SUBSCRIPTION_FEE = 150.00;
const MAX_SUBSCRIPTION_DISCOUNT = 150;

if (empty($_SESSION['eco_points_csrf'])) {
    $_SESSION['eco_points_csrf'] = bin2hex(random_bytes(32));
}

$message = '';
$message_type = 'success';
$available_points = 0;
$subscription = null;
$redeem_limit = 0;

function getAvailableEcoPoints(mysqli $conn, int $user_id): int
{
    $sql = "
        SELECT
            COALESCE((
                SELECT SUM(points)
                FROM eco_points
                WHERE user_id = ?
            ), 0)
            -
            COALESCE((
                SELECT SUM(points_redeemed)
                FROM subscription_point_redemptions
                WHERE user_id = ?
            ), 0) AS available_points
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException("Unable to calculate Eco Points: " . $conn->error);
    }

    $stmt->bind_param("ii", $user_id, $user_id);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException("Unable to calculate Eco Points: " . $error);
    }

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return max(0, (int)($row['available_points'] ?? 0));
}

function getResidentSubscription(mysqli $conn, int $user_id): ?array
{
    $stmt = $conn->prepare("
        SELECT id, service_fee, months, original_amount, points_redeemed,
               discount_amount, total_amount, amount_paid, balance_due,
               subscription_status, payment_status, created_at
        FROM subscriptions
        WHERE user_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new RuntimeException("Unable to retrieve subscription: " . $conn->error);
    }

    $stmt->bind_param("i", $user_id);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException("Unable to retrieve subscription: " . $error);
    }

    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $row;
}

try {
    $available_points = getAvailableEcoPoints($conn, $user_id);
    $subscription = getResidentSubscription($conn, $user_id);
    $redeem_limit = min($available_points, MAX_SUBSCRIPTION_DISCOUNT);
} catch (Throwable $e) {
    $message = $e->getMessage();
    $message_type = 'error';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_subscription'])) {
    try {
        if (!hash_equals($_SESSION['eco_points_csrf'], $_POST['csrf_token'] ?? '')) {
            throw new RuntimeException("Your session token is invalid. Please refresh the page and try again.");
        }

        if ($subscription !== null) {
            throw new RuntimeException("You already have a subscription record. Please view your subscription details instead.");
        }

        $points_to_redeem = filter_input(INPUT_POST, 'points_to_redeem', FILTER_VALIDATE_INT);
        if ($points_to_redeem === false || $points_to_redeem === null || $points_to_redeem < 0) {
            throw new RuntimeException("Enter a valid number of Eco Points.");
        }

        if ($points_to_redeem > MAX_SUBSCRIPTION_DISCOUNT) {
            throw new RuntimeException("You can use a maximum of " . MAX_SUBSCRIPTION_DISCOUNT . " Eco Points for one subscription.");
        }

        $conn->begin_transaction();

        // Lock the resident's account row to prevent simultaneous subscription requests.
        $lock = $conn->prepare("SELECT id FROM users WHERE id = ? FOR UPDATE");
        if (!$lock) {
            throw new RuntimeException("Unable to verify your account: " . $conn->error);
        }
        $lock->bind_param("i", $user_id);
        $lock->execute();
        $account = $lock->get_result()->fetch_assoc();
        $lock->close();

        if (!$account) {
            throw new RuntimeException("Resident account was not found.");
        }

        // Recheck subscription existence while inside the transaction.
        $existing = getResidentSubscription($conn, $user_id);
        if ($existing !== null) {
            throw new RuntimeException("You already have a subscription record.");
        }

        $current_points = getAvailableEcoPoints($conn, $user_id);
        if ($points_to_redeem > $current_points) {
            throw new RuntimeException("You do not have enough available Eco Points.");
        }

        // Rule: 1 Eco Point = ₱1 discount, up to the ₱150 one-month fee.
        $discount_amount = (float)$points_to_redeem;
        $original_amount = MONTHLY_SUBSCRIPTION_FEE;
        $total_amount = max(0, $original_amount - $discount_amount);
        $amount_paid = 0.00;
        $balance_due = $total_amount;

        // A fully point-covered subscription has no cash balance due.
        // It remains Pending Approval for administrator review.
        $payment_status = ($total_amount <= 0) ? 'Paid' : 'Unpaid';
        $subscription_status = 'Pending Approval';

        $insert = $conn->prepare("
            INSERT INTO subscriptions
                (user_id, service_fee, months, original_amount, points_redeemed,
                 discount_amount, total_amount, amount_paid, balance_due,
                 subscription_status, payment_status)
            VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        if (!$insert) {
            throw new RuntimeException("Unable to create subscription: " . $conn->error);
        }

        $insert->bind_param(
            "iddiddddss",
            $user_id,
            $original_amount,
            $original_amount,
            $points_to_redeem,
            $discount_amount,
            $total_amount,
            $amount_paid,
            $balance_due,
            $subscription_status,
            $payment_status
        );

        if (!$insert->execute()) {
            $error = $insert->error;
            $insert->close();
            throw new RuntimeException("Unable to create subscription: " . $error);
        }

        $subscription_id = (int)$conn->insert_id;
        $insert->close();

        if ($points_to_redeem > 0) {
            $redeem = $conn->prepare("
                INSERT INTO subscription_point_redemptions
                    (subscription_id, user_id, points_redeemed, discount_amount)
                VALUES (?, ?, ?, ?)
            ");

            if (!$redeem) {
                throw new RuntimeException("Unable to record Eco Points redemption: " . $conn->error);
            }

            $redeem->bind_param(
                "iiid",
                $subscription_id,
                $user_id,
                $points_to_redeem,
                $discount_amount
            );

            if (!$redeem->execute()) {
                $error = $redeem->error;
                $redeem->close();
                throw new RuntimeException("Unable to record Eco Points redemption: " . $error);
            }
            $redeem->close();
        }

        $conn->commit();

        $message = "Your one-month subscription request has been created. "
                 . number_format($points_to_redeem)
                 . " Eco Points were applied as a ₱"
                 . number_format($discount_amount, 2)
                 . " discount. Your request is Pending Approval.";
        $message_type = 'success';

        $available_points = getAvailableEcoPoints($conn, $user_id);
        $subscription = getResidentSubscription($conn, $user_id);
        $redeem_limit = min($available_points, MAX_SUBSCRIPTION_DISCOUNT);

    } catch (Throwable $e) {
        try {
            $conn->rollback();
        } catch (Throwable $ignored) {
            // Ignore rollback errors if a transaction was not started.
        }
        $message = $e->getMessage();
        $message_type = 'error';

        try {
            $available_points = getAvailableEcoPoints($conn, $user_id);
            $subscription = getResidentSubscription($conn, $user_id);
            $redeem_limit = min($available_points, MAX_SUBSCRIPTION_DISCOUNT);
        } catch (Throwable $refresh_error) {
            // Keep the original error message.
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Eco Points</title>
<style>
* { box-sizing: border-box; }
body { margin: 0; font-family: Arial, sans-serif; background: #f5f5f5; color: #333; }
.header { background: #198754; color: white; padding: 18px 25px; }
.header h2 { margin: 0; font-size: 22px; }
.container { max-width: 950px; margin: 30px auto; padding: 0 15px; }
.back-dashboard, .action-btn {
    display: inline-block; padding: 10px 16px; border-radius: 4px;
    text-decoration: none; border: 0; cursor: pointer; font-size: 14px;
}
.back-dashboard { margin-bottom: 22px; background: #6c757d; color: white; }
.action-btn { background: #198754; color: white; }
.action-btn:hover { background: #146c43; }
.page-title { margin-bottom: 20px; }
.page-title p { color: #666; font-size: 14px; }
.points-box, .info-box {
    background: white; border: 1px solid #ddd; border-radius: 5px;
    padding: 24px; margin-bottom: 22px;
}
.points-box h1 { color: #198754; font-size: 35px; margin: 10px 0; }
.points-box p, .info-box p { color: #666; }
.info-box h3 { margin-top: 0; color: #198754; font-size: 19px; }
.info-box li { margin-bottom: 10px; font-size: 14px; line-height: 1.5; }
.info-box ul { padding-left: 22px; }
.notice { padding: 13px 16px; border-radius: 4px; margin-bottom: 20px; line-height: 1.5; }
.notice.success { background: #d1e7dd; color: #0f5132; }
.notice.error { background: #f8d7da; color: #842029; }
.form-row { margin: 16px 0; }
.form-row label { display: block; font-weight: bold; margin-bottom: 7px; }
.form-row input {
    width: 100%; max-width: 280px; padding: 11px; border: 1px solid #bbb;
    border-radius: 4px; font-size: 16px;
}
.small { color: #666; font-size: 13px; line-height: 1.5; }
.summary { background: #f8f9fa; padding: 14px; border-radius: 4px; margin: 15px 0; }
.summary p { margin: 7px 0; }
.status { font-weight: bold; }
@media(max-width: 600px) {
    .container { margin-top: 20px; }
    .points-box h1 { font-size: 30px; }
}
</style>
</head>
<body>
<div class="header"><h2>Eco Points</h2></div>

<div class="container">
    <a href="dashboard.php" class="back-dashboard">← Back to Dashboard</a>

    <div class="page-title">
        <h2>My Eco Points</h2>
        <p>Earn points by participating in proper waste management.</p>
    </div>

    <?php if ($message !== ''): ?>
        <div class="notice <?= htmlspecialchars($message_type) ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <div class="points-box">
        <p>My Total Available Points</p>
        <h1><?= number_format($available_points) ?> Points</h1>
        <p class="small">Your available balance is your earned Eco Points minus points already redeemed.</p>
    </div>

    <div class="info-box">
        <h3>Redeem Eco Points for Your Subscription</h3>
        <p><strong>1 Eco Point = ₱1.00 discount.</strong> You may redeem up to 150 points for the one-month subscription fee of ₱150.</p>

        <?php if ($subscription): ?>
            <div class="summary">
                <p><strong>Subscription request already exists.</strong></p>
                <p>Subscription fee: ₱<?= number_format((float)$subscription['original_amount'], 2) ?></p>
                <p>Eco Points used: <?= number_format((int)$subscription['points_redeemed']) ?></p>
                <p>Discount: ₱<?= number_format((float)$subscription['discount_amount'], 2) ?></p>
                <p>Total amount: ₱<?= number_format((float)$subscription['total_amount'], 2) ?></p>
                <p>Balance due: ₱<?= number_format((float)$subscription['balance_due'], 2) ?></p>
                <p>Subscription status: <span class="status"><?= htmlspecialchars($subscription['subscription_status']) ?></span></p>
                <p>Payment status: <span class="status"><?= htmlspecialchars($subscription['payment_status']) ?></span></p>
                <a class="action-btn" href="subscription.php">View Subscription / Payment Details</a>
            </div>
        <?php else: ?>
            <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['eco_points_csrf']) ?>">
                    <div class="form-row">
                        <label for="points_to_redeem">Eco Points to Redeem</label>
                        <input
                            type="number"
                            id="points_to_redeem"
                            name="points_to_redeem"
                            min="0"
                            max="<?= (int)$redeem_limit ?>"
                            step="1"
                            value="0"
                            required
                        >
                        <p class="small">You can redeem up to <?= number_format($redeem_limit) ?> points based on your available balance and the ₱150 maximum discount.</p>
                    </div>

                    <div class="summary" aria-live="polite">
                        <p>Subscription fee: <strong>₱150.00</strong></p>
                        <p>Eco Points discount: <strong id="discount_preview">₱0.00</strong></p>
                        <p>Remaining amount to pay: <strong id="total_preview">₱150.00</strong></p>
                    </div>

                <button type="submit" name="create_subscription" class="action-btn">
                    Apply Points and Create Subscription
                </button>
            </form>
        <?php endif; ?>
    </div>

    <div class="info-box">
        <h3>How to Earn Eco Points</h3>
        <p>Residents can earn Eco Points by following proper waste management practices and participating in community environmental activities.</p>
        <ul>
            <li><strong>Proper Waste Segregation:</strong> Separate biodegradable, recyclable, and residual waste.</li>
            <li><strong>Recycling Activities:</strong> Bring recyclable materials to the designated collection area.</li>
            <li><strong>Scheduled Waste Collection:</strong> Follow the community waste collection schedule.</li>
            <li><strong>Community Clean-Up:</strong> Participate in approved community clean-up activities.</li>
            <li><strong>Environmental Programs:</strong> Join official environmental programs organized by the community.</li>
        </ul>
    </div>

    <div class="info-box">
        <h3>Important Redemption Rules</h3>
        <ul>
            <li>1 Eco Point is equal to ₱1.00 discount.</li>
            <li>The monthly subscription fee is ₱150.00 for one month.</li>
            <li>You can use a maximum of 150 Eco Points for one subscription.</li>
            <li>Points used for a subscription are deducted from your available balance.</li>
            <li>After creating a subscription request, check the Subscription page for payment instructions and status.</li>
            <li>Cash Simulation payments still require administrator confirmation.</li>
        </ul>
    </div>
</div>

<script>
const pointsInput = document.getElementById('points_to_redeem');
if (pointsInput) {
    const discountPreview = document.getElementById('discount_preview');
    const totalPreview = document.getElementById('total_preview');

    function updatePreview() {
        let points = parseInt(pointsInput.value, 10);
        if (!Number.isFinite(points) || points < 0) points = 0;
        points = Math.min(points, parseInt(pointsInput.max, 10) || 0, 150);

        discountPreview.textContent = '₱' + points.toFixed(2);
        totalPreview.textContent = '₱' + (150 - points).toFixed(2);
    }

    pointsInput.addEventListener('input', updatePreview);
    updatePreview();
}
</script>
</body>
</html>
