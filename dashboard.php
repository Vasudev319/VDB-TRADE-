<?php
// htdocs/dashboard.php — CUSTOMER HOME
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
$user_id = (int)($_SESSION['user_id'] ?? $_SESSION['customer_id'] ?? $_SESSION['id'] ?? 0);
if ($user_id <= 0) { header("Location: index.php"); exit; }

// ========== SELF-HEALING SCHEMA ==========
function table_exists(PDO $pdo, string $t): bool { try { $s=$pdo->prepare("SHOW TABLES LIKE ?"); $s->execute([$t]); return $s->fetch()!==false; } catch (PDOException $e) { return false; } }
function column_exists(PDO $pdo, string $t, string $c): bool { try { $s=$pdo->prepare("SHOW COLUMNS FROM `$t` LIKE ?"); $s->execute([$c]); return $s->fetch()!==false; } catch (PDOException $e) { return false; } }
function ensure_column(PDO $pdo, string $t, string $c, string $d): void { if (!table_exists($pdo,$t)||column_exists($pdo,$t,$c)) return; try { $pdo->exec("ALTER TABLE `$t` ADD COLUMN `$c` $d"); } catch (PDOException $e) {} }


$cat_cols = [
    'icon'=>"VARCHAR(100) NULL",
    'description'=>"TEXT NULL",
    'base_price'=>"DECIMAL(10,2) DEFAULT 0",
];
foreach ($cat_cols as $c=>$d) ensure_column($pdo,'categories',$c,$d);

$user_cols = ['area'=>"VARCHAR(120) NULL",'address'=>"TEXT NULL",'created_at'=>"TIMESTAMP DEFAULT CURRENT_TIMESTAMP"];
foreach ($user_cols as $c=>$d) ensure_column($pdo,'users',$c,$d);

// Seed default categories if empty
try {
    $count = (int)$pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
    if ($count === 0) {
        $defaults = [
            ['AC Repair & Service', 'fa-snowflake', 'AC servicing, gas refill, installation', 499],
            ['Plumbing', 'fa-faucet', 'Pipe leaks, tap repair, bathroom fittings', 299],
            ['Electrical', 'fa-bolt', 'Wiring, fan, switch, light repairs', 349],
            ['Home Cleaning', 'fa-broom', 'Deep cleaning, kitchen, bathroom', 799],
            ['Appliance Repair', 'fa-blender', 'Fridge, washing machine, microwave', 449],
            ['Carpentry', 'fa-hammer', 'Furniture repair, door, window', 399],
        ];
        $ins = $pdo->prepare("INSERT INTO categories (name, icon, description, base_price) VALUES (?, ?, ?, ?)");
        foreach ($defaults as $d) $ins->execute($d);
    }
} catch (PDOException $e) {}

// ========== LOAD CUSTOMER ==========
$customer = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $customer = $stmt->fetch();
} catch (PDOException $e) {}

if (!$customer) {
    $customer = ['id'=>$user_id,'name'=>$_SESSION['name']??'Customer','phone'=>'','email'=>'','role'=>'customer'];
}

// ========== FETCH DATA ==========
$categories = [];
try { $categories = $pdo->query("SELECT * FROM categories ORDER BY id ASC LIMIT 12")->fetchAll(); } catch (PDOException $e) {}

$activeBookings = [];
try {
    $stmt = $pdo->prepare("
        SELECT b.*,
               c.name AS category_name, c.icon AS category_icon,
               COALESCE(m.name,'') AS mechanic_name, m.phone AS mechanic_phone,
               COALESCE(p.status,'Pending') AS payment_status
        FROM bookings b
        LEFT JOIN categories c ON b.category_id = c.id
        LEFT JOIN users m ON b.mechanic_id = m.id
        LEFT JOIN payments p ON b.id = p.booking_id
        WHERE b.user_id = ?
          AND b.status NOT IN ('Completed','Cancelled','Admin Verified')
        ORDER BY b.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$user_id]);
    $activeBookings = $stmt->fetchAll();
} catch (PDOException $e) {
    try {
        $stmt = $pdo->prepare("SELECT b.*, c.name AS category_name, c.icon AS category_icon, COALESCE(m.name,'') AS mechanic_name, m.phone AS mechanic_phone FROM bookings b LEFT JOIN categories c ON b.category_id=c.id LEFT JOIN users m ON b.mechanic_id=m.id WHERE b.user_id=? AND b.status NOT IN ('Completed','Cancelled') ORDER BY b.created_at DESC LIMIT 5");
        $stmt->execute([$user_id]);
        $activeBookings = $stmt->fetchAll();
    } catch (PDOException $e2) { $activeBookings = []; }
}

