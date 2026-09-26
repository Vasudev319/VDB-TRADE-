<?php
// htdocs/track.php — CUSTOMER LIVE TRACKING
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


$bk_cols = [
    'problem_description'=>"TEXT NULL",'landmark'=>"VARCHAR(150) NULL",
    'contact_name'=>"VARCHAR(120) NULL",'contact_phone'=>"VARCHAR(20) NULL",
    'payment_method'=>"VARCHAR(50) NULL",'booking_ref'=>"VARCHAR(50) NULL",
    'completion_otp'=>"VARCHAR(10) NULL",'completion_proof'=>"VARCHAR(255) NULL",
    'completion_notes'=>"TEXT NULL",
    'mechanic_earning'=>"DECIMAL(10,2) DEFAULT 0",
    'admin_commission'=>"DECIMAL(10,2) DEFAULT 0",
    'accepted_at'=>"DATETIME NULL",'started_at'=>"DATETIME NULL",
    'completed_at'=>"DATETIME NULL",'admin_verified_at'=>"DATETIME NULL",
];
foreach ($bk_cols as $c=>$d) ensure_column($pdo,'bookings',$c,$d);

ensure_column($pdo, 'booking_history', 'proof_file_path', "VARCHAR(255) NULL");

$pay_cols = ['proof_file_path'=>"VARCHAR(255) NULL",'refund_status'=>"VARCHAR(50) DEFAULT 'None'"];
foreach ($pay_cols as $c=>$d) ensure_column($pdo,'payments',$c,$d);

// ========== FETCH BOOKING ==========
$booking_id = (int)($_GET['id'] ?? 0);

// If no ID, get latest active booking for this user
if ($booking_id <= 0) {
    try {
        $stmt = $pdo->prepare("SELECT id FROM bookings WHERE user_id = ? AND status NOT IN ('Completed','Cancelled') ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$user_id]);
        $booking_id = (int)$stmt->fetchColumn();
    } catch (PDOException $e) {}
}

$booking = null;
$history = [];
$payment = null;
$mechanic = null;

if ($booking_id > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT b.*, c.name AS category_name, c.icon AS category_icon
            FROM bookings b
            LEFT JOIN categories c ON b.category_id = c.id
            WHERE b.id = ? AND b.user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$booking_id, $user_id]);
        $booking = $stmt->fetch();
    } catch (PDOException $e) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = ? AND user_id = ? LIMIT 1");
            $stmt->execute([$booking_id, $user_id]);
            $booking = $stmt->fetch();
        } catch (PDOException $e2) {}
    }

    if ($booking) {
        // History
        try {
            $h = $pdo->prepare("SELECT * FROM booking_history WHERE booking_id = ? ORDER BY created_at ASC");
            $h->execute([$booking_id]);
            $history = $h->fetchAll();
        } catch (PDOException $e) {}

        // Payment
        try {
            $p = $pdo->prepare("SELECT * FROM payments WHERE booking_id = ? LIMIT 1");
            $p->execute([$booking_id]);
            $payment = $p->fetch();
        } catch (PDOException $e) {}

        // Mechanic
        if (!empty($booking['mechanic_id'])) {
            try {
                $m = $pdo->prepare("SELECT id, name, phone, specialization, availability, kyc_status FROM users WHERE id = ? LIMIT 1");
                $m->execute([$booking['mechanic_id']]);
                $mechanic = $m->fetch();
            } catch (PDOException $e) {}
        }
    }
}

// ========== HELPERS ==========
function status_badge($s) {
    $s = strtolower(trim($s));
    if (in_array($s,['pending assignment','broadcast','assigned','pending'])) return 'badge-assigned';
    if ($s==='accepted') return 'badge-accepted';
    if ($s==='on the way') return 'badge-onway';
    if ($s==='in progress') return 'badge-in-progress';
    if (strpos($s,'work completed')!==false) return 'badge-workdone';
    if (in_array($s,['completed','admin verified'])) return 'badge-completed';
    if ($s==='cancelled') return 'badge-cancelled';
    if (strpos($s,'payment')!==false || strpos($s,'verification')!==false) return 'badge-pending';
    return 'badge-assigned';
}

