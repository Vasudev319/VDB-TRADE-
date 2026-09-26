<?php
// htdocs/profile.php — CUSTOMER PROFILE & SETTINGS
if (session_status() === PHP_SESSION_NONE) { session_start(); }
ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL);

// ========== BOOTSTRAP ==========
$db_loaded = false;
if (file_exists(__DIR__ . '/config/db.php')) { require_once __DIR__ . '/config/db.php'; $db_loaded = true; }
if (file_exists(__DIR__ . '/config/security.php')) { require_once __DIR__ . '/config/security.php'; }

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


$user_cols = [
    'phone'=>"VARCHAR(20) NULL",'role'=>"VARCHAR(20) DEFAULT 'customer'",
    'area'=>"VARCHAR(120) NULL",'address'=>"TEXT NULL",'landmark'=>"VARCHAR(150) NULL",
    'pincode'=>"VARCHAR(10) NULL",'avatar'=>"VARCHAR(255) NULL",
    'change_otp'=>"VARCHAR(10) NULL",'change_otp_expires'=>"DATETIME NULL",
    'is_banned'=>"TINYINT(1) DEFAULT 0",
];
foreach ($user_cols as $c=>$d) ensure_column($pdo,'users',$c,$d);

// ========== LOAD CUSTOMER ==========
$customer = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $customer = $stmt->fetch();
} catch (PDOException $e) {}

if (!$customer) { session_destroy(); header("Location: index.php"); exit; }
if (!empty($customer['is_banned'])) { session_destroy(); header("Location: index.php?banned=1"); exit; }

