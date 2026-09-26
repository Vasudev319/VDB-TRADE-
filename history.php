<?php
// htdocs/history.php — CUSTOMER BOOKING HISTORY
if (session_status() === PHP_SESSION_NONE) { session_start(); }
ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL);

// ========== BOOTSTRAP ==========
$db_loaded = false;
if (file_exists(__DIR__ . '/config/db.php')) { require_once __DIR__ . '/config/db.php'; $db_loaded = true; }
if (file_exists(__DIR__ . '/config/security.php')) { require_once __DIR__ . '/config/security.php'; }
if (!$db_loaded || !isset($pdo)) {
    try {
        // Fallback to global config if needed
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        die("<div style='font-family:sans-serif;padding:30px;background:#FEE2E2;color:#991B1B;border-radius:12px;margin:30px;'><h3>DB Connection Failed</h3><small>".htmlspecialchars($e->getMessage())."</small></div>");
    }
}
if (!function_exists('verify_csrf_token')) { function verify_csrf_token($t) { return true; } }
if (!function_exists('generate_csrf_token')) { function generate_csrf_token() { return $_SESSION['csrf_token'] ??= bin2hex(random_bytes(16)); } }
if (!function_exists('clean_input')) { function clean_input($d) { return htmlspecialchars(trim($d ?? ''), ENT_QUOTES, 'UTF-8'); } }

// ========== SESSION CHECK ==========
$user_id = (int)($_SESSION['user_id'] ?? $_SESSION['customer_id'] ?? 0);
$role = $_SESSION['role'] ?? '';
if ($user_id <= 0 || !in_array($role, ['customer','user'])) {
    header("Location: index.php");
    exit;
}

// ========== SELF-HEALING SCHEMA ==========
function table_exists(PDO $pdo, string $t): bool { try { $s=$pdo->prepare("SHOW TABLES LIKE ?"); $s->execute([$t]); return $s->fetch()!==false; } catch (PDOException $e) { return false; } }
function column_exists(PDO $pdo, string $t, string $c): bool { try { $s=$pdo->prepare("SHOW COLUMNS FROM `$t` LIKE ?"); $s->execute([$c]); return $s->fetch()!==false; } catch (PDOException $e) { return false; } }
function ensure_column(PDO $pdo, string $t, string $c, string $d): void { if (!table_exists($pdo,$t)||column_exists($pdo,$t,$c)) return; try { $pdo->exec("ALTER TABLE `$t` ADD COLUMN `$c` $d"); } catch (PDOException $e) {} }


// ========== FILTERS ==========
$status_filter = isset($_GET['status']) ? clean_input($_GET['status']) : 'all';
$search = isset($_GET['search']) ? clean_input($_GET['search']) : '';
$sort = isset($_GET['sort']) ? clean_input($_GET['sort']) : 'newest';

// Pagination
$limit = 10;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

// Build WHERE
$where = ["b.user_id = ?"];
$params = [$user_id];

if ($status_filter === 'active') {
    $where[] = "b.status IN ('Payment Verification Pending','Pending Assignment','Broadcast','Assigned','Accepted','On The Way','In Progress','Work Completed (Admin Verification Pending)')";
} elseif ($status_filter === 'completed') {
    $where[] = "b.status IN ('Completed','Admin Verified')";
} elseif ($status_filter === 'cancelled') {
    $where[] = "b.status = 'Cancelled'";
}

if ($search !== '') {
    $where[] = "(b.booking_ref LIKE ? OR b.service_type LIKE ? OR b.problem_description LIKE ?)";
    $sp = "%$search%";
    $params[] = $sp; $params[] = $sp; $params[] = $sp;
}

$where_sql = implode(' AND ', $where);

// Sort
$order_sql = "b.created_at DESC";
if ($sort === 'oldest') $order_sql = "b.created_at ASC";
elseif ($sort === 'highest') $order_sql = "b.amount DESC";
elseif ($sort === 'lowest') $order_sql = "b.amount ASC";

// ========== FETCH DATA ==========
$bookings = [];
$total = 0;
$stats = ['total'=>0,'active'=>0,'completed'=>0,'cancelled'=>0,'spent'=>0];

