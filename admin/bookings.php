<?php
// htdocs/admin/bookings.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/db.php';
require_once '../config/security.php';

// Secure session check
if (!is_admin_logged_in()) {
    header("Location: index.php");
    exit;
}

$admin_id = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 1;

// ==========================================
// 1. SELF-HEALING DATABASE ROUTINE
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
    // Core self-healing tables
    // Auto-add created_at columns where missing
    ensure_column($pdo, 'bookings', 'created_at',    "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
    ensure_column($pdo, 'payments', 'created_at',    "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
    ensure_column($pdo, 'users',    'created_at',    "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");

    // Other self-healing columns
    ensure_column($pdo, 'bookings', 'cancel_reason', "TEXT NULL");
    ensure_column($pdo, 'bookings', 'assigned_at',   "DATETIME NULL");
    ensure_column($pdo, 'payments', 'refund_status', "VARCHAR(50) DEFAULT 'None'");
    ensure_column($pdo, 'payments', 'proof_file_path', "VARCHAR(255) NULL");

    // Default commission setting
    try {
        $pdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('commission_rate', '15.0')");
    } catch (PDOException $e) { /* ignore */ }
} catch (PDOException $e) {
    error_log("Schema upgrade error: " . $e->getMessage());
}

// Fetch Commission Rate (safe)
$admin_comm_rate = 15.0;
try {
    $val = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'commission_rate'")->fetchColumn();
    if ($val !== false && $val !== null) $admin_comm_rate = (float)$val;
} catch (PDOException $e) { /* keep default */ }
$mechanic_share_rate = 100 - $admin_comm_rate;

// ==========================================
// 2. HANDLE POST ACTIONS & EXPORTS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['flash_message'] = "<div class='alert alert-danger shadow-sm border-0 rounded-3'>Security token expired.</div>";
    } else {
        $action = $_POST['action'];

        try {
            // --- EXPORT CSV ---
            if ($action === 'export_csv') {
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename=bookings_report_' . date('Y-m-d') . '.csv');
                $output = fopen('php://output', 'w');
                fputcsv($output, ['Booking Ref', 'Date', 'Customer', 'Phone', 'Service', 'Mechanic', 'Status', 'Job Value']);

                try {
                    $export_stmt = $pdo->query("
                        SELECT b.booking_ref, b.created_at, u.name, u.phone, c.name, m.name, b.status, p.amount
                        FROM bookings b
                        JOIN users u ON b.user_id = u.id
                        LEFT JOIN categories c ON b.category_id = c.id
                        LEFT JOIN users m ON b.mechanic_id = m.id
                        LEFT JOIN payments p ON b.id = p.booking_id
                    ");
                } catch (PDOException $e) {
                    // Fallback without categories / mechanics
                    $export_stmt = $pdo->query("
                        SELECT b.booking_ref, b.created_at, u.name, u.phone, '' AS category, '' AS mechanic, b.status, p.amount
                        FROM bookings b
                        JOIN users u ON b.user_id = u.id
                        LEFT JOIN payments p ON b.id = p.booking_id
                    ");
                }
                while ($row = $export_stmt->fetch(PDO::FETCH_ASSOC)) { fputcsv($output, $row); }
                fclose($output);
                exit;
            }

            $booking_id = isset($_POST['booking_id']) ? (int)$_POST['booking_id'] : 0;

            // --- VERIFY PAYMENT (TRIGGER BROADCAST) ---
            if ($action === 'verify_payment') {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE payments SET status = 'Verified' WHERE booking_id = ?")->execute([$booking_id]);
                $pdo->prepare("UPDATE bookings SET status = 'Pending Assignment' WHERE id = ?")->execute([$booking_id]);
                $pdo->prepare("INSERT INTO booking_history (booking_id, status_updated_to, notes) VALUES (?, 'Pending Assignment', 'Payment verified. Job broadcasted to mechanics.')")->execute([$booking_id]);
                $pdo->commit();
                $_SESSION['flash_message'] = "<div class='alert alert-success shadow-sm border-0 rounded-3'>Payment verified. Job broadcasted!</div>";
            }

            // --- MANUAL ASSIGNMENT ---
            elseif ($action === 'assign_mechanic') {
                $mechanic_id = (int)$_POST['mechanic_id'];
                $pdo->prepare("UPDATE bookings SET status = 'Assigned', mechanic_id = ?, assigned_at = NOW() WHERE id = ?")->execute([$mechanic_id, $booking_id]);
                $pdo->prepare("INSERT INTO booking_history (booking_id, status_updated_to, notes) VALUES (?, 'Assigned', ?)")->execute([$booking_id, "Admin manually assigned mechanic ID: $mechanic_id."]);
                $_SESSION['flash_message'] = "<div class='alert alert-success shadow-sm border-0 rounded-3'>Mechanic assigned manually.</div>";
            }

            // --- CANCEL BOOKING & REFUND ---
            elseif ($action === 'cancel_booking') {
                $reason = clean_input($_POST['cancel_reason']);
                $issue_refund = isset($_POST['issue_refund']) ? 1 : 0;

                $pdo->beginTransaction();
                $pdo->prepare("UPDATE bookings SET status = 'Cancelled', cancel_reason = ? WHERE id = ?")->execute([$reason, $booking_id]);
                $pdo->prepare("INSERT INTO booking_history (booking_id, status_updated_to, notes) VALUES (?, 'Cancelled', ?)")->execute([$booking_id, "Cancelled by Admin: $reason"]);

                if ($issue_refund) {
                    $pdo->prepare("UPDATE payments SET refund_status = 'Full' WHERE booking_id = ?")->execute([$booking_id]);
                    $pdo->prepare("INSERT INTO booking_history (booking_id, status_updated_to, notes) VALUES (?, 'Refund Processing', 'Full refund initiated.')")->execute([$booking_id]);
                }
                $pdo->commit();
                $_SESSION['flash_message'] = "<div class='alert alert-warning shadow-sm border-0 rounded-3'>Booking cancelled successfully.</div>";
            }

            // --- BULK ACTIONS ---
            elseif ($action === 'bulk_action' && !empty($_POST['selected_bookings'])) {
                $bulk_type = $_POST['bulk_type'];
                $ids = array_map('intval', $_POST['selected_bookings']);
                $id_list = implode(',', $ids);

                if ($bulk_type === 'verify') {
                    $pdo->exec("UPDATE payments SET status = 'Verified' WHERE booking_id IN ($id_list) AND status = 'Pending'");
                    $pdo->exec("UPDATE bookings SET status = 'Pending Assignment' WHERE id IN ($id_list) AND status = 'Payment Verification Pending'");
                    $_SESSION['flash_message'] = "<div class='alert alert-success shadow-sm border-0 rounded-3'>Bulk verification completed.</div>";
                } elseif ($bulk_type === 'cancel') {
                    $pdo->exec("UPDATE bookings SET status = 'Cancelled', cancel_reason = 'Bulk Cancellation by Admin' WHERE id IN ($id_list)");
                    $_SESSION['flash_message'] = "<div class='alert alert-warning shadow-sm border-0 rounded-3'>Bulk cancellation completed.</div>";
                }
            }

        } catch (PDOException $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['flash_message'] = "<div class='alert alert-danger shadow-sm border-0 rounded-3'>Error: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
    header("Location: bookings.php");
    exit;
}

$message = '';
if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
}

// ==========================================
// 3. PAGINATION, SEARCH & FILTERS
// ==========================================
$limit = 15;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? clean_input($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? clean_input($_GET['status']) : '';
$date_filter = isset($_GET['date']) ? clean_input($_GET['date']) : '';

$where_clauses = ["1=1"];
$params = [];

if (!empty($search)) {
    $where_clauses[] = "(u.name LIKE ? OR u.phone LIKE ? OR b.booking_ref LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
if (!empty($status_filter)) {
    $where_clauses[] = "b.status = ?";
    $params[] = $status_filter;
}
if (!empty($date_filter)) {
    $where_clauses[] = "DATE(b.created_at) = ?";
    $params[] = $date_filter;
}
$where_sql = implode(" AND ", $where_clauses);

// --- SAFE DROPDOWN DATA ---
$mechanics = [];
try {
    $mechanics = $pdo->query("SELECT id, name FROM users WHERE role = 'mechanic' AND (is_banned = 0 OR is_banned IS NULL)")->fetchAll();
} catch (PDOException $e) {
    try {
        $mechanics = $pdo->query("SELECT id, name FROM users WHERE role = 'mechanic'")->fetchAll();
    } catch (PDOException $e2) { $mechanics = []; }
}

// --- SAFE STATS ---
$stats = ['total'=>0,'pay_pending'=>0,'assign_pending'=>0,'active'=>0,'completed'=>0,'cancelled'=>0,'earnings'=>0];
try {
    $stats['total']          = $pdo->query("SELECT COUNT(*) FROM bookings")->fetchColumn() ?: 0;
    $stats['pay_pending']    = $pdo->query("SELECT COUNT(*) FROM bookings WHERE status = 'Payment Verification Pending'")->fetchColumn() ?: 0;
    $stats['assign_pending'] = $pdo->query("SELECT COUNT(*) FROM bookings WHERE status = 'Pending Assignment'")->fetchColumn() ?: 0;
    $stats['active']         = $pdo->query("SELECT COUNT(*) FROM bookings WHERE status IN ('Assigned','Accepted','In Progress','Work Completed (Admin Verification Pending)')")->fetchColumn() ?: 0;
    $stats['completed']      = $pdo->query("SELECT COUNT(*) FROM bookings WHERE status = 'Completed'")->fetchColumn() ?: 0;
    $stats['cancelled']      = $pdo->query("SELECT COUNT(*) FROM bookings WHERE status = 'Cancelled'")->fetchColumn() ?: 0;
    $stats['earnings']       = $pdo->query("SELECT SUM(p.amount * $admin_comm_rate / 100) FROM bookings b JOIN payments p ON b.id = p.booking_id WHERE b.status = 'Completed'")->fetchColumn() ?: 0;
} catch (PDOException $e) { /* keep defaults */ }

// --- SAFE PAGINATION COUNT ---
$total_pages = 1;
try {
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM bookings b LEFT JOIN users u ON b.user_id = u.id WHERE $where_sql");
    $count_stmt->execute($params);
    $total_pages = max(1, ceil($count_stmt->fetchColumn() / $limit));
} catch (PDOException $e) { $total_pages = 1; }

// --- SAFE BOOKINGS LIST (with fallback chain) ---
$bookings_list = [];
try {
    // Try full query with categories/problems
    $query = "
        SELECT b.*,
        COALESCE(b.contact_name, u.name) as customer_name,
        COALESCE(b.contact_phone, u.phone) as customer_phone,
        c.name as category_name,
        p.problem_name,
        m.name as mechanic_name,
        pay.amount as job_amount,
        pay.status as payment_status,
        pay.refund_status,
        pay.proof_file_path as payment_proof
        FROM bookings b
        JOIN users u ON b.user_id = u.id
        LEFT JOIN categories c ON b.category_id = c.id
        LEFT JOIN problems p ON b.problem_id = p.id
        LEFT JOIN users m ON b.mechanic_id = m.id
        LEFT JOIN payments pay ON b.id = pay.booking_id
        WHERE $where_sql
        ORDER BY b.created_at DESC
        LIMIT $limit OFFSET $offset
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $bookings_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Fallback without categories / problems
    try {
        $query = "
            SELECT b.*,
            COALESCE(b.contact_name, u.name) as customer_name,
            COALESCE(b.contact_phone, u.phone) as customer_phone,
            'Service' as category_name,
            NULL as problem_name,
            m.name as mechanic_name,
            pay.amount as job_amount,
            pay.status as payment_status,
            pay.refund_status,
            pay.proof_file_path as payment_proof
            FROM bookings b
            JOIN users u ON b.user_id = u.id
            LEFT JOIN users m ON b.mechanic_id = m.id
            LEFT JOIN payments pay ON b.id = pay.booking_id
            WHERE $where_sql
            ORDER BY b.created_at DESC
            LIMIT $limit OFFSET $offset
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $bookings_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e2) {
        $bookings_list = [];
    }
}

// Attach history to each booking (safe)
foreach ($bookings_list as &$bk) {
    $bk['history'] = [];
    try {
        $hist_stmt = $pdo->prepare("SELECT status_updated_to, notes, created_at FROM booking_history WHERE booking_id = ? ORDER BY created_at DESC");
        $hist_stmt->execute([$bk['id']]);
        $bk['history'] = $hist_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { /* ignore */ }
}
unset($bk);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Management - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --admin-nav: #0F172A; --admin-bg: #F1F5F9; --primary: #4F46E5; }
        body { font-family: 'Inter', sans-serif; background-color: var(--admin-bg); color: #334155; padding-bottom: 50px;}

        .navbar { background-color: var(--admin-nav) !important; padding: 15px 0; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .navbar-brand { font-weight: 800; color: #fff !important; letter-spacing: -0.5px;}
        .nav-link { color: #94A3B8 !important; font-weight: 500; padding: 8px 16px !important; border-radius: 8px; transition: 0.3s;}
        .nav-link:hover, .nav-link.active { background-color: rgba(79, 70, 229, 0.2); color: #fff !important; }

        .stat-card { background: #fff; border-radius: 16px; padding: 20px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); border: 1px solid #E2E8F0; text-align: center;}

        .table-wrapper { background: #fff; border-radius: 20px; padding: 24px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); border: 1px solid #E2E8F0; }
        .table th { background: #F8FAFC; color: #64748B; font-weight: 600; text-transform: uppercase; font-size: 0.75rem; border-bottom: 2px solid #E2E8F0; padding: 16px;}
        .table td { vertical-align: middle; padding: 16px; border-bottom: 1px solid #F1F5F9; font-size: 0.95rem;}

        .badge-custom { padding: 6px 12px; border-radius: 20px; font-weight: 600; font-size: 0.75rem; }

        .nav-tabs .nav-link { color: #64748B; font-weight: 600; border: none; border-bottom: 3px solid transparent; padding: 12px 20px; background: transparent; transition: 0.3s;}
        .nav-tabs .nav-link.active { color: var(--primary); border-bottom: 3px solid var(--primary); background: transparent;}

        .timeline-item { position: relative; padding-left: 20px; margin-bottom: 15px; border-left: 2px solid #E2E8F0; }
        .timeline-item::before { content: ''; position: absolute; left: -6px; top: 0; width: 10px; height: 10px; border-radius: 50%; background: var(--primary); }

        #flash-msg-container { animation: slideDown 0.5s ease forwards; }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg sticky-top mb-4">
    <div class="container">
        <a class="navbar-brand" href="dashboard.php"><i class="fa-solid fa-bolt text-warning me-2"></i>ServiceFix</a>
        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#adminNav"><i class="fa-solid fa-bars text-white"></i></button>
        <div class="collapse navbar-collapse" id="adminNav">
            <ul class="navbar-nav ms-auto mb-2 mb-lg-0 gap-2">
                <li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
                <li class="nav-item"><a class="nav-link active" href="bookings.php">Bookings</a></li>
                <li class="nav-item"><a class="nav-link" href="mechanics.php">Mechanics</a></li>
                <li class="nav-item"><a class="nav-link" href="payments.php">Verify Payments</a></li>
                <li class="nav-item"><a class="nav-link" href="settings.php">Settings</a></li>
                <li class="nav-item ms-lg-3"><a href="logout.php" class="btn btn-danger btn-sm mt-1 px-4 fw-bold rounded-pill">Logout</a></li>
            </ul>
        </div>
    </div>
</nav>

<div class="container pb-5">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <h3 class="fw-bold text-dark mb-1">Booking Control Center</h3>
            <p class="text-muted small mb-0">Track, verify, assign, and manage the complete service lifecycle.</p>
        </div>
        <form method="POST" class="m-0">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <input type="hidden" name="action" value="export_csv">
            <button type="submit" class="btn btn-dark fw-bold rounded-pill shadow-sm"><i class="fa-solid fa-file-csv me-1"></i> Export Data</button>
        </form>
    </div>

    <div id="flash-msg-container"><?= $message ?></div>

    <!-- Stats Dashboard -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-2"><div class="stat-card"><div class="text-muted small fw-bold text-uppercase">Total</div><h4 class="fw-bold text-dark mb-0"><?= $stats['total'] ?></h4></div></div>
        <div class="col-6 col-md-2"><div class="stat-card"><div class="text-muted small fw-bold text-uppercase">Verify Pay</div><h4 class="fw-bold text-warning mb-0"><?= $stats['pay_pending'] ?></h4></div></div>
        <div class="col-6 col-md-2"><div class="stat-card"><div class="text-muted small fw-bold text-uppercase">Dispatch</div><h4 class="fw-bold text-info mb-0"><?= $stats['assign_pending'] ?></h4></div></div>
        <div class="col-6 col-md-2"><div class="stat-card"><div class="text-muted small fw-bold text-uppercase">Active</div><h4 class="fw-bold text-primary mb-0"><?= $stats['active'] ?></h4></div></div>
        <div class="col-6 col-md-2"><div class="stat-card"><div class="text-muted small fw-bold text-uppercase">Completed</div><h4 class="fw-bold text-success mb-0"><?= $stats['completed'] ?></h4></div></div>
        <div class="col-6 col-md-2"><div class="stat-card"><div class="text-muted small fw-bold text-uppercase">Comm. Earned</div><h4 class="fw-bold text-success mb-0">₹<?= number_format($stats['earnings']) ?></h4></div></div>
    </div>

    <!-- Filters -->
    <div class="table-wrapper mb-4 p-3 bg-light border-0">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-md-4">
                <input type="text" name="search" class="form-control" placeholder="Search Ref ID, Customer, Phone..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select">
                    <option value="">All Statuses</option>
                    <option value="Payment Verification Pending" <?= $status_filter == 'Payment Verification Pending' ? 'selected' : '' ?>>Verification Pending</option>
                    <option value="Pending Assignment" <?= $status_filter == 'Pending Assignment' ? 'selected' : '' ?>>Pending Assignment (Broadcast)</option>
                    <option value="Assigned" <?= $status_filter == 'Assigned' ? 'selected' : '' ?>>Assigned</option>
                    <option value="Accepted" <?= $status_filter == 'Accepted' ? 'selected' : '' ?>>Accepted</option>
                    <option value="In Progress" <?= $status_filter == 'In Progress' ? 'selected' : '' ?>>In Progress</option>
                    <option value="Completed" <?= $status_filter == 'Completed' ? 'selected' : '' ?>>Completed</option>
                    <option value="Cancelled" <?= $status_filter == 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>
            <div class="col-md-3">
                <input type="date" name="date" class="form-control" value="<?= htmlspecialchars($date_filter) ?>">
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-dark w-100 fw-medium">Filter</button>
                <a href="bookings.php" class="btn btn-outline-secondary px-3"><i class="fa-solid fa-rotate-left"></i></a>
            </div>
        </form>
    </div>

    <!-- Main Table -->
    <form method="POST" id="bulkForm">
        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
        <input type="hidden" name="action" value="bulk_action">

        <div class="table-wrapper p-0 overflow-hidden">
            <div class="bg-light p-3 border-bottom d-flex justify-content-between align-items-center">
                <div class="form-check m-0">
                    <input class="form-check-input" type="checkbox" id="selectAll">
                    <label class="form-check-label fw-bold text-dark small" for="selectAll">Select All</label>
                </div>
                <div class="d-flex gap-2">
                    <select name="bulk_type" class="form-select form-select-sm" style="width: 150px;" required>
                        <option value="">Bulk Action...</option>
                        <option value="verify">Verify Payments</option>
                        <option value="cancel">Cancel Bookings</option>
                    </select>
                    <button type="submit" class="btn btn-dark btn-sm fw-bold px-3" onclick="return confirm('Execute bulk action?');">Apply</button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead>
                        <tr>
                            <th></th>
                            <th>Ref ID & Date</th>
                            <th>Customer Details</th>
                            <th>Service & Value</th>
                            <th>Partner Assigned</th>
                            <th>Current Status</th>
                            <th class="text-end">Manage</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($bookings_list) > 0): ?>
                            <?php foreach ($bookings_list as $b): ?>
                                <?php
                                    $safe_json = htmlspecialchars(json_encode($b), ENT_QUOTES, 'UTF-8');

                                    $badge = 'bg-secondary';
                                    if ($b['status'] == 'Payment Verification Pending') $badge = 'bg-warning text-dark';
                                    if ($b['status'] == 'Pending Assignment') $badge = 'bg-info text-dark';
                                    if (in_array($b['status'], ['In Progress','Assigned','Accepted'])) $badge = 'bg-primary';
                                    if ($b['status'] == 'Completed') $badge = 'bg-success';
                                    if ($b['status'] == 'Cancelled') $badge = 'bg-danger';
                                ?>
                                <tr>
                                    <td><input class="form-check-input row-check" type="checkbox" name="selected_bookings[]" value="<?= $b['id'] ?>"></td>
                                    <td>
                                        <div class="fw-bold text-primary">#<?= htmlspecialchars($b['booking_ref'] ?? $b['id']) ?></div>
                                        <div class="text-muted small"><?= !empty($b['created_at']) ? date('M d, y', strtotime($b['created_at'])) : '—' ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-dark"><?= htmlspecialchars($b['customer_name'] ?? 'N/A') ?></div>
                                        <div class="text-muted small"><?= htmlspecialchars($b['customer_phone'] ?? '') ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-medium text-dark"><?= htmlspecialchars($b['category_name'] ?? 'Service') ?></div>
                                        <div class="fw-bold text-success small">₹<?= number_format($b['job_amount'] ?? 0, 2) ?></div>
                                    </td>
                                    <td>
                                        <?php if(!empty($b['mechanic_name'])): ?>
                                            <span class="badge bg-light text-dark border fw-medium"><i class="fa-solid fa-wrench text-muted me-1"></i> <?= htmlspecialchars($b['mechanic_name']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted small fst-italic">Unassigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge <?= $badge ?> rounded-pill px-2 py-1"><?= htmlspecialchars($b['status']) ?></span></td>
                                    <td class="text-end">
                                        <button type="button" class="btn btn-light border btn-sm text-primary fw-bold rounded-pill px-3" onclick='openDossier(<?= $safe_json ?>)'><i class="fa-solid fa-folder-open me-1"></i> Dossier</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="7" class="text-center py-5 text-muted"><i class="fa-solid fa-folder-open fs-1 opacity-25 d-block mb-3"></i>No bookings found matching your criteria.</td></tr>
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
                        <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status_filter) ?>&date=<?= urlencode($date_filter) ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
    <?php endif; ?>
</div>

<!-- OMNI-MODAL: Booking Dossier -->
<div class="modal fade" id="dossierModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-xl">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 20px; overflow:hidden;">
      <div class="modal-header bg-dark text-white border-0 px-4 pt-4 d-flex justify-content-between">
        <h5 class="modal-title fw-bold">Booking Dossier <span id="dos_ref" class="ms-2 text-info"></span></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>

      <ul class="nav nav-tabs px-4 pt-3 bg-light border-bottom-0" id="dosTabs" role="tablist">
          <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-info">Core Details & Actions</button></li>
          <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-finance">Financials & Commission</button></li>
          <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-history">Activity Timeline</button></li>
      </ul>

      <div class="modal-body bg-light px-4 py-4 tab-content">

          <!-- TAB 1: CORE DETAILS & ACTIONS -->
          <div class="tab-pane fade show active" id="tab-info">
              <div class="row g-4">
                  <div class="col-md-6">
                      <div class="bg-white p-4 rounded-4 shadow-sm border h-100">
                          <div class="d-flex justify-content-between border-bottom pb-3 mb-3">
                              <h6 class="fw-bold text-dark mb-0">Client Profile</h6>
                              <span class="badge bg-secondary rounded-pill" id="dos_status"></span>
                          </div>
                          <p class="mb-1"><i class="fa-solid fa-user text-primary me-2"></i> <strong class="text-dark" id="dos_cust"></strong></p>
                          <p class="mb-1"><i class="fa-solid fa-phone text-primary me-2"></i> <span id="dos_phone" class="text-muted"></span></p>
                          <p class="mb-3"><i class="fa-solid fa-location-dot text-primary me-2"></i> <span id="dos_address" class="text-muted small"></span></p>

                          <h6 class="fw-bold text-dark border-bottom pb-2 mt-4 mb-3">Service Scope</h6>
                          <p class="mb-1"><strong class="text-dark">Category:</strong> <span id="dos_cat" class="text-primary fw-medium"></span></p>
                          <p class="mb-3"><strong class="text-dark">Reported Issue:</strong> <span id="dos_prob" class="text-danger fw-medium"></span></p>

                          <div class="bg-light p-3 rounded-3 border">
                              <h6 class="fw-bold text-muted small text-uppercase mb-2"><i class="fa-solid fa-helmet-safety text-secondary me-2"></i> Partner Alignment</h6>
                              <div class="small fw-bold text-dark" id="dos_mech"></div>
                          </div>
                      </div>
                  </div>

                  <div class="col-md-6">
                      <div class="bg-white p-4 rounded-4 shadow-sm border h-100">
                          <h6 class="fw-bold text-dark mb-3 border-bottom pb-2">Administrative Controls</h6>

                          <div id="action_verify" class="mb-3 d-none">
                              <form method="POST" class="bg-warning bg-opacity-10 p-3 rounded-3 border border-warning">
                                  <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                  <input type="hidden" name="action" value="verify_payment">
                                  <input type="hidden" name="booking_id" class="dos_booking_id">
                                  <p class="small fw-bold text-dark mb-2">Customer payment is pending verification.</p>
                                  <button type="submit" class="btn btn-success w-100 fw-bold rounded-pill"><i class="fa-solid fa-satellite-dish me-2"></i> Verify & Broadcast to Partners</button>
                              </form>
                          </div>

                          <div id="action_assign" class="mb-3 d-none">
                              <form method="POST" class="bg-info bg-opacity-10 p-3 rounded-3 border border-info">
                                  <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                  <input type="hidden" name="action" value="assign_mechanic">
                                  <input type="hidden" name="booking_id" class="dos_booking_id">
                                  <label class="form-label small fw-bold text-dark">Manual Dispatch / Override</label>
                                  <select name="mechanic_id" class="form-select mb-2" required>
                                      <option value="">Select Partner...</option>
                                      <?php foreach($mechanics as $m): ?>
                                          <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['name']) ?></option>
                                      <?php endforeach; ?>
                                  </select>
                                  <button type="submit" class="btn btn-primary w-100 fw-bold rounded-pill"><i class="fa-solid fa-user-check me-2"></i> Force Assign Partner</button>
                              </form>
                          </div>

                          <div id="action_cancel" class="mb-3">
                              <form method="POST" class="bg-danger bg-opacity-10 p-3 rounded-3 border border-danger border-opacity-25" onsubmit="return confirm('Confirm cancellation?');">
                                  <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                  <input type="hidden" name="action" value="cancel_booking">
                                  <input type="hidden" name="booking_id" class="dos_booking_id">
                                  <label class="form-label small fw-bold text-danger">Cancel Booking</label>
                                  <textarea name="cancel_reason" class="form-control mb-2" placeholder="Reason for cancellation..." required></textarea>
                                  <div class="form-check mb-2">
                                      <input class="form-check-input" type="checkbox" name="issue_refund" id="refundCheck" value="1">
                                      <label class="form-check-label small fw-medium text-dark" for="refundCheck">Initiate Full Refund</label>
                                  </div>
                                  <button type="submit" class="btn btn-danger w-100 fw-bold rounded-pill"><i class="fa-solid fa-ban me-2"></i> Cancel Order</button>
                              </form>
                          </div>

                          <div id="action_closed" class="text-center py-4 d-none">
                              <i class="fa-solid fa-lock fs-1 text-muted opacity-25 mb-2 d-block"></i>
                              <span class="text-muted fw-bold">This booking is closed.</span>
                          </div>
                      </div>
                  </div>
              </div>
          </div>

          <!-- TAB 2: FINANCIALS -->
          <div class="tab-pane fade" id="tab-finance">
              <div class="row g-4">
                  <div class="col-md-6 text-center">
                      <div class="bg-white p-4 rounded-4 shadow-sm border h-100">
                          <h6 class="fw-bold text-dark border-bottom pb-2 mb-3 text-start">Customer Payment Proof</h6>
                          <img id="dos_pay_img" src="" style="max-width: 100%; max-height: 250px; border-radius: 10px; border: 3px solid #E2E8F0;" class="mb-2">
                          <div id="dos_no_pay" class="text-muted py-5 d-none"><i class="fa-solid fa-receipt fs-1 opacity-25 mb-2 d-block"></i>No receipt uploaded</div>
                      </div>
                  </div>
                  <div class="col-md-6">
                      <div class="bg-white p-4 rounded-4 shadow-sm border h-100">
                          <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Commission Calculation Preview</h6>

                          <div class="d-flex justify-content-between mb-3">
                              <span class="text-muted fw-bold">Gross Job Value</span>
                              <span class="fw-bold text-dark fs-5" id="dos_job_val"></span>
                          </div>
                          <div class="d-flex justify-content-between mb-3">
                              <span class="text-muted fw-bold">Platform Cut (<?= $admin_comm_rate ?>%)</span>
                              <span class="fw-bold text-danger fs-5" id="dos_admin_cut"></span>
                          </div>
                          <div class="d-flex justify-content-between mb-3 pt-3 border-top">
                              <span class="text-dark fw-bold">Partner Net Earning</span>
                              <span class="fw-bold text-success fs-4" id="dos_mech_cut"></span>
                          </div>

                          <div class="mt-4 bg-light p-3 rounded-3 border text-center">
                              <span class="d-block small text-muted fw-bold text-uppercase">Payment Status</span>
                              <span class="badge bg-secondary fs-6 mt-1" id="dos_pay_status"></span>
                              <span class="badge bg-secondary fs-6 mt-1 ms-1 d-none" id="dos_ref_status"></span>
                          </div>
                      </div>
                  </div>
              </div>
          </div>

          <!-- TAB 3: TIMELINE -->
          <div class="tab-pane fade" id="tab-history">
              <div class="bg-white p-4 rounded-4 shadow-sm border">
                  <h6 class="fw-bold text-dark mb-4 border-bottom pb-2">Chronological Activity Log</h6>
                  <div id="dos_timeline" style="max-height: 400px; overflow-y: auto; padding-left: 10px;"></div>
              </div>
          </div>

      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.getElementById('selectAll').addEventListener('change', function() {
        let checkboxes = document.querySelectorAll('.row-check');
        checkboxes.forEach(cb => cb.checked = this.checked);
    });

    setTimeout(() => {
        const flashMsg = document.getElementById('flash-msg-container');
        if (flashMsg && flashMsg.innerHTML.trim() !== '') {
            flashMsg.style.transition = 'opacity 0.5s ease';
            flashMsg.style.opacity = '0';
            setTimeout(() => flashMsg.innerHTML = '', 500);
        }
    }, 4000);

    function openDossier(data) {
        document.getElementById('dos_ref').innerText = '#' + (data.booking_ref || data.id);
        document.getElementById('dos_status').innerText = data.status;
        document.getElementById('dos_cust').innerText = data.customer_name || 'N/A';
        document.getElementById('dos_phone').innerText = data.customer_phone || '';

        let addr = data.address || '';
        if (data.landmark) addr += ' (LM: ' + data.landmark + ')';
        document.getElementById('dos_address').innerText = addr;

        document.getElementById('dos_cat').innerText = data.category_name || 'Service';
        document.getElementById('dos_prob').innerText = data.problem_name || data.other_problem || 'Not specified';

        if (data.mechanic_name) {
            document.getElementById('dos_mech').innerHTML = '<span class="text-primary">' + data.mechanic_name + '</span>';
            if (data.assigned_at) {
                let dt = new Date(data.assigned_at);
                document.getElementById('dos_mech').innerHTML += '<br><span class="text-muted fw-normal">Assigned: ' + dt.toLocaleString() + '</span>';
            }
        } else {
            document.getElementById('dos_mech').innerText = 'Unassigned';
        }

        document.querySelectorAll('.dos_booking_id').forEach(el => el.value = data.id);

        let aVer = document.getElementById('action_verify');
        let aAss = document.getElementById('action_assign');
        let aCan = document.getElementById('action_cancel');
        let aClo = document.getElementById('action_closed');

        aVer.classList.add('d-none'); aAss.classList.add('d-none'); aCan.classList.add('d-none'); aClo.classList.add('d-none');

        if (data.status === 'Payment Verification Pending') {
            aVer.classList.remove('d-none'); aCan.classList.remove('d-none');
        } else if (['Pending Assignment','Assigned','Accepted','In Progress'].includes(data.status)) {
            aAss.classList.remove('d-none'); aCan.classList.remove('d-none');
        } else {
            aClo.classList.remove('d-none');
        }

        let jVal = parseFloat(data.job_amount || 0);
        let aRate = <?= $admin_comm_rate ?>;
        let mRate = <?= $mechanic_share_rate ?>;

        document.getElementById('dos_job_val').innerText = '₹' + jVal.toFixed(2);
        document.getElementById('dos_admin_cut').innerText = '₹' + (jVal * aRate / 100).toFixed(2);
        document.getElementById('dos_mech_cut').innerText = '₹' + (jVal * mRate / 100).toFixed(2);

        document.getElementById('dos_pay_status').innerText = data.payment_status || 'Pending';
        let refStat = document.getElementById('dos_ref_status');
        if (data.refund_status && data.refund_status !== 'None') {
            refStat.innerText = data.refund_status + ' Refund';
            refStat.classList.remove('d-none');
        } else {
            refStat.classList.add('d-none');
        }

        let pImg = document.getElementById('dos_pay_img');
        let noPImg = document.getElementById('dos_no_pay');
        if (data.payment_proof) {
            pImg.src = '../' + data.payment_proof;
            pImg.classList.remove('d-none');
            noPImg.classList.add('d-none');
        } else {
            pImg.classList.add('d-none');
            noPImg.classList.remove('d-none');
        }

        let timeline = document.getElementById('dos_timeline');
        timeline.innerHTML = '';
        if (data.history && data.history.length > 0) {
            data.history.forEach(h => {
                let d = new Date(h.created_at);
                timeline.innerHTML += `
                    <div class="timeline-item">
                        <div class="fw-bold text-dark small">${h.status_updated_to}</div>
                        <div class="text-muted small fst-italic mb-1">${h.notes || ''}</div>
                        <div class="text-primary fw-bold" style="font-size: 0.7rem;">${d.toLocaleString()}</div>
                    </div>
                `;
            });
        } else {
            timeline.innerHTML = '<div class="text-muted small">No history recorded yet.</div>';
        }

        new bootstrap.Modal(document.getElementById('dossierModal')).show();
    }
</script>
</body>
</html>