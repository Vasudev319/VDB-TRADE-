<?php
// htdocs/admin/mechanics.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/db.php';
require_once '../config/security.php';

// Secure session check for admin
if (!is_admin_logged_in()) {
    header("Location: index.php");
    exit;
}

$admin_id = $_SESSION['admin_id'];

// ==========================================
// 1. SELF-HEALING DATABASE ROUTINE
// ==========================================
try {
    // Ensure Settlements table exists
    // Ensure Activity Logs table exists for tracking admin actions
    } catch (PDOException $e) {
    // Fail silently if structures already exist
}

// Fetch Global Commission Rate
$comm_stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'commission_rate'");
$global_commission_rate = (float)($comm_stmt->fetchColumn() ?: 15.0);
$mechanic_share_rate = 100 - $global_commission_rate;

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
                header('Content-Disposition: attachment; filename=mechanics_report_' . date('Y-m-d') . '.csv');
                $output = fopen('php://output', 'w');
                fputcsv($output, ['ID', 'Name', 'Phone', 'Status', 'Available', 'Total Earnings', 'Pending Commission Due']);

                $export_stmt = $pdo->query("SELECT id, name, phone, status, is_available FROM mechanics");
                while ($row = $export_stmt->fetch(PDO::FETCH_ASSOC)) {
                    fputcsv($output, $row);
                }
                fclose($output);
                exit;
            }

            // --- CRUD ACTIONS ---
            if ($action === 'add') {
                $name = clean_input($_POST['name']);
                $phone = clean_input($_POST['phone']);
                $category_id = (int)$_POST['category_id'];
                $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                $is_available = isset($_POST['is_available']) ? 1 : 0;

                $check = $pdo->prepare("SELECT id FROM mechanics WHERE phone = ?")->execute([$phone]);
                if ($pdo->query("SELECT FOUND_ROWS()")->fetchColumn() > 0) {
                    $_SESSION['flash_message'] = "<div class='alert alert-danger shadow-sm border-0 rounded-3'>Phone number already exists.</div>";
                } else {
                    $pdo->prepare("INSERT INTO mechanics (name, phone, password, category_id, status, is_available) VALUES (?, ?, ?, ?, 'active', ?)")->execute([$name, $phone, $password, $category_id, $is_available]);
                    $pdo->prepare("INSERT INTO admin_activity_logs (admin_id, action_type, details) VALUES (?, 'Add Mechanic', ?)")->execute([$admin_id, "Added mechanic: $name"]);
                    $_SESSION['flash_message'] = "<div class='alert alert-success shadow-sm border-0 rounded-3'>Mechanic added successfully!</div>";
                }
            } elseif ($action === 'edit') {
                $id = (int)$_POST['mechanic_id'];
                $name = clean_input($_POST['name']);
                $phone = clean_input($_POST['phone']);
                $category_id = (int)$_POST['category_id'];
                $is_available = isset($_POST['is_available']) ? 1 : 0;
                $password = $_POST['password'];

                if (!empty($password)) {
                    $hashed = password_hash($password, PASSWORD_DEFAULT);
                    $pdo->prepare("UPDATE mechanics SET name=?, phone=?, category_id=?, is_available=?, password=? WHERE id=?")->execute([$name, $phone, $category_id, $is_available, $hashed, $id]);
                } else {
                    $pdo->prepare("UPDATE mechanics SET name=?, phone=?, category_id=?, is_available=? WHERE id=?")->execute([$name, $phone, $category_id, $is_available, $id]);
                }
                $pdo->prepare("INSERT INTO admin_activity_logs (admin_id, action_type, details) VALUES (?, 'Edit Mechanic', ?)")->execute([$admin_id, "Edited profile for ID: $id"]);
                $_SESSION['flash_message'] = "<div class='alert alert-info shadow-sm border-0 rounded-3'>Profile updated.</div>";
            } elseif ($action === 'delete') {
                $id = (int)$_POST['mechanic_id'];
                $pdo->prepare("DELETE FROM mechanics WHERE id = ?")->execute([$id]);
                $pdo->prepare("INSERT INTO admin_activity_logs (admin_id, action_type, details) VALUES (?, 'Delete Mechanic', ?)")->execute([$admin_id, "Deleted mechanic ID: $id"]);
                $_SESSION['flash_message'] = "<div class='alert alert-success shadow-sm border-0 rounded-3'>Mechanic deleted.</div>";
            } elseif ($action === 'verify_kyc') {
                $id = (int)$_POST['mechanic_id'];
                $kyc_status = $_POST['kyc_status']; // 'active' or 'rejected'
                $pdo->prepare("UPDATE mechanics SET status = ?, is_available = IF(? = 'active', 1, 0) WHERE id = ?")->execute([$kyc_status, $kyc_status, $id]);
                $pdo->prepare("INSERT INTO admin_activity_logs (admin_id, action_type, details) VALUES (?, 'KYC Update', ?)")->execute([$admin_id, "Set KYC status to $kyc_status for ID: $id"]);
                $_SESSION['flash_message'] = "<div class='alert alert-success shadow-sm border-0 rounded-3'>KYC status updated.</div>";
            } elseif ($action === 'toggle_ban') {
                $id = (int)$_POST['mechanic_id'];
                $pdo->prepare("UPDATE mechanics SET status = IF(status = 'banned', 'active', 'banned'), is_available = 0 WHERE id = ?")->execute([$id]);
                $pdo->prepare("INSERT INTO admin_activity_logs (admin_id, action_type, details) VALUES (?, 'Toggle Ban', ?)")->execute([$admin_id, "Toggled ban status for ID: $id"]);
                $_SESSION['flash_message'] = "<div class='alert alert-warning shadow-sm border-0 rounded-3'>Account access modified.</div>";
            } elseif ($action === 'settle_commission') {
                $id = (int)$_POST['mechanic_id'];
                $amount = (float)$_POST['settle_amount'];
                if ($amount > 0) {
                    $pdo->prepare("INSERT INTO commission_settlements (mechanic_id, amount, status) VALUES (?, ?, 'Verified')")->execute([$id, $amount]);
                    $pdo->prepare("INSERT INTO admin_activity_logs (admin_id, action_type, details) VALUES (?, 'Settle Offline', ?)")->execute([$admin_id, "Settled ₹$amount offline for ID: $id"]);
                    $_SESSION['flash_message'] = "<div class='alert alert-success shadow-sm border-0 rounded-3'><i class='fa-solid fa-hand-holding-dollar me-2'></i>Offline settlement of ₹" . number_format($amount, 2) . " recorded.</div>";
                }
            }
        } catch (PDOException $e) {
            $_SESSION['flash_message'] = "<div class='alert alert-danger shadow-sm border-0 rounded-3'>Error: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
    header("Location: mechanics.php");
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
$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? clean_input($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? clean_input($_GET['status']) : '';

$where_clauses = ["1=1"];
$params = [];

if (!empty($search)) {
    $where_clauses[] = "(m.name LIKE ? OR m.phone LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%";
}
if (!empty($status_filter)) {
    if ($status_filter === 'available') {
        $where_clauses[] = "m.is_available = 1 AND m.status = 'active'";
    } elseif ($status_filter === 'busy') {
        $where_clauses[] = "m.is_available = 0 AND m.status = 'active'";
    } else {
        $where_clauses[] = "m.status = ?";
        $params[] = $status_filter;
    }
}
$where_sql = implode(" AND ", $where_clauses);

try {
    $categories = $pdo->query("SELECT id, name FROM categories ORDER BY name ASC")->fetchAll();

    $stats = [
        'total' => $pdo->query("SELECT COUNT(*) FROM mechanics")->fetchColumn(),
        'available' => $pdo->query("SELECT COUNT(*) FROM mechanics WHERE is_available = 1 AND status = 'active'")->fetchColumn(),
        'busy' => $pdo->query("SELECT COUNT(*) FROM mechanics WHERE is_available = 0 AND status = 'active'")->fetchColumn(),
        'banned' => $pdo->query("SELECT COUNT(*) FROM mechanics WHERE status = 'banned'")->fetchColumn(),
        'pending' => $pdo->query("SELECT COUNT(*) FROM mechanics WHERE status = 'pending'")->fetchColumn()
    ];

    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM mechanics m WHERE $where_sql");
    $count_stmt->execute($params);
    $total_pages = ceil($count_stmt->fetchColumn() / $limit);

    // Fetch Mechanics with Aggregate Financials
    $query = "
        SELECT m.*, c.name as category_name,
        (SELECT COUNT(*) FROM bookings WHERE mechanic_id = m.id) as total_jobs,
        (SELECT SUM(amount) FROM payments WHERE booking_id IN (SELECT id FROM bookings WHERE mechanic_id = m.id AND status = 'Completed')) as gross_volume,
        (SELECT SUM(amount) FROM commission_settlements WHERE mechanic_id = m.id AND status = 'Verified') as total_settled
        FROM mechanics m
        LEFT JOIN categories c ON m.category_id = c.id
        WHERE $where_sql
        ORDER BY m.id DESC
        LIMIT $limit OFFSET $offset
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $mechanics_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Build the Dossier Data for each mechanic
    foreach ($mechanics_list as &$mech) {
        // Calculate exact earnings and dues
        $gross = (float)$mech['gross_volume'];
        $admin_owed = ($gross * $global_commission_rate) / 100;
        $mechanic_earnings = ($gross * $mechanic_share_rate) / 100;
        $settled = (float)$mech['total_settled'];

        $mech['calc_earnings'] = $mechanic_earnings;
        $mech['pending_commission'] = max(0, $admin_owed - $settled);

        // Fetch Complete Job History for this mechanic
        $job_stmt = $pdo->prepare("
            SELECT b.booking_ref, b.status, b.created_at, c.name as cat_name,
            COALESCE(pay.amount, 0) as job_amount
            FROM bookings b
            LEFT JOIN categories c ON b.category_id = c.id
            LEFT JOIN payments pay ON b.id = pay.booking_id
            WHERE b.mechanic_id = ? ORDER BY b.created_at DESC LIMIT 50
        ");
        $job_stmt->execute([$mech['id']]);
        $mech['job_history'] = $job_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch Commission History Breakdown
        $mech['commission_history'] = [];
        foreach ($mech['job_history'] as $job) {
            if (strpos($job['status'], 'Completed') !== false) {
                $j_amt = (float)$job['job_amount'];
                $a_cut = $j_amt * ($global_commission_rate / 100);
                $m_cut = $j_amt * ($mechanic_share_rate / 100);
                $mech['commission_history'][] = [
                    'ref' => $job['booking_ref'],
                    'date' => $job['created_at'],
                    'gross' => $j_amt,
                    'admin_cut' => $a_cut,
                    'mech_cut' => $m_cut
                ];
            }
        }
    }
} catch (PDOException $e) {
    die("<div class='alert alert-danger'><h3>System Error</h3>" . htmlspecialchars($e->getMessage()) . "</div>");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Partner Management - Admin</title>
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

        .stat-card { background: #fff; border-radius: 16px; padding: 20px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); border: 1px solid #E2E8F0; display: flex; align-items: center; justify-content: space-between;}
        .stat-card .icon-box { width: 50px; height: 50px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; }

        .table-wrapper { background: #fff; border-radius: 20px; padding: 24px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); border: 1px solid #E2E8F0; }
        .table th { background: #F8FAFC; color: #64748B; font-weight: 600; text-transform: uppercase; font-size: 0.75rem; border-bottom: 2px solid #E2E8F0; padding: 16px;}
        .table td { vertical-align: middle; padding: 16px; border-bottom: 1px solid #F1F5F9; font-size: 0.95rem;}

        .badge-custom { padding: 6px 12px; border-radius: 20px; font-weight: 600; font-size: 0.75rem; }
        .badge-active { background-color: #D1FAE5; color: #059669; }
        .badge-pending { background-color: #FEF3C7; color: #D97706; }
        .badge-banned { background-color: #FEE2E2; color: #DC2626; }
        .badge-offline { background-color: #F1F5F9; color: #64748B; border: 1px solid #CBD5E1; }

        .nav-tabs .nav-link { color: #64748B; font-weight: 600; border: none; border-bottom: 3px solid transparent; padding: 12px 20px; background: transparent; transition: 0.3s;}
        .nav-tabs .nav-link.active { color: var(--primary); border-bottom: 3px solid var(--primary); background: transparent;}
        .kyc-img { max-width: 100%; border-radius: 10px; border: 2px dashed #CBD5E1; padding: 5px; }

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
                <li class="nav-item"><a class="nav-link" href="bookings.php">Bookings</a></li>
                <li class="nav-item"><a class="nav-link active" href="mechanics.php">Mechanics</a></li>
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
            <h3 class="fw-bold text-dark mb-1">Partner & Dispatch Management</h3>
            <p class="text-muted small mb-0">Onboard, verify, settle commissions, and audit partner accounts.</p>
        </div>
        <div class="d-flex gap-2">
            <form method="POST" class="m-0">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="export_csv">
                <button type="submit" class="btn btn-dark fw-bold rounded-pill shadow-sm"><i class="fa-solid fa-file-csv me-1"></i> Export Data</button>
            </form>
            <button class="btn btn-primary fw-bold px-4 rounded-pill shadow-sm" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="fa-solid fa-plus me-1"></i> Add Partner
            </button>
        </div>
    </div>

    <div id="flash-msg-container"><?= $message ?></div>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="stat-card">
                <div><div class="text-muted small fw-bold text-uppercase">Total Partners</div><h4 class="fw-bold mb-0 text-dark"><?= $stats['total'] ?></h4></div>
                <div class="icon-box bg-primary bg-opacity-10 text-primary"><i class="fa-solid fa-users"></i></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div><div class="text-muted small fw-bold text-uppercase">Online (Available)</div><h4 class="fw-bold mb-0 text-success"><?= $stats['available'] ?></h4></div>
                <div class="icon-box bg-success bg-opacity-10 text-success"><i class="fa-solid fa-check-circle"></i></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div><div class="text-muted small fw-bold text-uppercase">Offline / Busy</div><h4 class="fw-bold mb-0 text-secondary"><?= $stats['busy'] ?></h4></div>
                <div class="icon-box bg-secondary bg-opacity-10 text-secondary"><i class="fa-solid fa-bed"></i></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div><div class="text-muted small fw-bold text-uppercase">Action Required</div><h4 class="fw-bold mb-0 text-warning"><?= $stats['pending'] ?> KYC</h4></div>
                <div class="icon-box bg-warning bg-opacity-10 text-warning"><i class="fa-solid fa-id-card"></i></div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="table-wrapper mb-4 p-3 bg-light border-0">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-md-5">
                <input type="text" name="search" class="form-control" placeholder="Search by name or phone..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-md-4">
                <select name="status" class="form-select">
                    <option value="">All Statuses & Availability</option>
                    <option value="available" <?= $status_filter == 'available' ? 'selected' : '' ?>>Online & Available</option>
                    <option value="busy" <?= $status_filter == 'busy' ? 'selected' : '' ?>>Offline / Busy</option>
                    <option value="pending" <?= $status_filter == 'pending' ? 'selected' : '' ?>>Pending KYC</option>
                    <option value="banned" <?= $status_filter == 'banned' ? 'selected' : '' ?>>Suspended (Banned)</option>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-dark w-100 fw-medium">Apply Filter</button>
                <a href="mechanics.php" class="btn btn-outline-secondary px-3"><i class="fa-solid fa-rotate-left"></i></a>
            </div>
        </form>
    </div>

    <!-- Table -->
    <div class="table-wrapper p-0 overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Partner Profile</th>
                        <th>Specialization</th>
                        <th>Current Status</th>
                        <th>Due from Partner</th>
                        <th class="text-end">Management</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($mechanics_list) > 0): ?>
                        <?php foreach ($mechanics_list as $m): ?>
                            <?php
                                $safe_json = htmlspecialchars(json_encode($m), ENT_QUOTES, 'UTF-8');

                                $badge = 'badge-active';
                                $status_text = 'Active';
                                if ($m['status'] === 'pending') { $badge = 'badge-pending'; $status_text = 'Pending KYC'; }
                                elseif ($m['status'] === 'banned' || $m['status'] === 'rejected') { $badge = 'badge-banned'; $status_text = ucfirst($m['status']); }
                                elseif ($m['status'] === 'active' && $m['is_available'] == 0) { $badge = 'badge-offline'; $status_text = 'Offline'; }
                            ?>
                            <tr>
                                <td>
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($m['name']) ?></div>
                                    <div class="text-muted small"><i class="fa-solid fa-phone me-1"></i><?= htmlspecialchars($m['phone']) ?></div>
                                </td>
                                <td><span class="badge bg-light text-dark border fw-medium"><?= htmlspecialchars($m['category_name'] ?? 'General') ?></span></td>
                                <td><span class="badge-custom <?= $badge ?>"><?= $status_text ?></span></td>
                                <td>
                                    <div class="fw-bold <?= $m['pending_commission'] > 0 ? 'text-danger' : 'text-success' ?>">
                                        <?= $m['pending_commission'] > 0 ? '₹' . number_format($m['pending_commission'], 2) : '<i class="fa-solid fa-check"></i> Cleared' ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex gap-2 justify-content-end">
                                        <button class="btn btn-light border btn-sm text-primary rounded-pill px-3 fw-bold" onclick='openViewModal(<?= $safe_json ?>)' title="Open Dossier"><i class="fa-solid fa-folder-open me-1"></i> Dossier</button>
                                        <button class="btn btn-light border btn-sm text-secondary rounded-circle" onclick='openEditModal(<?= $safe_json ?>)' title="Edit Profile"><i class="fa-solid fa-pen"></i></button>
                                        <form method="POST" class="m-0" onsubmit="return confirm('Are you sure you want to permanently delete this mechanic?');">
                                            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="mechanic_id" value="<?= $m['id'] ?>">
                                            <button type="submit" class="btn btn-light border btn-sm text-danger rounded-circle" title="Delete"><i class="fa-solid fa-trash"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5" class="text-center py-5 text-muted"><i class="fa-solid fa-users-slash fs-1 opacity-25 d-block mb-3"></i>No mechanics found matching your criteria.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
        <nav class="mt-4">
            <ul class="pagination justify-content-center">
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <li class="page-item <?= ($i == $page) ? 'active' : '' ?>">
                        <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status_filter) ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
    <?php endif; ?>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
      <div class="modal-header bg-light border-0 px-4 pt-4">
        <h5 class="modal-title fw-bold text-dark">Add New Partner</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" class="px-4 py-4">
        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
        <input type="hidden" name="action" value="add">

        <div class="mb-3">
            <label class="form-label small fw-bold text-muted">Full Name</label>
            <input type="text" name="name" class="form-control" required>
        </div>
        <div class="mb-3">
            <label class="form-label small fw-bold text-muted">Phone Number</label>
            <input type="tel" name="phone" class="form-control" pattern="[0-9]{10}" required>
        </div>
        <div class="mb-3">
            <label class="form-label small fw-bold text-muted">Specialization</label>
            <select name="category_id" class="form-select" required>
                <option value="">Select Category...</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label small fw-bold text-muted">Password</label>
            <input type="password" name="password" class="form-control" required>
        </div>
        <div class="form-check form-switch mb-4">
            <input class="form-check-input" type="checkbox" name="is_available" id="addAvail" checked>
            <label class="form-check-label fw-medium ms-2" for="addAvail">Set Available Immediately</label>
        </div>
        <button type="submit" class="btn btn-primary w-100 fw-bold py-2 rounded-pill">Create Mechanic</button>
      </form>
    </div>
  </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
      <div class="modal-header bg-light border-0 px-4 pt-4">
        <h5 class="modal-title fw-bold text-dark">Edit Partner Profile</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" class="px-4 py-4">
        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="mechanic_id" id="edit_id">

        <div class="mb-3">
            <label class="form-label small fw-bold text-muted">Full Name</label>
            <input type="text" name="name" id="edit_name" class="form-control" required>
        </div>
        <div class="mb-3">
            <label class="form-label small fw-bold text-muted">Phone Number</label>
            <input type="tel" name="phone" id="edit_phone" class="form-control" pattern="[0-9]{10}" required>
        </div>
        <div class="mb-3">
            <label class="form-label small fw-bold text-muted">Specialization</label>
            <select name="category_id" id="edit_category" class="form-select" required>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label small fw-bold text-muted">New Password <span class="fw-normal">(Leave blank to keep)</span></label>
            <input type="password" name="password" class="form-control">
        </div>
        <div class="form-check form-switch mb-4">
            <input class="form-check-input" type="checkbox" name="is_available" id="edit_avail">
            <label class="form-check-label fw-medium ms-2" for="edit_avail">Available for work</label>
        </div>
        <button type="submit" class="btn btn-primary w-100 fw-bold py-2 rounded-pill">Save Changes</button>
      </form>
    </div>
  </div>
</div>

<!-- Comprehensive Dossier Modal -->
<div class="modal fade" id="viewModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-xl">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 20px; overflow:hidden;">
      <div class="modal-header bg-dark text-white border-0 px-4 pt-4 d-flex justify-content-between">
        <h5 class="modal-title fw-bold" id="view_modal_title">Partner Dossier</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>

      <!-- Modal Tabs -->
      <ul class="nav nav-tabs px-4 pt-3 bg-light border-bottom-0" id="mechTabs" role="tablist">
          <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-profile">Identity & Control</button></li>
          <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-finance">Finance & Commissions</button></li>
          <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-jobs">Job Assignment History</button></li>
      </ul>

      <div class="modal-body bg-light px-4 py-4 tab-content">

          <!-- TAB 1: IDENTITY & CONTROL (Profile, KYC, Ban) -->
          <div class="tab-pane fade show active" id="tab-profile">
              <div class="row g-4">
                  <div class="col-md-5">
                      <div class="bg-white p-4 rounded-4 shadow-sm border h-100 text-center">
                          <div class="bg-primary bg-opacity-10 text-primary d-inline-flex align-items-center justify-content-center rounded-circle mb-3" style="width: 80px; height: 80px; font-size: 2rem;">
                              <i class="fa-solid fa-user-wrench"></i>
                          </div>
                          <h5 class="fw-bold text-dark mb-1" id="view_name"></h5>
                          <p class="text-muted small mb-3"><i class="fa-solid fa-phone me-1"></i> <span id="view_phone"></span></p>
                          <span class="badge bg-secondary mb-4" id="view_category"></span>

                          <div class="row g-2 text-start">
                              <div class="col-6">
                                  <div class="bg-light p-2 rounded border text-center">
                                      <div class="small text-muted fw-bold">Joined On</div>
                                      <div class="fw-bold text-dark fs-6 mt-1" id="view_join_date"></div>
                                  </div>
                              </div>
                              <div class="col-6">
                                  <div class="bg-light p-2 rounded border text-center">
                                      <div class="small text-muted fw-bold">Total Jobs</div>
                                      <div class="fw-bold text-primary fs-6 mt-1" id="view_jobs"></div>
                                  </div>
                              </div>
                          </div>
                      </div>
                  </div>

                  <div class="col-md-7">
                      <div class="bg-white p-4 rounded-4 shadow-sm border h-100">
                          <h6 class="fw-bold text-dark mb-3 border-bottom pb-2">Compliance & Security</h6>

                          <div id="kyc_section" class="mb-4">
                              <p class="small fw-bold text-muted text-uppercase mb-2">KYC Document Status</p>
                              <img src="" id="view_kyc_img" class="kyc-img mb-3 d-none" alt="KYC Document">
                              <p id="no_kyc_msg" class="text-muted small fst-italic">No KYC document uploaded.</p>

                              <form method="POST" id="kyc_form" class="d-none mt-2">
                                  <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                  <input type="hidden" name="action" value="verify_kyc">
                                  <input type="hidden" name="mechanic_id" id="kyc_mech_id">
                                  <div class="d-flex gap-2">
                                      <button type="submit" name="kyc_status" value="active" class="btn btn-success btn-sm flex-grow-1 fw-bold"><i class="fa-solid fa-check me-1"></i> Approve KYC</button>
                                      <button type="submit" name="kyc_status" value="rejected" class="btn btn-outline-danger btn-sm flex-grow-1 fw-bold"><i class="fa-solid fa-xmark me-1"></i> Reject</button>
                                  </div>
                              </form>
                          </div>

                          <div id="ban_section">
                              <p class="small fw-bold text-muted text-uppercase mb-2">Access Control</p>
                              <form method="POST" onsubmit="return confirm('Change account access status?');">
                                  <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                  <input type="hidden" name="action" value="toggle_ban">
                                  <input type="hidden" name="mechanic_id" id="ban_mech_id">
                                  <button type="submit" id="ban_btn" class="btn btn-danger w-100 fw-bold rounded-pill"></button>
                              </form>
                          </div>
                      </div>
                  </div>
              </div>
          </div>

          <!-- TAB 2: FINANCE & SETTLEMENTS -->
          <div class="tab-pane fade" id="tab-finance">
              <div class="row g-4">
                  <div class="col-md-4">
                      <!-- Manual Settlement Form -->
                      <div class="bg-white p-4 rounded-4 shadow-sm border h-100">
                          <h6 class="fw-bold text-dark mb-4 border-bottom pb-2">Settle Due Charge Offline</h6>
                          <div class="text-center mb-4">
                              <span class="d-block text-muted small fw-bold text-uppercase mb-1">Pending Amount Owed to Admin</span>
                              <h2 class="fw-bold text-danger mb-0" id="view_pending_comm">₹0.00</h2>
                          </div>

                          <form method="POST">
                              <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                              <input type="hidden" name="action" value="settle_commission">
                              <input type="hidden" name="mechanic_id" id="settle_mech_id">

                              <div class="mb-3">
                                  <label class="form-label small fw-bold text-muted">Amount Collected Offline (₹)</label>
                                  <input type="number" step="0.01" name="settle_amount" id="settle_amount_input" class="form-control" required min="1">
                              </div>
                              <button type="submit" class="btn btn-success w-100 fw-bold rounded-pill shadow-sm"><i class="fa-solid fa-hand-holding-dollar me-2"></i> Record Offline Cash</button>
                          </form>

                          <div class="mt-4 pt-3 border-top text-center">
                              <span class="d-block text-muted small fw-bold text-uppercase mb-1">Partner Lifetime Earnings</span>
                              <h4 class="fw-bold text-success mb-0" id="view_earnings"></h4>
                          </div>
                      </div>
                  </div>

                  <div class="col-md-8">
                      <!-- Commission History Table -->
                      <div class="bg-white p-4 rounded-4 shadow-sm border h-100">
                          <h6 class="fw-bold text-dark mb-3 border-bottom pb-2">Detailed Commission Breakdown</h6>
                          <div class="table-responsive" style="max-height: 350px; overflow-y: auto;">
                              <table class="table table-sm table-hover align-middle mb-0">
                                  <thead class="sticky-top bg-white shadow-sm">
                                      <tr>
                                          <th class="py-2">Ref ID & Date</th>
                                          <th class="py-2">Job Gross Value</th>
                                          <th class="py-2 text-success">Partner Earning (<?= $mechanic_share_rate ?>%)</th>
                                          <th class="py-2 text-danger text-end">Admin Cut (<?= $global_commission_rate ?>%)</th>
                                      </tr>
                                  </thead>
                                  <tbody id="commission_history_body">
                                      <!-- Filled via JS -->
                                  </tbody>
                              </table>
                          </div>
                      </div>
                  </div>
              </div>
          </div>

          <!-- TAB 3: JOB ASSIGNMENT OVERVIEW -->
          <div class="tab-pane fade" id="tab-jobs">
               <div class="bg-white p-4 rounded-4 shadow-sm border h-100">
                    <h6 class="fw-bold text-dark mb-3 border-bottom pb-2">Recent Assignment & Activity History</h6>
                    <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead class="sticky-top bg-white shadow-sm">
                                <tr>
                                    <th class="py-2">Date & Time</th>
                                    <th class="py-2">Ref ID</th>
                                    <th class="py-2">Service</th>
                                    <th class="py-2 text-end">Progress Status</th>
                                </tr>
                            </thead>
                            <tbody id="job_history_body">
                                <!-- Filled via JS -->
                            </tbody>
                        </table>
                    </div>
               </div>
          </div>

      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Auto Dismiss Flash Messages
    setTimeout(() => {
        const flashMsg = document.getElementById('flash-msg-container');
        if (flashMsg && flashMsg.innerHTML.trim() !== '') {
            flashMsg.style.transition = 'opacity 0.5s ease';
            flashMsg.style.opacity = '0';
            setTimeout(() => flashMsg.innerHTML = '', 500);
        }
    }, 4000);

    function openEditModal(data) {
        document.getElementById('edit_id').value = data.id;
        document.getElementById('edit_name').value = data.name;
        document.getElementById('edit_phone').value = data.phone;
        document.getElementById('edit_category').value = data.category_id;
        document.getElementById('edit_avail').checked = data.is_available == 1;
        new bootstrap.Modal(document.getElementById('editModal')).show();
    }

    function openViewModal(data) {
        document.getElementById('view_modal_title').innerText = data.name + "'s Dossier";
        document.getElementById('view_name').innerText = data.name;
        document.getElementById('view_phone').innerText = data.phone;
        document.getElementById('view_category').innerText = data.category_name || 'General';

        let d = new Date(data.created_at);
        document.getElementById('view_join_date').innerText = d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});

        document.getElementById('view_jobs').innerText = data.total_jobs || '0';
        document.getElementById('view_earnings').innerText = data.calc_earnings ? '₹' + parseFloat(data.calc_earnings).toFixed(2) : '₹0.00';

        // --- KYC Handling ---
        let kycImg = document.getElementById('view_kyc_img');
        let noKyc = document.getElementById('no_kyc_msg');
        let kycForm = document.getElementById('kyc_form');

        if (data.kyc_file) {
            kycImg.src = '../' + data.kyc_file;
            kycImg.classList.remove('d-none');
            noKyc.classList.add('d-none');
            if (data.status === 'pending') {
                document.getElementById('kyc_mech_id').value = data.id;
                kycForm.classList.remove('d-none');
            } else {
                kycForm.classList.add('d-none');
            }
        } else {
            kycImg.classList.add('d-none');
            noKyc.classList.remove('d-none');
            kycForm.classList.add('d-none');
        }

        // --- Ban Handling ---
        let banBtn = document.getElementById('ban_btn');
        document.getElementById('ban_mech_id').value = data.id;
        if (data.status === 'banned') {
            banBtn.className = 'btn btn-success w-100 fw-bold rounded-pill shadow-sm';
            banBtn.innerHTML = '<i class="fa-solid fa-unlock me-2"></i> Restore Access (Unban)';
        } else {
            banBtn.className = 'btn btn-danger w-100 fw-bold rounded-pill shadow-sm';
            banBtn.innerHTML = '<i class="fa-solid fa-ban me-2"></i> Suspend Account (Ban)';
        }

        // --- Financials & Settlement Handling ---
        document.getElementById('settle_mech_id').value = data.id;
        let pendingComm = parseFloat(data.pending_commission);
        document.getElementById('view_pending_comm').innerText = '₹' + pendingComm.toFixed(2);

        let settleInput = document.getElementById('settle_amount_input');
        if(pendingComm > 0) {
            settleInput.value = pendingComm.toFixed(2);
            settleInput.max = pendingComm.toFixed(2);
            settleInput.readOnly = false;
        } else {
            settleInput.value = '';
            settleInput.placeholder = 'No pending dues';
            settleInput.readOnly = true;
        }

        // --- Populate Commission History ---
        let commBody = document.getElementById('commission_history_body');
        commBody.innerHTML = '';
        if (data.commission_history && data.commission_history.length > 0) {
            data.commission_history.forEach(hist => {
                let d = new Date(hist.date);
                let tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>
                        <div class="fw-bold text-dark small">#${hist.ref}</div>
                        <div class="text-muted small" style="font-size:0.7rem;">${d.toLocaleDateString()}</div>
                    </td>
                    <td class="fw-bold text-dark">₹${parseFloat(hist.gross).toFixed(2)}</td>
                    <td class="text-success fw-bold">+₹${parseFloat(hist.mech_cut).toFixed(2)}</td>
                    <td class="text-danger fw-bold text-end">-₹${parseFloat(hist.admin_cut).toFixed(2)}</td>
                `;
                commBody.appendChild(tr);
            });
        } else {
            commBody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-4">No commission data yet.</td></tr>';
        }

        // --- Populate Job Assignment Overview ---
        let jobBody = document.getElementById('job_history_body');
        jobBody.innerHTML = '';
        if (data.job_history && data.job_history.length > 0) {
            data.job_history.forEach(job => {
                let d = new Date(job.created_at);
                let badgeClass = 'bg-primary';
                if(job.status.includes('Completed')) badgeClass = 'bg-success';
                else if(job.status.includes('Cancelled') || job.status.includes('Rejected')) badgeClass = 'bg-danger';
                else if(job.status.includes('Pending')) badgeClass = 'bg-warning text-dark';

                let tr = document.createElement('tr');
                tr.innerHTML = `
                    <td class="text-muted small fw-medium">${d.toLocaleDateString()} ${d.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</td>
                    <td class="fw-bold text-dark">#${job.booking_ref}</td>
                    <td class="text-muted small">${job.cat_name || 'General'}</td>
                    <td class="text-end"><span class="badge ${badgeClass} rounded-pill px-2 py-1">${job.status}</span></td>
                `;
                jobBody.appendChild(tr);
            });
        } else {
            jobBody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-4">No job history available.</td></tr>';
        }

        // Show Modal
        new bootstrap.Modal(document.getElementById('viewModal')).show();
    }
</script>
</body>
</html>