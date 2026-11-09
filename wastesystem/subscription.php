<?php
session_start();
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'residents') {
    header('Location: ../login.php');
    exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection is unavailable.');
}

$user_id = (int) $_SESSION['user_id'];
$monthly_fee = 150.00;
$errors = [];
$success = '';

if (empty($_SESSION['subscription_csrf'])) {
    $_SESSION['subscription_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['subscription_csrf'];

function peso($amount): string {
    return '₱' . number_format((float)$amount, 2);
}

/*
 * Existing Eco Points are earned in eco_points.points.
 * Redemptions are stored separately and subtracted from that earned total.
 */
function getAvailablePoints(mysqli $conn, int $user_id): int {
    $sql = "
        SELECT
            COALESCE((SELECT SUM(points) FROM eco_points WHERE user_id = ?), 0)
            -
            COALESCE((SELECT SUM(points_redeemed)
                      FROM subscription_point_redemptions WHERE user_id = ?), 0)
            AS available_points
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to check Eco Points balance.');
    }
    $stmt->bind_param('ii', $user_id, $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return max(0, (int)($row['available_points'] ?? 0));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string)($_POST['csrf_token'] ?? '');
    $postAction = (string)($_POST['action'] ?? '');

    if (!hash_equals($csrf, $postedToken)) {
        $errors[] = 'Your session token is invalid. Refresh the page and try again.';
    } elseif ($postAction === 'submit_cash_payment') {
        // The resident only submits a simulated cash-payment notice.
        // The administrator alone can mark it Paid and activate the subscription.
        try {
            $conn->begin_transaction();
            $paymentLock = $conn->prepare(
                'SELECT id, total_amount, balance_due, payment_status
                 FROM subscriptions WHERE user_id = ? FOR UPDATE'
            );
            if (!$paymentLock) {
                throw new RuntimeException('Unable to check your subscription payment.');
            }
            $paymentLock->bind_param('i', $user_id);
            $paymentLock->execute();
            $currentSubscription = $paymentLock->get_result()->fetch_assoc();
            $paymentLock->close();

            if (!$currentSubscription) {
                throw new RuntimeException('Please submit your subscription request first.');
            }
            if ((float)$currentSubscription['balance_due'] <= 0) {
                throw new RuntimeException('There is no cash balance to submit. Your Eco Points already cover the subscription fee.');
            }
            if (strcasecmp((string)$currentSubscription['payment_status'], 'Paid') === 0) {
                throw new RuntimeException('Your payment has already been confirmed.');
            }
            if (strcasecmp((string)$currentSubscription['payment_status'], 'Pending Confirmation') === 0) {
                throw new RuntimeException('Your simulated cash payment is already waiting for administrator confirmation.');
            }

            $paymentUpdate = $conn->prepare(
                "UPDATE subscriptions SET payment_status = 'Pending Confirmation'
                 WHERE id = ? AND user_id = ?"
            );
            if (!$paymentUpdate) {
                throw new RuntimeException('Unable to submit your payment confirmation request.');
            }
            $subscriptionId = (int)$currentSubscription['id'];
            $paymentUpdate->bind_param('ii', $subscriptionId, $user_id);
            $paymentUpdate->execute();
            $paymentUpdate->close();
            $conn->commit();

            $success = 'Your simulated cash payment was submitted. Please wait for the administrator to confirm it.';
            $_SESSION['subscription_csrf'] = bin2hex(random_bytes(32));
            $csrf = $_SESSION['subscription_csrf'];
        } catch (Throwable $e) {
            $conn->rollback();
            $errors[] = $e instanceof RuntimeException
                ? $e->getMessage()
                : 'Unable to submit the simulated cash payment. Please try again.';
        }
    } elseif ($postAction === 'create_subscription') {
        $points_to_redeem = filter_input(INPUT_POST, 'points_to_redeem', FILTER_VALIDATE_INT);
        if ($points_to_redeem === false || $points_to_redeem === null || $points_to_redeem < 0) {
            $errors[] = 'Enter a valid whole-number Eco Points amount.';
        } else {
            try {
                $conn->begin_transaction();

                // Lock the resident row so simultaneous submissions cannot spend the same points.
                $lock = $conn->prepare("SELECT id FROM users WHERE id = ? AND role = 'residents' FOR UPDATE");
                $lock->bind_param('i', $user_id);
                $lock->execute();
                $resident = $lock->get_result()->fetch_assoc();
                $lock->close();

                if (!$resident) {
                    throw new RuntimeException('Resident account was not found.');
                }

                $existing = $conn->prepare('SELECT id FROM subscriptions WHERE user_id = ? LIMIT 1');
                $existing->bind_param('i', $user_id);
                $existing->execute();
                $existingSubscription = $existing->get_result()->fetch_assoc();
                $existing->close();

                if ($existingSubscription) {
                    throw new RuntimeException('You already have a subscription record. Please contact the administrator if you need assistance.');
                }

                $available = getAvailablePoints($conn, $user_id);
                if ($points_to_redeem > $available || $points_to_redeem > (int)$monthly_fee) {
                    throw new RuntimeException('The requested Eco Points amount is not available or exceeds the ₱150 subscription fee.');
                }

                // 1 point = ₱1. Discount cannot exceed the one-month fee.
                $points_used = min($points_to_redeem, (int)$monthly_fee);
                $discount = (float)$points_used;
                $total_due = max(0, $monthly_fee - $discount);
                $initial_payment_status = ($total_due <= 0) ? 'Paid' : 'Unpaid';

                $insert = $conn->prepare("
                    INSERT INTO subscriptions
                        (user_id, service_fee, months, original_amount, points_redeemed,
                         discount_amount, total_amount, amount_paid, balance_due,
                         subscription_status, payment_status)
                    VALUES (?, ?, 1, ?, ?, ?, ?, 0.00, ?, 'Pending Approval', ?)
                ");
                if (!$insert) {
                    throw new RuntimeException('Unable to prepare the subscription record.');
                }
                $insert->bind_param(
                    'iddiddds',
                    $user_id,
                    $monthly_fee,
                    $monthly_fee,
                    $points_used,
                    $discount,
                    $total_due,
                    $total_due,
                    $initial_payment_status
                );
                $insert->execute();
                $subscription_id = $conn->insert_id;
                $insert->close();

                if ($points_used > 0) {
                    $redeem = $conn->prepare("
                        INSERT INTO subscription_point_redemptions
                            (subscription_id, user_id, points_redeemed, discount_amount)
                        VALUES (?, ?, ?, ?)
                    ");
                    if (!$redeem) {
                        throw new RuntimeException('Unable to save Eco Points redemption history.');
                    }
                    $redeem->bind_param('iiid', $subscription_id, $user_id, $points_used, $discount);
                    $redeem->execute();
                    $redeem->close();
                }

                $conn->commit();
                $success = 'Your subscription request has been submitted. It is awaiting administrator review.';
                $_SESSION['subscription_csrf'] = bin2hex(random_bytes(32));
                $csrf = $_SESSION['subscription_csrf'];
            } catch (Throwable $e) {
                $conn->rollback();
                $errors[] = $e instanceof RuntimeException
                    ? $e->getMessage()
                    : 'The subscription could not be saved. Please verify the existing database tables and try again.';
            }
        }
    } else {
        $errors[] = 'Invalid subscription action.';
    }
}

$available_points = 0;
try {
    $available_points = getAvailablePoints($conn, $user_id);
} catch (Throwable $e) {
    $errors[] = 'Unable to load your Eco Points balance. Please check the database setup.';
}

$stmt = $conn->prepare("
    SELECT id, service_fee, months, original_amount, points_redeemed,
           discount_amount, total_amount, amount_paid, balance_due,
           subscription_status, payment_status, created_at
    FROM subscriptions
    WHERE user_id = ?
    LIMIT 1
");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$subscription = $stmt->get_result()->fetch_assoc();
$stmt->close();

$resident_name = 'Resident';
$nameStmt = $conn->prepare("SELECT fullname FROM users WHERE id = ? AND role = 'residents' LIMIT 1");
if ($nameStmt) {
    $nameStmt->bind_param('i', $user_id);
    $nameStmt->execute();
    $nameRow = $nameStmt->get_result()->fetch_assoc();
    if (!empty($nameRow['fullname'])) {
        $resident_name = $nameRow['fullname'];
    }
    $nameStmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Subscription</title>
<style>
    * { box-sizing: border-box; }
    body { margin: 0; font-family: Arial, sans-serif; background: #f4f7f4; color: #26332b; }
    .container { width: min(920px, 94%); margin: 35px auto; }
    .panel { background: #fff; border-radius: 12px; padding: 25px; margin-bottom: 20px; box-shadow: 0 3px 14px rgba(0,0,0,.06); }
    h1, h2 { margin-top: 0; color: #176b3a; }
    .muted { color: #65736a; }
    .price { font-size: 30px; font-weight: 700; color: #176b3a; margin: 8px 0; }
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; }
    .stat { background: #f2f8f3; border-radius: 9px; padding: 15px; }
    .stat strong { display: block; font-size: 20px; margin-top: 5px; }
    label { display: block; font-weight: 600; margin: 14px 0 7px; }
    input { width: 100%; max-width: 320px; padding: 11px; border: 1px solid #cbd5ce; border-radius: 7px; font-size: 16px; }
    button, .link { display: inline-block; border: 0; border-radius: 7px; background: #198044; color: #fff; padding: 12px 18px; font-size: 15px; text-decoration: none; cursor: pointer; margin-top: 16px; }
    .alert { padding: 13px 15px; border-radius: 8px; margin-bottom: 14px; }
    .error { background: #fff0f0; color: #9b2020; }
    .success { background: #eaf7ed; color: #176b3a; }
    table { width: 100%; border-collapse: collapse; }
    td { padding: 10px 5px; border-bottom: 1px solid #edf0ed; }
    td:last-child { text-align: right; font-weight: 600; }
    @media(max-width: 600px) { .panel { padding: 18px; } }
</style>
</head>
<body>
<div class="container">
    <div class="panel">
        <h1>My Subscription</h1>
        <p class="muted">Welcome, <?= htmlspecialchars($resident_name, ENT_QUOTES, 'UTF-8') ?>.</p>
        <div class="price"><?= peso($monthly_fee) ?> <span class="muted" style="font-size:15px;font-weight:400">/ month</span></div>
        <p class="muted">Subscription duration is one month. Each resident may have one subscription record.</p>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="alert error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endforeach; ?>
    <?php if ($success): ?>
        <div class="alert success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if (!$subscription): ?>
    <div class="panel">
        <h2>Subscribe</h2>
        <div class="grid">
            <div class="stat"><span class="muted">Available Eco Points</span><strong><?= number_format($available_points) ?></strong></div>
            <div class="stat"><span class="muted">Eco Points conversion</span><strong>1 point = ₱1</strong></div>
            <div class="stat"><span class="muted">Maximum discount</span><strong><?= peso($monthly_fee) ?></strong></div>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <label for="points_to_redeem">Eco Points to redeem (enter 0 if you do not want a discount)</label>
            <input type="number" id="points_to_redeem" name="points_to_redeem"
                   min="0" max="<?= min($available_points, (int)$monthly_fee) ?>"
                   value="0" step="1" required>
            <p class="muted">You can redeem up to <?= number_format(min($available_points, (int)$monthly_fee)) ?> points for this subscription. The discount cannot exceed ₱150.</p>
            <button type="submit" name="action" value="create_subscription">Submit Subscription</button>
        </form>
    </div>
    <?php else: ?>
    <div class="panel">
        <h2>Subscription and Billing Details</h2>
        <div class="grid">
            <div class="stat"><span class="muted">Subscription ID</span><strong>#<?= (int)$subscription['id'] ?></strong></div>
            <div class="stat"><span class="muted">Subscription status</span><strong><?= htmlspecialchars($subscription['subscription_status']) ?></strong></div>
            <div class="stat"><span class="muted">Payment status</span><strong><?= htmlspecialchars($subscription['payment_status']) ?></strong></div>
        </div>
        <table style="margin-top:18px">
            <tr><td>Subscription date</td><td><?= htmlspecialchars($subscription['created_at']) ?></td></tr>
            <tr><td>Duration</td><td>1 month</td></tr>
            <tr><td>Monthly fee</td><td><?= peso($subscription['original_amount']) ?></td></tr>
            <tr><td>Eco Points redeemed</td><td><?= number_format((int)$subscription['points_redeemed']) ?></td></tr>
            <tr><td>Eco Points discount</td><td><?= peso($subscription['discount_amount']) ?></td></tr>
            <tr><td>Total subscription amount</td><td><?= peso($subscription['total_amount']) ?></td></tr>
            <tr><td>Amount paid</td><td><?= peso($subscription['amount_paid']) ?></td></tr>
            <tr><td><strong>Remaining balance</strong></td><td><strong><?= peso($subscription['balance_due']) ?></strong></td></tr>
        </table>
        <p class="muted">Your subscription is not activated automatically. The administrator must review your subscription and confirm the simulated cash payment.</p>

        <?php if (strcasecmp($subscription['payment_status'], 'Unpaid') === 0 && (float)$subscription['balance_due'] > 0): ?>
            <div class="alert" style="background:#fff8e6;color:#795600">
                <strong>Cash Payment Simulation</strong><br>
                This is a school-project simulation. No actual money will be collected or transferred.
                Click the button below to submit a simulated cash-payment notice for administrator confirmation.
            </div>
            <form method="POST" onsubmit="return confirm('Submit your simulated cash-payment notice for administrator confirmation?');">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit" name="action" value="submit_cash_payment">I Have Paid (Cash Simulation)</button>
            </form>
        <?php elseif (strcasecmp($subscription['payment_status'], 'Pending Confirmation') === 0): ?>
            <div class="alert" style="background:#fff8e6;color:#795600">
                Your simulated cash-payment notice is awaiting administrator confirmation. Your subscription is not active yet.
            </div>
        <?php elseif (strcasecmp($subscription['payment_status'], 'Paid') === 0): ?>
            <div class="alert success">
                Your subscription balance is fully covered. The administrator still needs to approve and activate your subscription.
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <p><a class="link" href="dashboard.php">Back to Dashboard</a></p>
</div>
</body>
</html>
