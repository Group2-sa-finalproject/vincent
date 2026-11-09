<?php
session_start();

require_once __DIR__ . '/../../config/database.php';

// Only administrators may access this page.
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../../login.php');
    exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection error. Please check config/database.php.');
}

mysqli_set_charset($conn, 'utf8mb4');

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function peso($amount): string
{
    return '₱' . number_format((float)$amount, 2);
}

if (empty($_SESSION['subscription_admin_csrf'])) {
    $_SESSION['subscription_admin_csrf'] = bin2hex(random_bytes(32));
}

$success_message = '';
$error_message = '';

// Process an administrator's payment decision.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_token = $_POST['csrf_token'] ?? '';
    $subscription_id = filter_input(INPUT_POST, 'subscription_id', FILTER_VALIDATE_INT);
    $action = $_POST['action'] ?? '';

    if (
        !is_string($posted_token) ||
        !hash_equals($_SESSION['subscription_admin_csrf'], $posted_token)
    ) {
        $error_message = 'Your session token is invalid. Please refresh the page and try again.';
    } elseif (!$subscription_id || $subscription_id < 1) {
        $error_message = 'Invalid subscription record.';
    } elseif (!in_array($action, ['confirm', 'reject'], true)) {
        $error_message = 'Invalid administrator action.';
    } else {
        try {
            $conn->begin_transaction();

            // Lock the subscription row so it cannot be confirmed twice at the same time.
            $lock_stmt = $conn->prepare("
                SELECT id, total_amount, payment_status, subscription_status
                FROM subscriptions
                WHERE id = ?
                LIMIT 1
                FOR UPDATE
            ");

            if (!$lock_stmt) {
                throw new Exception('Unable to prepare the subscription lookup.');
            }

            $lock_stmt->bind_param('i', $subscription_id);
            $lock_stmt->execute();
            $subscription = $lock_stmt->get_result()->fetch_assoc();
            $lock_stmt->close();

            if (!$subscription) {
                throw new Exception('Subscription record was not found.');
            }

            if (
                $subscription['payment_status'] !== 'Pending Confirmation' ||
                $subscription['subscription_status'] !== 'Pending Approval'
            ) {
                throw new Exception('This record is no longer awaiting payment confirmation. Refresh the page.');
            }

            if ($action === 'confirm') {
                $total_amount = (float)$subscription['total_amount'];

                $update_stmt = $conn->prepare("
                    UPDATE subscriptions
                    SET amount_paid = total_amount,
                        balance_due = 0.00,
                        payment_status = 'Paid',
                        subscription_status = 'Active'
                    WHERE id = ?
                      AND payment_status = 'Pending Confirmation'
                      AND subscription_status = 'Pending Approval'
                ");

                if (!$update_stmt) {
                    throw new Exception('Unable to prepare the payment confirmation.');
                }

                $update_stmt->bind_param('i', $subscription_id);
                $update_stmt->execute();
                $changed = $update_stmt->affected_rows;
                $update_stmt->close();

                if ($changed !== 1) {
                    throw new Exception('The subscription could not be confirmed. Refresh and try again.');
                }

                $success_message = 'Payment simulation confirmed. The subscription is now active and marked as paid.';
            } else {
                // Rejection returns the payment to Unpaid. The resident may submit a new notice.
                $update_stmt = $conn->prepare("
                    UPDATE subscriptions
                    SET payment_status = 'Unpaid'
                    WHERE id = ?
                      AND payment_status = 'Pending Confirmation'
                      AND subscription_status = 'Pending Approval'
                ");

                if (!$update_stmt) {
                    throw new Exception('Unable to prepare the payment rejection.');
                }

                $update_stmt->bind_param('i', $subscription_id);
                $update_stmt->execute();
                $changed = $update_stmt->affected_rows;
                $update_stmt->close();

                if ($changed !== 1) {
                    throw new Exception('The payment notice could not be rejected. Refresh and try again.');
                }

                $success_message = 'Payment notice rejected. The subscription remains pending approval and unpaid.';
            }

            $conn->commit();

            // Rotate the token after a successful state-changing request.
            $_SESSION['subscription_admin_csrf'] = bin2hex(random_bytes(32));
        } catch (Throwable $exception) {
            $conn->rollback();
            $error_message = $exception->getMessage();
        }
    }
}

// Retrieve all subscription records for the administrator.
$list_sql = "
    SELECT
        s.id,
        s.user_id,
        u.fullname,
        u.users AS username,
        s.service_fee,
        s.months,
        s.original_amount,
        s.points_redeemed,
        s.discount_amount,
        s.total_amount,
        s.amount_paid,
        s.balance_due,
        s.subscription_status,
        s.payment_status,
        s.created_at
    FROM subscriptions AS s
    INNER JOIN users AS u ON u.id = s.user_id
    ORDER BY
        CASE
            WHEN s.payment_status = 'Pending Confirmation' THEN 0
            WHEN s.subscription_status = 'Pending Approval' THEN 1
            ELSE 2
        END,
        s.created_at DESC
";

$result = $conn->query($list_sql);

if (!$result) {
    die('Unable to load subscriptions: ' . e($conn->error));
}

$total_records = $result->num_rows;
$pending_confirmations = 0;
$active_subscriptions = 0;
$rows = [];

while ($row = $result->fetch_assoc()) {
    $rows[] = $row;

    if ($row['payment_status'] === 'Pending Confirmation') {
        $pending_confirmations++;
    }

    if (
        $row['subscription_status'] === 'Active' &&
        $row['payment_status'] === 'Paid' &&
        (float)$row['balance_due'] <= 0
    ) {
        $active_subscriptions++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Subscriptions | Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f5f7f6; }
        .page-header { background: #198754; color: #fff; border-radius: 12px; }
        .summary-card { border: 0; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,.05); }
        .table thead th { white-space: nowrap; }
        .table td { vertical-align: middle; }
        .status-badge { font-size: .78rem; }
        .action-form { display: inline-block; margin: 2px; }
    </style>
</head>
<body>
<div class="container-fluid py-4 px-3 px-lg-4">
    <div class="page-header p-4 mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h1 class="h3 mb-1">Manage Subscriptions and Payments</h1>
                <p class="mb-0">Review subscription records and confirm simulated cash payment notices.</p>
            </div>
            <a href="../dashboard.php" class="btn btn-light">Back to Dashboard</a>
        </div>
    </div>

    <?php if ($success_message !== ''): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= e($success_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($error_message !== ''): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?= e($error_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="alert alert-warning">
        <strong>School-project simulation:</strong> “Cash Simulation” is only a simulated payment notice.
        This page does not receive or process real money. The administrator must manually verify and confirm each notice.
    </div>

    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-4">
            <div class="card summary-card">
                <div class="card-body">
                    <div class="text-secondary small">All subscription records</div>
                    <div class="fs-3 fw-bold"><?= number_format($total_records) ?></div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-4">
            <div class="card summary-card">
                <div class="card-body">
                    <div class="text-secondary small">Awaiting payment confirmation</div>
                    <div class="fs-3 fw-bold text-warning"><?= number_format($pending_confirmations) ?></div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-4">
            <div class="card summary-card">
                <div class="card-body">
                    <div class="text-secondary small">Active and paid subscriptions</div>
                    <div class="fs-3 fw-bold text-success"><?= number_format($active_subscriptions) ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
            <h2 class="h5 mb-0">Subscription Records</h2>
        </div>
        <div class="card-body">
            <?php if (count($rows) === 0): ?>
                <div class="text-center text-secondary py-5">No subscription records found.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover table-striped align-middle">
                        <thead class="table-light">
                        <tr>
                            <th>Resident</th>
                            <th>Subscription</th>
                            <th>Eco Points</th>
                            <th>Total Due</th>
                            <th>Amount Paid</th>
                            <th>Balance</th>
                            <th>Subscription Status</th>
                            <th>Payment Status</th>
                            <th>Date Created</th>
                            <th style="min-width: 190px;">Administrator Action</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $row): ?>
                            <?php
                                $payment_status = $row['payment_status'];
                                $subscription_status = $row['subscription_status'];

                                $payment_badge = 'bg-secondary';
                                if ($payment_status === 'Paid') {
                                    $payment_badge = 'bg-success';
                                } elseif ($payment_status === 'Pending Confirmation') {
                                    $payment_badge = 'bg-warning text-dark';
                                } elseif ($payment_status === 'Unpaid') {
                                    $payment_badge = 'bg-danger';
                                }

                                $subscription_badge = $subscription_status === 'Active'
                                    ? 'bg-success'
                                    : 'bg-secondary';
                            ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= e($row['fullname']) ?></div>
                                    <small class="text-secondary">
                                        <?= e($row['username']) ?> · ID <?= (int)$row['user_id'] ?>
                                    </small>
                                </td>
                                <td>
                                    <?= (int)$row['months'] ?> month(s)<br>
                                    <small class="text-secondary">Fee: <?= peso($row['service_fee']) ?></small>
                                </td>
                                <td>
                                    <?= number_format((int)$row['points_redeemed']) ?> point(s)<br>
                                    <small class="text-secondary">Discount: <?= peso($row['discount_amount']) ?></small>
                                </td>
                                <td class="fw-semibold"><?= peso($row['total_amount']) ?></td>
                                <td><?= peso($row['amount_paid']) ?></td>
                                <td><?= peso($row['balance_due']) ?></td>
                                <td>
                                    <span class="badge status-badge <?= $subscription_badge ?>">
                                        <?= e($subscription_status) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge status-badge <?= $payment_badge ?>">
                                        <?= e($payment_status) ?>
                                    </span>
                                    <?php if ($payment_status === 'Pending Confirmation'): ?>
                                        <div><small class="text-secondary">Cash Simulation</small></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= e(date('M d, Y', strtotime($row['created_at']))) ?></td>
                                <td>
                                    <?php if (
                                        $payment_status === 'Pending Confirmation' &&
                                        $subscription_status === 'Pending Approval'
                                    ): ?>
                                        <form method="POST" class="action-form"
                                              onsubmit="return confirm('Confirm this simulated cash payment and activate the subscription?');">
                                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['subscription_admin_csrf']) ?>">
                                            <input type="hidden" name="subscription_id" value="<?= (int)$row['id'] ?>">
                                            <input type="hidden" name="action" value="confirm">
                                            <button type="submit" class="btn btn-sm btn-success">Confirm Payment</button>
                                        </form>
                                        <form method="POST" class="action-form"
                                              onsubmit="return confirm('Reject this payment notice? Its payment status will return to Unpaid.');">
                                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['subscription_admin_csrf']) ?>">
                                            <input type="hidden" name="subscription_id" value="<?= (int)$row['id'] ?>">
                                            <input type="hidden" name="action" value="reject">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Reject</button>
                                        </form>
                                    <?php elseif ($subscription_status === 'Active' && $payment_status === 'Paid'): ?>
                                        <span class="text-success small fw-semibold">Payment confirmed</span>
                                    <?php else: ?>
                                        <span class="text-secondary small">No action available</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