// ========== HANDLE POST ==========
$flash = '';
$active_tab = $_GET['tab'] ?? 'profile';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $flash = "<div class='alert alert-danger border-0 shadow-sm'>Security token expired.</div>";
    } else {
        $action = $_POST['action'];
        try {
            // ---------- UPDATE PROFILE ----------
            if ($action === 'update_profile') {
                $name = clean_input($_POST['name'] ?? '');
                $phone = clean_input($_POST['phone'] ?? '');
                $area = clean_input($_POST['area'] ?? '');
                $address = clean_input($_POST['address'] ?? '');
                $landmark = clean_input($_POST['landmark'] ?? '');
                $pincode = clean_input($_POST['pincode'] ?? '');

                if (empty($name)) $flash = "<div class='alert alert-danger border-0 shadow-sm'>Name is required.</div>";
                elseif (!preg_match('/^[0-9]{10}$/', $phone)) $flash = "<div class='alert alert-danger border-0 shadow-sm'>Enter valid 10-digit phone.</div>";
                else {
                    $pdo->prepare("UPDATE users SET name = ?, phone = ?, area = ?, address = ?, landmark = ?, pincode = ? WHERE id = ?")
                        ->execute([$name, $phone, $area, $address, $landmark, $pincode, $user_id]);
                    $flash = "<div class='alert alert-success border-0 shadow-sm'><i class='fa-solid fa-circle-check me-2'></i>Profile updated successfully.</div>";
                    $_SESSION['user_name'] = $name;

                    // Refresh
                    $s = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                    $s->execute([$user_id]);
                    $customer = $s->fetch();
                }
            }

            // ---------- UPLOAD AVATAR ----------
            elseif ($action === 'upload_avatar') {
                if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
                    $flash = "<div class='alert alert-danger border-0 shadow-sm'>Please select a file.</div>";
                } else {
                    $upload_dir = __DIR__ . '/uploads/avatars/';
                    if (!is_dir($upload_dir)) @mkdir($upload_dir, 0777, true);

                    $file = $_FILES['avatar'];
                    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

                    if (!in_array($ext, ['jpg','jpeg','png','webp'])) {
                        $flash = "<div class='alert alert-danger border-0 shadow-sm'>Only JPG, PNG, or WebP allowed.</div>";
                    } elseif ($file['size'] > 3 * 1024 * 1024) {
                        $flash = "<div class='alert alert-danger border-0 shadow-sm'>Image too large. Max 3MB.</div>";
                    } else {
                        $filename = 'avatar_' . $user_id . '_' . time() . '.' . $ext;
                        $dest = $upload_dir . $filename;
                        if (move_uploaded_file($file['tmp_name'], $dest)) {
                            // Delete old avatar
                            if (!empty($customer['avatar']) && file_exists(__DIR__ . '/' . $customer['avatar']) && strpos($customer['avatar'], 'uploads/avatars/') === 0) {
                                @unlink(__DIR__ . '/' . $customer['avatar']);
                            }
                            $path = 'uploads/avatars/' . $filename;
                            $pdo->prepare("UPDATE users SET avatar = ? WHERE id = ?")->execute([$path, $user_id]);
                            $flash = "<div class='alert alert-success border-0 shadow-sm'><i class='fa-solid fa-circle-check me-2'></i>Avatar updated.</div>";

                            $s = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                            $s->execute([$user_id]);
                            $customer = $s->fetch();
                        } else {
                            $flash = "<div class='alert alert-danger border-0 shadow-sm'>Upload failed.</div>";
                        }
                    }
                }
            }

            // ---------- SEND OTP FOR PASSWORD CHANGE ----------
            elseif ($action === 'send_change_otp') {
                // Generate demo OTP (always 1234)
                $otp = DEMO_OTP;
                $pdo->prepare("UPDATE users SET change_otp = ?, change_otp_expires = DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE id = ?")
                    ->execute([$otp, $user_id]);
                $flash = "<div class='alert alert-info border-0 shadow-sm'><i class='fa-solid fa-message me-2'></i>OTP sent! Use <strong>" . DEMO_OTP . "</strong> (demo).</div>";
                $active_tab = 'security';
            }

            // ---------- CHANGE PASSWORD ----------
            elseif ($action === 'change_password') {
                $current = $_POST['current_password'] ?? '';
                $new = $_POST['new_password'] ?? '';
                $confirm = $_POST['confirm_password'] ?? '';
                $otp_entered = clean_input($_POST['change_otp'] ?? '');

                if (empty($current) || empty($new) || empty($confirm)) {
                    $flash = "<div class='alert alert-danger border-0 shadow-sm'>All fields are required.</div>";
                    $active_tab = 'security';
                } elseif (strlen($new) < 6) {
                    $flash = "<div class='alert alert-danger border-0 shadow-sm'>New password must be at least 6 characters.</div>";
                    $active_tab = 'security';
                } elseif ($new !== $confirm) {
                    $flash = "<div class='alert alert-danger border-0 shadow-sm'>New passwords do not match.</div>";
                    $active_tab = 'security';
                } elseif (!password_verify($current, $customer['password'] ?? '')) {
                    $flash = "<div class='alert alert-danger border-0 shadow-sm'>Current password is incorrect.</div>";
                    $active_tab = 'security';
                } else {
                    // Verify OTP
                    $db_otp = trim($customer['change_otp'] ?? '');
                    $db_exp = $customer['change_otp_expires'] ?? null;
                    $otpOk = false;
                    if ($otp_entered === DEMO_OTP || ($db_otp && $otp_entered === $db_otp)) {
                        if (!$db_exp || strtotime($db_exp) > time() || $otp_entered === DEMO_OTP) {
                            $otpOk = true;
                        }
                    }

                    if (!$otpOk) {
                        $flash = "<div class='alert alert-danger border-0 shadow-sm'>Invalid or expired OTP. Please request a new one.</div>";
                        $active_tab = 'security';
                    } else {
                        $hash = password_hash($new, PASSWORD_DEFAULT);
                        $pdo->prepare("UPDATE users SET password = ?, change_otp = NULL, change_otp_expires = NULL WHERE id = ?")
                            ->execute([$hash, $user_id]);
                        $flash = "<div class='alert alert-success border-0 shadow-sm'><i class='fa-solid fa-circle-check me-2'></i>Password changed successfully!</div>";
                        $active_tab = 'security';
                    }
                }
            }
        } catch (PDOException $e) {
            $flash = "<div class='alert alert-danger border-0 shadow-sm'>Error: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
}