function progress_pct($s) {
    $s = strtolower(trim($s));
    if (strpos($s,'payment verification')!==false) return 10;
    if (in_array($s,['pending assignment','broadcast','pending'])) return 20;
    if ($s==='assigned') return 30;
    if ($s==='accepted') return 45;
    if ($s==='on the way') return 60;
    if ($s==='in progress') return 75;
    if (strpos($s,'work completed')!==false) return 90;
    if (in_array($s,['completed','admin verified'])) return 100;
    if ($s==='cancelled') return 100;
    return 5;
}

function stage_state($status, $stage) {
    $s = strtolower(trim($status));
    $order = [
        'placed' => 1,
        'verified' => 2,
        'assigned' => 3,
        'accepted' => 4,
        'progress' => 5,
        'workdone' => 6,
        'completed' => 7,
    ];

    $current = 0;
    if (strpos($s,'payment verification')!==false) $current = 1;
    elseif (in_array($s,['pending assignment','broadcast','pending'])) $current = 2;
    elseif ($s==='assigned') $current = 3;
    elseif ($s==='accepted') $current = 4;
    elseif (in_array($s,['on the way','in progress'])) $current = 5;
    elseif (strpos($s,'work completed')!==false) $current = 6;
    elseif (in_array($s,['completed','admin verified'])) $current = 7;

    $target = $order[$stage] ?? 0;
    if ($target < $current) return 'done';
    if ($target == $current) return 'active';
    return 'pending';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Track Booking — AutoCare</title>
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
        .page-header .ref { font-family:'SF Mono',monospace; background:rgba(255,255,255,0.2); padding:4px 12px; border-radius:100px; font-size:0.8rem; font-weight:700; display:inline-block; margin-top:8px; position:relative; z-index:1;}

        .panel { background:#fff; border-radius:18px; border:1px solid var(--border); box-shadow:0 1px 3px rgba(0,0,0,0.02); padding:24px; margin-bottom:20px;}
        .panel-title { font-weight:700; color:var(--dark); font-size:1rem; display:flex; align-items:center; gap:10px; margin-bottom:20px; padding-bottom:14px; border-bottom:1px solid #F1F5F9;}
        .panel-icon { width:32px; height:32px; border-radius:9px; background:#EEF2FF; color:#4F46E5; display:flex; align-items:center; justify-content:center; font-size:0.85rem;}

        .badge-soft { padding:7px 14px; border-radius:100px; font-weight:700; font-size:0.75rem; display:inline-flex; align-items:center; gap:6px;}
        .badge-assigned { background:#E2E8F0; color:#475569;}
        .badge-accepted { background:#DBEAFE; color:#1D4ED8;}
        .badge-onway { background:#FEF3C7; color:#92400E;}
        .badge-in-progress { background:#CFFAFE; color:#155E75;}
        .badge-workdone { background:#EDE9FE; color:#5B21B6;}
        .badge-completed { background:#D1FAE5; color:#065F46;}
        .badge-cancelled { background:#FEE2E2; color:#991B1B;}
        .badge-pending { background:#FEF3C7; color:#92400E;}

        /* Progress bar */
        .progress-track { height:10px; background:#F1F5F9; border-radius:100px; overflow:hidden; margin:18px 0 6px;}
        .progress-fill { height:100%; background:linear-gradient(90deg,#6366F1,#10B981); border-radius:100px; transition:width 0.6s ease; position:relative;}
        .progress-fill::after { content:''; position:absolute; right:0; top:50%; transform:translateY(-50%); width:16px; height:16px; border-radius:50%; background:#10B981; box-shadow:0 0 0 4px rgba(16,185,129,0.2); animation:pulseDot 1.8s infinite;}
        @keyframes pulseDot { 0% { box-shadow:0 0 0 0 rgba(16,185,129,0.4);} 70% { box-shadow:0 0 0 12px rgba(16,185,129,0);} 100% { box-shadow:0 0 0 0 rgba(16,185,129,0);} }
        .progress-label { font-size:0.78rem; font-weight:700; color:var(--muted); display:flex; justify-content:space-between;}

        /* Vertical Timeline */
        .timeline { position:relative; padding-left:36px; margin-top:16px;}
        .timeline::before { content:''; position:absolute; left:14px; top:6px; bottom:6px; width:2px; background:linear-gradient(180deg,#6366F1 0%,#10B981 100%); border-radius:2px;}
        .tl-item { position:relative; padding-bottom:22px;}
        .tl-item:last-child { padding-bottom:0;}
        .tl-dot { position:absolute; left:-30px; top:0; width:28px; height:28px; border-radius:50%; background:#fff; border:3px solid var(--primary); display:flex; align-items:center; justify-content:center; color:var(--primary); font-size:0.7rem; z-index:2;}
        .tl-item:last-child .tl-dot { border-color: var(--success); color: var(--success);}
        .tl-title { font-weight:700; color:var(--dark); font-size:0.9rem; margin-bottom:2px;}
        .tl-desc { font-size:0.78rem; color:var(--muted); margin-bottom:3px;}
        .tl-time { font-size:0.7rem; color:var(--primary); font-weight:700;}

        /* Stepper */
        .stepper { display:flex; justify-content:space-between; position:relative; margin:20px 0;}
        .stepper::before { content:''; position:absolute; top:22px; left:36px; right:36px; height:3px; background:#E2E8F0; z-index:0; border-radius:2px;}
        .step { flex:1; text-align:center; position:relative; z-index:1;}
        .step .dot { width:44px; height:44px; border-radius:50%; background:#fff; border:3px solid #E2E8F0; display:inline-flex; align-items:center; justify-content:center; color:#94A3B8; font-size:1rem; transition:all .3s;}
        .step.done .dot { background:#10B981; border-color:#10B981; color:#fff;}
        .step.active .dot { background:var(--primary); border-color:var(--primary); color:#fff; box-shadow:0 0 0 6px rgba(99,102,241,0.15); animation:pulseStep 1.8s infinite;}
        @keyframes pulseStep { 0% { box-shadow:0 0 0 0 rgba(99,102,241,0.5);} 70% { box-shadow:0 0 0 14px rgba(99,102,241,0);} 100% { box-shadow:0 0 0 0 rgba(99,102,241,0);} }
        .step .label { font-size:0.68rem; font-weight:700; margin-top:8px; color:#64748B; text-transform:uppercase; letter-spacing:0.4px;}
        .step.done .label, .step.active .label { color:var(--dark);}

        /* Info rows */
        .info-row { display:flex; padding:12px 0; border-bottom:1px dashed #E2E8F0; font-size:0.9rem; align-items:flex-start;}
        .info-row:last-child { border-bottom:none;}
        .info-row .lbl { color:var(--muted); font-weight:600; flex-shrink:0; width:130px; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.3px;}
        .info-row .val { color:var(--dark); font-weight:600; flex:1; word-break:break-word;}
        .info-row .val.amount { color:var(--success); font-size:1.1rem; font-weight:800;}

        /* Mechanic card */
        .mech-card { background:linear-gradient(135deg,#F5F7FF 0%,#EEF2FF 100%); border:2px solid #C7D2FE; border-radius:16px; padding:18px;}
        .mech-avatar { width:56px; height:56px; border-radius:16px; background:linear-gradient(135deg,#4F46E5,#7C3AED); color:#fff; display:flex; align-items:center; justify-content:center; font-size:1.5rem; font-weight:800; flex-shrink:0; box-shadow:0 8px 16px -6px rgba(79,70,229,0.4);}
        .mech-name { font-weight:800; color:var(--dark); font-size:1.05rem; margin:0;}
        .mech-meta { color:var(--muted); font-size:0.8rem; font-weight:500;}
        .call-btn { background:#10B981; color:#fff; border:none; border-radius:100px; padding:10px 18px; font-weight:700; font-size:0.85rem; text-decoration:none; display:inline-flex; align-items:center; gap:6px; transition:all .2s;}
        .call-btn:hover { background:#059669; color:#fff; transform:translateY(-1px); box-shadow:0 8px 16px -4px rgba(16,185,129,0.4);}

        /* OTP box */
        .otp-box { background:#FEF3C7; border:2px dashed #FDE68A; border-radius:14px; padding:20px; text-align:center;}
        .otp-label { font-size:0.75rem; font-weight:800; text-transform:uppercase; letter-spacing:0.8px; color:#92400E; margin-bottom:6px;}
        .otp-code { font-family:'SF Mono',monospace; font-size:2.2rem; font-weight:800; letter-spacing:12px; color:#B45309; line-height:1;}
        .otp-note { font-size:0.75rem; color:#92400E; margin-top:8px; font-weight:500;}

        /* Proof image */
        .proof-img { max-width:100%; border-radius:14px; border:3px solid #F1F5F9; box-shadow:0 8px 20px -6px rgba(0,0,0,0.15);}

        /* Cancel notice */
        .cancelled-box { background:#FEF2F2; border:2px solid #FECACA; border-radius:14px; padding:20px; text-align:center;}
        .cancelled-box i { font-size:2rem; color:#DC2626; margin-bottom:8px;}

        /* Empty state */
        .empty-state { background:#fff; border-radius:18px; border:1px dashed var(--border); padding:60px 24px; text-align:center;}
        .empty-state i { font-size:3.5rem; color:#CBD5E1; margin-bottom:16px; display:block;}
        .empty-state h5 { font-weight:800; color:var(--dark); margin-bottom:8px;}
        .empty-state p { color:var(--muted); font-size:0.9rem; margin-bottom:22px;}

        .btn-primary-c { background:linear-gradient(135deg,var(--primary) 0%,var(--primary-dark) 100%); color:#fff; border:none; border-radius:12px; padding:12px 26px; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; gap:8px; transition:all .25s; box-shadow:0 10px 20px -6px rgba(99,102,241,0.5);}
        .btn-primary-c:hover { transform:translateY(-2px); color:#fff; box-shadow:0 15px 25px -6px rgba(99,102,241,0.6);}

        .info-chip { background:#EFF6FF; border:1px solid #BFDBFE; border-radius:10px; padding:12px 16px; font-size:0.85rem; color:#1E40AF; font-weight:500; display:flex; gap:10px; align-items:flex-start; margin-bottom:16px;}
        .info-chip i { color:#3B82F6; margin-top:2px; font-size:1.1rem;}

        .auto-refresh-note { font-size:0.72rem; color:var(--muted); font-weight:600; display:flex; align-items:center; gap:6px; justify-content:flex-end; margin-top:8px;}
        .auto-refresh-note .dot { width:6px; height:6px; border-radius:50%; background:#10B981; animation:pulseDot 1.8s infinite;}

        @media (max-width:576px) {
            .otp-code { font-size:1.8rem; letter-spacing:8px;}
            .info-row .lbl { width:100px; font-size:0.72rem;}
        }
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
                <li class="nav-item"><a class="nav-link active" href="track.php"><i class="fa-solid fa-location-dot me-1"></i>Track</a></li>
                <li class="nav-item"><a class="nav-link" href="history.php"><i class="fa-solid fa-clock-rotate-left me-1"></i>History</a></li>
                <li class="nav-item"><a class="nav-link" href="profile.php"><i class="fa-solid fa-user me-1"></i>Profile</a></li>
                <li class="nav-item ms-lg-2"><a href="logout.php" class="btn btn-danger btn-sm px-3 fw-bold rounded-pill"><i class="fa-solid fa-power-off me-1"></i>Logout</a></li>
            </ul>
        </div>
    </div>
</nav>

<div class="container pb-5">

<?php if (!$booking): ?>
    <!-- EMPTY STATE -->
    <div class="empty-state">
        <i class="fa-solid fa-location-dot"></i>
        <h5>No Active Booking to Track</h5>
        <p>You don't have any ongoing bookings right now. Book a new service to start tracking.</p>
        <a href="book.php" class="btn-primary-c">
            <i class="fa-solid fa-plus"></i> Book a New Service
        </a>
    </div>
<?php else:

    $pct = progress_pct($booking['status']);
    $is_cancelled = strtolower(trim($booking['status'])) === 'cancelled';
    $is_completed = in_array(strtolower(trim($booking['status'])), ['completed','admin verified']);
    $is_work_done = strpos(strtolower($booking['status']), 'work completed') !== false;

?>
    <!-- PAGE HEADER -->
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <h2 class="mb-1">Track Your Booking</h2>
                <p>Live status update as your mechanic progresses.</p>
                <span class="ref"><i class="fa-solid fa-hashtag me-1"></i><?= htmlspecialchars($booking['booking_ref'] ?? 'BKG' . $booking['id']) ?></span>
            </div>
            <div class="text-end">
                <span class="badge-soft <?= status_badge($booking['status']) ?>" style="background:rgba(255,255,255,0.9);color:#0F172A;">
                    <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($booking['status']) ?>
                </span>
            </div>
        </div>
    </div>

    <!-- PROGRESS BAR -->
    <?php if (!$is_cancelled): ?>
    <div class="panel">
        <div class="progress-track">
            <div class="progress-fill" style="width: <?= $pct ?>%;"></div>
        </div>
        <div class="progress-label">
            <span>Booking Progress</span>
            <span><?= $pct ?>% Complete</span>
        </div>

        <!-- STEPPER -->
        <div class="stepper mt-4">
            <div class="step <?= stage_state($booking['status'], 'placed') ?>">
                <div class="dot"><i class="fa-solid fa-receipt"></i></div>
                <div class="label">Placed</div>
            </div>
            <div class="step <?= stage_state($booking['status'], 'verified') ?>">
                <div class="dot"><i class="fa-solid fa-check-double"></i></div>
                <div class="label">Verified</div>
            </div>
            <div class="step <?= stage_state($booking['status'], 'assigned') ?>">
                <div class="dot"><i class="fa-solid fa-user-check"></i></div>
                <div class="label">Assigned</div>
            </div>
            <div class="step <?= stage_state($booking['status'], 'progress') ?>">
                <div class="dot"><i class="fa-solid fa-wrench"></i></div>
                <div class="label">In Progress</div>
            </div>
            <div class="step <?= stage_state($booking['status'], 'workdone') ?>">
                <div class="dot"><i class="fa-solid fa-camera"></i></div>
                <div class="label">Work Done</div>
            </div>
            <div class="step <?= stage_state($booking['status'], 'completed') ?>">
                <div class="dot"><i class="fa-solid fa-trophy"></i></div>
                <div class="label">Completed</div>
            </div>
        </div>

        <?php if (!$is_completed && !$is_work_done): ?>
        <div class="auto-refresh-note">
            <span class="dot"></span> Auto-refreshing every 30 seconds
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- LEFT COLUMN -->
        <div class="col-lg-7">

            <?php if ($is_cancelled): ?>
            <div class="panel">
                <div class="cancelled-box">
                    <i class="fa-solid fa-circle-xmark"></i>
                    <h5 class="fw-bold text-danger mb-2">Booking Cancelled</h5>
                    <p class="text-muted small mb-0">This booking has been cancelled. If you made a payment, refund will be processed shortly.</p>
                </div>
            </div>
            <?php endif; ?>

            <!-- MECHANIC DETAILS -->
            <?php if ($mechanic && !$is_cancelled): ?>
            <div class="panel">
                <div class="panel-title">
                    <div class="panel-icon"><i class="fa-solid fa-user-gear"></i></div>
                    <span>Your Assigned Mechanic</span>
                </div>

                <div class="mech-card">
                    <div class="d-flex align-items-center gap-3 flex-wrap">
                        <div class="mech-avatar"><?= strtoupper(substr($mechanic['name'] ?? 'M', 0, 1)) ?></div>
                        <div class="flex-grow-1">
                            <h6 class="mech-name"><?= htmlspecialchars($mechanic['name'] ?? 'Mechanic') ?></h6>
                            <div class="mech-meta">
                                <i class="fa-solid fa-wrench me-1"></i><?= htmlspecialchars($mechanic['specialization'] ?? 'General Handyman') ?>
                            </div>
                            <?php if (!empty($mechanic['phone'])): ?>
                            <div class="mech-meta mt-1">
                                <i class="fa-solid fa-phone me-1"></i><?= htmlspecialchars($mechanic['phone']) ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($mechanic['phone']) && !$is_completed): ?>
                        <a href="tel:<?= htmlspecialchars($mechanic['phone']) ?>" class="call-btn">
                            <i class="fa-solid fa-phone"></i> Call
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php elseif (!$is_cancelled && !$mechanic): ?>
            <div class="panel">
                <div class="panel-title">
                    <div class="panel-icon"><i class="fa-solid fa-hourglass-half"></i></div>
                    <span>Finding a Mechanic</span>
                </div>
                <div class="info-chip">
                    <i class="fa-solid fa-satellite-dish"></i>
                    <span>We're broadcasting your job to available mechanics near you. First to accept gets the job.</span>
                </div>
            </div>
            <?php endif; ?>

            <!-- OTP DISPLAY (only in progress) -->
            <?php if (!empty($booking['completion_otp']) && !$is_completed && !$is_cancelled && strpos(strtolower($booking['status']), 'in progress') !== false): ?>
            <div class="panel">
                <div class="panel-title">
                    <div class="panel-icon" style="background:#FEF3C7;color:#D97706;"><i class="fa-solid fa-key"></i></div>
                    <span>Your Completion PIN</span>
                </div>
                <div class="otp-box">
                    <div class="otp-label"><i class="fa-solid fa-shield-halved me-1"></i>Share this PIN only when work is done</div>
                    <div class="otp-code"><?= htmlspecialchars($booking['completion_otp']) ?></div>
                    <div class="otp-note">The mechanic will ask for this 4-digit PIN after completing the job. This confirms you're satisfied.</div>
                </div>
            </div>
            <?php endif; ?>

            <!-- COMPLETION PROOF -->
            <?php if ($is_work_done || $is_completed): ?>
                <?php if (!empty($booking['completion_proof'])): ?>
                <div class="panel">
                    <div class="panel-title">
                        <div class="panel-icon" style="background:#D1FAE5;color:#059669;"><i class="fa-solid fa-camera"></i></div>
                        <span>Work Completion Proof</span>
                    </div>
                    <img src="<?= htmlspecialchars($booking['completion_proof']) ?>" alt="Completion Proof" class="proof-img">
                    <?php if (!empty($booking['completion_notes'])): ?>
                        <p class="mt-3 mb-0 small text-muted"><strong>Notes:</strong> <?= nl2br(htmlspecialchars($booking['completion_notes'])) ?></p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            <?php endif; ?>

            <!-- TIMELINE -->
            <div class="panel">
                <div class="panel-title">
                    <div class="panel-icon"><i class="fa-solid fa-clock-rotate-left"></i></div>
                    <span>Activity Timeline</span>
                </div>

                <?php if (count($history) > 0): ?>
                <div class="timeline">
                    <?php foreach ($history as $h): ?>
                        <div class="tl-item">
                            <div class="tl-dot"><i class="fa-solid fa-check"></i></div>
                            <div class="tl-title"><?= htmlspecialchars($h['status_updated_to']) ?></div>
                            <?php if (!empty($h['notes'])): ?>
                                <div class="tl-desc"><?= htmlspecialchars($h['notes']) ?></div>
                            <?php endif; ?>
                            <div class="tl-time"><i class="fa-regular fa-clock me-1"></i><?= date('d M Y, h:i A', strtotime($h['created_at'])) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="text-muted small py-3">No activity recorded yet. Your booking was created on <?= date('d M Y, h:i A', strtotime($booking['created_at'] ?? 'now')) ?>.</div>
                <?php endif; ?>
            </div>

        </div>

        <!-- RIGHT COLUMN -->
        <div class="col-lg-5">

            <!-- BOOKING DETAILS -->
            <div class="panel">
                <div class="panel-title">
                    <div class="panel-icon"><i class="fa-solid fa-receipt"></i></div>
                    <span>Booking Details</span>
                </div>

                <div class="info-row">
                    <div class="lbl">Service</div>
                    <div class="val">
                        <i class="fa-solid <?= htmlspecialchars($booking['category_icon'] ?? 'fa-tools') ?> me-1 text-primary"></i>
                        <?= htmlspecialchars($booking['service_type'] ?? $booking['category_name'] ?? 'Service') ?>
                    </div>
                </div>
                <div class="info-row">
                    <div class="lbl">Problem</div>
                    <div class="val"><?= nl2br(htmlspecialchars($booking['problem_description'] ?? '—')) ?></div>
                </div>
                <div class="info-row">
                    <div class="lbl">Date</div>
                    <div class="val">
                        <?= !empty($booking['booking_date']) ? date('d M Y', strtotime($booking['booking_date'])) : '—' ?>
                        <?php if (!empty($booking['booking_time'])): ?>
                            <span class="text-muted"> • <?= date('h:i A', strtotime($booking['booking_time'])) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="info-row">
                    <div class="lbl">Address</div>
                    <div class="val">
                        <?= htmlspecialchars($booking['address'] ?? '—') ?>
                        <?php if (!empty($booking['area'])): ?>
                            <div class="text-muted small mt-1"><i class="fa-solid fa-map-pin me-1"></i><?= htmlspecialchars($booking['area']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($booking['landmark'])): ?>
                            <div class="text-muted small"><i class="fa-solid fa-location-dot me-1"></i><?= htmlspecialchars($booking['landmark']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if (!empty($booking['contact_name'])): ?>
                <div class="info-row">
                    <div class="lbl">Contact</div>
                    <div class="val">
                        <?= htmlspecialchars($booking['contact_name']) ?>
                        <?php if (!empty($booking['contact_phone'])): ?>
                            <div class="text-muted small mt-1"><i class="fa-solid fa-phone me-1"></i><?= htmlspecialchars($booking['contact_phone']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                <div class="info-row">
                    <div class="lbl">Amount</div>
                    <div class="val amount">₹<?= number_format((float)($booking['amount'] ?? 0), 2) ?></div>
                </div>
                <?php if (!empty($booking['payment_method'])): ?>
                <div class="info-row">
                    <div class="lbl">Payment</div>
                    <div class="val"><?= htmlspecialchars($booking['payment_method']) ?></div>
                </div>
                <?php endif; ?>
            </div>

            <!-- PAYMENT STATUS -->
            <?php if ($payment): ?>
            <div class="panel">
                <div class="panel-title">
                    <div class="panel-icon"><i class="fa-solid fa-credit-card"></i></div>
                    <span>Payment Status</span>
                </div>

                <?php
                    $payStatus = strtolower($payment['status'] ?? 'pending');
                    $payClass = 'badge-pending';
                    $payIcon = 'fa-hourglass-half';
                    $payText = $payment['status'];
                    if ($payStatus === 'verified' || $payStatus === 'accepted') { $payClass = 'badge-completed'; $payIcon = 'fa-circle-check'; }
                    elseif ($payStatus === 'rejected') { $payClass = 'badge-cancelled'; $payIcon = 'fa-circle-xmark'; }
                ?>

                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span class="text-muted small fw-bold text-uppercase">Status</span>
                    <span class="badge-soft <?= $payClass ?>"><i class="fa-solid <?= $payIcon ?>"></i> <?= htmlspecialchars($payText) ?></span>
                </div>

                <?php if (!empty($payment['transaction_id'])): ?>
                <div class="info-row">
                    <div class="lbl">Txn ID</div>
                    <div class="val small" style="font-family:monospace;"><?= htmlspecialchars($payment['transaction_id']) ?></div>
                </div>
                <?php endif; ?>

                <?php if (!empty($payment['refund_status']) && $payment['refund_status'] !== 'None'): ?>
                <div class="info-row">
                    <div class="lbl">Refund</div>
                    <div class="val text-warning fw-bold"><?= htmlspecialchars($payment['refund_status']) ?> Refund</div>
                </div>
                <?php endif; ?>

                <?php if (!empty($payment['proof_file_path'])): ?>
                <div class="mt-3">
                    <div class="text-muted small fw-bold text-uppercase mb-2">Your Proof</div>
                    <?php if (preg_match('/\.(jpg|jpeg|png|webp)$/i', $payment['proof_file_path'])): ?>
                        <img src="<?= htmlspecialchars($payment['proof_file_path']) ?>" alt="Payment Proof" class="proof-img" style="max-height:160px;">
                    <?php else: ?>
                        <a href="<?= htmlspecialchars($payment['proof_file_path']) ?>" target="_blank" class="btn btn-sm btn-outline-primary fw-bold">
                            <i class="fa-solid fa-file-pdf me-1"></i>View Proof
                        </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($payStatus === 'pending'): ?>
                <div class="info-chip mt-3 mb-0">
                    <i class="fa-solid fa-clock"></i>
                    <span>Admin is reviewing your payment. Usually takes a few minutes.</span>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- CTA -->
            <div class="panel">
                <div class="d-grid gap-2">
                    <a href="dashboard.php" class="btn-primary-c w-100 justify-content-center">
                        <i class="fa-solid fa-house"></i> Back to Dashboard
                    </a>
                    <a href="book.php" class="btn btn-outline-primary fw-bold rounded-3 py-2">
                        <i class="fa-solid fa-plus me-1"></i> Book Another Service
                    </a>
                </div>
            </div>

        </div>
    </div>

<?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Auto-refresh every 30s if not completed/cancelled
    const currentStatus = <?= json_encode(strtolower(trim($booking['status'] ?? ''))) ?>;
    const isFinal = ['completed','admin verified','cancelled'].includes(currentStatus);
    if (!isFinal) {
        setTimeout(() => {
            if (!document.hidden) location.reload();
        }, 30000);
    }
</script>
</body>
</html>