// Stats
$stats = ['total'=>0,'active'=>0,'completed'=>0,'spent'=>0];
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS total,
        SUM(CASE WHEN status IN ('Accepted','On The Way','In Progress','Work Completed (Admin Verification Pending)','Pending Assignment','Assigned') THEN 1 ELSE 0 END) AS active,
        SUM(CASE WHEN status='Completed' THEN 1 ELSE 0 END) AS completed,
        SUM(CASE WHEN status='Completed' THEN amount ELSE 0 END) AS spent
        FROM bookings WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $row = $stmt->fetch();
    if ($row) foreach ($stats as $k=>$v) if (isset($row[$k]) && $row[$k]!==null) $stats[$k]=$row[$k];
} catch (PDOException $e) {}

// Notification - jobs pending payment
$pendingPayment = 0;
try { $s = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE user_id=? AND status='Payment Verification Pending'"); $s->execute([$user_id]); $pendingPayment = (int)$s->fetchColumn(); } catch (PDOException $e) {}

// Helper
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
function progress_pct($s) {
    $s = strtolower(trim($s));
    if (in_array($s,['pending assignment','broadcast','pending','payment verification pending'])) return 10;
    if ($s==='assigned') return 25;
    if ($s==='accepted') return 40;
    if ($s==='on the way') return 60;
    if ($s==='in progress') return 75;
    if (strpos($s,'work completed')!==false) return 90;
    if (in_array($s,['completed','admin verified'])) return 100;
    return 5;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Dashboard — AutoCare</title>
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

        .hero { background:linear-gradient(135deg,#4F46E5 0%,#7C3AED 100%); border-radius:22px; padding:38px 32px; color:#fff; position:relative; overflow:hidden; margin-bottom:26px; box-shadow:0 20px 40px -15px rgba(79,70,229,0.5);}
        .hero::before { content:''; position:absolute; width:340px; height:340px; border-radius:50%; background:rgba(255,255,255,0.08); top:-120px; right:-80px;}
        .hero::after { content:''; position:absolute; width:220px; height:220px; border-radius:50%; background:rgba(255,255,255,0.06); bottom:-90px; right:120px;}
        .hero h2 { font-weight:800; letter-spacing:-0.7px; position:relative; z-index:1;}
        .hero p { color:rgba(255,255,255,0.85); position:relative; z-index:1; margin-bottom:0;}
        .hero-actions { position:relative; z-index:1; margin-top:18px; display:flex; gap:10px; flex-wrap:wrap;}
        .hero-btn { background:rgba(255,255,255,0.15); color:#fff; border:1px solid rgba(255,255,255,0.25); padding:10px 18px; border-radius:100px; font-weight:600; font-size:0.85rem; text-decoration:none; transition:all .2s; backdrop-filter:blur(6px);}
        .hero-btn:hover { background:#fff; color:var(--primary-dark); transform:translateY(-2px);}
        .hero-btn.primary { background:#fff; color:var(--primary-dark); border-color:#fff;}
        .hero-btn.primary:hover { background:#F5F3FF;}

        .stat-mini { background:#fff; border-radius:16px; padding:18px; border:1px solid var(--border); display:flex; align-items:center; gap:14px; transition:all .25s;}
        .stat-mini:hover { transform:translateY(-3px); box-shadow:0 12px 24px -8px rgba(0,0,0,0.08);}
        .stat-mini .icon-box { width:44px; height:44px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:1.1rem; flex-shrink:0;}
        .stat-mini .label { font-size:0.7rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--muted);}
        .stat-mini .value { font-size:1.35rem; font-weight:800; color:var(--dark); letter-spacing:-0.5px; line-height:1.1;}

        .section-title { font-weight:800; color:var(--dark); letter-spacing:-0.4px; font-size:1.15rem; margin-bottom:16px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;}
        .section-title .link { font-size:0.85rem; font-weight:600; color:var(--primary); text-decoration:none;}
        .section-title .link:hover { color:var(--primary-dark);}

        .cat-card { background:#fff; border-radius:16px; padding:22px 16px; text-align:center; border:1px solid var(--border); text-decoration:none; color:var(--dark); display:block; transition:all .25s; height:100%; position:relative; overflow:hidden;}
        .cat-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; background:var(--primary); opacity:0; transition:opacity .25s;}
        .cat-card:hover { transform:translateY(-5px); box-shadow:0 15px 30px -12px rgba(99,102,241,0.35); border-color:var(--primary); color:var(--primary-dark);}
        .cat-card:hover::before { opacity:1;}
        .cat-icon { width:60px; height:60px; border-radius:16px; display:inline-flex; align-items:center; justify-content:center; font-size:1.5rem; margin-bottom:12px; transition:transform .25s;}
        .cat-card:hover .cat-icon { transform:scale(1.1);}
        .cat-name { font-weight:700; font-size:0.9rem; margin-bottom:4px;}
        .cat-price { font-size:0.75rem; color:var(--muted); font-weight:600;}

        .booking-card { background:#fff; border-radius:18px; border:1px solid var(--border); padding:20px; margin-bottom:14px; transition:all .25s;}
        .booking-card:hover { box-shadow:0 12px 24px -8px rgba(0,0,0,0.06);}
        .booking-head { display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:10px; margin-bottom:14px;}
        .booking-ref { font-weight:800; color:var(--primary); font-size:1rem;}
        .booking-meta { font-size:0.82rem; color:var(--muted);}
        .progress-track { height:8px; background:#F1F5F9; border-radius:100px; overflow:hidden; margin:14px 0 6px;}
        .progress-fill { height:100%; background:linear-gradient(90deg,#6366F1,#10B981); border-radius:100px; transition:width .6s ease;}
        .progress-label { font-size:0.72rem; font-weight:700; color:var(--muted); display:flex; justify-content:space-between;}

        .badge-soft { padding:6px 12px; border-radius:100px; font-weight:700; font-size:0.7rem; display:inline-flex; align-items:center; gap:5px;}
        .badge-assigned { background:#E2E8F0; color:#475569;}
        .badge-accepted { background:#DBEAFE; color:#1D4ED8;}
        .badge-onway { background:#FEF3C7; color:#92400E;}
        .badge-in-progress { background:#CFFAFE; color:#155E75;}
        .badge-workdone { background:#EDE9FE; color:#5B21B6;}
        .badge-completed { background:#D1FAE5; color:#065F46;}
        .badge-cancelled { background:#FEE2E2; color:#991B1B;}
        .badge-pending { background:#FEF3C7; color:#92400E;}

        .empty-state { background:#fff; border-radius:18px; border:1px dashed var(--border); padding:50px 20px; text-align:center;}
        .empty-state i { font-size:3rem; color:#CBD5E1; margin-bottom:14px;}
        .empty-state h6 { font-weight:700; color:var(--dark); margin-bottom:6px;}
        .empty-state p { color:var(--muted); font-size:0.9rem; margin-bottom:18px;}

        .alert-banner { background:#FEF3C7; border:1px solid #FDE68A; border-radius:14px; padding:14px 18px; display:flex; align-items:center; gap:12px; margin-bottom:20px; color:#92400E; font-weight:500;}
        .alert-banner i { font-size:1.3rem;}
        .alert-banner a { color:#78350F; font-weight:700; text-decoration:none; border-bottom:2px solid #78350F;}

        .profile-img { width:40px; height:40px; border-radius:50%; object-fit:cover; border:2px solid var(--primary);}
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg sticky-top mb-4">
    <div class="container">
        <a class="navbar-brand" href="dashboard.php"><i class="fa-solid fa-bolt text-warning me-2"></i>AutoCare</a>
        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu"><i class="fa-solid fa-bars text-white"></i></button>
        <div class="collapse navbar-collapse" id="navMenu">
            <ul class="navbar-nav ms-auto mb-2 mb-lg-0 gap-1 align-items-center">
                <li class="nav-item"><a class="nav-link active" href="dashboard.php"><i class="fa-solid fa-house me-1"></i>Home</a></li>
                <li class="nav-item"><a class="nav-link" href="book.php"><i class="fa-solid fa-plus me-1"></i>Book Service</a></li>
                <li class="nav-item"><a class="nav-link" href="track.php"><i class="fa-solid fa-location-dot me-1"></i>Track</a></li>
                <li class="nav-item"><a class="nav-link" href="history.php"><i class="fa-solid fa-clock-rotate-left me-1"></i>History</a></li>
                <li class="nav-item"><a class="nav-link" href="profile.php"><i class="fa-solid fa-user me-1"></i>Profile</a></li>
                <li class="nav-item ms-lg-2"><a href="logout.php" class="btn btn-danger btn-sm px-3 fw-bold rounded-pill"><i class="fa-solid fa-power-off me-1"></i>Logout</a></li>
            </ul>
        </div>
    </div>
</nav>

<div class="container pb-5">

    <!-- HERO -->
    <div class="hero">
        <h2>Welcome back, <?= htmlspecialchars($customer['name'] ?? 'Customer') ?> 👋</h2>
        <p>Book verified mechanics, track live progress, and pay securely — all in one place.</p>
        <div class="hero-actions">
            <a href="book.php" class="hero-btn primary"><i class="fa-solid fa-bolt me-2"></i>Book a Service</a>
            <a href="track.php" class="hero-btn"><i class="fa-solid fa-satellite-dish me-2"></i>Track Booking</a>
            <a href="history.php" class="hero-btn"><i class="fa-solid fa-clock-rotate-left me-2"></i>My History</a>
        </div>
    </div>

    <!-- ALERTS -->
    <?php if ($pendingPayment > 0): ?>
    <div class="alert-banner">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <div class="flex-grow-1">
            You have <strong><?= $pendingPayment ?></strong> booking<?= $pendingPayment > 1 ? 's' : '' ?> pending payment verification.
            <a href="track.php">Complete now →</a>
        </div>
    </div>
    <?php endif; ?>

    <!-- STATS -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-mini">
                <div class="icon-box" style="background:#EEF2FF; color:#4F46E5;"><i class="fa-solid fa-clipboard-list"></i></div>
                <div>
                    <div class="label">Total Bookings</div>
                    <div class="value"><?= number_format((int)$stats['total']) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-mini">
                <div class="icon-box" style="background:#CFFAFE; color:#0891B2;"><i class="fa-solid fa-spinner"></i></div>
                <div>
                    <div class="label">Active</div>
                    <div class="value" style="color:#0891B2;"><?= number_format((int)$stats['active']) ?></div>
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
                <div class="icon-box" style="background:#FEF3C7; color:#D97706;"><i class="fa-solid fa-indian-rupee-sign"></i></div>
                <div>
                    <div class="label">Total Spent</div>
                    <div class="value">₹<?= number_format((float)$stats['spent'], 0) ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- SERVICE CATEGORIES -->
    <div class="section-title">
        <span><i class="fa-solid fa-layer-group text-primary me-2"></i>What do you need help with?</span>
        <a href="book.php" class="link">See All Services <i class="fa-solid fa-arrow-right ms-1"></i></a>
    </div>

    <?php if (count($categories) > 0): ?>
    <div class="row g-3 mb-4">
        <?php
        $colors = [
            ['bg'=>'#DBEAFE','fg'=>'#1D4ED8'],
            ['bg'=>'#FEF3C7','fg'=>'#D97706'],
            ['bg'=>'#D1FAE5','fg'=>'#059669'],
            ['bg'=>'#FCE7F3','fg'=>'#BE185D'],
            ['bg'=>'#EDE9FE','fg'=>'#7C3AED'],
            ['bg'=>'#CFFAFE','fg'=>'#0891B2'],
        ];
        foreach ($categories as $i => $c):
            $col = $colors[$i % count($colors)];
            $icon = $c['icon'] ?? 'fa-tools';
            if (strpos($icon,'fa-')!==0) $icon = 'fa-'.$icon;
        ?>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="book.php?category=<?= (int)$c['id'] ?>" class="cat-card">
                    <div class="cat-icon" style="background:<?= $col['bg'] ?>; color:<?= $col['fg'] ?>;">
                        <i class="fa-solid <?= htmlspecialchars($icon) ?>"></i>
                    </div>
                    <div class="cat-name"><?= htmlspecialchars($c['name']) ?></div>
                    <div class="cat-price">From ₹<?= number_format((float)($c['base_price'] ?? 0), 0) ?></div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="empty-state mb-4">
        <i class="fa-solid fa-layer-group"></i>
        <h6>No services available</h6>
        <p>Service categories will appear here once admin adds them.</p>
    </div>
    <?php endif; ?>

    <!-- ACTIVE BOOKINGS -->
    <div class="section-title">
        <span><i class="fa-solid fa-wrench text-primary me-2"></i>Your Active Bookings</span>
        <?php if (count($activeBookings) > 0): ?>
            <a href="track.php" class="link">Track All <i class="fa-solid fa-arrow-right ms-1"></i></a>
        <?php endif; ?>
    </div>

    <?php if (count($activeBookings) > 0): ?>
        <?php foreach ($activeBookings as $b):
            $pct = progress_pct($b['status']);
        ?>
            <div class="booking-card">
                <div class="booking-head">
                    <div>
                        <div class="booking-ref">#<?= htmlspecialchars($b['booking_ref'] ?? $b['id']) ?></div>
                        <div class="booking-meta">
                            <i class="fa-solid <?= htmlspecialchars($b['category_icon'] ?? 'fa-tools') ?> me-1"></i>
                            <?= htmlspecialchars($b['service_type'] ?? $b['category_name'] ?? 'Service') ?>
                            <?php if (!empty($b['booking_date'])): ?>
                                &nbsp;•&nbsp; <i class="fa-solid fa-calendar me-1"></i><?= date('d M Y', strtotime($b['booking_date'])) ?>
                                <?php if (!empty($b['booking_time'])): ?>
                                    <?= date('h:i A', strtotime($b['booking_time'])) ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="text-end">
                        <span class="badge-soft <?= status_badge($b['status']) ?>"><?= htmlspecialchars($b['status']) ?></span>
                        <?php if (!empty($b['amount']) && $b['amount'] > 0): ?>
                            <div class="mt-2 fw-bold text-dark">₹<?= number_format((float)$b['amount'], 2) ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="progress-track">
                    <div class="progress-fill" style="width: <?= $pct ?>%;"></div>
                </div>
                <div class="progress-label">
                    <span>Progress</span>
                    <span><?= $pct ?>%</span>
                </div>

                <?php if (!empty($b['mechanic_name'])): ?>
                <div class="d-flex align-items-center gap-2 mt-3 pt-3 border-top">
                    <div class="icon-box" style="width:36px;height:36px;background:#EEF2FF;color:#4F46E5;border-radius:10px;display:flex;align-items:center;justify-content:center;">
                        <i class="fa-solid fa-user-gear"></i>
                    </div>
                    <div class="flex-grow-1">
                        <div class="fw-semibold small text-dark"><?= htmlspecialchars($b['mechanic_name']) ?></div>
                        <div class="text-muted" style="font-size:0.72rem;">Assigned Mechanic</div>
                    </div>
                    <?php if (!empty($b['mechanic_phone'])): ?>
                        <a href="tel:<?= htmlspecialchars($b['mechanic_phone']) ?>" class="btn btn-sm btn-outline-primary rounded-pill fw-bold" style="font-size:0.72rem;">
                            <i class="fa-solid fa-phone me-1"></i>Call
                        </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="d-flex gap-2 mt-3">
                    <a href="track.php?id=<?= (int)$b['id'] ?>" class="btn btn-primary btn-sm fw-bold rounded-pill px-3 flex-grow-1">
                        <i class="fa-solid fa-location-dot me-1"></i>Track Live
                    </a>
                    <?php if (strpos(strtolower($b['status']), 'payment') !== false): ?>
                        <a href="track.php?id=<?= (int)$b['id'] ?>" class="btn btn-warning btn-sm fw-bold rounded-pill px-3 flex-grow-1">
                            <i class="fa-solid fa-credit-card me-1"></i>Complete Payment
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="empty-state">
            <i class="fa-solid fa-calendar-plus"></i>
            <h6>No active bookings</h6>
            <p>Book a service and it will appear here with live status tracking.</p>
            <a href="book.php" class="btn btn-primary rounded-pill fw-bold px-4">
                <i class="fa-solid fa-plus me-1"></i>Book Your First Service
            </a>
        </div>
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Auto-refresh progress every 45s on the active bookings
    setTimeout(() => {
        if (document.querySelectorAll('.booking-card').length > 0 && !document.hidden) {
            // Optional: auto refresh
            // location.reload();
        }
    }, 45000);
</script>
</body>
</html>