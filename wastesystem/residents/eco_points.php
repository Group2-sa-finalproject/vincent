
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

const MAX_SUBSCRIPTION_DISCOUNT = 150;

if (empty($_SESSION['eco_points_csrf'])) {
    $_SESSION['eco_points_csrf'] = bin2hex(random_bytes(32));
}

$message = '';
$message_type = 'success';

$available_points = 0;
$reserved_points = 0;
$redeem_limit = 0;
$redemption_history = [];
$subscription_exists = false;

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
        throw new RuntimeException(
            "Unable to calculate Eco Points: " . $conn->error
        );
    }

    $stmt->bind_param("ii", $user_id, $user_id);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException($error);
    }

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return max(0, (int)($row['available_points'] ?? 0));
}

/*
 * Get points already reserved for a future subscription.
 * These are redemption records that have not yet been
 * attached to a subscription.
 */
function getReservedEcoPoints(mysqli $conn, int $user_id): int
{
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(points_redeemed), 0) AS reserved_points
        FROM subscription_point_redemptions
        WHERE user_id = ?
          AND subscription_id IS NULL
    ");

    if (!$stmt) {
        throw new RuntimeException(
            "Unable to retrieve reserved points: " . $conn->error
        );
    }

    $stmt->bind_param("i", $user_id);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['reserved_points'] ?? 0);
}

/*
 * Check whether the resident already has a subscription.
 * This prevents reserving points for another subscription
 * when the existing system allows only one subscription per user.
 */
function hasExistingSubscription(mysqli $conn, int $user_id): bool
{
    $stmt = $conn->prepare("
        SELECT id
        FROM subscriptions
        WHERE user_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new RuntimeException(
            "Unable to check subscription: " . $conn->error
        );
    }

    $stmt->bind_param("i", $user_id);
    $stmt->execute();

    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $exists;
}

/*
 * Retrieve the resident's redemption history.
 */
