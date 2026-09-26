<?php
// htdocs/admin/payments.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/db.php';
require_once '../config/security.php';

if (!is_admin_logged_in()) {
    header("Location: index.php");
    exit;
}

$admin_id = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 1;

// ==========================================
// SELF-HEALING SCHEMA
// ==========================================
function ensure_column(PDO $pdo, string $table, string $column, string $definition): void {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        if ($stmt->fetch() === false) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    } catch (PDOException $e) { /* ignore */ }
}



try {
    ensure_column($pdo, 'payments', 'payment_method',   "VARCHAR(50) DEFAULT 'Online'");
    ensure_column($pdo, 'payments', 'transaction_id',   "VARCHAR(100) NULL");
    ensure_column($pdo, 'payments', 'refund_status',    "VARCHAR(50) DEFAULT 'None'");
    ensure_column($pdo, 'payments', 'rejection_reason', "TEXT NULL");
    ensure_column($pdo, 'payments', 'proof_file_path',  "VARCHAR(255) NULL");
    ensure_column($pdo, 'payments', 'created_at',       "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");

    $pdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('commission_rate', '15.0')");
} catch (PDOException $e) {
    error_log("Schema upgrade error: " . $e->getMessage());
}

// ==========================================
// FETCH SETTINGS
// ==========================================
$comm_rate = 15.0;
try {
    $val = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'commission_rate'")->fetchColumn();
    if ($val !== false && $val !== null) $comm_rate = (float)$val;
} catch (PDOException $e) {}

