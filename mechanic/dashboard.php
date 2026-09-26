<?php
// htdocs/mechanic/dashboard.php — FULL WORKFLOW VERSION
if (session_status() === PHP_SESSION_NONE) { session_start(); }
ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL);

// ========== BOOTSTRAP ==========
$db_loaded = false;
if (file_exists(__DIR__ . '/../config/db.php')) { require_once __DIR__ . '/../config/db.php'; $db_loaded = true; }
if (file_exists(__DIR__ . '/../config/security.php')) { require_once __DIR__ . '/../config/security.php'; }
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

// ========== SESSION ==========
$mechanic_id = (int)($_SESSION['user_id'] ?? $_SESSION['mechanic_id'] ?? $_SESSION['id'] ?? 0);
if ($mechanic_id <= 0) { header("Location: ../index.php"); exit; }

// ========== SELF-HEALING ==========
function table_exists(PDO $pdo, string $t): bool { try { $s=$pdo->prepare("SHOW TABLES LIKE ?"); $s->execute([$t]); return $s->fetch()!==false; } catch (PDOException $e) { return false; } }
function column_exists(PDO $pdo, string $t, string $c): bool { try { $s=$pdo->prepare("SHOW COLUMNS FROM `$t` LIKE ?"); $s->execute([$c]); return $s->fetch()!==false; } catch (PDOException $e) { return false; } }
function ensure_column(PDO $pdo, string $t, string $c, string $d): void { if (!table_exists($pdo,$t)||column_exists($pdo,$t,$c)) return; try { $pdo->exec("ALTER TABLE `$t` ADD COLUMN `$c` $d"); } catch (PDOException $e) {} }


$booking_cols = [
    'booking_ref'=>"VARCHAR(50) NULL",'user_id'=>"INT NULL",'mechanic_id'=>"INT NULL",'category_id'=>"INT NULL",
    'service_type'=>"VARCHAR(120) NULL",'booking_date'=>"DATE NULL",'booking_time'=>"TIME NULL",
    'status'=>"VARCHAR(60) DEFAULT 'Pending'",'area'=>"VARCHAR(120) NULL",'address'=>"TEXT NULL",'notes'=>"TEXT NULL",
    'amount'=>"DECIMAL(10,2) DEFAULT 0",'mechanic_earning'=>"DECIMAL(10,2) DEFAULT 0",'admin_commission'=>"DECIMAL(10,2) DEFAULT 0",
    'commission_settled'=>"TINYINT(1) DEFAULT 0",'settled_at'=>"DATETIME NULL",'assigned_at'=>"DATETIME NULL",
    'accepted_at'=>"DATETIME NULL",'started_at'=>"DATETIME NULL",'completed_at'=>"DATETIME NULL",
    'broadcast_at'=>"DATETIME NULL",'completion_otp'=>"VARCHAR(10) NULL",'completion_proof'=>"VARCHAR(255) NULL",
    'completion_notes'=>"TEXT NULL",'mechanic_profit'=>"DECIMAL(10,2) DEFAULT 0",'admin_verified_at'=>"DATETIME NULL",
    'otp_verified'=>"TINYINT(1) DEFAULT 0",'created_at'=>"TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
];
foreach ($booking_cols as $c=>$d) ensure_column($pdo,'bookings',$c,$d);

$user_cols = ['specialization'=>"VARCHAR(120) NULL",'availability'=>"VARCHAR(20) DEFAULT 'available'",'kyc_status'=>"VARCHAR(20) DEFAULT 'pending'",'is_banned'=>"TINYINT(1) DEFAULT 0",'area'=>"VARCHAR(120) NULL",'address'=>"TEXT NULL",'created_at'=>"TIMESTAMP DEFAULT CURRENT_TIMESTAMP"];
foreach ($user_cols as $c=>$d) ensure_column($pdo,'users',$c,$d);

ensure_column($pdo, 'booking_history', 'proof_file_path', "VARCHAR(255) NULL");

try {
    $pdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('commission_rate', '15.0')");
    $pdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('payment_qr', '')");
    $pdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('admin_upi_id', '')");
} catch (PDOException $e) {}