function getRedemptionHistory(mysqli $conn, int $user_id): array
{
    $stmt = $conn->prepare("
        SELECT
            id,
            subscription_id,
            points_redeemed,
            discount_amount,
            created_at
        FROM subscription_point_redemptions
        WHERE user_id = ?
        ORDER BY created_at DESC, id DESC
    ");

    if (!$stmt) {
        throw new RuntimeException(
            "Unable to retrieve redemption history: " . $conn->error
        );
    }

    $stmt->bind_param("i", $user_id);
    $stmt->execute();

    $result = $stmt->get_result();
    $history = [];

    while ($row = $result->fetch_assoc()) {
        $history[] = $row;
    }

    $stmt->close();

    return $history;
}

/*
 * Refresh the resident's Eco Points information.
 */
function refreshEcoPoints(
    mysqli $conn,
    int $user_id,
    &$available_points,
    &$reserved_points,
    &$redeem_limit,
    &$subscription_exists,
    &$redemption_history
): void {
    $available_points = getAvailableEcoPoints($conn, $user_id);

    $reserved_points = getReservedEcoPoints($conn, $user_id);

    $redeem_limit = min(
        $available_points,
        max(0, MAX_SUBSCRIPTION_DISCOUNT - $reserved_points)
    );

    $subscription_exists = hasExistingSubscription($conn, $user_id);

    $redemption_history = getRedemptionHistory($conn, $user_id);
}

/*
 * Initial page information.
 */
try {
    refreshEcoPoints(
        $conn,
        $user_id,
        $available_points,
        $reserved_points,
        $redeem_limit,
        $subscription_exists,
        $redemption_history
    );
} catch (Throwable $e) {
    $message = $e->getMessage();
    $message_type = 'error';
}

/*
 * REDEEM ECO POINTS
 *
 * IMPORTANT:
 * This process ONLY creates a redemption record.
 * It does NOT create a subscription.
 *
 * subscription_id is deliberately saved as NULL.
 */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['redeem_points'])
) {
    $transaction_started = false;

    try {
        $posted_token = $_POST['csrf_token'] ?? '';

        if (!hash_equals($_SESSION['eco_points_csrf'], $posted_token)) {
            throw new RuntimeException(
                "Your session token is invalid. Please refresh the page."
            );
        }

        $points_to_redeem = filter_input(
            INPUT_POST,
            'points_to_redeem',
            FILTER_VALIDATE_INT
        );

        if (
            $points_to_redeem === false
            || $points_to_redeem === null
            || $points_to_redeem <= 0
        ) {
            throw new RuntimeException(
                "Enter a valid number of Eco Points greater than zero."
            );
        }

        if ($points_to_redeem > MAX_SUBSCRIPTION_DISCOUNT) {
            throw new RuntimeException(
                "You can reserve a maximum of 150 Eco Points."
            );
        }

        $conn->begin_transaction();
        $transaction_started = true;

        /*
         * Lock the resident account to prevent simultaneous
         * redemption requests from spending the same points.
         */
        $lock = $conn->prepare("
            SELECT id
            FROM users
            WHERE id = ?
            FOR UPDATE
        ");

        if (!$lock) {
            throw new RuntimeException(
                "Unable to verify your account: " . $conn->error
            );
        }

        $lock->bind_param("i", $user_id);
        $lock->execute();

        $account = $lock->get_result()->fetch_assoc();
        $lock->close();

        if (!$account) {
            throw new RuntimeException(
                "Resident account was not found."
            );
        }

        /*
         * Do not reserve points if a subscription already exists.
         */
        if (hasExistingSubscription($conn, $user_id)) {
            throw new RuntimeException(
                "You already have a subscription record. Please check your Subscription page."
            );
        }

        /*
         * Recalculate the balance and existing reservations
         * while the resident account is locked.
         */
        $current_available = getAvailableEcoPoints(
            $conn,
            $user_id
        );

        $current_reserved = getReservedEcoPoints(
            $conn,
            $user_id
        );

        $remaining_reservation_limit = max(
            0,
            MAX_SUBSCRIPTION_DISCOUNT - $current_reserved
        );

        if ($points_to_redeem > $current_available) {
            throw new RuntimeException(
                "You do not have enough available Eco Points."
            );
        }

        if ($points_to_redeem > $remaining_reservation_limit) {
            throw new RuntimeException(
                "You can reserve only "
                . $remaining_reservation_limit
                . " more points. The maximum reservation is 150 points."
            );
        }

        /*
         * Conversion:
         * 1 Eco Point = ₱1.00 discount.
         */
        $discount_amount = (float)$points_to_redeem;

        /*
         * IMPORTANT:
         * subscription_id is NULL because no subscription
         * has been created yet.
         */
        $insert = $conn->prepare("
            INSERT INTO subscription_point_redemptions
                (
                    subscription_id,
                    user_id,
                    points_redeemed,
                    discount_amount
                )
            VALUES
                (NULL, ?, ?, ?)
        ");

        if (!$insert) {
            throw new RuntimeException(
                "Unable to prepare redemption: " . $conn->error
            );
        }

        $insert->bind_param(
            "iid",
            $user_id,
            $points_to_redeem,
            $discount_amount
        );

        if (!$insert->execute()) {
            $error = $insert->error;
            $insert->close();

            throw new RuntimeException(
                "Unable to save redemption: " . $error
            );
        }

        $insert->close();

        /*
         * Commit the redemption.
         * The available Eco Points will immediately decrease
         * because the balance calculation subtracts all
         * redemption records, including unassigned ones.
         */
        $conn->commit();
        $transaction_started = false;

        $message = number_format($points_to_redeem)
            . " Eco Points successfully reserved for your next subscription. "
            . "Your available balance has been updated.";

        $message_type = 'success';

    } catch (Throwable $e) {

        if ($transaction_started) {
            try {
                $conn->rollback();
            } catch (Throwable $ignored) {
                // Ignore rollback errors.
            }
        }

        $message = $e->getMessage();
        $message_type = 'error';
    }

    /*
     * Refresh all information after redemption.
     */
    try {
        refreshEcoPoints(
            $conn,
            $user_id,
            $available_points,
            $reserved_points,
            $redeem_limit,
            $subscription_exists,
            $redemption_history
        );
    } catch (Throwable $e) {
        if ($message === '') {
            $message = $e->getMessage();
            $message_type = 'error';
        }
    }
}

function h($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

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

.back-dashboard,
.action-btn {
    display: inline-block;
    padding: 10px 16px;
    border-radius: 4px;
    text-decoration: none;
    border: 0;
    cursor: pointer;
    font-size: 14px;
}

.back-dashboard {
    margin-bottom: 22px;
    background: #6c757d;
    color: white;
}

.action-btn {
    background: #198754;
    color: white;
}

.action-btn:hover {
    background: #146c43;
}

.action-btn:disabled {
    background: #999;
    cursor: not-allowed;
}

.page-title {
    margin-bottom: 20px;
}

.page-title p {
    color: #666;
    font-size: 14px;
}

.points-box,
.info-box {
    background: white;
    border: 1px solid #ddd;
    border-radius: 5px;
    padding: 24px;
    margin-bottom: 22px;
}

.points-box h1 {
    color: #198754;
    font-size: 35px;
    margin: 10px 0;
}

.points-box p,
.info-box p {
    color: #666;
}

.info-box h3 {
    margin-top: 0;
    color: #198754;
    font-size: 19px;
}

.info-box li {
    margin-bottom: 10px;
    font-size: 14px;
    line-height: 1.5;
}

.info-box ul {
    padding-left: 22px;
}

.notice {
    padding: 13px 16px;
    border-radius: 4px;
    margin-bottom: 20px;
    line-height: 1.5;
}

.notice.success {
    background: #d1e7dd;
    color: #0f5132;
}

.notice.error {
    background: #f8d7da;
    color: #842029;
}

.form-row {
    margin: 16px 0;
}

.form-row label {
    display: block;
    font-weight: bold;
    margin-bottom: 7px;
}

.form-row input {
    width: 100%;
    max-width: 280px;
    padding: 11px;
    border: 1px solid #bbb;
    border-radius: 4px;
    font-size: 16px;
}

.small {
    color: #666;
    font-size: 13px;
    line-height: 1.5;
}

.summary {
    background: #f8f9fa;
    padding: 14px;
    border-radius: 4px;
    margin: 15px 0;
}

.summary p {
    margin: 7px 0;
}

.history-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 15px;
    font-size: 14px;
}

.history-table th,
.history-table td {
    border: 1px solid #ddd;
    padding: 11px;
    text-align: left;
}

.history-table th {
    background: #f1f3f5;
}

.status {
    font-weight: bold;
}

@media(max-width: 600px) {
    .container {
        margin-top: 20px;
    }

    .points-box h1 {
        font-size: 30px;
    }

    .history-table {
        font-size: 12px;
    }

    .history-table th,
    .history-table td {
        padding: 7px;
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
        <p>Earn points by participating in proper waste management.</p>
    </div>

    <?php if ($message !== ''): ?>
        <div class="notice <?= h($message_type) ?>">
            <?= h($message) ?>
        </div>
    <?php endif; ?>

    <!-- AVAILABLE BALANCE -->

    <div class="points-box">
        <p>My Total Available Points</p>

        <h1>
            <?= number_format($available_points) ?> Points
        </h1>

        <p class="small">
            Your available balance is your earned Eco Points
            minus all points already redeemed or reserved.
        </p>
    </div>

    <!-- REDEMPTION FORM -->

    <div class="info-box">

        <h3>Reserve Eco Points for Your Subscription</h3>

        <p>
            <strong>1 Eco Point = ₱1.00 discount.</strong>
            You may reserve up to 150 points for your
            one-month subscription fee of ₱150.
        </p>

        <div class="summary">
            <p>
                Points already reserved:
                <strong><?= number_format($reserved_points) ?></strong>
            </p>

            <p>
                Maximum additional points you can reserve:
                <strong><?= number_format($redeem_limit) ?></strong>
            </p>
        </div>

        <?php if ($subscription_exists): ?>

            <div class="notice error">
                You already have a subscription record.
                Please visit your Subscription page to view
                your subscription and payment details.
            </div>

            <a href="subscription.php" class="action-btn">
                View Subscription
            </a>

        <?php elseif ($redeem_limit <= 0): ?>

            <p class="small">
                <?php if ($reserved_points >= MAX_SUBSCRIPTION_DISCOUNT): ?>
                    You have already reserved the maximum
                    of 150 Eco Points for your next subscription.
                <?php else: ?>
                    You currently have no available points to redeem.
                <?php endif; ?>
            </p>

        <?php else: ?>

            <form method="POST" action="">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= h($_SESSION['eco_points_csrf']) ?>"
                >

                <div class="form-row">

                    <label for="points_to_redeem">
                        Eco Points to Redeem
                    </label>

                    <input
                        type="number"
                        id="points_to_redeem"
                        name="points_to_redeem"
                        min="1"
                        max="<?= (int)$redeem_limit ?>"
                        step="1"
                        value="1"
                        required
                    >

                    <p class="small">
                        You can reserve up to
                        <?= number_format($redeem_limit) ?>
                        additional points.
                    </p>

                </div>

                <div class="summary" aria-live="polite">

                    <p>
                        Eco Points discount:
                        <strong id="discount_preview">₱1.00</strong>
                    </p>

                    <p>
                        Remaining subscription amount:
                        <strong id="total_preview">₱149.00</strong>
                    </p>

                </div>

                <button
                    type="submit"
                    name="redeem_points"
                    class="action-btn"
                >
                    Apply Points
                </button>

            </form>

        <?php endif; ?>

        <p class="small">
            Important: Applying points only records your
            redemption. It does not create a subscription.
            Your reserved points will be applied when you
            submit your subscription application.
        </p>

    </div>

    <!-- REDEMPTION HISTORY -->

    <div class="info-box">

        <h3>My Redemption History</h3>

        <?php if (empty($redemption_history)): ?>

            <p class="small">
                You have no Eco Points redemption records yet.
            </p>

        <?php else: ?>

            <div style="overflow-x:auto;">

                <table class="history-table">

                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Points</th>
                            <th>Discount</th>
                            <th>Status</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($redemption_history as $redemption): ?>

                        <tr>

                            <td>
                                <?= h($redemption['created_at']) ?>
                            </td>

                            <td>
                                <?= number_format(
                                    (int)$redemption['points_redeemed']
                                ) ?>
                            </td>

                            <td>
                                ₱<?= number_format(
                                    (float)$redemption['discount_amount'],
                                    2
                                ) ?>
                            </td>

                            <td>
                                <?php if ($redemption['subscription_id'] === null): ?>

                                    <span class="status">
                                        Reserved
                                    </span>

                                <?php else: ?>

                                    <span class="status">
                                        Applied to Subscription
                                    </span>

                                <?php endif; ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </div>

    <!-- HOW TO EARN POINTS -->

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

    <!-- REDEMPTION RULES -->

    <div class="info-box">

        <h3>Important Redemption Rules</h3>

        <ul>
            <li>1 Eco Point is equal to ₱1.00 discount.</li>

            <li>
                The monthly subscription fee is ₱150.00
                for one month.
            </li>

            <li>
                A maximum of 150 Eco Points can be reserved
                for one subscription.
            </li>

            <li>
                Reserved points are immediately deducted
                from your available balance.
            </li>

            <li>
                Applying points does not create a subscription.
            </li>

            <li>
                Your reserved points will be attached to your
                subscription when you apply through the
                Subscription page.
            </li>

            <li>
                Cash Simulation payments still require
                administrator confirmation.
            </li>
        </ul>

    </div>

</div>

<script>
const pointsInput = document.getElementById('points_to_redeem');

if (pointsInput) {

    const discountPreview =
        document.getElementById('discount_preview');

    const totalPreview =
        document.getElementById('total_preview');

    function updatePreview() {

        let points = parseInt(pointsInput.value, 10);

        if (!Number.isFinite(points) || points < 0) {
            points = 0;
        }

        const maximum = parseInt(pointsInput.max, 10) || 0;

        points = Math.min(points, maximum, 150);

        discountPreview.textContent =
            '₱' + points.toFixed(2);

        totalPreview.textContent =
            '₱' + (150 - points).toFixed(2);
    }

    pointsInput.addEventListener('input', updatePreview);

    updatePreview();
}
</script>

</body>
</html>
