<?php
// htdocs/admin/dashboard.php
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
// 1. BULLETPROOF SELF-HEALING ROUTINE
// ==========================================
try {
    $pdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('maintenance_mode', '0')");
    $pdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('commission_rate', '15')");

    } catch (PDOException $e) { /* ignore */ }

// Fetch Global Settings (safe)
$comm_rate = 15.0;
$maintenance_mode = false;
try {
    $val = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'commission_rate'")->fetchColumn();
    if ($val !== false && $val !== null) $comm_rate = (float)$val;
    $maintenance_mode = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'maintenance_mode'")->fetchColumn() === '1';
} catch (PDOException $e) { /* defaults */ }

// ==========================================
// 2. HANDLE QUICK POST ACTIONS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['flash_message'] = "<div class='alert alert-danger border-0 shadow-sm'><i class='fa-solid fa-shield-halved me-2'></i>Security token expired.</div>";
    } else {
        $action = $_POST['action'];
        try {
            if ($action === 'toggle_maintenance') {
                $new_state = $maintenance_mode ? '0' : '1';
                $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'maintenance_mode'")->execute([$new_state]);
                $state_text = $new_state === '1' ? 'Enabled' : 'Disabled';
                $pdo->prepare("INSERT INTO admin_activity_logs (admin_id, action_type, details) VALUES (?, 'System', ?)")->execute([$admin_id, "Maintenance Mode $state_text"]);
                $_SESSION['flash_message'] = "<div class='alert alert-info border-0 shadow-sm'><i class='fa-solid fa-circle-info me-2'></i>Maintenance Mode $state_text.</div>";
            } elseif ($action === 'quick_kyc_approve') {
                $mech_id = (int)$_POST['mechanic_id'];
                $pdo->prepare("UPDATE mechanics SET status = 'active', is_available = 1 WHERE id = ?")->execute([$mech_id]);
                $pdo->prepare("INSERT INTO admin_activity_logs (admin_id, action_type, details) VALUES (?, 'KYC Verify', ?)")->execute([$admin_id, "Quick approved KYC for mechanic ID: $mech_id"]);
                $_SESSION['flash_message'] = "<div class='alert alert-success border-0 shadow-sm'><i class='fa-solid fa-circle-check me-2'></i>Mechanic KYC Approved and Activated.</div>";
            }
        } catch (PDOException $e) {
            $_SESSION['flash_message'] = "<div class='alert alert-danger border-0 shadow-sm'>Error: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
    header("Location: dashboard.php" . (isset($_GET['range']) ? "?range=" . urlencode($_GET['range']) : ""));
    exit;
}

$message = $_SESSION['flash_message'] ?? '';
unset($_SESSION['flash_message']);

// ==========================================
// 3. DATE FILTERING & DATA AGGREGATION
// ==========================================
$range_days = isset($_GET['range']) ? (int)$_GET['range'] : 30;
$date_filter_sql = "AND created_at >= DATE_SUB(CURDATE(), INTERVAL $range_days DAY)";

// Safe defaults
$stats = ['users'=>0,'mechanics'=>0,'bookings_total'=>0,'bookings_active'=>0,'bookings_completed'=>0,'bookings_cancelled'=>0,'revenue_collected'=>0,'admin_commission'=>0,'refunded'=>0];
$mech_status = ['available'=>0,'busy'=>0,'offline'=>0];
$pending_payments = $pending_kyc = $broadcast_jobs = $live_bookings = $top_mechanics = $top_services = [];
$chart_labels = []; $chart_values = [];

try {
    $stats['users']              = $pdo->query("SELECT COUNT(*) FROM users WHERE 1=1 $date_filter_sql")->fetchColumn() ?: 0;
    $stats['mechanics']          = $pdo->query("SELECT COUNT(*) FROM mechanics WHERE status != 'rejected' $date_filter_sql")->fetchColumn() ?: 0;
    $stats['bookings_total']     = $pdo->query("SELECT COUNT(*) FROM bookings WHERE 1=1 $date_filter_sql")->fetchColumn() ?: 0;
    $stats['bookings_active']    = $pdo->query("SELECT COUNT(*) FROM bookings WHERE status IN ('Assigned','Accepted','In Progress')")->fetchColumn() ?: 0;
    $stats['bookings_completed'] = $pdo->query("SELECT COUNT(*) FROM bookings WHERE status = 'Completed' $date_filter_sql")->fetchColumn() ?: 0;
    $stats['bookings_cancelled'] = $pdo->query("SELECT COUNT(*) FROM bookings WHERE status = 'Cancelled' $date_filter_sql")->fetchColumn() ?: 0;
    $stats['revenue_collected']  = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='Verified' $date_filter_sql")->fetchColumn() ?: 0;
    $stats['admin_commission']   = $pdo->query("SELECT COALESCE(SUM(p.amount * $comm_rate / 100),0) FROM bookings b JOIN payments p ON b.id = p.booking_id WHERE b.status='Completed' AND b.created_at >= DATE_SUB(CURDATE(), INTERVAL $range_days DAY)")->fetchColumn() ?: 0;
    $stats['refunded']           = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE refund_status IN ('Full','Partial') $date_filter_sql")->fetchColumn() ?: 0;

    $mech_status['available'] = $pdo->query("SELECT COUNT(*) FROM mechanics WHERE is_available=1 AND status='active'")->fetchColumn() ?: 0;
    $mech_status['busy']      = $pdo->query("SELECT COUNT(*) FROM mechanics WHERE is_available=0 AND status='active'")->fetchColumn() ?: 0;
    $mech_status['offline']   = $pdo->query("SELECT COUNT(*) FROM mechanics WHERE status IN ('banned','rejected')")->fetchColumn() ?: 0;

    $pending_payments = $pdo->query("SELECT b.id, b.booking_ref, p.amount, u.name as customer FROM bookings b JOIN payments p ON b.id = p.booking_id JOIN users u ON b.user_id = u.id WHERE b.status = 'Payment Verification Pending' ORDER BY b.created_at ASC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    $pending_kyc      = $pdo->query("SELECT id, name, phone FROM mechanics WHERE status = 'pending' ORDER BY created_at ASC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    $broadcast_jobs   = $pdo->query("SELECT b.booking_ref, c.name as service FROM bookings b JOIN categories c ON b.category_id = c.id WHERE b.status = 'Pending Assignment' ORDER BY b.created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

    $live_bookings = $pdo->query("
        SELECT b.booking_ref, b.status, b.created_at, u.name as customer, c.name as service, m.name as mechanic
        FROM bookings b
        JOIN users u ON b.user_id = u.id
        JOIN categories c ON b.category_id = c.id
        LEFT JOIN mechanics m ON b.mechanic_id = m.id
        ORDER BY b.created_at DESC LIMIT 6
    ")->fetchAll(PDO::FETCH_ASSOC);

    $top_mechanics = $pdo->query("
        SELECT m.name, COUNT(b.id) as jobs_done
        FROM mechanics m JOIN bookings b ON m.id = b.mechanic_id
        WHERE b.status = 'Completed' GROUP BY m.id ORDER BY jobs_done DESC LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

    $top_services = $pdo->query("
        SELECT c.name, COUNT(b.id) as hits
        FROM categories c JOIN bookings b ON c.id = b.category_id
        GROUP BY c.id ORDER BY hits DESC LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

    $chart_stmt = $pdo->query("
        SELECT DATE(b.created_at) as date_val, SUM(p.amount) as daily_revenue
        FROM bookings b JOIN payments p ON b.id = p.booking_id
        WHERE b.status='Completed' AND b.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        GROUP BY DATE(b.created_at) ORDER BY DATE(b.created_at) ASC
    ");
    foreach ($chart_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $chart_labels[] = date('M d', strtotime($row['date_val']));
        $chart_values[] = (float)$row['daily_revenue'];
    }
} catch (PDOException $e) {
    // Keep defaults — page still renders
    error_log("Dashboard error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Master Dashboard - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --admin-nav: #0B1120;
            --admin-bg: #F5F7FB;
            --primary: #6366F1;
            --primary-dark: #4F46E5;
            --success: #10B981;
            --warning: #F59E0B;
            --danger: #EF4444;
            --info: #06B6D4;
            --text-dark: #0F172A;
            --text-muted: #64748B;
            --border: #E5E7EB;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--admin-bg);
            color: #334155;
            padding-bottom: 60px;
            -webkit-font-smoothing: antialiased;
        }

        /* ===== Navbar ===== */
        .navbar {
            background: linear-gradient(180deg, #0B1120 0%, #111827 100%) !important;
            padding: 14px 0;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }
        .navbar-brand { font-weight: 800; color: #fff !important; letter-spacing: -0.5px; font-size: 1.15rem;}
        .nav-link {
            color: #94A3B8 !important;
            font-weight: 500;
            padding: 8px 16px !important;
            border-radius: 10px;
            transition: all 0.2s ease;
            font-size: 0.9rem;
        }
        .nav-link:hover { color: #fff !important; background: rgba(255,255,255,0.06); }
        .nav-link.active {
            color: #fff !important;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            box-shadow: 0 4px 12px rgba(99,102,241,0.35);
        }

        /* ===== Page Header ===== */
        .page-header h3 { letter-spacing: -0.6px; }
        .range-pill {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 100px;
            padding: 4px 4px 4px 16px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.03);
            display: flex;
            align-items: center;
            transition: all .2s;
        }
        .range-pill:hover { border-color: #CBD5E1; }
        .range-pill select {
            border: none !important;
            background: transparent !important;
            font-weight: 600;
            color: var(--text-dark);
            box-shadow: none !important;
            cursor: pointer;
            padding-right: 28px;
        }

        .btn-maintenance {
            border-radius: 100px;
            padding: 8px 20px;
            font-weight: 700;
            font-size: 0.85rem;
            transition: all .2s ease;
            border: 1px solid transparent;
        }
        .btn-maintenance.live {
            background: #fff;
            color: var(--success);
            border-color: #D1FAE5;
            box-shadow: 0 2px 6px rgba(16,185,129,0.08);
        }
        .btn-maintenance.live:hover { background: #ECFDF5; }
        .btn-maintenance.maintenance {
            background: var(--danger);
            color: #fff;
            box-shadow: 0 4px 12px rgba(239,68,68,0.3);
        }

        /* ===== KPI Cards ===== */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 14px;
            margin-bottom: 24px;
        }
        @media (max-width: 1200px) { .kpi-grid { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 640px)  { .kpi-grid { grid-template-columns: repeat(2, 1fr); } }

        .kpi-card {
            background: #fff;
            border-radius: 16px;
            padding: 18px;
            border: 1px solid var(--border);
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            transition: all 0.25s ease;
            position: relative;
            overflow: hidden;
        }
        .kpi-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            background: var(--primary);
            opacity: 0;
            transition: opacity 0.25s;
        }
        .kpi-card:hover { transform: translateY(-3px); box-shadow: 0 12px 24px -8px rgba(0,0,0,0.08); border-color: #CBD5E1;}
        .kpi-card:hover::before { opacity: 1; }
        .kpi-card.kpi-success::before { background: var(--success); }
        .kpi-card.kpi-info::before { background: var(--info); }
        .kpi-card.kpi-warning::before { background: var(--warning); }
        .kpi-card.kpi-danger::before { background: var(--danger); }
        .kpi-card.kpi-primary::before { background: var(--primary); }

        .kpi-icon {
            width: 38px; height: 38px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem;
            margin-bottom: 12px;
        }
        .kpi-label {
            font-size: 0.68rem;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--text-muted);
            letter-spacing: 0.6px;
            margin-bottom: 6px;
        }
        .kpi-value {
            font-size: 1.55rem;
            font-weight: 800;
            color: var(--text-dark);
            letter-spacing: -0.8px;
            line-height: 1.1;
        }

        /* ===== Panel Card ===== */
        .panel {
            background: #fff;
            border-radius: 18px;
            padding: 22px;
            border: 1px solid var(--border);
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            height: 100%;
            transition: box-shadow 0.25s;
        }
        .panel:hover { box-shadow: 0 8px 24px -8px rgba(0,0,0,0.06); }
        .panel-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--text-dark);
            letter-spacing: -0.2px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-bottom: 14px;
            border-bottom: 1px solid #F1F5F9;
            margin-bottom: 16px;
        }
        .panel-title-left { display: flex; align-items: center; gap: 10px;}
        .panel-icon {
            width: 30px; height: 30px;
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.8rem;
            background: #EEF2FF; color: var(--primary);
        }

        /* ===== Partner Fleet Status ===== */
        .fleet-stat {
            border-radius: 12px;
            padding: 12px 8px;
            text-align: center;
            border: 1px solid transparent;
            transition: transform 0.2s;
        }
        .fleet-stat:hover { transform: translateY(-2px); }
        .fleet-stat .value { font-size: 1.5rem; font-weight: 800; line-height: 1; letter-spacing: -0.8px;}
        .fleet-stat .label { font-size: 0.65rem; font-weight: 700; letter-spacing: 0.7px; margin-top: 6px; text-transform: uppercase;}
        .fleet-online { background: #ECFDF5; color: #065F46; border-color: #D1FAE5;}
        .fleet-busy { background: #FFFBEB; color: #92400E; border-color: #FDE68A;}
        .fleet-offline { background: #F8FAFC; color: #475569; border-color: #E2E8F0;}

        /* ===== Quick Actions ===== */
        .quick-action-btn {
            background: #F8FAFC;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 14px 8px;
            text-align: center;
            color: #475569;
            font-weight: 600;
            font-size: 0.78rem;
            text-decoration: none;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            transition: all 0.2s ease;
            height: 100%;
        }
        .quick-action-btn i { font-size: 1.1rem; color: var(--primary); transition: transform 0.2s;}
        .quick-action-btn:hover {
            border-color: var(--primary);
            color: var(--primary-dark);
            background: #F5F7FF;
            transform: translateY(-2px);
            box-shadow: 0 6px 14px -6px rgba(99,102,241,0.35);
        }
        .quick-action-btn:hover i { transform: scale(1.15); }

        /* ===== Feed Items ===== */
        .feed-item {
            padding: 12px 0;
            border-bottom: 1px solid #F1F5F9;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
        }
        .feed-item:last-child { border-bottom: none; padding-bottom: 0;}
        .feed-item:first-child { padding-top: 0;}

        .feed-name { font-weight: 600; color: var(--text-dark); font-size: 0.85rem;}
        .feed-sub { font-size: 0.72rem; color: var(--text-muted); margin-top: 2px;}

        /* ===== Badges ===== */
        .badge-soft {
            padding: 5px 11px;
            border-radius: 100px;
            font-size: 0.68rem;
            font-weight: 700;
            letter-spacing: 0.2px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            white-space: nowrap;
        }
        .badge-soft-warning { background: #FEF3C7; color: #92400E; }
        .badge-soft-success { background: #D1FAE5; color: #065F46; }
        .badge-soft-primary { background: #E0E7FF; color: #3730A3; }
        .badge-soft-danger { background: #FEE2E2; color: #991B1B;}
        .badge-soft-info { background: #CFFAFE; color: #155E75;}

        /* ===== Table ===== */
        .table-clean thead th {
            background: #F8FAFC;
            color: var(--text-muted);
            font-size: 0.68rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            border-bottom: 1px solid var(--border);
            padding: 12px 10px;
        }
        .table-clean tbody td { padding: 14px 10px; border-bottom: 1px solid #F1F5F9; }
        .table-clean tbody tr:last-child td { border-bottom: none;}
        .table-clean tbody tr { transition: background 0.15s;}
        .table-clean tbody tr:hover { background: #FAFBFF;}

        /* ===== Flash ===== */
        #flash-msg-container { animation: slideDown 0.5s ease forwards; }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-20px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .alert { border-radius: 12px; padding: 14px 18px; font-weight: 500;}

        /* ===== Global Search ===== */
        .global-search .form-control,
        .global-search .input-group-text {
            border-color: #E2E8F0;
            background: rgba(255,255,255,0.06);
            color: #E2E8F0;
        }
        .global-search .input-group-text { border-right: none; color: #94A3B8;}
        .global-search .form-control { border-left: none; color: #E2E8F0;}
        .global-search .form-control::placeholder { color: #64748B;}
        .global-search .form-control:focus { box-shadow: none; border-color: #E2E8F0; background: rgba(255,255,255,0.1);}
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg sticky-top mb-4">
    <div class="container-fluid px-4">
        <a class="navbar-brand" href="dashboard.php"><i class="fa-solid fa-bolt text-warning me-2"></i>ServiceFix</a>
        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#adminNav">
            <i class="fa-solid fa-bars text-white"></i>
        </button>
        <div class="collapse navbar-collapse" id="adminNav">
            <ul class="navbar-nav ms-auto mb-2 mb-lg-0 gap-1 align-items-center">
                <li class="nav-item me-lg-3 mb-2 mb-lg-0">
                    <form method="GET" action="bookings.php" class="global-search">
                        <div class="input-group input-group-sm" style="width: 220px;">
                            <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
                            <input type="text" name="search" class="form-control" placeholder="Global Search...">
                        </div>
                    </form>
                </li>
                <li class="nav-item"><a class="nav-link active" href="dashboard.php"><i class="fa-solid fa-gauge-high me-1"></i>Dashboard</a></li>
                <li class="nav-item"><a class="nav-link" href="bookings.php"><i class="fa-solid fa-calendar-check me-1"></i>Bookings</a></li>
                <li class="nav-item"><a class="nav-link" href="mechanics.php"><i class="fa-solid fa-user-gear me-1"></i>Mechanics</a></li>
                <li class="nav-item"><a class="nav-link" href="payments.php"><i class="fa-solid fa-wallet me-1"></i>Payments</a></li>
                <li class="nav-item"><a class="nav-link" href="settings.php"><i class="fa-solid fa-sliders me-1"></i>Settings</a></li>
                <li class="nav-item ms-lg-2">
                    <a href="logout.php" class="btn btn-danger btn-sm px-3 fw-bold rounded-pill">
                        <i class="fa-solid fa-power-off me-1"></i>Logout
                    </a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<div class="container-fluid px-4">

    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3 page-header">
        <div>
            <h3 class="fw-bold text-dark mb-1">Intelligence Overview</h3>
            <p class="text-muted small mb-0">Platform analytics and operational queues at a glance.</p>
        </div>
        <div class="d-flex gap-3 align-items-center flex-wrap">
            <form method="GET" class="range-pill m-0">
                <i class="fa-regular fa-calendar text-muted me-2"></i>
                <select name="range" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="1" <?= $range_days == 1 ? 'selected' : '' ?>>Today</option>
                    <option value="7" <?= $range_days == 7 ? 'selected' : '' ?>>Last 7 Days</option>
                    <option value="30" <?= $range_days == 30 ? 'selected' : '' ?>>Last 30 Days</option>
                    <option value="365" <?= $range_days == 365 ? 'selected' : '' ?>>This Year</option>
                </select>
            </form>
            <form method="POST" class="m-0">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="toggle_maintenance">
                <button type="submit" class="btn btn-maintenance <?= $maintenance_mode ? 'maintenance' : 'live' ?>" title="Toggle Maintenance Mode">
                    <i class="fa-solid fa-power-off me-1"></i>
                    <?= $maintenance_mode ? 'Maintenance ON' : 'System Live' ?>
                </button>
            </form>
        </div>
    </div>

    <div id="flash-msg-container"><?= $message ?></div>

    <!-- KPI GRID -->
    <div class="kpi-grid">
        <div class="kpi-card kpi-success">
            <div class="kpi-icon" style="background:#ECFDF5; color:#059669;"><i class="fa-solid fa-chart-pie"></i></div>
            <div class="kpi-label">Admin Earnings</div>
            <div class="kpi-value text-success">₹<?= number_format($stats['admin_commission']) ?></div>
        </div>
        <div class="kpi-card kpi-primary">
            <div class="kpi-icon" style="background:#EEF2FF; color:#4F46E5;"><i class="fa-solid fa-vault"></i></div>
            <div class="kpi-label">Gross Revenue</div>
            <div class="kpi-value">₹<?= number_format($stats['revenue_collected']) ?></div>
        </div>
        <div class="kpi-card kpi-info">
            <div class="kpi-icon" style="background:#CFFAFE; color:#0891B2;"><i class="fa-solid fa-calendar-check"></i></div>
            <div class="kpi-label">Total Bookings</div>
            <div class="kpi-value"><?= number_format($stats['bookings_total']) ?></div>
        </div>
        <div class="kpi-card kpi-primary">
            <div class="kpi-icon" style="background:#EDE9FE; color:#7C3AED;"><i class="fa-solid fa-briefcase"></i></div>
            <div class="kpi-label">Active Jobs</div>
            <div class="kpi-value"><?= number_format($stats['bookings_active']) ?></div>
        </div>
        <div class="kpi-card kpi-warning">
            <div class="kpi-icon" style="background:#FEF3C7; color:#D97706;"><i class="fa-solid fa-users"></i></div>
            <div class="kpi-label">Customers</div>
            <div class="kpi-value"><?= number_format($stats['users']) ?></div>
        </div>
        <div class="kpi-card kpi-success">
            <div class="kpi-icon" style="background:#DCFCE7; color:#16A34A;"><i class="fa-solid fa-user-gear"></i></div>
            <div class="kpi-label">Partners</div>
            <div class="kpi-value"><?= number_format($stats['mechanics']) ?></div>
        </div>
    </div>

    <!-- ROW 2: Chart + Fleet Status -->
    <div class="row g-4 mb-4">
        <div class="col-lg-7">
            <div class="panel">
                <div class="panel-title">
                    <div class="panel-title-left">
                        <div class="panel-icon"><i class="fa-solid fa-chart-line"></i></div>
                        <span>Revenue Trend</span>
                    </div>
                    <span class="text-muted small fw-medium">Last 7 Days</span>
                </div>
                <div style="height: 260px;">
                    <canvas id="revenueChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="panel">
                <div class="panel-title">
                    <div class="panel-title-left">
                        <div class="panel-icon"><i class="fa-solid fa-users-gear"></i></div>
                        <span>Partner Fleet & Quick Actions</span>
                    </div>
                </div>
                <div class="row g-2 mb-4">
                    <div class="col-4">
                        <div class="fleet-stat fleet-online">
                            <div class="value"><?= $mech_status['available'] ?></div>
                            <div class="label">Online</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="fleet-stat fleet-busy">
                            <div class="value"><?= $mech_status['busy'] ?></div>
                            <div class="label">Busy</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="fleet-stat fleet-offline">
                            <div class="value"><?= $mech_status['offline'] ?></div>
                            <div class="label">Offline</div>
                        </div>
                    </div>
                </div>
                <div class="row g-2">
                    <div class="col-6"><a href="mechanics.php" class="quick-action-btn"><i class="fa-solid fa-user-plus"></i>Add Partner</a></div>
                    <div class="col-6"><a href="payments.php" class="quick-action-btn"><i class="fa-solid fa-file-invoice-dollar"></i>Verify Pay</a></div>
                    <div class="col-6"><a href="settings.php" class="quick-action-btn"><i class="fa-solid fa-layer-group"></i>Services</a></div>
                    <div class="col-6"><a href="bookings.php" class="quick-action-btn"><i class="fa-solid fa-truck-fast"></i>Dispatch</a></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ROW 3: Broadcast / KYC / Payments -->
    <div class="row g-4 mb-4">
        <div class="col-lg-4">
            <div class="panel">
                <div class="panel-title">
                    <div class="panel-title-left">
                        <div class="panel-icon" style="background:#E0E7FF; color:#4F46E5;"><i class="fa-solid fa-satellite-dish"></i></div>
                        <span>Broadcast Monitor</span>
                    </div>
                    <span class="badge-soft badge-soft-primary"><i class="fa-solid fa-spinner fa-spin"></i><?= count($broadcast_jobs) ?> Pinging</span>
                </div>
                <?php if(count($broadcast_jobs) > 0): ?>
                    <?php foreach($broadcast_jobs as $job): ?>
                        <div class="feed-item">
                            <div>
                                <div class="feed-name">#<?= htmlspecialchars($job['booking_ref']) ?></div>
                                <div class="feed-sub"><?= htmlspecialchars($job['service']) ?></div>
                            </div>
                            <span class="badge-soft badge-soft-primary"><i class="fa-solid fa-spinner fa-spin"></i>Awaiting</span>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center text-muted py-4 small fst-italic">No jobs currently broadcasting.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="panel">
                <div class="panel-title">
                    <div class="panel-title-left">
                        <div class="panel-icon" style="background:#FEF3C7; color:#D97706;"><i class="fa-solid fa-id-card"></i></div>
                        <span>KYC Approvals</span>
                    </div>
                    <a href="mechanics.php" class="text-decoration-none small fw-bold text-primary">View All</a>
                </div>
                <?php if(count($pending_kyc) > 0): ?>
                    <?php foreach($pending_kyc as $kyc): ?>
                        <div class="feed-item">
                            <div>
                                <div class="feed-name"><?= htmlspecialchars($kyc['name']) ?></div>
                                <div class="feed-sub"><?= htmlspecialchars($kyc['phone']) ?></div>
                            </div>
                            <form method="POST" class="m-0">
                                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                <input type="hidden" name="action" value="quick_kyc_approve">
                                <input type="hidden" name="mechanic_id" value="<?= $kyc['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-success rounded-pill fw-bold px-3" style="font-size: 0.7rem;">Approve</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center text-muted py-4 small fst-italic">No pending KYC applications.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="panel">
                <div class="panel-title">
                    <div class="panel-title-left">
                        <div class="panel-icon" style="background:#D1FAE5; color:#059669;"><i class="fa-solid fa-wallet"></i></div>
                        <span>Payment Checks</span>
                    </div>
                    <a href="payments.php" class="text-decoration-none small fw-bold text-primary">View All</a>
                </div>
                <?php if(count($pending_payments) > 0): ?>
                    <?php foreach($pending_payments as $pay): ?>
                        <div class="feed-item">
                            <div>
                                <div class="feed-name">#<?= htmlspecialchars($pay['booking_ref']) ?>
                                    <span class="text-success ms-1">₹<?= number_format($pay['amount']) ?></span>
                                </div>
                                <div class="feed-sub"><?= htmlspecialchars($pay['customer']) ?></div>
                            </div>
                            <a href="payments.php" class="btn btn-sm btn-primary rounded-pill fw-bold px-3" style="font-size: 0.7rem;">Verify</a>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center text-muted py-4 small fst-italic">No pending payment verifications.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ROW 4: Live Feed + Leaderboards -->
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="panel">
                <div class="panel-title">
                    <div class="panel-title-left">
                        <div class="panel-icon" style="background:#F1F5F9; color:#475569;"><i class="fa-solid fa-list-check"></i></div>
                        <span>Live Booking Feed</span>
                    </div>
                    <a href="bookings.php" class="text-decoration-none small fw-bold text-primary">Full Record <i class="fa-solid fa-arrow-right ms-1"></i></a>
                </div>
                <div class="table-responsive">
                    <table class="table table-clean align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Customer</th>
                                <th>Service / Partner</th>
                                <th class="text-end">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(count($live_bookings) > 0): ?>
                                <?php foreach($live_bookings as $lb): ?>
                                    <?php
                                        $badge = 'badge-soft-primary';
                                        if(strpos($lb['status'], 'Pending') !== false) $badge = 'badge-soft-warning';
                                        if(strpos($lb['status'], 'Progress') !== false || in_array($lb['status'], ['Assigned','Accepted'])) $badge = 'badge-soft-primary';
                                        if($lb['status'] == 'Completed') $badge = 'badge-soft-success';
                                        if($lb['status'] == 'Cancelled') $badge = 'badge-soft-danger';
                                    ?>
                                    <tr>
                                        <td class="fw-bold text-dark" style="font-size: 0.85rem;">#<?= htmlspecialchars($lb['booking_ref']) ?></td>
                                        <td style="font-size: 0.85rem;"><?= htmlspecialchars($lb['customer']) ?></td>
                                        <td>
                                            <div class="fw-semibold text-dark" style="font-size: 0.85rem;"><?= htmlspecialchars($lb['service']) ?></div>
                                            <div class="text-muted" style="font-size: 0.72rem;"><i class="fa-solid fa-wrench me-1"></i><?= htmlspecialchars($lb['mechanic'] ?? 'Unassigned') ?></div>
                                        </td>
                                        <td class="text-end"><span class="badge-soft <?= $badge ?>"><?= htmlspecialchars($lb['status']) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="4" class="text-center text-muted py-4 small fst-italic">No recent bookings.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="panel">
                <div class="panel-title">
                    <div class="panel-title-left">
                        <div class="panel-icon" style="background:#FEF3C7; color:#D97706;"><i class="fa-solid fa-trophy"></i></div>
                        <span>Top Partners</span>
                    </div>
                </div>
                <?php if(count($top_mechanics) > 0): ?>
                    <?php foreach($top_mechanics as $index => $tm): ?>
                        <div class="d-flex justify-content-between align-items-center py-2 <?= $index < count($top_mechanics)-1 ? 'border-bottom' : '' ?>">
                            <div class="d-flex align-items-center gap-2">
                                <span class="fw-bold text-muted small">#<?= $index+1 ?></span>
                                <span class="fw-medium text-dark small"><?= htmlspecialchars($tm['name']) ?></span>
                            </div>
                            <span class="badge-soft badge-soft-success"><?= $tm['jobs_done'] ?> Jobs</span>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center text-muted py-3 small fst-italic">No data yet.</div>
                <?php endif; ?>

                <div class="panel-title mt-4">
                    <div class="panel-title-left">
                        <div class="panel-icon" style="background:#FEE2E2; color:#DC2626;"><i class="fa-solid fa-fire"></i></div>
                        <span>High Demand Services</span>
                    </div>
                </div>
                <?php if(count($top_services) > 0): ?>
                    <?php foreach($top_services as $index => $ts): ?>
                        <div class="d-flex justify-content-between align-items-center py-2 <?= $index < count($top_services)-1 ? 'border-bottom' : '' ?>">
                            <div class="d-flex align-items-center gap-2">
                                <span class="fw-bold text-muted small">#<?= $index+1 ?></span>
                                <span class="fw-medium text-dark small"><?= htmlspecialchars($ts['name']) ?></span>
                            </div>
                            <span class="badge-soft badge-soft-info"><?= $ts['hits'] ?> Requests</span>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center text-muted py-3 small fst-italic">No data yet.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // Auto Dismiss Flash Messages
    setTimeout(() => {
        const flashMsg = document.getElementById('flash-msg-container');
        if (flashMsg && flashMsg.innerHTML.trim() !== '') {
            flashMsg.style.transition = 'opacity 0.5s ease';
            flashMsg.style.opacity = '0';
            setTimeout(() => flashMsg.innerHTML = '', 500);
        }
    }, 3000);

    // Revenue Chart
    document.addEventListener("DOMContentLoaded", function() {
        const ctx = document.getElementById('revenueChart').getContext('2d');
        const labels = <?= json_encode($chart_labels) ?>;
        const dataValues = <?= json_encode($chart_values) ?>;

        let gradient = ctx.createLinearGradient(0, 0, 0, 260);
        gradient.addColorStop(0, 'rgba(99, 102, 241, 0.35)');
        gradient.addColorStop(1, 'rgba(99, 102, 241, 0.0)');

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels.length > 0 ? labels : ['No Data'],
                datasets: [{
                    label: 'Daily Revenue (₹)',
                    data: dataValues.length > 0 ? dataValues : [0],
                    borderColor: '#6366F1',
                    backgroundColor: gradient,
                    borderWidth: 3,
                    pointBackgroundColor: '#ffffff',
                    pointBorderColor: '#6366F1',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 7,
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#0F172A',
                        padding: 12,
                        titleFont: { family: 'Inter', size: 12, weight: '600'},
                        bodyFont: { family: 'Inter', size: 13, weight: '500'},
                        cornerRadius: 8,
                        displayColors: false,
                        callbacks: {
                            label: function(context) {
                                return '₹' + context.parsed.y.toLocaleString();
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { borderDash: [5, 5], color: '#E5E7EB', drawBorder: false },
                        ticks: { font: { family: 'Inter', size: 11 }, color: '#64748B', padding: 8 }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { font: { family: 'Inter', size: 11 }, color: '#64748B' }
                    }
                }
            }
        });
    });
</script>
</body>
</html>