// ========== LOAD MECHANIC ==========
$mechanic = null;
try { $stmt=$pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1"); $stmt->execute([$mechanic_id]); $mechanic=$stmt->fetch(); } catch (PDOException $e) {}
if (!$mechanic) $mechanic = ['id'=>$mechanic_id,'name'=>$_SESSION['name']??'Partner','phone'=>'','email'=>'','role'=>'mechanic','specialization'=>'','availability'=>'available','kyc_status'=>'pending','is_banned'=>0];
if (!empty($mechanic['is_banned'])) { session_destroy(); header("Location: ../index.php?banned=1"); exit; }

$comm_rate = 15.0;
try { $v=$pdo->query("SELECT setting_value FROM settings WHERE setting_key='commission_rate'")->fetchColumn(); if ($v!==false&&$v!==null&&$v!=='') $comm_rate=(float)$v; } catch (PDOException $e) {}

$kyc_normalized = strtolower(trim($mechanic['kyc_status'] ?? 'pending'));
if (in_array($kyc_normalized,['verified','approved','active','1','yes'])) $kyc_normalized='verified';
elseif (in_array($kyc_normalized,['rejected','denied','0','no'])) $kyc_normalized='rejected';
else $kyc_normalized='pending';

// QR
$admin_qr=''; $admin_upi='';
try {
    $v=$pdo->query("SELECT setting_value FROM settings WHERE setting_key='payment_qr'")->fetchColumn(); if ($v!==false&&$v!==null&&trim($v)!=='') $admin_qr=trim($v);
    $u=$pdo->query("SELECT setting_value FROM settings WHERE setting_key='admin_upi_id'")->fetchColumn(); if ($u!==false&&$u!==null&&trim($u)!=='') $admin_upi=trim($u);
} catch (PDOException $e) {}
if (empty($admin_qr)) { $c=glob(__DIR__.'/../uploads/qr/*.{png,jpg,jpeg,webp}',GLOB_BRACE); if (!empty($c)) $admin_qr='uploads/qr/'.basename($c[0]); }

$qr_display_src='';
if (!empty($admin_qr)) {
    if (strpos($admin_qr,'http')===0) $qr_display_src=$admin_qr;
    elseif (strpos($admin_qr,'uploads/')===0) $qr_display_src='../'.$admin_qr;
    else $qr_display_src='../'.ltrim($admin_qr,'/');
}

// ========== HANDLE POST ==========
$flash='';
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $flash="<div class='alert alert-danger shadow-sm border-0'>Security token expired.</div>";
    } else {
        $action=$_POST['action'];
        try {
            // ---- ACCEPT JOB ----
            if ($action==='accept_job') {
                $bid=(int)($_POST['booking_id'] ?? 0);
                $stmt=$pdo->prepare("UPDATE bookings SET mechanic_id=?, status='Accepted', accepted_at=NOW(), assigned_at=COALESCE(assigned_at,NOW()) WHERE id=? AND status IN ('Pending Assignment','Assigned','Pending','Broadcast') AND (mechanic_id IS NULL OR mechanic_id=0 OR mechanic_id=?)");
                $stmt->execute([$mechanic_id,$bid,$mechanic_id]);
                if ($stmt->rowCount()>0) {
                    try { $pdo->prepare("UPDATE users SET availability='busy' WHERE id=?")->execute([$mechanic_id]); } catch (PDOException $e) {}
                    try { $pdo->prepare("INSERT INTO booking_history (booking_id,status_updated_to,notes) VALUES (?, 'Accepted', ?)")->execute([$bid,"Accepted by mechanic #$mechanic_id"]); } catch (PDOException $e) {}
                    $flash="<div class='alert alert-success shadow-sm border-0'><i class='fa-solid fa-circle-check me-2'></i>Job accepted! Full details unlocked.</div>";
                } else {
                    $flash="<div class='alert alert-warning shadow-sm border-0'>Job already taken by another partner.</div>";
                }
            }

            // ---- START JOB (mark In Progress) ----
            elseif ($action==='start_job') {
                $bid=(int)($_POST['booking_id'] ?? 0);

                // Generate OTP for customer at start
                $otp = str_pad((string)random_int(0,9999), 4, '0', STR_PAD_LEFT);

                $stmt = $pdo->prepare("UPDATE bookings SET status='In Progress', started_at=NOW(), completion_otp=? WHERE id=? AND mechanic_id=? AND status IN ('Accepted','On The Way')");
                $stmt->execute([$otp, $bid, $mechanic_id]);

                if ($stmt->rowCount() > 0) {
                    try { $pdo->prepare("INSERT INTO booking_history (booking_id,status_updated_to,notes) VALUES (?, 'In Progress', ?)")->execute([$bid,"Work started. Customer OTP: $otp"]); } catch (PDOException $e) {}
                    $flash="<div class='alert alert-primary shadow-sm border-0'><i class='fa-solid fa-play me-2'></i>Work started! Ask customer for OTP <strong>$otp</strong> when done.</div>";
                } else {
                    $flash="<div class='alert alert-warning shadow-sm border-0'>Could not start job. It may have changed status.</div>";
                }
            }

            // ---- CANCEL JOB ----
            elseif ($action==='cancel_job') {
                $bid=(int)($_POST['booking_id'] ?? 0);
                $reason = clean_input($_POST['cancel_reason'] ?? 'Cancelled by mechanic');
                $pdo->prepare("UPDATE bookings SET status='Cancelled' WHERE id=? AND mechanic_id=? AND status IN ('Accepted','On The Way','In Progress')")->execute([$bid,$mechanic_id]);
                try { $pdo->prepare("INSERT INTO booking_history (booking_id,status_updated_to,notes) VALUES (?, 'Cancelled', ?)")->execute([$bid,"Cancelled by mechanic: $reason"]); } catch (PDOException $e) {}
                try { $pdo->prepare("UPDATE users SET availability='available' WHERE id=?")->execute([$mechanic_id]); } catch (PDOException $e) {}
                $flash="<div class='alert alert-warning shadow-sm border-0'>Job cancelled.</div>";
            }

            // ---- UPDATE PROFILE ----
            elseif ($action==='update_profile') {
                $name=clean_input($_POST['name']??''); $phone=clean_input($_POST['phone']??'');
                $spec=clean_input($_POST['specialization']??''); $av=clean_input($_POST['availability']??'available');
                if (!in_array($av,['available','busy','offline'])) $av='available';
                $pdo->prepare("UPDATE users SET name=?, phone=?, specialization=?, availability=? WHERE id=?")->execute([$name,$phone,$spec,$av,$mechanic_id]);
                $flash="<div class='alert alert-success shadow-sm border-0'>Profile updated.</div>";
                $s=$pdo->prepare("SELECT * FROM users WHERE id=?"); $s->execute([$mechanic_id]); $f=$s->fetch(); if ($f) $mechanic=$f;
            }
        } catch (PDOException $e) {
            $flash="<div class='alert alert-danger shadow-sm border-0'>Error: ".htmlspecialchars($e->getMessage())."</div>";
        }
    }
}