// ========== STATS ==========
$stats = ['total'=>0,'completed'=>0,'active'=>0,'spent'=>0];
try {
    $s = $pdo->prepare("SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status IN ('Completed','Admin Verified') THEN 1 ELSE 0 END) AS completed,
        SUM(CASE WHEN status NOT IN ('Completed','Admin Verified','Cancelled') THEN 1 ELSE 0 END) AS active,
        SUM(CASE WHEN status IN ('Completed','Admin Verified') THEN amount ELSE 0 END) AS spent
        FROM bookings WHERE user_id = ?");
    $s->execute([$user_id]);
    $row = $s->fetch();
    if ($row) foreach ($stats as $k=>$v) if (isset($row[$k]) && $row[$k]!==null) $stats[$k] = $row[$k];
} catch (PDOException $e) {}

// Avatar
$avatar_url = '';
if (!empty($customer['avatar']) && file_exists(__DIR__ . '/' . $customer['avatar'])) {
    $avatar_url = $customer['avatar'];
}
$avatar_initial = strtoupper(substr($customer['name'] ?? 'C', 0, 1));
$member_since = !empty($customer['created_at']) ? date('M Y', strtotime($customer['created_at'])) : 'New Member';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile — AutoCare</title>
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

        /* Profile header */
        .profile-header { background:linear-gradient(135deg,#4F46E5 0%,#7C3AED 100%); border-radius:22px; padding:32px; color:#fff; position:relative; overflow:hidden; margin-bottom:24px; box-shadow:0 20px 40px -15px rgba(79,70,229,0.5);}
        .profile-header::before { content:''; position:absolute; width:300px; height:300px; border-radius:50%; background:rgba(255,255,255,0.08); top:-100px; right:-60px;}
        .profile-header::after { content:''; position:absolute; width:200px; height:200px; border-radius:50%; background:rgba(255,255,255,0.05); bottom:-80px; left:-40px;}

        .avatar-circle { width:90px; height:90px; border-radius:24px; background:#fff; display:flex; align-items:center; justify-content:center; font-size:2.5rem; font-weight:800; color:var(--primary-dark); box-shadow:0 12px 24px -6px rgba(0,0,0,0.25); position:relative; z-index:1; overflow:hidden; border:4px solid rgba(255,255,255,0.4);}
        .avatar-circle img { width:100%; height:100%; object-fit:cover; }
        .avatar-upload { position:absolute; bottom:4px; right:4px; width:30px; height:30px; border-radius:50%; background:#fff; color:var(--primary-dark); display:flex; align-items:center; justify-content:center; cursor:pointer; box-shadow:0 4px 8px rgba(0,0,0,0.2); font-size:0.8rem; z-index:2;}
        .avatar-upload:hover { background:#F5F7FF; }
        .avatar-wrapper { position:relative; z-index:1; }

        .profile-name { font-weight:800; font-size:1.5rem; letter-spacing:-0.6px; position:relative; z-index:1; margin: 0;}
        .profile-meta { color:rgba(255,255,255,0.85); font-size:0.9rem; position:relative; z-index:1;}
        .member-chip { background:rgba(255,255,255,0.2); padding:6px 14px; border-radius:100px; font-size:0.78rem; font-weight:700; position:relative; z-index:1; display:inline-flex; align-items:center; gap:6px; margin-top:10px;}

        /* Stats */
        .stat-mini { background:#fff; border-radius:14px; padding:16px; border:1px solid var(--border); display:flex; align-items:center; gap:12px; transition:all .2s;}
        .stat-mini:hover { transform:translateY(-2px); box-shadow:0 10px 20px -8px rgba(0,0,0,0.06);}
        .stat-mini .icon-box { width:42px; height:42px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:1.1rem; flex-shrink:0;}
        .stat-mini .label { font-size:0.68rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--muted);}
        .stat-mini .value { font-size:1.35rem; font-weight:800; color:var(--dark); letter-spacing:-0.5px; line-height:1.1;}

        /* Tabs */
        .nav-tabs-profile { border-bottom:2px solid #F1F5F9; gap:4px; margin-bottom:22px;}
        .nav-tabs-profile .nav-link { border:none; border-bottom:3px solid transparent; color:var(--muted); font-weight:700; padding:12px 18px; font-size:0.9rem; border-radius:0;}
        .nav-tabs-profile .nav-link:hover { color:var(--primary); background:transparent;}
        .nav-tabs-profile .nav-link.active { color:var(--primary); border-bottom:3px solid var(--primary); background:transparent;}

        .panel { background:#fff; border-radius:18px; border:1px solid var(--border); box-shadow:0 1px 3px rgba(0,0,0,0.02); padding:26px;}
        .panel-title { font-weight:800; color:var(--dark); font-size:1.05rem; display:flex; align-items:center; gap:10px; margin-bottom:22px; padding-bottom:14px; border-bottom:1px solid #F1F5F9;}
        .panel-icon { width:34px; height:34px; border-radius:10px; background:#EEF2FF; color:#4F46E5; display:flex; align-items:center; justify-content:center; font-size:0.9rem;}

        .form-label { font-weight:700; font-size:0.78rem; color:#475569; text-transform:uppercase; letter-spacing:0.4px; margin-bottom:6px;}
        .form-control, .form-select { border-radius:12px; padding:12px 16px; border:2px solid var(--border); font-weight:500; font-size:0.92rem; transition:0.25s; background:#F8FAFC; color:var(--dark);}
        .form-control:focus, .form-select:focus { border-color:var(--primary); background:#fff; box-shadow:0 0 0 4px rgba(99,102,241,0.1);}
        .form-control::placeholder { color:#94A3B8;}
        .form-control:disabled { background:#F1F5F9; cursor:not-allowed; color:#94A3B8;}

        .input-icon { position:relative;}
        .input-icon i { position:absolute; left:16px; top:50%; transform:translateY(-50%); color:#94A3B8; font-size:0.9rem;}
        .input-icon input { padding-left:44px;}

        .btn-primary-c { background:linear-gradient(135deg,var(--primary) 0%,var(--primary-dark) 100%); color:#fff; border:none; border-radius:12px; padding:12px 26px; font-weight:700; font-size:0.92rem; transition:all .25s; box-shadow:0 10px 20px -6px rgba(99,102,241,0.5); display:inline-flex; align-items:center; gap:8px;}
        .btn-primary-c:hover { transform:translateY(-2px); color:#fff; box-shadow:0 15px 25px -6px rgba(99,102,241,0.6);}
        .btn-primary-c:disabled { opacity:0.6; cursor:not-allowed; transform:none;}

        .btn-light-c { background:#F8FAFC; border:2px solid var(--border); color:#475569; border-radius:12px; padding:10px 20px; font-weight:700; font-size:0.88rem; transition:all .2s; display:inline-flex; align-items:center; gap:8px;}
        .btn-light-c:hover { border-color:var(--primary); color:var(--primary-dark); background:#F5F7FF;}

        .info-row { display:flex; padding:14px 0; border-bottom:1px dashed #E2E8F0; align-items:center; gap:14px;}
        .info-row:last-child { border-bottom:none;}
        .info-icon { width:42px; height:42px; border-radius:12px; background:#F8FAFC; display:flex; align-items:center; justify-content:center; color:var(--primary); font-size:1rem; flex-shrink:0;}
        .info-label { font-size:0.72rem; color:var(--muted); font-weight:700; text-transform:uppercase; letter-spacing:0.4px; margin-bottom:2px;}
        .info-value { font-size:0.95rem; font-weight:600; color:var(--dark); word-break:break-word;}

        .security-card { background:#F8FAFC; border:2px solid var(--border); border-radius:16px; padding:20px; margin-bottom:16px;}
        .security-icon { width:48px; height:48px; border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:1.3rem; flex-shrink:0;}
        .security-card h6 { font-weight:800; color:var(--dark); margin:0 0 4px; font-size:0.98rem;}
        .security-card p { color:var(--muted); margin:0; font-size:0.82rem;}

        .alert { border-radius:12px; padding:14px 18px; font-weight:500; font-size:0.9rem;}

        .twofa-badge { display:inline-flex; align-items:center; gap:6px; background:#FEF3C7; color:#92400E; padding:5px 12px; border-radius:100px; font-size:0.72rem; font-weight:700;}

        .danger-zone { background:#FEF2F2; border:2px solid #FECACA; border-radius:16px; padding:20px; margin-top:20px;}
        .danger-zone h6 { color:#DC2626; font-weight:800; margin-bottom:6px;}
        .danger-zone p { color:#991B1B; font-size:0.85rem; margin-bottom:14px;}
        .btn-danger-outline { background:#fff; border:2px solid #FECACA; color:#DC2626; border-radius:12px; padding:10px 20px; font-weight:700; font-size:0.88rem; transition:all .2s;}
        .btn-danger-outline:hover { background:#DC2626; color:#fff; border-color:#DC2626;}

        .otp-hint { font-size:0.75rem; color:#16A34A; font-weight:700; margin-top:6px;}
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
                <li class="nav-item"><a class="nav-link" href="history.php"><i class="fa-solid fa-clock-rotate-left me-1"></i>History</a></li>
                <li class="nav-item"><a class="nav-link active" href="profile.php"><i class="fa-solid fa-user me-1"></i>Profile</a></li>
                <li class="nav-item ms-lg-2"><a href="logout.php" class="btn btn-danger btn-sm px-3 fw-bold rounded-pill"><i class="fa-solid fa-power-off me-1"></i>Logout</a></li>
            </ul>
        </div>
    </div>
</nav>

<div class="container pb-5">

    <!-- PROFILE HEADER -->
    <div class="profile-header">
        <div class="d-flex align-items-center gap-4 flex-wrap">
            <div class="avatar-wrapper">
                <form method="POST" enctype="multipart/form-data" id="avatarForm" class="m-0">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                    <input type="hidden" name="action" value="upload_avatar">
                    <div class="avatar-circle" onclick="document.getElementById('avatarFile').click()" style="cursor:pointer;">
                        <?php if ($avatar_url): ?>
                            <img src="<?= htmlspecialchars($avatar_url) ?>" alt="Avatar">
                        <?php else: ?>
                            <?= $avatar_initial ?>
                        <?php endif; ?>
                    </div>
                    <div class="avatar-upload" onclick="document.getElementById('avatarFile').click()">
                        <i class="fa-solid fa-camera"></i>
                    </div>
                    <input type="file" name="avatar" id="avatarFile" accept="image/*" style="display:none;" onchange="document.getElementById('avatarForm').submit()">
                </form>
            </div>
            <div class="flex-grow-1">
                <h2 class="profile-name"><?= htmlspecialchars($customer['name'] ?? 'Customer') ?></h2>
                <p class="profile-meta mb-0">
                    <i class="fa-solid fa-phone me-1"></i><?= htmlspecialchars($customer['phone'] ?? '—') ?>
                    <?php if (!empty($customer['area'])): ?>
                        &nbsp;•&nbsp; <i class="fa-solid fa-location-dot me-1"></i><?= htmlspecialchars($customer['area']) ?>
                    <?php endif; ?>
                </p>
                <div class="member-chip">
                    <i class="fa-solid fa-star"></i> Member since <?= $member_since ?>
                </div>
            </div>
        </div>
    </div>

    <!-- FLASH -->
    <?php if ($flash): ?><?= $flash ?><?php endif; ?>

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
                <div class="icon-box" style="background:#D1FAE5; color:#059669;"><i class="fa-solid fa-check-circle"></i></div>
                <div>
                    <div class="label">Completed</div>
                    <div class="value text-success"><?= number_format((int)$stats['completed']) ?></div>
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
                <div class="icon-box" style="background:#EDE9FE; color:#7C3AED;"><i class="fa-solid fa-indian-rupee-sign"></i></div>
                <div>
                    <div class="label">Total Spent</div>
                    <div class="value">₹<?= number_format((float)$stats['spent'], 0) ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- TABS -->
    <ul class="nav nav-tabs-profile" id="profileTabs" role="tablist">
        <li class="nav-item"><button class="nav-link <?= $active_tab === 'profile' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tab-profile"><i class="fa-solid fa-user me-2"></i>Personal Info</button></li>
        <li class="nav-item"><button class="nav-link <?= $active_tab === 'address' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tab-address"><i class="fa-solid fa-location-dot me-2"></i>Address</button></li>
        <li class="nav-item"><button class="nav-link <?= $active_tab === 'security' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tab-security"><i class="fa-solid fa-shield-halved me-2"></i>Security</button></li>
    </ul>

    <div class="tab-content">

        <!-- TAB 1: PERSONAL INFO -->
        <div class="tab-pane fade <?= $active_tab === 'profile' ? 'show active' : '' ?>" id="tab-profile">
            <div class="panel">
                <div class="panel-title">
                    <div class="panel-icon"><i class="fa-solid fa-user-pen"></i></div>
                    <span>Edit Personal Information</span>
                </div>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                    <input type="hidden" name="action" value="update_profile">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Full Name *</label>
                            <div class="input-icon">
                                <i class="fa-solid fa-user"></i>
                                <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($customer['name'] ?? '') ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone Number *</label>
                            <div class="input-icon">
                                <i class="fa-solid fa-phone"></i>
                                <input type="tel" name="phone" id="profilePhone" class="form-control" pattern="[0-9]{10}" maxlength="10" value="<?= htmlspecialchars($customer['phone'] ?? '') ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email (cannot change)</label>
                            <div class="input-icon">
                                <i class="fa-solid fa-envelope"></i>
                                <input type="email" class="form-control" value="<?= htmlspecialchars($customer['email'] ?? '') ?>" disabled>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Area / City</label>
                            <div class="input-icon">
                                <i class="fa-solid fa-city"></i>
                                <input type="text" name="area" class="form-control" value="<?= htmlspecialchars($customer['area'] ?? '') ?>" placeholder="e.g., Mumbai">
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Full Address</label>
                            <textarea name="address" class="form-control" rows="3" placeholder="Flat, building, street..."><?= htmlspecialchars($customer['address'] ?? '') ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Landmark</label>
                            <input type="text" name="landmark" class="form-control" value="<?= htmlspecialchars($customer['landmark'] ?? '') ?>" placeholder="Near City Mall">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Pincode</label>
                            <input type="text" name="pincode" class="form-control" pattern="[0-9]{6}" maxlength="6" value="<?= htmlspecialchars($customer['pincode'] ?? '') ?>" placeholder="400001">
                        </div>
                    </div>

                    <div class="mt-4 d-flex gap-2">
                        <button type="submit" class="btn-primary-c">
                            <i class="fa-solid fa-save"></i> Save Changes
                        </button>
                        <a href="profile.php" class="btn-light-c">
                            <i class="fa-solid fa-rotate-left"></i> Reset
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- TAB 2: ADDRESS -->
        <div class="tab-pane fade <?= $active_tab === 'address' ? 'show active' : '' ?>" id="tab-address">
            <div class="panel">
                <div class="panel-title">
                    <div class="panel-icon"><i class="fa-solid fa-location-dot"></i></div>
                    <span>Saved Address</span>
                </div>

                <div class="info-row">
                    <div class="info-icon"><i class="fa-solid fa-user"></i></div>
                    <div class="flex-grow-1">
                        <div class="info-label">Contact Name</div>
                        <div class="info-value"><?= htmlspecialchars($customer['name'] ?? '—') ?></div>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-icon"><i class="fa-solid fa-phone"></i></div>
                    <div class="flex-grow-1">
                        <div class="info-label">Phone</div>
                        <div class="info-value"><?= htmlspecialchars($customer['phone'] ?? '—') ?></div>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-icon"><i class="fa-solid fa-city"></i></div>
                    <div class="flex-grow-1">
                        <div class="info-label">Area / City</div>
                        <div class="info-value"><?= htmlspecialchars($customer['area'] ?? 'Not set') ?></div>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-icon"><i class="fa-solid fa-map-pin"></i></div>
                    <div class="flex-grow-1">
                        <div class="info-label">Full Address</div>
                        <div class="info-value"><?= $customer['address'] ? nl2br(htmlspecialchars($customer['address'])) : 'Not set' ?></div>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-icon"><i class="fa-solid fa-flag"></i></div>
                    <div class="flex-grow-1">
                        <div class="info-label">Landmark</div>
                        <div class="info-value"><?= htmlspecialchars($customer['landmark'] ?? 'Not set') ?></div>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-icon"><i class="fa-solid fa-hashtag"></i></div>
                    <div class="flex-grow-1">
                        <div class="info-label">Pincode</div>
                        <div class="info-value"><?= htmlspecialchars($customer['pincode'] ?? 'Not set') ?></div>
                    </div>
                </div>

                <div class="mt-4">
                    <a href="?tab=profile" class="btn-primary-c">
                        <i class="fa-solid fa-pen-to-square"></i> Edit Address
                    </a>
                </div>
            </div>
        </div>

        <!-- TAB 3: SECURITY -->
        <div class="tab-pane fade <?= $active_tab === 'security' ? 'show active' : '' ?>" id="tab-security">
            <div class="panel">
                <div class="panel-title">
                    <div class="panel-icon"><i class="fa-solid fa-shield-halved"></i></div>
                    <span>Security Settings</span>
                </div>

                <!-- 2FA info -->
                <div class="security-card mb-4">
                    <div class="d-flex align-items-center gap-3">
                        <div class="security-icon" style="background:#D1FAE5; color:#059669;"><i class="fa-solid fa-shield-halved"></i></div>
                        <div class="flex-grow-1">
                            <h6>Two-Factor Authentication</h6>
                            <p>Your account is protected with password + OTP</p>
                        </div>
                        <span class="twofa-badge"><i class="fa-solid fa-check"></i> Active</span>
                    </div>
                </div>

                <!-- Change Password -->
                <h6 class="fw-bold text-dark mb-3" style="font-size:0.95rem;">
                    <i class="fa-solid fa-lock me-2 text-primary"></i>Change Password
                </h6>

                <form method="POST" id="pwdForm">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                    <input type="hidden" name="action" id="pwdAction" value="change_password">

                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Current Password *</label>
                            <div class="input-icon">
                                <i class="fa-solid fa-lock"></i>
                                <input type="password" name="current_password" class="form-control" placeholder="Enter current password" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">New Password *</label>
                            <div class="input-icon">
                                <i class="fa-solid fa-key"></i>
                                <input type="password" name="new_password" id="newPwd" class="form-control" placeholder="Min 6 characters" minlength="6" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Confirm New Password *</label>
                            <div class="input-icon">
                                <i class="fa-solid fa-key"></i>
                                <input type="password" name="confirm_password" id="confirmPwd" class="form-control" placeholder="Re-enter new password" minlength="6" required>
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="form-label">OTP Verification *</label>
                            <div class="d-flex gap-2">
                                <div class="input-icon flex-grow-1">
                                    <i class="fa-solid fa-message"></i>
                                    <input type="text" name="change_otp" id="changeOtp" class="form-control" placeholder="Enter 4-digit OTP" maxlength="4" inputmode="numeric" required>
                                </div>
                                <button type="button" class="btn-light-c" id="sendOtpBtn" onclick="sendChangeOtp()">
                                    <i class="fa-solid fa-paper-plane"></i> Send OTP
                                </button>
                            </div>
                            <div class="otp-hint" id="otpHint"></div>
                        </div>
                    </div>

                    <div class="mt-4 d-flex gap-2">
                        <button type="submit" class="btn-primary-c" onclick="return validatePwd()">
                            <i class="fa-solid fa-shield-halved"></i> Update Password
                        </button>
                    </div>
                </form>

                <!-- Danger Zone -->
                <div class="danger-zone">
                    <h6><i class="fa-solid fa-triangle-exclamation me-2"></i>Danger Zone</h6>
                    <p>Logging out will end your session on this device. You'll need to log in again with password + OTP.</p>
                    <a href="logout.php" class="btn-danger-outline">
                        <i class="fa-solid fa-power-off me-2"></i>Logout from this device
                    </a>
                </div>
            </div>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Phone input - digits only
    const phoneEl = document.getElementById('profilePhone');
    if (phoneEl) {
        phoneEl.addEventListener('input', function() {
            this.value = this.value.replace(/\D/g, '').slice(0, 10);
        });
    }

    // Send OTP for password change
    function sendChangeOtp() {
        const btn = document.getElementById('sendOtpBtn');
        const hint = document.getElementById('otpHint');

        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';

        const formData = new FormData();
        formData.append('csrf_token', '<?= generate_csrf_token() ?>');
        formData.append('action', 'send_change_otp');

        fetch(window.location.href, { method: 'POST', body: formData })
            .then(() => {
                hint.innerHTML = '<i class="fa-solid fa-check-circle me-1"></i>Demo OTP: <strong><?= DEMO_OTP ?></strong>';
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-rotate-right"></i> Resend';
            })
            .catch(() => {
                hint.innerHTML = '<i class="fa-solid fa-check-circle me-1"></i>Demo OTP: <strong><?= DEMO_OTP ?></strong>';
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-rotate-right"></i> Resend';
            });
    }

    // Password validation
    function validatePwd() {
        const newPwd = document.getElementById('newPwd').value;
        const confirmPwd = document.getElementById('confirmPwd').value;
        if (newPwd !== confirmPwd) {
            alert('New passwords do not match.');
            return false;
        }
        if (newPwd.length < 6) {
            alert('Password must be at least 6 characters.');
            return false;
        }
        return true;
    }

    // Activate tab from URL
    const params = new URLSearchParams(window.location.search);
    const tab = params.get('tab');
    if (tab) {
        const btn = document.querySelector(`[data-bs-target="#tab-${tab}"]`);
        if (btn) new bootstrap.Tab(btn).show();
    }
</script>
</body>
</html>