try {
    // Total for pagination
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM bookings b WHERE $where_sql");
    $count_stmt->execute($params);
    $total = (int)$count_stmt->fetchColumn();

    // Fetch bookings with mechanic + category
    $query = "
        SELECT b.*,
               c.name AS category_name, c.icon AS category_icon,
               m.name AS mechanic_name, m.phone AS mechanic_phone
        FROM bookings b
        LEFT JOIN categories c ON b.category_id = c.id
        LEFT JOIN users m ON b.mechanic_id = m.id
        WHERE $where_sql
        ORDER BY $order_sql
        LIMIT $limit OFFSET $offset
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $bookings = $stmt->fetchAll();
} catch (PDOException $e) {
    // Fallback without joins
    try {
        $stmt = $pdo->prepare("SELECT b.*, 'Service' as category_name, NULL as category_icon, m.name as mechanic_name, m.phone as mechanic_phone FROM bookings b LEFT JOIN users m ON b.mechanic_id = m.id WHERE $where_sql ORDER BY $order_sql LIMIT $limit OFFSET $offset");
        $stmt->execute($params);
        $bookings = $stmt->fetchAll();
    } catch (PDOException $e2) { $bookings = []; }
}

// Overall stats
try {
    $s = $pdo->prepare("SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status IN ('Payment Verification Pending','Pending Assignment','Broadcast','Assigned','Accepted','On The Way','In Progress','Work Completed (Admin Verification Pending)') THEN 1 ELSE 0 END) AS active,
        SUM(CASE WHEN status IN ('Completed','Admin Verified') THEN 1 ELSE 0 END) AS completed,
        SUM(CASE WHEN status = 'Cancelled' THEN 1 ELSE 0 END) AS cancelled,
        SUM(CASE WHEN status IN ('Completed','Admin Verified') THEN amount ELSE 0 END) AS spent
        FROM bookings WHERE user_id = ?");
    $s->execute([$user_id]);
    $row = $s->fetch();
    if ($row) {
        foreach ($stats as $k=>$v) if (isset($row[$k]) && $row[$k] !== null) $stats[$k] = $row[$k];
    }
} catch (PDOException $e) {}

$total_pages = max(1, ceil($total / $limit));

// Helpers
function status_badge($s) {
    $s = strtolower(trim($s));
    if (in_array($s,['pending assignment','broadcast','assigned','pending'])) return 'badge-assigned';
    if ($s==='accepted') return 'badge-accepted';
    if ($s==='on the way') return 'badge-onway';
    if ($s==='in progress') return 'badge-in-progress';
    if (strpos($s,'work completed')!==false) return 'badge-workdone';
    if (in_array($s,['completed','admin verified'])) return 'badge-completed';
    if ($s==='cancelled') return 'badge-cancelled';
    if (strpos($s,'payment')!==false) return 'badge-pending';
    return 'badge-assigned';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Booking History — AutoCare</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --nav:#0B1120; --bg:#F5F7FB; --primary:#6366F1; --primary-dark:#4F46E5; --border:#E5E7EB; --dark:#0F172A; --muted:#64748B; --success:#10B981; --warning:#F59E0B; --danger:#EF4444;}
        * { box-sizing: border-box;}
        body { font-family:'Inter',sans-serif; background:var(--bg); color:#334155; padding-bottom:60px; -webkit-font-smoothing:antialiased;}

        .navbar { background:linear-gradient(180deg,#0B1120 0%,#111827 100%) !important; padding:14px 0; box-shadow:0 4px 20px rgba(0,0,0,0.08); border-bottom:1px solid rgba(255,255,255,0.05);}
        .navbar-brand { font-weight:800; color:#fff !important; letter-spacing:-0.5px;}
        .nav-link { color:#94A3B8 !important; font-weight:500; padding:8px 16px !important; border-radius:10px; transition:all .2s; font-size:0.9rem;}
        .nav-link:hover { color:#fff !important; background:rgba(255,255,255,0.06);}
        .nav-link.active { color:#fff !important; background:linear-gradient(135deg,var(--primary),var(--primary-dark)); box-shadow:0 4px 12px rgba(99,102,241,0.35);}

        .page-header { background:linear-gradient(135deg,#4F46E5 0%,#7C3AED 100%); border-radius:22px; padding:28px 32px; color:#fff; position:relative; overflow:hidden; margin-bottom:26px; box-shadow:0 20px 40px -15px rgba(79,70,229,0.5);}
        .page-header::before { content:''; position:absolute; width:300px; height:300px; border-radius:50%; background:rgba(255,255,255,0.08); top:-100px; right:-60px;}
        .page-header h2 { font-weight:800; letter-spacing:-0.6px; position:relative; z-index:1; margin-bottom:4px;}
        .page-header p { color:rgba(255,255,255,0.85); position:relative; z-index:1; margin-bottom:0; font-size:0.9rem;}

        .stat-mini { background:#fff; border-radius:14px; padding:16px; border:1px solid var(--border); display:flex; align-items:center; gap:12px; transition:all .2s;}
        .stat-mini:hover { transform:translateY(-2px); box-shadow:0 10px 20px -8px rgba(0,0,0,0.06);}
        .stat-mini .icon-box { width:42px; height:42px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:1.1rem; flex-shrink:0;}
        .stat-mini .label { font-size:0.68rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--muted);}
        .stat-mini .value { font-size:1.35rem; font-weight:800; color:var(--dark); letter-spacing:-0.5px; line-height:1.1;}

        .filter-bar { background:#fff; border:1px solid var(--border); border-radius:16px; padding:16px; box-shadow:0 1px 3px rgba(0,0,0,0.02); margin-bottom:20px;}
        .tab-pills { display:flex; gap:8px; flex-wrap:wrap;}
        .tab-pill { padding:8px 16px; border-radius:100px; font-weight:700; font-size:0.82rem; border:2px solid var(--border); background:#fff; color:var(--muted); text-decoration:none; transition:all .2s;}
        .tab-pill:hover { border-color:var(--primary); color:var(--primary-dark);}
        .tab-pill.active { background:linear-gradient(135deg,var(--primary),var(--primary-dark)); color:#fff; border-color:transparent; box-shadow:0 6px 14px -4px rgba(99,102,241,0.4);}
        .tab-pill .count { background:rgba(255,255,255,0.25); padding:1px 7px; border-radius:100px; font-size:0.7rem; margin-left:5px;}
        .tab-pill.active .count { background:rgba(255,255,255,0.25);}
        .tab-pill:not(.active) .count { background:#F1F5F9; color:var(--muted);}

        .form-control, .form-select { border-radius:10px; padding:10px 14px; border:2px solid var(--border); font-weight:500; font-size:0.9rem;}
        .form-control:focus, .form-select:focus { border-color:var(--primary); box-shadow:0 0 0 4px rgba(99,102,241,0.1);}

        .booking-card { background:#fff; border-radius:16px; border:1px solid var(--border); padding:18px 20px; margin-bottom:14px; transition:all .25s; position:relative; overflow:hidden;}
        .booking-card:hover { box-shadow:0 12px 24px -8px rgba(0,0,0,0.08); transform:translateY(-2px);}
        .booking-card::before { content:''; position:absolute; left:0; top:0; bottom:0; width:4px; background:var(--primary);}
        .booking-card.status-completed::before { background:var(--success);}
        .booking-card.status-cancelled::before { background:var(--danger);}
        .booking-card.status-active::before { background:var(--warning);}

        .booking-ref { font-family:'SF Mono',monospace; font-weight:800; color:var(--primary); font-size:0.95rem;}
        .booking-service { font-weight:700; color:var(--dark); font-size:1rem; margin-bottom:2px;}
        .booking-meta { font-size:0.8rem; color:var(--muted); display:flex; flex-wrap:wrap; gap:12px; margin-top:6px;}
        .booking-meta i { color:#94A3B8; margin-right:4px;}

        .booking-amount { font-size:1.25rem; font-weight:800; color:var(--dark); letter-spacing:-0.5px;}

        .badge-soft { padding:6px 12px; border-radius:100px; font-weight:700; font-size:0.7rem; display:inline-flex; align-items:center; gap:5px;}
        .badge-assigned { background:#E2E8F0; color:#475569;}
        .badge-accepted { background:#DBEAFE; color:#1D4ED8;}
        .badge-onway { background:#FEF3C7; color:#92400E;}
        .badge-in-progress { background:#CFFAFE; color:#155E75;}
        .badge-workdone { background:#EDE9FE; color:#5B21B6;}
        .badge-completed { background:#D1FAE5; color:#065F46;}
        .badge-cancelled { background:#FEE2E2; color:#991B1B;}
        .badge-pending { background:#FEF3C7; color:#92400E;}

        .btn-view { background:linear-gradient(135deg,var(--primary),var(--primary-dark)); color:#fff; border:none; border-radius:10px; padding:8px 16px; font-weight:700; font-size:0.82rem; text-decoration:none; display:inline-flex; align-items:center; gap:6px; transition:all .2s;}
        .btn-view:hover { color:#fff; transform:translateY(-1px); box-shadow:0 8px 16px -6px rgba(99,102,241,0.5);}

        .empty-state { background:#fff; border-radius:18px; border:1px dashed var(--border); padding:60px 24px; text-align:center;}
        .empty-state i { font-size:3.5rem; color:#CBD5E1; margin-bottom:16px; display:block;}
        .empty-state h5 { font-weight:800; color:var(--dark); margin-bottom:8px;}
        .empty-state p { color:var(--muted); font-size:0.9rem; margin-bottom:22px;}

        .btn-primary-c { background:linear-gradient(135deg,var(--primary) 0%,var(--primary-dark) 100%); color:#fff; border:none; border-radius:12px; padding:12px 26px; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; gap:8px; transition:all .25s; box-shadow:0 10px 20px -6px rgba(99,102,241,0.5);}
        .btn-primary-c:hover { transform:translateY(-2px); color:#fff; box-shadow:0 15px 25px -6px rgba(99,102,241,0.6);}

        .pagination .page-link { border:none; color:var(--primary); margin:0 3px; border-radius:10px; font-weight:700; font-size:0.85rem;}
        .pagination .page-item.active .page-link { background:linear-gradient(135deg,var(--primary),var(--primary-dark)); color:#fff; box-shadow:0 6px 14px -4px rgba(99,102,241,0.4);}

        .mech-chip { display:inline-flex; align-items:center; gap:6px; background:#F8FAFC; border:1px solid var(--border); border-radius:100px; padding:4px 12px; font-size:0.78rem; font-weight:600; color:var(--dark);}
        .mech-chip i { color:var(--primary);}
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg sticky-top mb-4">
    <div class="container">
        <a class="navbar-brand" href="dashboard.php"><i class="fa-solid fa-bolt text-warning me-2"></i>AutoCare</a>
        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu"><i class="fa-solid fa-bars text-white"></i></button>
        <div class="collapse navbar-collapse" id="navMenu">
            <ul class="navbar-nav ms-auto mb-2 mb-lg-0 gap-1 align-items-center">
                <li class="nav-item"><a class="nav-link" href="dashboard.php"><i class="fa-solid fa-house me-1"></i>Home</a></li>
                <li class="nav-item"><a class="nav-link" href="book.php"><i class="fa-solid fa-plus me-1"></i>Book Service</a></li>
                <li class="nav-item"><a class="nav-link" href="track.php"><i class="fa-solid fa-location-dot me-1"></i>Track</a></li>
                <li class="nav-item"><a class="nav-link active" href="history.php"><i class="fa-solid fa-clock-rotate-left me-1"></i>History</a></li>
                <li class="nav-item"><a class="nav-link" href="profile.php"><i class="fa-solid fa-user me-1"></i>Profile</a></li>
                <li class="nav-item ms-lg-2"><a href="logout.php" class="btn btn-danger btn-sm px-3 fw-bold rounded-pill"><i class="fa-solid fa-power-off me-1"></i>Logout</a></li>
            </ul>
        </div>
    </div>
</nav>

<div class="container pb-5">

    <!-- HEADER -->
    <div class="page-header">
        <h2><i class="fa-solid fa-clock-rotate-left me-2"></i>My Booking History</h2>
        <p>All your past and ongoing service bookings in one place.</p>
    </div>

    <!-- STATS -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-mini">
                <div class="icon-box" style="background:#EEF2FF; color:#4F46E5;"><i class="fa-solid fa-clipboard-list"></i></div>
                <div>
                    <div class="label">Total</div>
                    <div class="value"><?= number_format((int)$stats['total']) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-mini">
                <div class="icon-box" style="background:#FEF3C7; color:#D97706;"><i class="fa-solid fa-spinner"></i></div>
                <div>
                    <div class="label">Active</div>
                    <div class="value" style="color:#D97706;"><?= number_format((int)$stats['active']) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-mini">
                <div class="icon-box" style="background:#D1FAE5; color:#059669;"><i class="fa-solid fa-check-circle"></i></div>
                <div>
                    <div class="label">Completed</div>
                    <div class="value text-success"><?= number_format((int)$stats['completed']) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-mini">
                <div class="icon-box" style="background:#FEE2E2; color:#DC2626;"><i class="fa-solid fa-circle-xmark"></i></div>
                <div>
                    <div class="label">Cancelled</div>
                    <div class="value text-danger"><?= number_format((int)$stats['cancelled']) ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- FILTER BAR -->
    <div class="filter-bar">
        <div class="row g-3 align-items-center">
            <div class="col-lg-6">
                <div class="tab-pills">
                    <a href="?status=all&search=<?= urlencode($search) ?>&sort=<?= urlencode($sort) ?>" class="tab-pill <?= $status_filter === 'all' ? 'active' : '' ?>">
                        All <span class="count"><?= (int)$stats['total'] ?></span>
                    </a>
                    <a href="?status=active&search=<?= urlencode($search) ?>&sort=<?= urlencode($sort) ?>" class="tab-pill <?= $status_filter === 'active' ? 'active' : '' ?>">
                        Active <span class="count"><?= (int)$stats['active'] ?></span>
                    </a>
                    <a href="?status=completed&search=<?= urlencode($search) ?>&sort=<?= urlencode($sort) ?>" class="tab-pill <?= $status_filter === 'completed' ? 'active' : '' ?>">
                        Completed <span class="count"><?= (int)$stats['completed'] ?></span>
                    </a>
                    <a href="?status=cancelled&search=<?= urlencode($search) ?>&sort=<?= urlencode($sort) ?>" class="tab-pill <?= $status_filter === 'cancelled' ? 'active' : '' ?>">
                        Cancelled <span class="count"><?= (int)$stats['cancelled'] ?></span>
                    </a>
                </div>
            </div>
            <div class="col-lg-6">
                <form method="GET" class="row g-2">
                    <input type="hidden" name="status" value="<?= htmlspecialchars($status_filter) ?>">
                    <div class="col-md-6">
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                            <input type="text" name="search" class="form-control border-start-0" placeholder="Search by ref, service..." value="<?= htmlspecialchars($search) ?>">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <select name="sort" class="form-select" onchange="this.form.submit()">
                            <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest First</option>
                            <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest First</option>
                            <option value="highest" <?= $sort === 'highest' ? 'selected' : '' ?>>Highest Amount</option>
                            <option value="lowest" <?= $sort === 'lowest' ? 'selected' : '' ?>>Lowest Amount</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100 fw-bold" style="background:linear-gradient(135deg,var(--primary),var(--primary-dark));border:none;border-radius:10px;padding:10px;">
                            <i class="fa-solid fa-filter"></i>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- BOOKINGS LIST -->
    <?php if (count($bookings) > 0): ?>
        <?php foreach ($bookings as $b):
            $st = strtolower(trim($b['status']));
            $cardClass = 'status-active';
            if (in_array($st, ['completed','admin verified'])) $cardClass = 'status-completed';
            elseif ($st === 'cancelled') $cardClass = 'status-cancelled';
        ?>
        <div class="booking-card <?= $cardClass ?>">
            <div class="row align-items-center g-3">
                <div class="col-md-8">
                    <div class="d-flex align-items-start gap-3">
                        <div class="flex-shrink-0">
                            <div style="width:52px;height:52px;border-radius:14px;background:#EEF2FF;color:#4F46E5;display:flex;align-items:center;justify-content:center;font-size:1.3rem;">
                                <i class="fa-solid <?= htmlspecialchars($b['category_icon'] ?? 'fa-tools') ?>"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1">
                            <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                                <span class="booking-ref">#<?= htmlspecialchars($b['booking_ref'] ?? 'BKG' . $b['id']) ?></span>
                                <span class="badge-soft <?= status_badge($b['status']) ?>">
                                    <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($b['status']) ?>
                                </span>
                            </div>
                            <div class="booking-service"><?= htmlspecialchars($b['service_type'] ?? $b['category_name'] ?? 'Service') ?></div>
                            <div class="booking-meta">
                                <span><i class="fa-solid fa-calendar"></i><?= !empty($b['booking_date']) ? date('d M Y', strtotime($b['booking_date'])) : date('d M Y', strtotime($b['created_at'])) ?></span>
                                <?php if (!empty($b['booking_time'])): ?>
                                    <span><i class="fa-solid fa-clock"></i><?= date('h:i A', strtotime($b['booking_time'])) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($b['mechanic_name'])): ?>
                                    <span class="mech-chip"><i class="fa-solid fa-wrench"></i><?= htmlspecialchars($b['mechanic_name']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="text-md-end d-flex d-md-block align-items-center justify-content-between gap-3">
                        <div>
                            <div class="booking-amount">₹<?= number_format((float)($b['amount'] ?? 0), 2) ?></div>
                        </div>
                        <div class="mt-md-2">
                            <?php if (in_array($st, ['completed','admin verified','cancelled'])): ?>
                                <a href="track.php?id=<?= (int)$b['id'] ?>" class="btn-view">
                                    <i class="fa-solid fa-eye"></i> View
                                </a>
                            <?php else: ?>
                                <a href="track.php?id=<?= (int)$b['id'] ?>" class="btn-view" style="background:linear-gradient(135deg,#F59E0B,#D97706);">
                                    <i class="fa-solid fa-location-dot"></i> Track
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- PAGINATION -->
        <?php if ($total_pages > 1): ?>
        <nav class="mt-4">
            <ul class="pagination justify-content-center">
                <?php if ($page > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="?<?= http_build_query(['status'=>$status_filter,'search'=>$search,'sort'=>$sort,'page'=>$page-1]) ?>"><i class="fa-solid fa-chevron-left"></i></a>
                    </li>
                <?php endif; ?>

                <?php
                    $start = max(1, $page - 2);
                    $end = min($total_pages, $page + 2);
                    if ($start > 1) { echo '<li class="page-item"><a class="page-link" href="?' . http_build_query(['status'=>$status_filter,'search'=>$search,'sort'=>$sort,'page'=>1]) . '">1</a></li>'; if ($start > 2) echo '<li class="page-item disabled"><span class="page-link">…</span></li>'; }
                    for ($i = $start; $i <= $end; $i++):
                ?>
                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(['status'=>$status_filter,'search'=>$search,'sort'=>$sort,'page'=>$i]) ?>"><?= $i ?></a>
                    </li>
                <?php endfor;
                    if ($end < $total_pages) { if ($end < $total_pages - 1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>'; echo '<li class="page-item"><a class="page-link" href="?' . http_build_query(['status'=>$status_filter,'search'=>$search,'sort'=>$sort,'page'=>$total_pages]) . '">' . $total_pages . '</a></li>'; }
                ?>

                <?php if ($page < $total_pages): ?>
                    <li class="page-item">
                        <a class="page-link" href="?<?= http_build_query(['status'=>$status_filter,'search'=>$search,'sort'=>$sort,'page'=>$page+1]) ?>"><i class="fa-solid fa-chevron-right"></i></a>
                    </li>
                <?php endif; ?>
            </ul>
        </nav>
        <?php endif; ?>

    <?php else: ?>
        <div class="empty-state">
            <i class="fa-solid fa-folder-open"></i>
            <h5>
                <?php if ($search !== ''): ?>
                    No results found
                <?php elseif ($status_filter !== 'all'): ?>
                    No <?= htmlspecialchars($status_filter) ?> bookings
                <?php else: ?>
                    No bookings yet
                <?php endif; ?>
            </h5>
            <p>
                <?php if ($search !== ''): ?>
                    Try different keywords or clear your search.
                <?php elseif ($status_filter !== 'all'): ?>
                    You don't have any bookings in this category.
                <?php else: ?>
                    Book your first service and it will appear here.
                <?php endif; ?>
            </p>
            <a href="book.php" class="btn-primary-c">
                <i class="fa-solid fa-plus"></i> Book a Service
            </a>
        </div>
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>