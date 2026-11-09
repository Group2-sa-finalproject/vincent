
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

mysqli_set_charset($conn, 'utf8mb4');

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

function escapeHtml($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/*
|--------------------------------------------------------------------------
| HANDLE FORM SUBMISSIONS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postedToken = $_POST['csrf_token'] ?? '';

    if (!is_string($postedToken) || !hash_equals($csrf, $postedToken)) {
        $errors[] = 'Your session token is invalid. Refresh the page and try again.';
    } else {

        $action = $_POST['action'] ?? '';

        /*
        |--------------------------------------------------------------------------
        | APPLY FOR SUBSCRIPTION WITH OPTIONAL ECO POINTS
        |--------------------------------------------------------------------------
        */

        if ($action === 'create_subscription') {

            try {
                $conn->begin_transaction();

                /*
                * Lock the resident account.
                */
                $userLock = $conn->prepare("
                    SELECT id
                    FROM users
                    WHERE id = ?
                    LIMIT 1
                    FOR UPDATE
                ");

                if (!$userLock) {
                    throw new RuntimeException('Unable to verify your account.');
                }

                $userLock->bind_param('i', $user_id);
                $userLock->execute();

                $userExists = $userLock->get_result()->fetch_assoc();
                $userLock->close();

                if (!$userExists) {
                    throw new RuntimeException('Resident account was not found.');
                }

                /*
                * Check whether a subscription already exists.
                */
                $existingStmt = $conn->prepare("
                    SELECT id
                    FROM subscriptions
                    WHERE user_id = ?
                    LIMIT 1
                    FOR UPDATE
                ");

                if (!$existingStmt) {
                    throw new RuntimeException('Unable to check your subscription.');
                }

                $existingStmt->bind_param('i', $user_id);
                $existingStmt->execute();

                $existingSubscription = $existingStmt->get_result()->fetch_assoc();
                $existingStmt->close();

                if ($existingSubscription) {
                    throw new RuntimeException(
                        'You already have a subscription record. Please check your Subscription and Billing Details.'
                    );
                }

                /*
                * Retrieve previously reserved Eco Points.
                *
                * These records were created in eco_points.php.
                * We must NOT create another redemption record.
                */
                $reservedStmt = $conn->prepare("
                    SELECT
                        id,
                        points_redeemed,
                        discount_amount
                    FROM subscription_point_redemptions
                    WHERE user_id = ?
                    AND subscription_id IS NULL
                    ORDER BY id ASC
                    FOR UPDATE
                ");

                if (!$reservedStmt) {
                    throw new RuntimeException(
                        'Unable to retrieve your reserved Eco Points.'
                    );
                }

                $reservedStmt->bind_param('i', $user_id);
                $reservedStmt->execute();

                $reservedResult = $reservedStmt->get_result();

                $reserved_ids = [];
                $points_used = 0;
                $discount = 0.00;

                while ($row = $reservedResult->fetch_assoc()) {

                    $row_points = (int)$row['points_redeemed'];
                    $row_discount = (float)$row['discount_amount'];

                    if ($row_points <= 0 || $row_discount < 0) {
                        throw new RuntimeException(
                            'Your Eco Points redemption records contain invalid values. Please contact the administrator.'
                        );
                    }

                    $reserved_ids[] = (int)$row['id'];

                    $points_used += $row_points;
                    $discount += $row_discount;
                }

                $reservedStmt->close();

                /*
                * Validate the reserved points and discount.
                *
                * Conversion must be:
                * 1 Eco Point = ₱1.00
                *
                * Maximum discount = ₱150.
                */
                if ($points_used > 150 || $discount > 150) {
                    throw new RuntimeException(
                        'Your reserved Eco Points exceed the maximum ₱150 subscription discount. Please contact the administrator.'
                    );
                }

                if (abs($discount - $points_used) > 0.001) {
                    throw new RuntimeException(
                        'Your Eco Points and discount records do not match. Please contact the administrator.'
                    );
                }

                /*
                * Calculate subscription billing.
                *
                * No reserved points?
                * The resident can still apply normally for ₱150.
                */
                $original_amount = $monthly_fee;
                $total_due = max(0, $original_amount - $discount);

                $amount_paid = 0.00;
                $balance_due = $total_due;

                $months = 1;
                $service_fee = $monthly_fee;

                /*
                * Create the subscription.
                */
                $insert = $conn->prepare("
                    INSERT INTO subscriptions (
                        user_id,
                        service_fee,
                        months,
                        original_amount,
                        points_redeemed,
                        discount_amount,
                        total_amount,
                        amount_paid,
                        balance_due,
                        subscription_status,
                        payment_status,
                        created_at
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?, ?, ?,
                        'Pending Approval',
                        'Unpaid',
                        NOW()
                    )
                ");

                if (!$insert) {
                    throw new RuntimeException(
                        'Unable to create your subscription application.'
                    );
                }

                $insert->bind_param(
                    'idididddd',
                    $user_id,
                    $service_fee,
                    $months,
                    $original_amount,
                    $points_used,
                    $discount,
                    $total_due,
                    $amount_paid,
                    $balance_due
                );

                if (!$insert->execute()) {
                    throw new RuntimeException(
                        'Unable to save your subscription application.'
                    );
                }

                $subscription_id = (int)$conn->insert_id;
                $insert->close();

                /*
                * Attach existing reserved redemption records
                * to the newly created subscription.
                *
                * Do NOT INSERT another redemption record.
                */
                if (!empty($reserved_ids)) {

                    $attachStmt = $conn->prepare("
                        UPDATE subscription_point_redemptions
                        SET subscription_id = ?
                        WHERE user_id = ?
                        AND subscription_id IS NULL
                    ");

                    if (!$attachStmt) {
                        throw new RuntimeException(
                            'Unable to attach your reserved Eco Points.'
                        );
                    }

                    $attachStmt->bind_param(
                        'ii',
                        $subscription_id,
                        $user_id
                    );

                    if (!$attachStmt->execute()) {
                        throw new RuntimeException(
                            'Unable to attach your reserved Eco Points.'
                        );
                    }

                    $attached_count = $attachStmt->affected_rows;
                    $attachStmt->close();

                    if ($attached_count !== count($reserved_ids)) {
                        throw new RuntimeException(
                            'Not all reserved Eco Points could be attached. Please try again.'
                        );
                    }
                }

                /*
                * Commit the subscription and redemption attachment.
                */
                $conn->commit();

                $success = 'Your subscription application has been submitted successfully. Your previously reserved Eco Points have been automatically applied. You can review your billing details below.';

                $_SESSION['subscription_csrf'] = bin2hex(random_bytes(32));
                $csrf = $_SESSION['subscription_csrf'];

            } catch (Throwable $e) {

                $conn->rollback();

                $errors[] = $e instanceof RuntimeException
                    ? $e->getMessage()
                    : 'Unable to process your subscription application. Please try again.';
            }


        } elseif ($action === 'submit_cash_notice') {

            try {
                $conn->begin_transaction();

                $lock = $conn->prepare("
                    SELECT id, total_amount, balance_due,
                           subscription_status, payment_status
                    FROM subscriptions
                    WHERE user_id = ?
                    LIMIT 1
                    FOR UPDATE
                ");

                if (!$lock) {
                    throw new RuntimeException('Unable to check your subscription.');
                }

                $lock->bind_param('i', $user_id);
                $lock->execute();

                $current = $lock->get_result()->fetch_assoc();
                $lock->close();

                if (!$current) {
                    throw new RuntimeException('No subscription record was found.');
                }

                if ($current['subscription_status'] !== 'Pending Approval') {
                    throw new RuntimeException('Your subscription is not awaiting payment notice.');
                }

                if ($current['payment_status'] !== 'Unpaid') {
                    throw new RuntimeException('Your payment notice has already been submitted or processed.');
                }

                if (
                    (float)$current['total_amount'] <= 0 ||
                    (float)$current['balance_due'] <= 0
                ) {
                    throw new RuntimeException('There is no remaining amount to submit for cash payment.');
                }

                $update = $conn->prepare("
                    UPDATE subscriptions
                    SET payment_status = 'Pending Confirmation'
                    WHERE id = ?
                      AND payment_status = 'Unpaid'
                      AND subscription_status = 'Pending Approval'
                ");

                if (!$update) {
                    throw new RuntimeException('Unable to submit your payment notice.');
                }

                $subscription_id = (int)$current['id'];

                $update->bind_param('i', $subscription_id);
                $update->execute();

                $changed = $update->affected_rows;
                $update->close();

                if ($changed !== 1) {
                    throw new RuntimeException(
                        'Your payment notice could not be submitted. Please refresh and try again.'
                    );
                }

                $conn->commit();

                $success = 'Your Cash Simulation payment notice has been submitted successfully. You may now print your Statement of Account and present it to the cashier.';

                $_SESSION['subscription_csrf'] = bin2hex(random_bytes(32));
                $csrf = $_SESSION['subscription_csrf'];

            } catch (Throwable $e) {

                $conn->rollback();

                $errors[] = $e instanceof RuntimeException
                    ? $e->getMessage()
                    : 'Unable to submit your payment notice. Please try again.';
            }

        } else {
            $errors[] = 'Invalid request.';
        }
    }
}

/*
|--------------------------------------------------------------------------
| RETRIEVE RESIDENT INFORMATION
|--------------------------------------------------------------------------
*/

$resident_name = 'Resident';

$nameStmt = $conn->prepare("
    SELECT fullname
    FROM users
    WHERE id = ?
    LIMIT 1
");

if ($nameStmt) {
    $nameStmt->bind_param('i', $user_id);
    $nameStmt->execute();

    $nameRow = $nameStmt->get_result()->fetch_assoc();

    if (!empty($nameRow['fullname'])) {
        $resident_name = $nameRow['fullname'];
    }

    $nameStmt->close();
}

/*
|--------------------------------------------------------------------------
| RETRIEVE AVAILABLE ECO POINTS
|--------------------------------------------------------------------------
*/


/*
|--------------------------------------------------------------------------
| RETRIEVE ECO POINTS INFORMATION
|--------------------------------------------------------------------------
*/

$available_points = 0;
$reserved_points = 0;
$reserved_discount = 0.00;

/*
 * Available points = earned points - all reserved/redeemed points.
 */
$availableStmt = $conn->prepare("
    SELECT
        COALESCE((
            SELECT SUM(points)
            FROM eco_points
            WHERE user_id = ?
        ), 0) AS earned_points,

        COALESCE((
            SELECT SUM(points_redeemed)
            FROM subscription_point_redemptions
            WHERE user_id = ?
        ), 0) AS redeemed_points
");

if ($availableStmt) {

    $availableStmt->bind_param('ii', $user_id, $user_id);
    $availableStmt->execute();

    $availableRow = $availableStmt->get_result()->fetch_assoc();

    $available_points = max(
        0,
        (int)$availableRow['earned_points']
        - (int)$availableRow['redeemed_points']
    );

    $availableStmt->close();
}

/*
 * Retrieve points reserved for the next subscription.
 */
$reservedStmt = $conn->prepare("
    SELECT
        COALESCE(SUM(points_redeemed), 0) AS reserved_points,
        COALESCE(SUM(discount_amount), 0) AS reserved_discount
    FROM subscription_point_redemptions
    WHERE user_id = ?
      AND subscription_id IS NULL
");

if ($reservedStmt) {

    $reservedStmt->bind_param('i', $user_id);
    $reservedStmt->execute();

    $reservedRow = $reservedStmt->get_result()->fetch_assoc();

    $reserved_points = (int)$reservedRow['reserved_points'];
    $reserved_discount = (float)$reservedRow['reserved_discount'];

    $reservedStmt->close();
}


/*
|--------------------------------------------------------------------------
| RETRIEVE SUBSCRIPTION AND PAYMENT INFORMATION
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        id,
        service_fee,
        months,
        original_amount,
        points_redeemed,
        discount_amount,
        total_amount,
        amount_paid,
        balance_due,
        subscription_status,
        payment_status,
        created_at
    FROM subscriptions
    WHERE user_id = ?
    LIMIT 1
");

if (!$stmt) {
    die('Unable to load your subscription record.');
}

$stmt->bind_param('i', $user_id);
$stmt->execute();

$subscription = $stmt->get_result()->fetch_assoc();

$stmt->close();

$can_print_soa = $subscription && in_array(
    $subscription['payment_status'],
    ['Pending Confirmation', 'Paid'],
    true
);

$is_pending_payment = $subscription &&
    $subscription['payment_status'] === 'Pending Confirmation';

$is_paid = $subscription &&
    $subscription['payment_status'] === 'Paid';

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>My Subscription</title>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: #f4f7f4;
    color: #26332b;
}

.container {
    width: min(920px, 94%);
    margin: 35px auto;
}

.panel {
    background: #fff;
    border-radius: 12px;
    padding: 25px;
    margin-bottom: 20px;
    box-shadow: 0 3px 14px rgba(0,0,0,.06);
}

h1, h2 {
    margin-top: 0;
    color: #176b3a;
}

.muted {
    color: #65736a;
}

.price {
    font-size: 30px;
    font-weight: 700;
    color: #176b3a;
    margin: 8px 0;
}

.grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 14px;
}

.stat {
    background: #f2f8f3;
    border-radius: 9px;
    padding: 15px;
}

.stat strong {
    display: block;
    font-size: 20px;
    margin-top: 5px;
}

button, .link {
    display: inline-block;
    border: 0;
    border-radius: 7px;
    background: #198044;
    color: #fff;
    padding: 12px 18px;
    font-size: 15px;
    text-decoration: none;
    cursor: pointer;
    margin-top: 16px;
}

button:disabled {
    opacity: .6;
    cursor: not-allowed;
}

.alert {
    padding: 13px 15px;
    border-radius: 8px;
    margin-bottom: 14px;
}

.error {
    background: #fff0f0;
    color: #9b2020;
}

.success {
    background: #eaf7ed;
    color: #176b3a;
}

.notice {
    background: #fff7df;
    color: #76520b;
    padding: 14px;
    border-radius: 8px;
    margin-top: 16px;
}

table {
    width: 100%;
    border-collapse: collapse;
}

td {
    padding: 10px 5px;
    border-bottom: 1px solid #edf0ed;
}

td:last-child {
    text-align: right;
    font-weight: 600;
}

input[type="number"] {
    width: 100%;
    max-width: 250px;
    padding: 12px;
    border: 1px solid #ccd8ce;
    border-radius: 7px;
    font-size: 16px;
    margin-top: 8px;
}

.print-button {
    background: #176b3a;
}

.secondary-link {
    color: #176b3a;
    text-decoration: underline;
}

@media(max-width: 600px) {
    .panel {
        padding: 18px;
    }
}

/* PRINT ONLY THE SOA */
@media print {

    @page {
        size: A4;
        margin: 15mm;
    }

    body {
        background: #fff !important;
    }

    body * {
        visibility: hidden !important;
    }

    #soa, #soa * {
        visibility: visible !important;
    }

    #soa {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        margin: 0;
        padding: 20px;
        box-shadow: none;
        border: 1px solid #ccc;
        border-radius: 0;
    }

    .no-print {
        display: none !important;
    }

    #soa h1, #soa h2 {
        color: #000 !important;
    }

    #soa table {
        width: 100%;
    }

    #soa td {
        padding: 10px;
        border-bottom: 1px solid #ccc;
    }
}
</style>
</head>

<body>

<div class="container">

    <div class="panel no-print">

        <h1>My Subscription</h1>

        <p class="muted">
            Welcome, <?= escapeHtml($resident_name) ?>.
        </p>

        <div class="price">
            <?= peso($monthly_fee) ?>
            <span class="muted" style="font-size:15px;font-weight:400">
                / month
            </span>
        </div>

        <p class="muted">
            Subscription duration is one month.
        </p>

    </div>

    <?php foreach ($errors as $error): ?>
        <div class="alert error no-print">
            <?= escapeHtml($error) ?>
        </div>
    <?php endforeach; ?>

    <?php if ($success): ?>
        <div class="alert success no-print">
            <?= escapeHtml($success) ?>
        </div>
    <?php endif; ?>


    <?php if (!$subscription): ?>
        
        <!-- SUBSCRIPTION APPLICATION AND BILLING PREVIEW -->

        <div class="panel no-print">

            <h2>Subscription and Billing Details</h2>

            <p class="muted">
                You do not have a subscription application yet.
                Review your billing details and apply below.
            </p>

            <div class="grid">

                <div class="stat">
                    <span class="muted">Subscription Fee</span>
                    <strong><?= peso($monthly_fee) ?></strong>
                </div>

                <div class="stat">
                    <span class="muted">Available Eco Points</span>
                    <strong><?= number_format($available_points) ?></strong>
                </div>

                <div class="stat">
                    <span class="muted">Reserved Eco Points</span>
                    <strong><?= number_format($reserved_points) ?></strong>
                </div>

                <div class="stat">
                    <span class="muted">Duration</span>
                    <strong>1 month</strong>
                </div>

            </div>

            <h3 style="margin-top:25px">
                Apply for Subscription
            </h3>

            <div class="notice">

                <strong>Eco Points Discount</strong><br>

                1 Eco Point = ₱1.00 discount.<br>

                Maximum discount for one month: ₱150.<br>

                Your reserved Eco Points will automatically
                be applied to this subscription.

            </div>

            <table style="margin-top:18px">

                <tr>
                    <td>Monthly Subscription Fee</td>
                    <td><?= peso($monthly_fee) ?></td>
                </tr>

                <tr>
                    <td>Eco Points Reserved</td>
                    <td><?= number_format($reserved_points) ?> Points</td>
                </tr>

                <tr>
                    <td>Eco Points Discount</td>
                    <td><?= peso($reserved_discount) ?></td>
                </tr>

                <tr>
                    <td><strong>Total Amount Due</strong></td>
                    <td>
                        <strong>
                            <?= peso(max(0, $monthly_fee - $reserved_discount)) ?>
                        </strong>
                    </td>
                </tr>

            </table>

            <form method="POST"
                onsubmit="return confirm('Submit your subscription application? Your reserved Eco Points will automatically be applied.');">

                <input type="hidden"
                    name="csrf_token"
                    value="<?= escapeHtml($csrf) ?>">

                <input type="hidden"
                    name="action"
                    value="create_subscription">

                <button type="submit">
                    Apply for Subscription
                </button>

            </form>

            <p class="muted" style="margin-top:20px">

                Want to reserve more points?

                <a href="eco_points.php" class="secondary-link">
                    Go to My Eco Points
                </a>

            </p>

        </div>


    <?php else: ?>

        <!-- EXISTING SUBSCRIPTION BILLING DETAILS -->

        <div class="panel no-print">

            <h2>Subscription and Billing Details</h2>

            <div class="grid">

                <div class="stat">
                    <span class="muted">Subscription ID</span>
                    <strong>#<?= (int)$subscription['id'] ?></strong>
                </div>

                <div class="stat">
                    <span class="muted">Subscription Status</span>
                    <strong>
                        <?= escapeHtml($subscription['subscription_status']) ?>
                    </strong>
                </div>

                <div class="stat">
                    <span class="muted">Payment Status</span>
                    <strong>
                        <?= escapeHtml($subscription['payment_status']) ?>
                    </strong>
                </div>

            </div>

            <table style="margin-top:18px">

                <tr>
                    <td>Subscription Date</td>
                    <td><?= escapeHtml($subscription['created_at']) ?></td>
                </tr>

                <tr>
                    <td>Duration</td>
                    <td><?= (int)$subscription['months'] ?> month(s)</td>
                </tr>

                <tr>
                    <td>Monthly Fee</td>
                    <td><?= peso($subscription['original_amount']) ?></td>
                </tr>

                <tr>
                    <td>Eco Points Redeemed</td>
                    <td><?= number_format((int)$subscription['points_redeemed']) ?></td>
                </tr>

                <tr>
                    <td>Eco Points Discount</td>
                    <td><?= peso($subscription['discount_amount']) ?></td>
                </tr>

                <tr>
                    <td>Total Subscription Amount</td>
                    <td><?= peso($subscription['total_amount']) ?></td>
                </tr>

                <tr>
                    <td>Amount Paid</td>
                    <td><?= peso($subscription['amount_paid']) ?></td>
                </tr>

                <tr>
                    <td><strong>Remaining Balance</strong></td>
                    <td><strong><?= peso($subscription['balance_due']) ?></strong></td>
                </tr>

            </table>


            <?php if (
                $subscription['subscription_status'] === 'Pending Approval' &&
                $subscription['payment_status'] === 'Unpaid' &&
                (float)$subscription['balance_due'] > 0
            ): ?>

                <div class="notice">

                    <strong>Cash Simulation Payment</strong><br>

                    This is a school-project payment simulation, not a real money transaction.

                    If you have completed the simulated cash payment, submit a payment notice below.

                    Your subscription will remain pending until the administrator confirms it.

                    Note that the subscription will be automatically rejected if not paid after 3 days.

                </div>

                <form method="POST"
                      onsubmit="return confirm('Submit your Cash Simulation payment notice?');">

                    <input type="hidden"
                           name="csrf_token"
                           value="<?= escapeHtml($csrf) ?>">

                    <input type="hidden"
                           name="action"
                           value="submit_cash_notice">

                    <button type="submit">
                        Submit Cash Simulation Payment Notice
                    </button>

                </form>


            <?php elseif ($is_pending_payment): ?>

                <div class="notice">

                    <strong>Payment Notice Submitted!</strong><br>

                    Your payment notice has been submitted successfully.

                    Please present your printed Statement of Account to the cashier.

                    Your payment is still awaiting administrator confirmation.

                </div>


            <?php elseif ($is_paid): ?>

                <div class="alert success" style="margin-top:16px">

                    Your payment has been confirmed by the administrator.

                    Your subscription is now <?= escapeHtml($subscription['subscription_status']) ?>.

                </div>


            <?php elseif ((float)$subscription['total_amount'] <= 0): ?>

                <div class="notice">

                    Your Eco Points covered the full subscription fee.

                    No cash payment is required. Your subscription still requires administrator review.

                </div>


            <?php else: ?>

                <div class="notice">

                    Your subscription is awaiting administrator review.

                    Please check this page for updates.

                </div>

            <?php endif; ?>


            <?php if ($can_print_soa): ?>

                <button type="button"
                        class="print-button"
                        onclick="window.print()">

                    Print Statement of Account

                </button>

            <?php endif; ?>

            <p class="muted">
                <a href="eco_points.php" class="secondary-link">
                    View Eco Points and Redemption History
                </a>
            </p>

        </div>


        <?php if ($can_print_soa): ?>

            <!-- PRINTABLE STATEMENT OF ACCOUNT -->

            <div class="panel" id="soa">

                <div style="text-align:center; margin-bottom:25px">

                    <h1 style="margin-bottom:5px">
                        ECO PHP
                    </h1>

                    <p style="margin:0">
                        WASTE MANAGEMENT SYSTEM
                    </p>

                    <h2 style="margin-top:25px; color:#222">
                        STATEMENT OF ACCOUNT
                    </h2>

                    <p class="muted">
                        SUBSCRIPTION PAYMENT SLIP
                    </p>

                </div>

                <hr>

                <h3>Resident Information</h3>

                <table>

                    <tr>
                        <td>Resident Name</td>
                        <td><?= escapeHtml($resident_name) ?></td>
                    </tr>

                    <tr>
                        <td>Resident ID</td>
                        <td><?= $user_id ?></td>
                    </tr>

                    <tr>
                        <td>Subscription ID</td>
                        <td>#<?= (int)$subscription['id'] ?></td>
                    </tr>

                    <tr>
                        <td>Subscription Date</td>
                        <td><?= escapeHtml($subscription['created_at']) ?></td>
                    </tr>

                    <tr>
                        <td>Statement Printed</td>
                        <td><?= date('F d, Y h:i A') ?></td>
                    </tr>

                </table>


                <h3 style="margin-top:25px">
                    Subscription Payment Breakdown
                </h3>

                <table>

                    <tr>
                        <td>Monthly Subscription Fee</td>
                        <td><?= peso($subscription['original_amount']) ?></td>
                    </tr>

                    <tr>
                        <td>Subscription Duration</td>
                        <td><?= (int)$subscription['months'] ?> month(s)</td>
                    </tr>

                    <tr>
                        <td>Eco Points Redeemed</td>
                        <td><?= number_format((int)$subscription['points_redeemed']) ?></td>
                    </tr>

                    <tr>
                        <td>Eco Points Discount</td>
                        <td><?= peso($subscription['discount_amount']) ?></td>
                    </tr>

                    <tr>
                        <td><strong>Total Subscription Amount</strong></td>
                        <td><strong><?= peso($subscription['total_amount']) ?></strong></td>
                    </tr>

                    <tr>
                        <td>Amount Paid / Recorded</td>
                        <td><?= peso($subscription['amount_paid']) ?></td>
                    </tr>

                    <tr style="font-size:18px">
                        <td><strong>Remaining Balance</strong></td>
                        <td>
                            <strong><?= peso($subscription['balance_due']) ?></strong>
                        </td>
                    </tr>

                </table>


                <h3 style="margin-top:25px">
                    Payment Information
                </h3>

                <table>

                    <tr>
                        <td>Payment Method</td>
                        <td>Cash Simulation</td>
                    </tr>

                    <tr>
                        <td>Payment Status</td>
                        <td><?= escapeHtml($subscription['payment_status']) ?></td>
                    </tr>

                    <tr>
                        <td>Subscription Status</td>
                        <td><?= escapeHtml($subscription['subscription_status']) ?></td>
                    </tr>

                </table>


                <div style="margin-top:30px; padding:15px; border:1px solid #aaa;">

                    <strong>IMPORTANT NOTICE</strong>

                    <p>
                        This Statement of Account is presented for cashier verification and payment reference.
                    </p>

                    <?php if ($is_pending_payment): ?>

                        <p>
                            Payment notice has been submitted and is awaiting administrator confirmation.
                            This document is NOT an official receipt or proof of confirmed payment.
                        </p>

                    <?php else: ?>

                        <p>
                            Payment has been confirmed in the system.
                            This statement is not a substitute for an official receipt.
                        </p>

                    <?php endif; ?>

                </div>


                <div style="display:flex; justify-content:space-between; gap:30px; margin-top:60px;">

                    <div style="width:45%; text-align:center">

                        <hr>

                        Resident Signature

                    </div>

                    <div style="width:45%; text-align:center">

                        <hr>

                        Cashier / Authorized Personnel

                    </div>

                </div>


                <p style="text-align:center; margin-top:40px; font-size:12px;">

                    Thank you for supporting our waste management program!

                </p>

            </div>

        <?php endif; ?>

    <?php endif; ?>


    <p class="no-print">
        <a class="link" href="dashboard.php">
            Back to Dashboard
        </a>
    </p>

</div>

</body>
</html>