// ==========================================
// HANDLE POST ACTIONS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['flash_message'] = "<div class='alert alert-danger shadow-sm border-0'><i class='fa-solid fa-shield-halved me-2'></i>Security token expired.</div>";
    } else {
        $action = $_POST['action'];

        try {
            // ----- EXPORT CSV -----
            if ($action === 'export_csv') {
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename=financial_report_' . date('Y-m-d') . '.csv');
                $output = fopen('php://output', 'w');
                fputcsv($output, ['Ref ID', 'Customer', 'Amount', 'Method', 'Txn ID', 'Status', 'Refund', 'Date']);

                try {
                    $stmt = $pdo->query("
                        SELECT b.booking_ref, u.name, p.amount, p.payment_method, p.transaction_id, p.status, p.refund_status, p.created_at
                        FROM payments p
                        JOIN bookings b ON p.booking_id = b.id
                        JOIN users u ON b.user_id = u.id
                        ORDER BY p.created_at DESC
                    ");
                } catch (PDOException $e) {
                    $stmt = $pdo->query("SELECT '' AS booking_ref, '' AS name, amount, payment_method, transaction_id, status, refund_status, created_at FROM payments");
                }
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { fputcsv($output, $row); }
                fclose($output);
                exit;
            }

            $booking_id = isset($_POST['booking_id']) ? (int)$_POST['booking_id'] : 0;

            // ----- ACCEPT / VERIFY PAYMENT -----
            if ($action === 'verify_payment' || $action === 'accept_payment') {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE payments SET status = 'Verified' WHERE booking_id = ?")->execute([$booking_id]);
                $pdo->prepare("UPDATE bookings SET status = 'Pending Assignment' WHERE id = ?")->execute([$booking_id]);
                $pdo->prepare("INSERT INTO booking_history (booking_id, status_updated_to, notes) VALUES (?, 'Pending Assignment', 'Payment verified. Job broadcasted to available partners.')")->execute([$booking_id]);
                $pdo->prepare("INSERT INTO financial_logs (admin_id, action_type, target_id, details) VALUES (?, 'Accept Payment', ?, 'Payment accepted and job broadcasted.')")->execute([$admin_id, $booking_id]);
                $pdo->commit();
                $_SESSION['flash_message'] = "<div class='alert alert-success shadow-sm border-0'><i class='fa-solid fa-circle-check me-2'></i>Payment accepted! Job broadcasted to mechanics.</div>";
            }

            // ----- REJECT PAYMENT -----
            elseif ($action === 'reject_payment') {
                $reason = clean_input($_POST['rejection_reason'] ?? 'Rejected by admin');
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE payments SET status = 'Rejected', rejection_reason = ? WHERE booking_id = ?")->execute([$reason, $booking_id]);
                $pdo->prepare("UPDATE bookings SET status = 'Payment Rejected' WHERE id = ?")->execute([$booking_id]);
                $pdo->prepare("INSERT INTO financial_logs (admin_id, action_type, target_id, details) VALUES (?, 'Reject Payment', ?, ?)")->execute([$admin_id, $booking_id, "Rejected: $reason"]);
                $pdo->commit();
                $_SESSION['flash_message'] = "<div class='alert alert-danger shadow-sm border-0'><i class='fa-solid fa-circle-xmark me-2'></i>Payment rejected. Customer notified.</div>";
            }

            // ----- ISSUE REFUND -----
            elseif ($action === 'issue_refund') {
                $payment_id = (int)$_POST['payment_id'];
                $refund_type = clean_input($_POST['refund_type']);
                $pdo->prepare("UPDATE payments SET refund_status = ? WHERE id = ?")->execute([$refund_type, $payment_id]);
                $pdo->prepare("INSERT INTO financial_logs (admin_id, action_type, target_id, details) VALUES (?, 'Issue Refund', ?, ?)")->execute([$admin_id, $payment_id, "Issued $refund_type refund."]);
                $_SESSION['flash_message'] = "<div class='alert alert-info shadow-sm border-0'>Refund status updated to $refund_type.</div>";
            }

            // ----- BULK ACTIONS -----
            elseif ($action === 'bulk_action' && !empty($_POST['selected_payments'])) {
                $bulk_type = $_POST['bulk_type'];
                $ids = array_map('intval', $_POST['selected_payments']);
                $id_list = implode(',', $ids);

                if ($bulk_type === 'verify') {
                    $pdo->exec("UPDATE payments SET status = 'Verified' WHERE booking_id IN ($id_list)");
                    $pdo->exec("UPDATE bookings SET status = 'Pending Assignment' WHERE id IN ($id_list) AND status = 'Payment Verification Pending'");
                    $pdo->prepare("INSERT INTO financial_logs (admin_id, action_type, target_id, details) VALUES (?, 'Bulk Accept', 0, ?)")->execute([$admin_id, "Bulk accepted payments: $id_list"]);
                    $_SESSION['flash_message'] = "<div class='alert alert-success shadow-sm border-0'>Bulk verification completed. Jobs broadcasted.</div>";
                } elseif ($bulk_type === 'reject') {
                    $pdo->exec("UPDATE payments SET status = 'Rejected', rejection_reason = 'Bulk rejection by admin' WHERE booking_id IN ($id_list)");
                    $pdo->exec("UPDATE bookings SET status = 'Payment Rejected' WHERE id IN ($id_list) AND status = 'Payment Verification Pending'");
                    $pdo->prepare("INSERT INTO financial_logs (admin_id, action_type, target_id, details) VALUES (?, 'Bulk Reject', 0, ?)")->execute([$admin_id, "Bulk rejected payments: $id_list"]);
                    $_SESSION['flash_message'] = "<div class='alert alert-danger shadow-sm border-0'>Bulk rejection completed.</div>";
                }
            }

            // ----- MANUAL ENTRY -----
            elseif ($action === 'manual_entry') {
                $booking_ref = clean_input($_POST['booking_ref']);
                $amount = (float)$_POST['amount'];
                $method = clean_input($_POST['payment_method']);

                $stmt = $pdo->prepare("SELECT id FROM bookings WHERE booking_ref = ?");
                $stmt->execute([$booking_ref]);
                $booking = $stmt->fetch();

                if ($booking) {
                    $pdo->prepare("UPDATE payments SET amount = ?, payment_method = ?, status = 'Verified' WHERE booking_id = ?")->execute([$amount, $method, $booking['id']]);
                    $pdo->prepare("UPDATE bookings SET status = 'Pending Assignment' WHERE id = ? AND status = 'Payment Verification Pending'")->execute([$booking['id']]);
                    $pdo->prepare("INSERT INTO financial_logs (admin_id, action_type, target_id, details) VALUES (?, 'Manual Entry', ?, ?)")->execute([$admin_id, $booking['id'], "Manual entry: ₹$amount via $method"]);
                    $_SESSION['flash_message'] = "<div class='alert alert-success shadow-sm border-0'>Manual payment recorded and job broadcasted.</div>";
                } else {
                    $_SESSION['flash_message'] = "<div class='alert alert-danger shadow-sm border-0'>Booking Reference not found.</div>";
                }
            }

        } catch (PDOException $e) {
            if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
            $_SESSION['flash_message'] = "<div class='alert alert-danger shadow-sm border-0'>System Error: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
    header("Location: payments.php");
    exit;
}

// ==========================================
// PAGINATION & FILTERS
// ==========================================
$limit = 15;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? clean_input($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? clean_input($_GET['status']) : '';
$method_filter = isset($_GET['method']) ? clean_input($_GET['method']) : '';

$where_clauses = ["1=1"];
$params = [];

if (!empty($search)) {
    $where_clauses[] = "(u.name LIKE ? OR b.booking_ref LIKE ? OR p.transaction_id LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
if (!empty($status_filter)) {
    $where_clauses[] = "p.status = ?";
    $params[] = $status_filter;
}
if (!empty($method_filter)) {
    $where_clauses[] = "p.payment_method = ?";
    $params[] = $method_filter;
}
$where_sql = implode(" AND ", $where_clauses);

// ----- STATS -----
$stats = ['collected' => 0, 'pending_count' => 0, 'refunded' => 0, 'comm_earned' => 0];
try {
    $stats['collected']     = $pdo->query("SELECT SUM(amount) FROM payments WHERE status='Verified'")->fetchColumn() ?: 0;
    $stats['pending_count'] = $pdo->query("SELECT COUNT(*) FROM payments WHERE status='Pending'")->fetchColumn() ?: 0;
    $stats['refunded']      = $pdo->query("SELECT SUM(amount) FROM payments WHERE refund_status IN ('Full','Partial')")->fetchColumn() ?: 0;
    $stats['comm_earned']   = $pdo->query("SELECT SUM(p.amount * $comm_rate / 100) FROM bookings b JOIN payments p ON b.id = p.booking_id WHERE b.status = 'Completed'")->fetchColumn() ?: 0;
} catch (PDOException $e) {}

// ----- PAGINATION COUNT -----
$total_pages = 1;
try {
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM payments p JOIN bookings b ON p.booking_id = b.id JOIN users u ON b.user_id = u.id WHERE $where_sql");
    $count_stmt->execute($params);
    $total_pages = max(1, ceil($count_stmt->fetchColumn() / $limit));
} catch (PDOException $e) { $total_pages = 1; }

// ----- PAYMENTS LIST -----
$payments_list = [];
try {
    $query = "
        SELECT p.*, b.booking_ref, c.name as service_name, u.name as customer_name, u.phone
        FROM payments p
        JOIN bookings b ON p.booking_id = b.id
        JOIN users u ON b.user_id = u.id
        LEFT JOIN categories c ON b.category_id = c.id
        WHERE $where_sql
        ORDER BY p.created_at DESC
        LIMIT $limit OFFSET $offset
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $payments_list = $stmt->fetchAll();
} catch (PDOException $e) {
    try {
        $query = "
            SELECT p.*, b.booking_ref, 'Service' as service_name, u.name as customer_name, u.phone
            FROM payments p
            JOIN bookings b ON p.booking_id = b.id
            JOIN users u ON b.user_id = u.id
            WHERE $where_sql
            ORDER BY p.created_at DESC
            LIMIT $limit OFFSET $offset
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $payments_list = $stmt->fetchAll();
    } catch (PDOException $e2) { $payments_list = []; }
}

// ----- LOGS -----
$logs = [];
try {
    $logs = $pdo->query("SELECT l.*, COALESCE(a.email, CONCAT('Admin #', l.admin_id)) AS email
                         FROM financial_logs l
                         LEFT JOIN admins a ON l.admin_id = a.id
                         ORDER BY l.created_at DESC LIMIT 20")->fetchAll();
} catch (PDOException $e) { $logs = []; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Hub - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --admin-nav: #0B1120; --admin-bg: #F5F7FB; --primary: #6366F1; --primary-dark: #4F46E5; --border: #E5E7EB; --text-dark: #0F172A; --text-muted: #64748B; }
        * { box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: var(--admin-bg); color: #334155; padding-bottom: 50px; -webkit-font-smoothing: antialiased;}

        .navbar { background: linear-gradient(180deg, #0B1120 0%, #111827 100%) !important; padding: 14px 0; box-shadow: 0 4px 20px rgba(0,0,0,0.08); border-bottom: 1px solid rgba(255,255,255,0.05);}
        .navbar-brand { font-weight: 800; color: #fff !important; letter-spacing: -0.5px;}
        .nav-link { color: #94A3B8 !important; font-weight: 500; padding: 8px 16px !important; border-radius: 10px; transition: all 0.2s; font-size: 0.9rem;}
        .nav-link:hover { color: #fff !important; background: rgba(255,255,255,0.06);}
        .nav-link.active { color: #fff !important; background: linear-gradient(135deg, var(--primary), var(--primary-dark)); box-shadow: 0 4px 12px rgba(99,102,241,0.35);}

        .stat-card { background: #fff; border-radius: 16px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.02); border: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; transition: all 0.25s ease; position: relative; overflow: hidden;}
        .stat-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; opacity: 0; transition: opacity 0.25s;}
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 12px 24px -8px rgba(0,0,0,0.08);}
        .stat-card:hover::before { opacity: 1;}
        .stat-card.sc-success::before { background: #10B981;}
        .stat-card.sc-primary::before { background: var(--primary);}
        .stat-card.sc-warning::before { background: #F59E0B;}
        .stat-card.sc-danger::before { background: #EF4444;}
        .stat-card .icon-box { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;}

        .table-wrapper { background: #fff; border-radius: 18px; box-shadow: 0 1px 3px rgba(0,0,0,0.02); border: 1px solid var(--border); overflow: hidden;}
        .table th { background: #F8FAFC; color: var(--text-muted); font-weight: 700; text-transform: uppercase; font-size: 0.68rem; letter-spacing: 0.6px; border-bottom: 1px solid var(--border); padding: 14px 16px;}
        .table td { vertical-align: middle; padding: 14px 16px; border-bottom: 1px solid #F1F5F9; font-size: 0.9rem;}
        .table tbody tr:hover { background: #FAFBFF;}

        .badge-custom { padding: 6px 12px; border-radius: 100px; font-weight: 700; font-size: 0.72rem;}

        .nav-tabs .nav-link { color: var(--text-muted); font-weight: 600; border: none; border-bottom: 3px solid transparent; padding: 12px 22px; background: transparent; transition: 0.3s; font-size: 0.9rem;}
        .nav-tabs .nav-link:hover { color: var(--primary);}
        .nav-tabs .nav-link.active { color: var(--primary); border-bottom: 3px solid var(--primary); background: transparent;}

        .filter-bar { background: #fff; border: 1px solid var(--border); border-radius: 16px; padding: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.02);}
        .filter-bar .form-control, .filter-bar .form-select { border-radius: 10px; border-color: #E2E8F0; font-size: 0.88rem;}
        .filter-bar .form-control:focus, .filter-bar .form-select:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(99,102,241,0.1);}

        .img-preview { max-width: 100%; max-height: 320px; border-radius: 12px; object-fit: contain; border: 2px solid #F1F5F9;}
        .modal-content { border-radius: 18px; border: none;}
        .modal-header { border-bottom: 1px solid #F1F5F9; padding: 20px 24px;}
        .modal-body { padding: 22px 24px;}
        .modal-footer { border-top: 1px solid #F1F5F9; padding: 16px 24px;}

        .btn-primary { background: var(--primary); border: none;}
        .btn-primary:hover { background: var(--primary-dark);}
        .btn-dark { background: #0F172A; border: none;}
        .btn-dark:hover { background: #1E293B;}

        /* Quick action buttons */
        .btn-accept { background: #10B981; color: #fff; border: none; font-weight: 700; transition: all 0.2s;}
        .btn-accept:hover { background: #059669; color: #fff; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(16,185,129,0.35);}
        .btn-reject { background: #FEE2E2; color: #DC2626; border: 1px solid #FECACA; font-weight: 700; transition: all 0.2s;}
        .btn-reject:hover { background: #DC2626; color: #fff; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(220,38,38,0.35);}

        #flash-msg-container { animation: slideDown 0.5s ease forwards;}
        @keyframes slideDown { from { opacity: 0; transform: translateY(-20px);} to { opacity: 1; transform: translateY(0);} }
        .alert { border-radius: 12px; padding: 14px 18px; font-weight: 500;}

        .pagination .page-link { border: none; color: var(--primary); margin: 0 3px; border-radius: 10px; font-weight: 600;}
        .pagination .page-item.active .page-link { background: var(--primary); color: #fff;}
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg sticky-top mb-4">
    <div class="container">
        <a class="navbar-brand" href="dashboard.php"><i class="fa-solid fa-bolt text-warning me-2"></i>ServiceFix</a>
        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#adminNav"><i class="fa-solid fa-bars text-white"></i></button>
        <div class="collapse navbar-collapse" id="adminNav">
            <ul class="navbar-nav ms-auto mb-2 mb-lg-0 gap-1 align-items-center">
                <li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
                <li class="nav-item"><a class="nav-link" href="bookings.php">Bookings</a></li>
                <li class="nav-item"><a class="nav-link" href="mechanics.php">Mechanics</a></li>
                <li class="nav-item"><a class="nav-link active" href="payments.php">Verify Payments</a></li>
                <li class="nav-item"><a class="nav-link" href="settings.php">Settings</a></li>
                <li class="nav-item ms-lg-3"><a href="logout.php" class="btn btn-danger btn-sm px-4 fw-bold rounded-pill">Logout</a></li>
            </ul>
        </div>
    </div>
</nav>

<div class="container pb-5">

    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <h3 class="fw-bold text-dark mb-1">Financial Accounting Hub</h3>
            <p class="text-muted small mb-0">Manage payments, refunds, commissions, and manual entries.</p>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-light border fw-bold text-dark shadow-sm rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#manualEntryModal">
                <i class="fa-solid fa-plus me-1"></i> Manual Entry
            </button>
            <form method="POST" class="m-0">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="export_csv">
                <button type="submit" class="btn btn-dark fw-bold shadow-sm rounded-pill px-3"><i class="fa-solid fa-file-csv me-1"></i> Export Report</button>
            </form>
        </div>
    </div>

    <div id="flash-msg-container"><?php if(isset($_SESSION['flash_message'])) { echo $_SESSION['flash_message']; unset($_SESSION['flash_message']); } ?></div>

    <!-- Stats Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="stat-card sc-success">
                <div>
                    <div class="text-muted small fw-bold text-uppercase" style="letter-spacing:0.5px;">Total Collected</div>
                    <h4 class="fw-bold mb-0 text-success mt-1">₹<?= number_format($stats['collected'], 2) ?></h4>
                </div>
                <div class="icon-box" style="background:#ECFDF5; color:#059669;"><i class="fa-solid fa-vault"></i></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card sc-primary">
                <div>
                    <div class="text-muted small fw-bold text-uppercase" style="letter-spacing:0.5px;">Commission Earned</div>
                    <h4 class="fw-bold mb-0 text-primary mt-1">₹<?= number_format($stats['comm_earned'], 2) ?></h4>
                </div>
                <div class="icon-box" style="background:#EEF2FF; color:#4F46E5;"><i class="fa-solid fa-chart-pie"></i></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card sc-warning">
                <div>
                    <div class="text-muted small fw-bold text-uppercase" style="letter-spacing:0.5px;">Pending Verifications</div>
                    <h4 class="fw-bold mb-0 text-warning mt-1"><?= $stats['pending_count'] ?></h4>
                </div>
                <div class="icon-box" style="background:#FFFBEB; color:#D97706;"><i class="fa-solid fa-hourglass-half"></i></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card sc-danger">
                <div>
                    <div class="text-muted small fw-bold text-uppercase" style="letter-spacing:0.5px;">Total Refunded</div>
                    <h4 class="fw-bold mb-0 text-danger mt-1">₹<?= number_format($stats['refunded'], 2) ?></h4>
                </div>
                <div class="icon-box" style="background:#FEE2E2; color:#DC2626;"><i class="fa-solid fa-money-bill-transfer"></i></div>
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-4" id="financeTabs" role="tablist">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-customers"><i class="fa-solid fa-users me-1"></i> Customer Transactions</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-logs"><i class="fa-solid fa-server me-1"></i> System Logs</button></li>
    </ul>

    <div class="tab-content">
        <!-- CUSTOMER TRANSACTIONS TAB -->
        <div class="tab-pane fade show active" id="tab-customers">

            <!-- Filter Bar -->
            <div class="filter-bar mb-4">
                <form method="GET" class="row g-2 align-items-center">
                    <div class="col-md-4">
                        <input type="text" name="search" class="form-control" placeholder="Search Name, Ref ID, Txn ID..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <div class="col-md-3">
                        <select name="status" class="form-select">
                            <option value="">All Statuses</option>
                            <option value="Pending" <?= $status_filter == 'Pending' ? 'selected' : '' ?>>Pending Verification</option>
                            <option value="Verified" <?= $status_filter == 'Verified' ? 'selected' : '' ?>>Accepted / Verified</option>
                            <option value="Rejected" <?= $status_filter == 'Rejected' ? 'selected' : '' ?>>Rejected</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select name="method" class="form-select">
                            <option value="">All Methods</option>
                            <option value="Online" <?= $method_filter == 'Online' ? 'selected' : '' ?>>Online (QR/UPI)</option>
                            <option value="Cash" <?= $method_filter == 'Cash' ? 'selected' : '' ?>>Cash</option>
                            <option value="Bank Transfer" <?= $method_filter == 'Bank Transfer' ? 'selected' : '' ?>>Bank Transfer</option>
                            <option value="Cheque" <?= $method_filter == 'Cheque' ? 'selected' : '' ?>>Cheque</option>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex gap-2">
                        <button type="submit" class="btn btn-primary w-100 fw-bold rounded-pill">Filter</button>
                        <a href="payments.php" class="btn btn-outline-secondary rounded-pill px-3"><i class="fa-solid fa-rotate-left"></i></a>
                    </div>
                </form>
            </div>

            <!-- Payments Table -->
            <form method="POST" id="bulkForm">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="bulk_action">

                <div class="table-wrapper">
                    <div class="bg-light p-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div class="form-check m-0">
                            <input class="form-check-input" type="checkbox" id="selectAll">
                            <label class="form-check-label fw-bold text-dark small" for="selectAll">Select All</label>
                        </div>
                        <div class="d-flex gap-2">
                            <select name="bulk_type" class="form-select form-select-sm" style="width: 190px;" required>
                                <option value="">Bulk Action...</option>
                                <option value="verify">Accept Selected</option>
                                <option value="reject">Reject Selected</option>
                            </select>
                            <button type="submit" class="btn btn-dark btn-sm fw-bold px-3 rounded-pill" onclick="return confirm('Execute bulk action?');">Apply</button>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th style="width: 40px;"></th>
                                    <th>Ref ID & Date</th>
                                    <th>Customer</th>
                                    <th>Details</th>
                                    <th>Amount</th>
                                    <th>Status & Refund</th>
                                    <th class="text-end" style="min-width: 220px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($payments_list) > 0): ?>
                                    <?php foreach ($payments_list as $p): ?>
                                        <?php
                                            $safe_json = htmlspecialchars(json_encode($p), ENT_QUOTES, 'UTF-8');
                                            $s_badge = 'background:#FEF3C7; color:#92400E;';
                                            if ($p['status'] == 'Verified') $s_badge = 'background:#D1FAE5; color:#065F46;';
                                            if ($p['status'] == 'Rejected') $s_badge = 'background:#FEE2E2; color:#991B1B;';
                                        ?>
                                        <tr>
                                            <td><input class="form-check-input row-check" type="checkbox" name="selected_payments[]" value="<?= $p['booking_id'] ?>"></td>
                                            <td>
                                                <div class="fw-bold text-primary">#<?= htmlspecialchars($p['booking_ref']) ?></div>
                                                <div class="text-muted small"><?= !empty($p['created_at']) ? date('M d, Y', strtotime($p['created_at'])) : '—' ?></div>
                                            </td>
                                            <td>
                                                <div class="fw-semibold text-dark"><?= htmlspecialchars($p['customer_name']) ?></div>
                                                <div class="text-muted small"><?= htmlspecialchars($p['phone'] ?? '') ?></div>
                                            </td>
                                            <td>
                                                <div class="text-dark small"><?= htmlspecialchars($p['service_name'] ?? 'Service') ?></div>
                                                <div class="text-muted small"><i class="fa-solid fa-credit-card me-1"></i><?= htmlspecialchars($p['payment_method'] ?? 'Online') ?></div>
                                            </td>
                                            <td class="fw-bold text-dark">₹<?= number_format($p['amount'], 2) ?></td>
                                            <td>
                                                <span class="badge-custom d-inline-block mb-1" style="<?= $s_badge ?>"><?= htmlspecialchars($p['status']) ?></span>
                                                <?php if(($p['refund_status'] ?? 'None') !== 'None'): ?>
                                                    <span class="badge-custom d-block" style="background:#E2E8F0; color:#475569;"><i class="fa-solid fa-reply me-1"></i><?= htmlspecialchars($p['refund_status']) ?> Refund</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <!-- QUICK ACTION BUTTONS -->
                                                <?php if ($p['status'] === 'Pending'): ?>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                                        <input type="hidden" name="action" value="accept_payment">
                                                        <input type="hidden" name="booking_id" value="<?= (int)$p['booking_id'] ?>">
                                                        <button type="submit" class="btn btn-sm btn-accept rounded-pill px-3" onclick="return confirm('Accept this payment and broadcast the job?');">
                                                            <i class="fa-solid fa-check me-1"></i>Accept
                                                        </button>
                                                    </form>
                                                    <button type="button" class="btn btn-sm btn-reject rounded-pill px-3 ms-1" data-bs-toggle="modal" data-bs-target="#rejectModal<?= (int)$p['booking_id'] ?>">
                                                        <i class="fa-solid fa-xmark me-1"></i>Reject
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-light border text-primary fw-bold rounded-pill ms-1" onclick='openDetailModal(<?= $safe_json ?>)'>
                                                        <i class="fa-solid fa-gear"></i>
                                                    </button>

                                                    <!-- REJECT MODAL -->
                                                    <div class="modal fade" id="rejectModal<?= (int)$p['booking_id'] ?>" tabindex="-1">
                                                        <div class="modal-dialog modal-dialog-centered">
                                                            <div class="modal-content">
                                                                <div class="modal-header bg-danger bg-opacity-10">
                                                                    <h5 class="modal-title fw-bold text-danger">
                                                                        <i class="fa-solid fa-circle-xmark me-2"></i>Reject Payment
                                                                    </h5>
                                                                    <button class="btn-close" data-bs-dismiss="modal"></button>
                                                                </div>
                                                                <form method="POST">
                                                                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                                                    <input type="hidden" name="action" value="reject_payment">
                                                                    <input type="hidden" name="booking_id" value="<?= (int)$p['booking_id'] ?>">
                                                                    <div class="modal-body">
                                                                        <p class="small text-muted mb-3">
                                                                            Rejecting payment for <strong>#<?= htmlspecialchars($p['booking_ref']) ?></strong>
                                                                            (₹<?= number_format($p['amount'], 2) ?>).
                                                                            The customer will be notified.
                                                                        </p>
                                                                        <label class="form-label small fw-bold">Rejection Reason *</label>
                                                                        <textarea name="rejection_reason" class="form-control" rows="3" placeholder="e.g., Payment proof invalid, Amount mismatch, Duplicate transaction..." required></textarea>
                                                                    </div>
                                                                    <div class="modal-footer">
                                                                        <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                                                                        <button type="submit" class="btn btn-danger fw-bold rounded-pill px-4">
                                                                            <i class="fa-solid fa-xmark me-1"></i>Confirm Rejection
                                                                        </button>
                                                                    </div>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    </div>

                                                <?php elseif ($p['status'] === 'Verified'): ?>
                                                    <span class="badge-custom" style="background:#D1FAE5; color:#065F46;">
                                                        <i class="fa-solid fa-circle-check me-1"></i>Accepted
                                                    </span>
                                                    <button type="button" class="btn btn-sm btn-light border text-primary fw-bold rounded-pill ms-1" onclick='openDetailModal(<?= $safe_json ?>)'>
                                                        <i class="fa-solid fa-gear"></i>
                                                    </button>

                                                <?php else: ?>
                                                    <span class="badge-custom" style="background:#FEE2E2; color:#991B1B;">
                                                        <i class="fa-solid fa-circle-xmark me-1"></i>Rejected
                                                    </span>
                                                    <button type="button" class="btn btn-sm btn-light border text-primary fw-bold rounded-pill ms-1" onclick='openDetailModal(<?= $safe_json ?>)'>
                                                        <i class="fa-solid fa-gear"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="7" class="text-center py-5 text-muted"><i class="fa-solid fa-folder-open fs-2 opacity-25 d-block mb-2"></i>No transactions found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </form>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <nav class="mt-4">
                    <ul class="pagination justify-content-center">
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?= ($i == $page) ? 'active' : '' ?>">
                                <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status_filter) ?>&method=<?= urlencode($method_filter) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>

        <!-- SYSTEM LOGS TAB -->
        <div class="tab-pane fade" id="tab-logs">
            <div class="table-wrapper">
                <table class="table table-hover mb-0 align-middle">
                    <thead>
                        <tr><th>Timestamp</th><th>Administrator</th><th>Action</th><th>Details</th></tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($logs)): ?>
                            <?php foreach($logs as $log): ?>
                                <tr>
                                    <td class="text-muted small"><?= date('d M Y, h:i A', strtotime($log['created_at'])) ?></td>
                                    <td class="fw-semibold text-dark small"><?= htmlspecialchars($log['email']) ?></td>
                                    <td>
                                        <?php
                                            $actionColor = 'background:#E2E8F0; color:#475569;';
                                            if (strpos($log['action_type'], 'Accept') !== false || strpos($log['action_type'], 'Verify') !== false) $actionColor = 'background:#D1FAE5; color:#065F46;';
                                            if (strpos($log['action_type'], 'Reject') !== false) $actionColor = 'background:#FEE2E2; color:#991B1B;';
                                            if (strpos($log['action_type'], 'Refund') !== false) $actionColor = 'background:#CFFAFE; color:#155E75;';
                                        ?>
                                        <span class="badge-custom" style="<?= $actionColor ?>"><?= htmlspecialchars($log['action_type']) ?></span>
                                    </td>
                                    <td class="small text-muted"><?= htmlspecialchars($log['details']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="4" class="text-center py-5 text-muted"><i class="fa-solid fa-server fs-2 opacity-25 d-block mb-2"></i>No activity logs yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- =============== DETAIL MODAL =============== -->
<div class="modal fade" id="detailModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content shadow-lg">
      <div class="modal-header bg-light">
        <h5 class="modal-title fw-bold text-dark">Transaction Management <span id="mod_ref" class="text-primary ms-2"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-4">
            <div class="col-md-6 text-center">
                <div class="bg-light p-3 rounded-4 border h-100 d-flex flex-column justify-content-center">
                    <span class="small fw-bold text-muted text-uppercase mb-2">Attached Evidence</span>
                    <img id="mod_img" src="" class="img-preview shadow-sm mx-auto" alt="Proof">
                    <div id="mod_no_img" class="text-muted py-5 d-none"><i class="fa-solid fa-receipt fs-1 opacity-25 mb-2 d-block"></i>No visual proof (Offline/API)</div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="mb-4">
                    <h2 class="fw-bold text-dark mb-0" id="mod_amount"></h2>
                    <span class="badge-custom mt-2 d-inline-block" style="background:#E2E8F0; color:#475569;" id="mod_method"></span>
                </div>

                <p class="mb-1 small"><strong class="text-dark">Customer:</strong> <span id="mod_cust" class="text-muted"></span></p>
                <p class="mb-1 small"><strong class="text-dark">Txn ID:</strong> <span id="mod_txn" class="text-muted"></span></p>
                <p class="mb-3 small"><strong class="text-dark">Date:</strong> <span id="mod_date" class="text-muted"></span></p>

                <div id="action_area">
                    <form method="POST" class="mb-2">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <input type="hidden" name="action" value="accept_payment">
                        <input type="hidden" name="booking_id" id="mod_verify_id">
                        <button type="submit" class="btn btn-success w-100 fw-bold py-2 rounded-pill">
                            <i class="fa-solid fa-check-double me-2"></i> Accept & Broadcast Job
                        </button>
                    </form>

                    <button class="btn btn-outline-danger w-100 fw-bold py-2 rounded-pill" type="button" data-bs-toggle="collapse" data-bs-target="#rejectBox">
                        <i class="fa-solid fa-xmark me-2"></i> Reject Transaction
                    </button>

                    <div class="collapse mt-2" id="rejectBox">
                        <form method="POST" class="bg-danger bg-opacity-10 p-3 rounded-3 border border-danger border-opacity-25">
                            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                            <input type="hidden" name="action" value="reject_payment">
                            <input type="hidden" name="booking_id" id="mod_reject_id">
                            <textarea name="rejection_reason" class="form-control mb-2" placeholder="Provide reason for customer..." required></textarea>
                            <button type="submit" class="btn btn-danger btn-sm w-100 fw-bold rounded-pill">Confirm Rejection</button>
                        </form>
                    </div>
                </div>

                <div id="refund_area" class="mt-4 pt-3 border-top d-none">
                    <h6 class="fw-bold text-dark small text-uppercase">Issue Refund</h6>
                    <form method="POST" class="d-flex gap-2">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <input type="hidden" name="action" value="issue_refund">
                        <input type="hidden" name="payment_id" id="mod_refund_id">
                        <select name="refund_type" class="form-select form-select-sm">
                            <option value="Full">Full Refund</option>
                            <option value="Partial">Partial Refund</option>
                        </select>
                        <button type="submit" class="btn btn-dark btn-sm fw-bold px-3 rounded-pill">Process</button>
                    </form>
                </div>
            </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- =============== MANUAL ENTRY MODAL =============== -->
<div class="modal fade" id="manualEntryModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content shadow-lg">
      <div class="modal-header bg-light">
        <h5 class="modal-title fw-bold text-dark"><i class="fa-solid fa-pen-to-square text-primary me-2"></i>Manual Payment Entry</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" class="px-2">
        <div class="modal-body">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <input type="hidden" name="action" value="manual_entry">

            <div class="mb-3">
                <label class="form-label small fw-bold">Booking Reference ID</label>
                <input type="text" name="booking_ref" class="form-control" placeholder="e.g. BKG-123456" required>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-bold">Amount Collected (₹)</label>
                <input type="number" step="0.01" name="amount" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-bold">Payment Method</label>
                <select name="payment_method" class="form-select" required>
                    <option value="Cash">Cash</option>
                    <option value="Bank Transfer">Bank Transfer</option>
                    <option value="Cheque">Cheque</option>
                </select>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-dark fw-bold px-4 rounded-pill">Record & Broadcast Job</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.getElementById('selectAll')?.addEventListener('change', function() {
        document.querySelectorAll('.row-check').forEach(cb => cb.checked = this.checked);
    });

    setTimeout(() => {
        const f = document.getElementById('flash-msg-container');
        if (f && f.innerHTML.trim() !== '') {
            f.style.transition = 'opacity .5s';
            f.style.opacity = '0';
            setTimeout(() => f.innerHTML = '', 500);
        }
    }, 4000);

    function openDetailModal(data) {
        document.getElementById('mod_ref').innerText = '#' + (data.booking_ref || data.booking_id);
        document.getElementById('mod_amount').innerText = '₹' + parseFloat(data.amount || 0).toFixed(2);
        document.getElementById('mod_method').innerText = data.payment_method || 'Online';
        document.getElementById('mod_cust').innerText = (data.customer_name || 'N/A') + ' (' + (data.phone || '') + ')';
        document.getElementById('mod_txn').innerText = data.transaction_id || 'N/A';
        document.getElementById('mod_date').innerText = data.created_at ? new Date(data.created_at).toLocaleString() : '—';

        let img = document.getElementById('mod_img');
        let noImg = document.getElementById('mod_no_img');
        if (data.proof_file_path) {
            img.src = '../' + data.proof_file_path;
            img.classList.remove('d-none');
            noImg.classList.add('d-none');
        } else {
            img.classList.add('d-none');
            noImg.classList.remove('d-none');
        }

        let actionArea = document.getElementById('action_area');
        let refundArea = document.getElementById('refund_area');

        document.getElementById('mod_verify_id').value = data.booking_id;
        document.getElementById('mod_reject_id').value = data.booking_id;
        document.getElementById('mod_refund_id').value = data.id;

        if (data.status === 'Pending') {
            actionArea.classList.remove('d-none');
            refundArea.classList.add('d-none');
        } else if (data.status === 'Verified') {
            actionArea.classList.add('d-none');
            refundArea.classList.remove('d-none');
        } else {
            actionArea.classList.add('d-none');
            refundArea.classList.add('d-none');
        }

        new bootstrap.Modal(document.getElementById('detailModal')).show();
    }
</script>
</body>
</html>