// ========== FETCH DATA ==========
$stats=['total'=>0,'assigned'=>0,'active'=>0,'completed'=>0,'total_earning'=>0,'pending_commission'=>0,'settled_commission'=>0];
try {
    $stmt=$pdo->prepare("SELECT COUNT(*) AS total,
        SUM(CASE WHEN status IN ('Assigned','Pending Assignment','Broadcast') AND (mechanic_id IS NULL OR mechanic_id=0 OR mechanic_id=?) THEN 1 ELSE 0 END) AS assigned,
        SUM(CASE WHEN status IN ('Accepted','On The Way','In Progress','Work Completed (Admin Verification Pending)') AND mechanic_id=? THEN 1 ELSE 0 END) AS active,
        SUM(CASE WHEN status='Completed' AND mechanic_id=? THEN 1 ELSE 0 END) AS completed,
        SUM(CASE WHEN status='Completed' AND mechanic_id=? THEN mechanic_earning ELSE 0 END) AS total_earning,
        SUM(CASE WHEN status='Completed' AND commission_settled=0 AND mechanic_id=? THEN mechanic_earning ELSE 0 END) AS pending_commission,
        SUM(CASE WHEN status='Completed' AND commission_settled=1 AND mechanic_id=? THEN mechanic_earning ELSE 0 END) AS settled_commission
        FROM bookings WHERE mechanic_id=? OR (mechanic_id IS NULL AND status IN ('Pending Assignment','Broadcast'))");
    $stmt->execute([$mechanic_id,$mechanic_id,$mechanic_id,$mechanic_id,$mechanic_id,$mechanic_id,$mechanic_id]);
    $row=$stmt->fetch();
    if ($row) foreach ($stats as $k=>$v) if (isset($row[$k]) && $row[$k]!==null) $stats[$k]=$row[$k];
} catch (PDOException $e) {}

$assignedJobs=[];
try {
    $stmt=$pdo->prepare("SELECT b.id,b.booking_ref,b.service_type,b.booking_date,b.booking_time,b.area,b.address,b.status,b.amount,b.created_at,b.mechanic_id,COALESCE(u.name,'Customer') AS customer_name FROM bookings b LEFT JOIN users u ON b.user_id=u.id WHERE ((b.status IN ('Pending Assignment','Broadcast') AND (b.mechanic_id IS NULL OR b.mechanic_id=0)) OR (b.status='Assigned' AND b.mechanic_id=?)) ORDER BY b.assigned_at DESC, b.created_at DESC");
    $stmt->execute([$mechanic_id]); $assignedJobs=$stmt->fetchAll();
} catch (PDOException $e) {}

$activeJobs=[];
try {
    $stmt=$pdo->prepare("SELECT b.*,COALESCE(u.name,'Customer') AS customer_name,u.phone AS customer_phone FROM bookings b LEFT JOIN users u ON b.user_id=u.id WHERE b.mechanic_id=? AND b.status IN ('Accepted','On The Way','In Progress','Work Completed (Admin Verification Pending)') ORDER BY b.accepted_at DESC, b.created_at DESC");
    $stmt->execute([$mechanic_id]); $activeJobs=$stmt->fetchAll();
} catch (PDOException $e) {}

$history=[];
try {
    $stmt=$pdo->prepare("SELECT b.*,COALESCE(u.name,'Customer') AS customer_name FROM bookings b LEFT JOIN users u ON b.user_id=u.id WHERE b.mechanic_id=? AND b.status IN ('Completed','Admin Verified') ORDER BY b.completed_at DESC, b.created_at DESC LIMIT 50");
    $stmt->execute([$mechanic_id]); $history=$stmt->fetchAll();
} catch (PDOException $e) {}

$commissionHistory=[];
try {
    $stmt=$pdo->prepare("SELECT id,booking_ref,service_type,booking_date,completed_at,amount,admin_commission,mechanic_earning,commission_settled,settled_at FROM bookings WHERE mechanic_id=? AND status IN ('Completed','Admin Verified') ORDER BY completed_at DESC, created_at DESC LIMIT 100");
    $stmt->execute([$mechanic_id]); $commissionHistory=$stmt->fetchAll();
} catch (PDOException $e) {}

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
    return 'badge-assigned';
}
function progress_step($s) {
    $s = strtolower(trim($s));
    if ($s==='accepted') return 1;
    if ($s==='on the way') return 2;
    if ($s==='in progress') return 3;
    if (strpos($s,'work completed')!==false) return 4;
    if (in_array($s,['completed','admin verified'])) return 5;
    return 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mechanic Dashboard — ServiceFix</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --nav:#0B1120; --bg:#F5F7FB; --primary:#6366F1; --primary-dark:#4F46E5; --border:#E5E7EB; --dark:#0F172A; --muted:#64748B;}
        * { box-sizing: border-box;}
        body { font-family:'Inter',sans-serif; background:var(--bg); color:#334155; padding-bottom:60px; -webkit-font-smoothing:antialiased;}
        .navbar { background:linear-gradient(180deg,#0B1120 0%,#111827 100%) !important; padding:14px 0; box-shadow:0 4px 20px rgba(0,0,0,0.08); border-bottom:1px solid rgba(255,255,255,0.05);}
        .navbar-brand { font-weight:800; color:#fff !important; letter-spacing:-0.5px;}
        .nav-link { color:#94A3B8 !important; font-weight:500; padding:8px 16px !important; border-radius:10px; transition:all .2s; font-size:0.9rem;}
        .nav-link:hover { color:#fff !important; background:rgba(255,255,255,0.06);}
        .nav-link.active { color:#fff !important; background:linear-gradient(135deg,var(--primary),var(--primary-dark)); box-shadow:0 4px 12px rgba(99,102,241,0.35);}
        .stat-card { background:#fff; border-radius:16px; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,0.02); border:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; transition:all 0.25s ease; position:relative; overflow:hidden;}
        .stat-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; opacity:0; transition:opacity .25s;}
        .stat-card:hover { transform:translateY(-3px); box-shadow:0 12px 24px -8px rgba(0,0,0,0.08);}
        .stat-card:hover::before { opacity:1;}
        .stat-card.sc-primary::before { background:var(--primary);}
        .stat-card.sc-info::before    { background:#06B6D4;}
        .stat-card.sc-success::before { background:#10B981;}
        .stat-card.sc-warning::before { background:#F59E0B;}
        .stat-card .icon-box { width:48px; height:48px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:1.25rem;}
        .panel { background:#fff; border-radius:18px; border:1px solid var(--border); box-shadow:0 1px 3px rgba(0,0,0,0.02); overflow:hidden;}
        .panel-header { padding:18px 22px; border-bottom:1px solid #F1F5F9; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;}
        .panel-title { font-weight:700; color:var(--dark); letter-spacing:-0.2px; font-size:1rem; margin:0; display:flex; align-items:center; gap:10px;}
        .panel-icon { width:32px; height:32px; border-radius:9px; display:flex; align-items:center; justify-content:center; font-size:0.85rem;}
        .table thead th { background:#F8FAFC; color:var(--muted); font-weight:700; text-transform:uppercase; font-size:0.68rem; letter-spacing:0.6px; border-bottom:1px solid var(--border); padding:14px 16px;}
        .table td { vertical-align:middle; padding:14px 16px; border-bottom:1px solid #F1F5F9; font-size:0.9rem;}
        .table tbody tr:hover { background:#FAFBFF;}
        .badge-soft { padding:6px 12px; border-radius:100px; font-weight:700; font-size:0.7rem; display:inline-flex; align-items:center; gap:5px;}
        .badge-assigned { background:#E2E8F0; color:#475569;}
        .badge-accepted { background:#DBEAFE; color:#1D4ED8;}
        .badge-onway { background:#FEF3C7; color:#92400E;}
        .badge-in-progress { background:#CFFAFE; color:#155E75;}
        .badge-workdone { background:#EDE9FE; color:#5B21B6;}
        .badge-completed { background:#D1FAE5; color:#065F46;}
        .badge-cancelled { background:#FEE2E2; color:#991B1B;}
        .badge-pending { background:#FEF3C7; color:#92400E;}
        .badge-settled { background:#D1FAE5; color:#065F46;}
        .badge-live { background:#DCFCE7; color:#166534;}
        .nav-tabs .nav-link { color:var(--muted); font-weight:600; border:none; border-bottom:3px solid transparent; padding:12px 22px; background:transparent; font-size:0.9rem; transition:.25s;}
        .nav-tabs .nav-link:hover { color:var(--primary);}
        .nav-tabs .nav-link.active { color:var(--primary); border-bottom:3px solid var(--primary);}
        .pulse-dot { display:inline-block; width:8px; height:8px; border-radius:50%; background:#10B981; box-shadow:0 0 0 0 rgba(16,185,129,0.7); animation:pulse 1.6s infinite;}
        @keyframes pulse { 0% { box-shadow:0 0 0 0 rgba(16,185,129,0.7);} 70% { box-shadow:0 0 0 12px rgba(16,185,129,0);} 100% { box-shadow:0 0 0 0 rgba(16,185,129,0);} }
        .modal-content { border-radius:18px; border:none;}
        .modal-header { border-bottom:1px solid #F1F5F9; padding:20px 24px;}
        .modal-body { padding:22px 24px;}
        #flash-container { animation: slideDown 0.5s ease forwards;}
        @keyframes slideDown { from { opacity:0; transform:translateY(-20px);} to { opacity:1; transform:translateY(0);} }
        .alert { border-radius:12px; padding:14px 18px; font-weight:500;}
        .qr-box { background:#F8FAFC; border:1px solid var(--border); border-radius:14px; padding:18px;}
        .qr-img { background:#fff; padding:10px; border-radius:10px; border:1px solid var(--border); max-height:200px;}

        /* STEPPER */
        .stepper { display:flex; justify-content:space-between; position:relative; margin:20px 0; }
        .stepper::before { content:''; position:absolute; top:22px; left:40px; right:40px; height:3px; background:#E2E8F0; z-index:0; }
        .stepper .step { flex:1; text-align:center; position:relative; z-index:1; }
        .stepper .step .dot { width:44px; height:44px; border-radius:50%; background:#fff; border:3px solid #E2E8F0; display:inline-flex; align-items:center; justify-content:center; color:#94A3B8; font-size:1rem; transition:all 0.3s;}
        .stepper .step.done .dot { background:#10B981; border-color:#10B981; color:#fff;}
        .stepper .step.active .dot { background:var(--primary); border-color:var(--primary); color:#fff; box-shadow:0 0 0 5px rgba(99,102,241,0.2); animation:pulseDot 1.8s infinite;}
        @keyframes pulseDot { 0% { box-shadow:0 0 0 0 rgba(99,102,241,0.5);} 70% { box-shadow:0 0 0 12px rgba(99,102,241,0);} 100% { box-shadow:0 0 0 0 rgba(99,102,241,0);} }
        .stepper .step .label { font-size:0.7rem; font-weight:700; margin-top:8px; color:#64748B; text-transform:uppercase; letter-spacing:0.4px;}
        .stepper .step.done .label, .stepper .step.active .label { color:var(--dark);}

        .action-btn { border-radius:12px; padding:12px 20px; font-weight:700; font-size:0.9rem; transition:all 0.2s; border:none;}
        .action-btn:hover { transform:translateY(-2px); box-shadow:0 8px 16px -6px rgba(0,0,0,0.2);}
        .btn-onway { background:#F59E0B; color:#fff;}
        .btn-start { background:var(--primary); color:#fff;}
        .btn-complete-work { background:#10B981; color:#fff;}
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg sticky-top mb-4">
    <div class="container">
        <a class="navbar-brand" href="dashboard.php"><i class="fa-solid fa-tools text-warning me-2"></i>AutoCare Partner</a>
        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu"><i class="fa-solid fa-bars text-white"></i></button>
        <div class="collapse navbar-collapse" id="navMenu">
            <ul class="navbar-nav ms-auto mb-2 mb-lg-0 gap-1 align-items-center">
                <li class="nav-item"><a class="nav-link active" href="dashboard.php"><i class="fa-solid fa-gauge-high me-1"></i>Dashboard</a></li>
                <li class="nav-item d-none d-lg-block"><span class="nav-link" style="cursor:default;"><span class="pulse-dot me-2"></span><?= ucfirst(htmlspecialchars($mechanic['availability'] ?? 'available')) ?></span></li>
                <li class="nav-item"><a class="nav-link" href="#" data-bs-toggle="modal" data-bs-target="#profileModal"><i class="fa-solid fa-user-cog me-1"></i>Profile</a></li>
                <li class="nav-item ms-lg-2"><a href="../logout.php" class="btn btn-danger btn-sm px-3 fw-bold rounded-pill"><i class="fa-solid fa-power-off me-1"></i>Logout</a></li>
            </ul>
        </div>
    </div>
</nav>

<div class="container pb-5">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <h3 class="fw-bold text-dark mb-1">Welcome, <?= htmlspecialchars($mechanic['name'] ?? 'Partner') ?> 👋</h3>
            <p class="text-muted small mb-0">
                <i class="fa-solid fa-wrench me-1"></i> <?= htmlspecialchars($mechanic['specialization'] ?? 'General Partner') ?>
                &nbsp;•&nbsp; <i class="fa-solid fa-phone me-1"></i> <?= htmlspecialchars($mechanic['phone'] ?? '—') ?>
                &nbsp;•&nbsp;
                <?php
                    $kycClass='badge-pending'; $kycIcon='fa-hourglass-half'; $kycLabel='Pending';
                    if ($kyc_normalized==='verified') { $kycClass='badge-completed'; $kycIcon='fa-shield-halved'; $kycLabel='Verified'; }
                    elseif ($kyc_normalized==='rejected') { $kycClass='badge-cancelled'; $kycIcon='fa-xmark'; $kycLabel='Rejected'; }
                ?>
                <span class="badge-soft <?= $kycClass ?>"><i class="fa-solid <?= $kycIcon ?>"></i> KYC: <?= $kycLabel ?></span>
            </p>
        </div>
        <button class="btn btn-primary fw-bold rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#profileModal"><i class="fa-solid fa-user-pen me-1"></i> Edit Profile</button>
    </div>

    <div id="flash-container"><?= $flash ?></div>

    <!-- KPI GRID -->
    <div class="row g-3 mb-4">
        <div class="col-md-3"><div class="stat-card sc-primary"><div><div class="text-muted small fw-bold text-uppercase" style="letter-spacing:0.5px;">Total Jobs</div><h4 class="fw-bold mb-0 text-dark mt-1"><?= number_format((int)$stats['total']) ?></h4></div><div class="icon-box" style="background:#EEF2FF; color:#4F46E5;"><i class="fa-solid fa-clipboard-list"></i></div></div></div>
        <div class="col-md-3"><div class="stat-card sc-info"><div><div class="text-muted small fw-bold text-uppercase" style="letter-spacing:0.5px;">New Pings</div><h4 class="fw-bold mb-0 mt-1" style="color:#0891B2;"><?= number_format((int)$stats['assigned']) ?></h4></div><div class="icon-box" style="background:#CFFAFE; color:#0891B2;"><i class="fa-solid fa-bell"></i></div></div></div>
        <div class="col-md-3"><div class="stat-card sc-success"><div><div class="text-muted small fw-bold text-uppercase" style="letter-spacing:0.5px;">Completed</div><h4 class="fw-bold mb-0 text-success mt-1"><?= number_format((int)$stats['completed']) ?></h4></div><div class="icon-box" style="background:#D1FAE5; color:#059669;"><i class="fa-solid fa-check-circle"></i></div></div></div>
        <div class="col-md-3"><div class="stat-card sc-warning"><div><div class="text-muted small fw-bold text-uppercase" style="letter-spacing:0.5px;">Pending Commission</div><h4 class="fw-bold mb-0 mt-1" style="color:#D97706;">₹<?= number_format((float)$stats['pending_commission'], 2) ?></h4></div><div class="icon-box" style="background:#FFFBEB; color:#D97706;"><i class="fa-solid fa-hourglass-half"></i></div></div></div>
    </div>

    <!-- TABS -->
    <ul class="nav nav-tabs mb-4" id="mainTabs">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-assigned"><i class="fa-solid fa-satellite-dish me-1"></i>New Pings <?php if (count($assignedJobs)>0): ?><span class="badge bg-danger ms-1 rounded-pill"><?= count($assignedJobs) ?></span><?php endif; ?></button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-active"><i class="fa-solid fa-wrench me-1"></i>Active Jobs <?php if (count($activeJobs)>0): ?><span class="badge bg-primary ms-1 rounded-pill"><?= count($activeJobs) ?></span><?php endif; ?></button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-history"><i class="fa-solid fa-clock-rotate-left me-1"></i>Work History</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-commission"><i class="fa-solid fa-indian-rupee-sign me-1"></i>Commission / Pay</button></li>
    </ul>

    <div class="tab-content">

        <!-- TAB 1: NEW PINGS -->
        <div class="tab-pane fade show active" id="tab-assigned">
            <div class="panel">
                <div class="panel-header">
                    <h6 class="panel-title"><span class="panel-icon" style="background:#E0E7FF; color:#4F46E5;"><i class="fa-solid fa-satellite-dish"></i></span>New Job Pings from Admin</h6>
                    <span class="badge-soft badge-live"><span class="pulse-dot me-1" style="background:#16A34A;"></span>LIVE — First to accept wins</span>
                </div>
                <div class="p-3 border-bottom bg-light small text-muted"><i class="fa-solid fa-lock me-1"></i> Limited info shown before accepting — only name and area.</div>
                <div class="table-responsive">
                    <table class="table mb-0 align-middle">
                        <thead><tr><th>#</th><th>Customer</th><th>Area / Address</th><th>Service</th><th>Date</th><th class="text-end">Action</th></tr></thead>
                        <tbody>
                        <?php if (count($assignedJobs)>0): ?>
                            <?php foreach ($assignedJobs as $i=>$j): ?>
                                <tr>
                                    <td class="text-muted"><?= $i+1 ?></td>
                                    <td class="fw-semibold text-dark"><?= htmlspecialchars($j['customer_name'] ?? 'Customer') ?>
                                        <?php if (($j['mechanic_id'] ?? 0)==$mechanic_id && ($j['status'] ?? '')==='Assigned'): ?>
                                            <span class="badge-soft badge-accepted ms-2" style="font-size:0.6rem;"><i class="fa-solid fa-user-check"></i>Assigned to you</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><div class="fw-medium text-dark"><?= htmlspecialchars($j['area'] ?? '—') ?></div><div class="text-muted small"><?= htmlspecialchars($j['address'] ?? '') ?></div></td>
                                    <td><?= htmlspecialchars($j['service_type'] ?? 'Service') ?></td>
                                    <td><div class="fw-medium"><?= !empty($j['booking_date']) ? date('d M Y', strtotime($j['booking_date'])) : '—' ?></div><div class="text-muted small"><?= !empty($j['booking_time']) ? date('h:i A', strtotime($j['booking_time'])) : '' ?></div></td>
                                    <td class="text-end">
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                            <input type="hidden" name="action" value="accept_job">
                                            <input type="hidden" name="booking_id" value="<?= (int)$j['id'] ?>">
                                            <button type="submit" class="btn btn-success btn-sm fw-bold rounded-pill px-3"><i class="fa-solid fa-bolt me-1"></i>Accept Now</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="text-center text-muted py-5"><i class="fa-solid fa-satellite-dish fs-1 opacity-25 d-block mb-3"></i><div class="fw-semibold">No new pings yet</div><div class="small">New jobs will appear here instantly when admin broadcasts.</div></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- TAB 2: ACTIVE JOBS with full workflow -->
        <div class="tab-pane fade" id="tab-active">
            <div class="panel">
                <div class="panel-header">
                    <h6 class="panel-title"><span class="panel-icon" style="background:#DBEAFE; color:#1D4ED8;"><i class="fa-solid fa-wrench"></i></span>Active Jobs — Full Workflow</h6>
                    <span class="badge-soft badge-in-progress"><?= count($activeJobs) ?> Active</span>
                </div>

                <?php if (count($activeJobs)>0): ?>
                    <?php foreach ($activeJobs as $j):
                        $step = progress_step($j['status']);
                        $is_waiting = strpos(strtolower($j['status']), 'work completed') !== false;
                    ?>
                    <div class="p-4 border-bottom">
                        <!-- Header -->
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                            <div>
                                <h5 class="fw-bold text-dark mb-1">#<?= htmlspecialchars($j['booking_ref'] ?? $j['id']) ?></h5>
                                <p class="text-muted small mb-0">
                                    <i class="fa-solid fa-user me-1"></i><?= htmlspecialchars($j['customer_name'] ?? 'Customer') ?>
                                    &nbsp;•&nbsp; <i class="fa-solid fa-phone me-1"></i>
                                    <a href="tel:<?= htmlspecialchars($j['customer_phone'] ?? '') ?>" class="text-decoration-none"><?= htmlspecialchars($j['customer_phone'] ?? '—') ?></a>
                                    &nbsp;•&nbsp; <i class="fa-solid fa-wrench me-1"></i><?= htmlspecialchars($j['service_type'] ?? 'Service') ?>
                                </p>
                            </div>
                            <span class="badge-soft <?= status_badge($j['status']) ?>"><?= htmlspecialchars($j['status']) ?></span>
                        </div>

                        <!-- STEPPER -->
                        <div class="stepper mb-4">
                            <div class="step <?= $step >= 1 ? 'done' : '' ?>"><div class="dot"><i class="fa-solid fa-check"></i></div><div class="label">Accepted</div></div>
                            <div class="step <?= $step >= 2 ? 'done' : '' ?>"><div class="dot"><i class="fa-solid fa-truck-fast"></i></div><div class="label">On Way</div></div>
                            <div class="step <?= $step >= 3 ? 'done' : '' ?>"><div class="dot"><i class="fa-solid fa-play"></i></div><div class="label">Started</div></div>
                            <div class="step <?= $step >= 4 ? 'done' : '' ?>"><div class="dot"><i class="fa-solid fa-camera"></i></div><div class="label">Work Done</div></div>
                            <div class="step <?= $step >= 5 ? 'done' : '' ?>"><div class="dot"><i class="fa-solid fa-trophy"></i></div><div class="label">Approved</div></div>
                        </div>

                        <!-- ACTION BUTTONS -->
                        <div class="d-flex flex-wrap gap-2">
                            <?php if ($j['status'] === 'Accepted'): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                    <input type="hidden" name="action" value="start_job">
                                    <input type="hidden" name="booking_id" value="<?= (int)$j['id'] ?>">
                                    <button type="submit" class="btn action-btn btn-start"><i class="fa-solid fa-play me-2"></i>Start Work</button>
                                </form>
                                <button class="btn action-btn" style="background:#FEE2E2; color:#DC2626;" data-bs-toggle="modal" data-bs-target="#cancelModal<?= (int)$j['id'] ?>"><i class="fa-solid fa-ban me-2"></i>Cancel</button>

                            <?php elseif ($j['status'] === 'In Progress'): ?>
                                <a href="complete_job.php?id=<?= (int)$j['id'] ?>" class="btn action-btn btn-complete-work">
                                    <i class="fa-solid fa-circle-check me-2"></i>Complete Job (Upload Proof)
                                </a>
                                <button class="btn action-btn" style="background:#FEE2E2; color:#DC2626;" data-bs-toggle="modal" data-bs-target="#cancelModal<?= (int)$j['id'] ?>"><i class="fa-solid fa-ban me-2"></i>Cancel</button>

                            <?php elseif ($is_waiting): ?>
                                <div class="alert alert-warning border-0 bg-warning bg-opacity-10 text-warning mb-0 py-3 px-4 rounded-3 flex-grow-1">
                                    <i class="fa-solid fa-hourglass-half me-2"></i>
                                    <strong>Waiting for admin approval.</strong>
                                    You'll be notified once approved. Work photo and OTP already submitted.
                                </div>
                            <?php endif; ?>

                            <button class="btn action-btn" style="background:#F1F5F9; color:#475569;" data-bs-toggle="modal" data-bs-target="#detailModal<?= (int)$j['id'] ?>"><i class="fa-solid fa-circle-info me-2"></i>Details</button>
                        </div>

                        <!-- CANCEL MODAL -->
                        <div class="modal fade" id="cancelModal<?= (int)$j['id'] ?>" tabindex="-1">
                            <div class="modal-dialog modal-dialog-centered">
                                <div class="modal-content">
                                    <div class="modal-header bg-danger bg-opacity-10">
                                        <h5 class="modal-title fw-bold text-danger"><i class="fa-solid fa-ban me-2"></i>Cancel Job</h5>
                                        <button class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                        <input type="hidden" name="action" value="cancel_job">
                                        <input type="hidden" name="booking_id" value="<?= (int)$j['id'] ?>">
                                        <div class="modal-body">
                                            <p class="small text-muted mb-3">Cancelling job <strong>#<?= htmlspecialchars($j['booking_ref'] ?? $j['id']) ?></strong>. Admin will be notified.</p>
                                            <label class="form-label small fw-bold">Reason *</label>
                                            <textarea name="cancel_reason" class="form-control" rows="3" placeholder="e.g., Customer not available, Location too far..." required></textarea>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Close</button>
                                            <button type="submit" class="btn btn-danger fw-bold rounded-pill px-4">Confirm Cancel</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- DETAILS MODAL -->
                        <div class="modal fade" id="detailModal<?= (int)$j['id'] ?>" tabindex="-1">
                            <div class="modal-dialog modal-lg modal-dialog-centered">
                                <div class="modal-content">
                                    <div class="modal-header"><h5 class="modal-title fw-bold text-dark">Job #<?= htmlspecialchars($j['booking_ref'] ?? $j['id']) ?></h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
                                    <div class="modal-body">
                                        <div class="row g-4">
                                            <div class="col-md-6">
                                                <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Customer Info</h6>
                                                <p class="mb-2"><strong>Name:</strong> <?= htmlspecialchars($j['customer_name'] ?? '—') ?></p>
                                                <p class="mb-2"><strong>Phone:</strong> <?= htmlspecialchars($j['customer_phone'] ?? '—') ?></p>
                                                <p class="mb-2"><strong>Area:</strong> <?= htmlspecialchars($j['area'] ?? '—') ?></p>
                                                <p class="mb-0"><strong>Address:</strong> <?= htmlspecialchars($j['address'] ?? '—') ?></p>
                                            </div>
                                            <div class="col-md-6">
                                                <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Job Info</h6>
                                                <p class="mb-2"><strong>Service:</strong> <?= htmlspecialchars($j['service_type'] ?? '—') ?></p>
                                                <p class="mb-2"><strong>Date:</strong> <?= !empty($j['booking_date']) ? date('d M Y', strtotime($j['booking_date'])) : '—' ?></p>
                                                <p class="mb-2"><strong>Time:</strong> <?= !empty($j['booking_time']) ? date('h:i A', strtotime($j['booking_time'])) : '—' ?></p>
                                                <p class="mb-2"><strong>Amount:</strong> ₹<?= number_format((float)($j['amount'] ?? 0), 2) ?></p>
                                                <p class="mb-0"><strong>Your Earning:</strong> <span class="text-success fw-bold">₹<?= number_format((float)($j['mechanic_earning'] ?? 0), 2) ?></span></p>
                                            </div>
                                            <?php if (!empty($j['notes'])): ?>
                                            <div class="col-12">
                                                <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Notes</h6>
                                                <p class="mb-0"><?= nl2br(htmlspecialchars($j['notes'])) ?></p>
                                            </div>
                                            <?php endif; ?>
                                            <?php if (!empty($j['completion_otp']) && $j['status'] === 'In Progress'): ?>
                                            <div class="col-12">
                                                <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Customer OTP (Show/Ask)</h6>
                                                <div class="bg-light p-3 rounded-3 border text-center">
                                                    <span class="text-muted small fw-bold text-uppercase d-block mb-1">Completion PIN</span>
                                                    <span class="fw-bold text-primary" style="font-size:1.8rem;letter-spacing:8px;"><?= htmlspecialchars($j['completion_otp']) ?></span>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                            <?php if (!empty($qr_display_src)): ?>
                                            <div class="col-12">
                                                <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="fa-solid fa-qrcode text-primary me-2"></i>Payment QR</h6>
                                                <div class="qr-box">
                                                    <div class="row g-3 align-items-center">
                                                        <div class="col-md-4 text-center">
                                                            <img src="<?= htmlspecialchars($qr_display_src) ?>" alt="QR" class="img-fluid qr-img">
                                                        </div>
                                                        <div class="col-md-8">
                                                            <?php if (!empty($admin_upi)): ?>
                                                                <p class="small mb-2"><strong>UPI ID:</strong> <code><?= htmlspecialchars($admin_upi) ?></code></p>
                                                            <?php endif; ?>
                                                            <p class="small text-muted mb-0">Scan with any UPI app (GPay, PhonePe, Paytm).</p>
                                                            <span class="badge-soft badge-completed mt-2"><i class="fa-solid fa-shield-halved"></i>Admin Verified QR</span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center text-muted py-5">
                        <i class="fa-solid fa-wrench fs-1 opacity-25 d-block mb-3"></i>
                        <div class="fw-semibold">No active jobs</div>
                        <div class="small">Accept a ping to see it here.</div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- TAB 3: WORK HISTORY -->
        <div class="tab-pane fade" id="tab-history">
            <div class="panel">
                <div class="panel-header">
                    <h6 class="panel-title"><span class="panel-icon" style="background:#D1FAE5; color:#059669;"><i class="fa-solid fa-clock-rotate-left"></i></span>Work History — Completed Jobs</h6>
                    <span class="badge-soft badge-completed"><?= count($history) ?> Done</span>
                </div>
                <div class="table-responsive">
                    <table class="table mb-0 align-middle">
                        <thead><tr><th>#</th><th>Job Ref</th><th>Customer</th><th>Service</th><th>Date</th><th class="text-end">Amount</th><th class="text-end">Your Earning</th></tr></thead>
                        <tbody>
                        <?php if (count($history)>0): ?>
                            <?php foreach ($history as $i=>$h): ?>
                                <tr>
                                    <td class="text-muted"><?= $i+1 ?></td>
                                    <td class="fw-bold text-primary">#<?= htmlspecialchars($h['booking_ref'] ?? $h['id']) ?></td>
                                    <td><?= htmlspecialchars($h['customer_name'] ?? '—') ?></td>
                                    <td><?= htmlspecialchars($h['service_type'] ?? '—') ?></td>
                                    <td class="text-muted small"><?= !empty($h['completed_at']) ? date('d M Y, h:i A', strtotime($h['completed_at'])) : (!empty($h['booking_date']) ? date('d M Y', strtotime($h['booking_date'])) : '—') ?></td>
                                    <td class="text-end">₹<?= number_format((float)($h['amount'] ?? 0), 2) ?></td>
                                    <td class="text-end fw-bold text-success">₹<?= number_format((float)($h['mechanic_earning'] ?? 0), 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="7" class="text-center text-muted py-5"><i class="fa-solid fa-clock-rotate-left fs-1 opacity-25 d-block mb-3"></i><div class="fw-semibold">No completed jobs yet</div></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- TAB 4: COMMISSION -->
        <div class="tab-pane fade" id="tab-commission">
            <?php if (!empty($qr_display_src)): ?>
            <div class="panel mb-4">
                <div class="panel-header">
                    <h6 class="panel-title"><span class="panel-icon" style="background:#EEF2FF; color:#4F46E5;"><i class="fa-solid fa-qrcode"></i></span>Official Payment QR (Admin Verified)</h6>
                    <span class="badge-soft badge-completed"><i class="fa-solid fa-shield-halved"></i>Verified by Admin</span>
                </div>
                <div class="p-3">
                    <div class="row g-3 align-items-center">
                        <div class="col-md-3 text-center"><img src="<?= htmlspecialchars($qr_display_src) ?>" alt="QR" class="img-fluid qr-img"></div>
                        <div class="col-md-9">
                            <p class="mb-2 small"><strong>This is the only authorized payment QR.</strong></p>
                            <?php if (!empty($admin_upi)): ?><p class="small mb-2">UPI ID: <code><?= htmlspecialchars($admin_upi) ?></code></p><?php endif; ?>
                            <p class="small text-muted mb-0"><i class="fa-solid fa-triangle-exclamation text-warning me-1"></i>Do not use any other QR.</p>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="row g-3 mb-4">
                <div class="col-md-4"><div class="stat-card sc-success"><div><div class="text-muted small fw-bold text-uppercase" style="letter-spacing:0.5px;">Total Earning</div><h4 class="fw-bold mb-0 text-success mt-1">₹<?= number_format((float)$stats['total_earning'], 2) ?></h4></div><div class="icon-box" style="background:#D1FAE5; color:#059669;"><i class="fa-solid fa-sack-dollar"></i></div></div></div>
                <div class="col-md-4"><div class="stat-card sc-warning"><div><div class="text-muted small fw-bold text-uppercase" style="letter-spacing:0.5px;">Pending (Due from Admin)</div><h4 class="fw-bold mb-0 mt-1" style="color:#D97706;">₹<?= number_format((float)$stats['pending_commission'], 2) ?></h4></div><div class="icon-box" style="background:#FFFBEB; color:#D97706;"><i class="fa-solid fa-hourglass-half"></i></div></div></div>
                <div class="col-md-4"><div class="stat-card sc-primary"><div><div class="text-muted small fw-bold text-uppercase" style="letter-spacing:0.5px;">Settled</div><h4 class="fw-bold mb-0 text-primary mt-1">₹<?= number_format((float)$stats['settled_commission'], 2) ?></h4></div><div class="icon-box" style="background:#EEF2FF; color:#4F46E5;"><i class="fa-solid fa-circle-check"></i></div></div></div>
            </div>

            <div class="panel">
                <div class="panel-header">
                    <h6 class="panel-title"><span class="panel-icon" style="background:#EEF2FF; color:#4F46E5;"><i class="fa-solid fa-list-ul"></i></span>Commission Breakdown — Per Job</h6>
                    <span class="badge-soft badge-assigned">Platform cut: <?= $comm_rate ?>%</span>
                </div>
                <div class="table-responsive">
                    <table class="table mb-0 align-middle">
                        <thead><tr><th>Job Ref</th><th>Service</th><th>Date</th><th class="text-end">Job Amount</th><th class="text-end">Admin Cut</th><th class="text-end">Your Earning</th><th class="text-center">Status</th><th class="text-end">Due / Settled</th></tr></thead>
                        <tbody>
                        <?php if (count($commissionHistory)>0): ?>
                            <?php foreach ($commissionHistory as $c): ?>
                                <tr>
                                    <td class="fw-bold text-primary">#<?= htmlspecialchars($c['booking_ref'] ?? $c['id']) ?></td>
                                    <td><?= htmlspecialchars($c['service_type'] ?? '—') ?></td>
                                    <td class="text-muted small"><?= !empty($c['completed_at']) ? date('d M Y', strtotime($c['completed_at'])) : (!empty($c['booking_date']) ? date('d M Y', strtotime($c['booking_date'])) : '—') ?></td>
                                    <td class="text-end">₹<?= number_format((float)($c['amount'] ?? 0), 2) ?></td>
                                    <td class="text-end text-danger">-₹<?= number_format((float)($c['admin_commission'] ?? 0), 2) ?></td>
                                    <td class="text-end fw-bold text-success">₹<?= number_format((float)($c['mechanic_earning'] ?? 0), 2) ?></td>
                                    <td class="text-center">
                                        <?php if (!empty($c['commission_settled'])): ?>
                                            <span class="badge-soft badge-settled"><i class="fa-solid fa-check"></i>Settled</span>
                                        <?php else: ?>
                                            <span class="badge-soft badge-pending"><i class="fa-solid fa-hourglass-half"></i>Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end small">
                                        <?php if (!empty($c['commission_settled'])): ?>
                                            <span class="text-success"><i class="fa-solid fa-circle-check me-1"></i><?= !empty($c['settled_at']) ? date('d M Y', strtotime($c['settled_at'])) : 'Settled' ?></span>
                                        <?php else: ?>
                                            <span class="text-warning fw-semibold">Due: ₹<?= number_format((float)($c['mechanic_earning'] ?? 0), 2) ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="8" class="text-center text-muted py-5"><i class="fa-solid fa-indian-rupee-sign fs-1 opacity-25 d-block mb-3"></i><div class="fw-semibold">No commission history yet</div><div class="small">Complete jobs to see earnings breakdown.</div></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- PROFILE MODAL -->
<div class="modal fade" id="profileModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title fw-bold text-dark"><i class="fa-solid fa-user-pen text-primary me-2"></i>Edit Profile</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="update_profile">
                <div class="modal-body">
                    <div class="mb-3"><label class="form-label small fw-bold">Full Name</label><input type="text" name="name" class="form-control" value="<?= htmlspecialchars($mechanic['name'] ?? '') ?>" required></div>
                    <div class="mb-3"><label class="form-label small fw-bold">Phone Number</label><input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($mechanic['phone'] ?? '') ?>"></div>
                    <div class="mb-3"><label class="form-label small fw-bold">Specialization</label><input type="text" name="specialization" class="form-control" value="<?= htmlspecialchars($mechanic['specialization'] ?? '') ?>" placeholder="e.g., Engine, Electrical, AC Repair"></div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Availability</label>
                        <select name="availability" class="form-select">
                            <option value="available" <?= ($mechanic['availability'] ?? '') === 'available' ? 'selected' : '' ?>>🟢 Available</option>
                            <option value="busy"      <?= ($mechanic['availability'] ?? '') === 'busy' ? 'selected' : '' ?>>🟡 Busy</option>
                            <option value="offline"   <?= ($mechanic['availability'] ?? '') === 'offline' ? 'selected' : '' ?>>⚫ Offline</option>
                        </select>
                    </div>
                    <div class="mb-0">
                        <label class="form-label small fw-bold">KYC Status</label>
                        <div>
                            <?php if ($kyc_normalized === 'verified'): ?>
                                <span class="badge-soft badge-completed"><i class="fa-solid fa-shield-halved"></i>Verified</span>
                            <?php elseif ($kyc_normalized === 'rejected'): ?>
                                <span class="badge-soft badge-cancelled"><i class="fa-solid fa-xmark"></i>Rejected</span>
                            <?php else: ?>
                                <span class="badge-soft badge-pending"><i class="fa-solid fa-hourglass-half"></i>Pending Review</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary fw-bold px-4 rounded-pill">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    setTimeout(() => {
        const f = document.getElementById('flash-container');
        if (f && f.innerHTML.trim() !== '') {
            f.style.transition = 'opacity .5s';
            f.style.opacity = '0';
            setTimeout(() => f.innerHTML = '', 500);
        }
    }, 4000);

    let lastCount = <?= count($assignedJobs) ?>;
    setInterval(() => {
        const activeTab = document.querySelector('.tab-pane.active');
        if (activeTab && activeTab.id === 'tab-assigned' && !document.querySelector('.modal.show')) {
            fetch(location.href, { method: 'GET' })
                .then(r => r.text())
                .then(html => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    const newPings = doc.querySelectorAll('#tab-assigned tbody tr').length;
                    if (newPings > lastCount && lastCount > 0) location.reload();
                    lastCount = newPings;
                })
                .catch(() => {});
        }
    }, 25000);
</script>
</body>
</html>