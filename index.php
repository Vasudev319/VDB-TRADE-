<?php
// htdocs/index.php — Demo OTP (1234) only, no countdown
if (session_status() === PHP_SESSION_NONE) { session_start(); }
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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
        die("<div style='font-family:sans-serif;padding:30px;background:#FEE2E2;color:#991B1B;border-radius:12px;margin:30px;'><h3>Database Connection Failed</h3><small>".htmlspecialchars($e->getMessage())."</small></div>");
    }
}

if (!function_exists('verify_csrf_token')) { function verify_csrf_token($t) { return true; } }
if (!function_exists('generate_csrf_token')) { function generate_csrf_token() { return $_SESSION['csrf_token'] ??= bin2hex(random_bytes(16)); } }
if (!function_exists('clean_input')) { function clean_input($d) { return htmlspecialchars(trim($d ?? ''), ENT_QUOTES, 'UTF-8'); } }

// ========== SELF-HEALING SCHEMA ==========
function table_exists(PDO $pdo, string $t): bool { try { $s=$pdo->prepare("SHOW TABLES LIKE ?"); $s->execute([$t]); return $s->fetch()!==false; } catch (PDOException $e) { return false; } }
function column_exists(PDO $pdo, string $t, string $c): bool { try { $s=$pdo->prepare("SHOW COLUMNS FROM `$t` LIKE ?"); $s->execute([$c]); return $s->fetch()!==false; } catch (PDOException $e) { return false; } }
function ensure_column(PDO $pdo, string $t, string $c, string $d): void { if (!table_exists($pdo,$t)||column_exists($pdo,$t,$c)) return; try { $pdo->exec("ALTER TABLE `$t` ADD COLUMN `$c` $d"); } catch (PDOException $e) {} }


$user_cols = [
    'phone'=>"VARCHAR(20) NULL",
    'role'=>"VARCHAR(20) DEFAULT 'customer'",
    'category_id'=>"INT NULL",
    'specialization'=>"VARCHAR(120) NULL",
    'availability'=>"VARCHAR(20) DEFAULT 'available'",
    'kyc_status'=>"VARCHAR(20) DEFAULT 'pending'",
    'kyc_file'=>"VARCHAR(255) NULL",
    'status'=>"VARCHAR(20) DEFAULT 'active'",
    'area'=>"VARCHAR(120) NULL",
    'address'=>"TEXT NULL",
    'is_banned'=>"TINYINT(1) DEFAULT 0",
    'login_otp'=>"VARCHAR(10) NULL",
    'login_otp_expires'=>"DATETIME NULL",
];
foreach ($user_cols as $c=>$d) ensure_column($pdo,'users',$c,$d);

try {
    $count = (int)$pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
    if ($count === 0) {
        $defaults = [
            ['AC Repair', 'fa-snowflake'],['Plumbing', 'fa-faucet'],['Electrical', 'fa-bolt'],
            ['Home Cleaning', 'fa-broom'],['Appliance Repair', 'fa-blender'],
            ['Carpentry', 'fa-hammer'],['Painting', 'fa-paint-roller'],['General Handyman', 'fa-tools'],
        ];
        $ins = $pdo->prepare("INSERT INTO categories (name, icon, status) VALUES (?, ?, 'active')");
        foreach ($defaults as $d) $ins->execute($d);
    }
} catch (PDOException $e) {}

// Redirect if already logged in
if (isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'customer') { header("Location: dashboard.php"); exit; }
elseif (isset($_SESSION['mechanic_id']) && ($_SESSION['role'] ?? '') === 'mechanic') { header("Location: mechanic/dashboard.php"); exit; }
elseif (isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'admin') { header("Location: admin/dashboard.php"); exit; }

$error = '';
$success = '';

try {
    $categories = $pdo->query("SELECT id, name FROM categories WHERE status = 'active' ORDER BY name ASC")->fetchAll();
} catch (PDOException $e) { $categories = []; }

// ========== CONSTANT DEMO OTP ==========
require_once 'config/security.php';

// ========== AJAX: SEND OTP (Demo) ==========
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'send_otp') {
    header('Content-Type: application/json');
    try {
        $phone = clean_input($_POST['phone'] ?? '');
        $role  = $_POST['role'] ?? 'user';

        if (empty($phone) || strlen($phone) !== 10) {
            echo json_encode(['success' => false, 'message' => 'Please enter a valid 10-digit mobile number.']);
            exit;
        }

        // Save demo OTP in DB (no expiry check in demo mode)
        $roleDb = ($role === 'mechanic') ? 'mechanic' : 'customer';
        $stmt = $pdo->prepare("SELECT id FROM users WHERE phone = ? AND role = ? LIMIT 1");
        $stmt->execute([$phone, $roleDb]);
        $u = $stmt->fetch();

        if ($u) {
            try {
                $pdo->prepare("UPDATE users SET login_otp = ?, login_otp_expires = DATE_ADD(NOW(), INTERVAL 9999 MINUTE) WHERE id = ?")
                    ->execute([DEMO_OTP, $u['id']]);
            } catch (PDOException $e) {}
        }

        echo json_encode([
            'success' => true,
            'message' => 'Demo OTP sent! Use 1234 to verify.'
        ]);
        exit;
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
        exit;
    }
}

// ========== HANDLE POST ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax_action'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Security token expired. Please try again.";
    } else {
        $action = $_POST['action'] ?? '';
        $role = $_POST['role'] ?? 'user';
        $phone = clean_input($_POST['phone'] ?? '');

        // ---------- REGISTER ----------
        if ($action === 'register') {
            $name = clean_input($_POST['name'] ?? '');
            $password = $_POST['password'] ?? '';
            $otp_entered = clean_input($_POST['otp'] ?? '');

            if ($otp_entered !== DEMO_OTP) {
                $error = "Invalid OTP. Please use 1234 (demo).";
            } elseif (empty($name) || empty($password) || strlen($password) < 6) {
                $error = "Please enter a valid name and password (min 6 chars).";
            } else {
                $check = $pdo->prepare("SELECT id FROM users WHERE phone = ? LIMIT 1");
                $check->execute([$phone]);
                if ($check->rowCount() > 0) {
                    $error = "This mobile number is already registered. Please login.";
                } else {
                    if ($role === 'user') {
                        $hashed = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("INSERT INTO users (name, phone, password, role, status, kyc_status) VALUES (?, ?, ?, 'customer', 'active', 'verified')");
                        if ($stmt->execute([$name, $phone, $hashed])) {
                            $success = "Customer account created successfully! Please login.";
                        } else { $error = "Failed to create account."; }
                    } elseif ($role === 'mechanic') {
                        if (!isset($_FILES['kyc_doc']) || $_FILES['kyc_doc']['error'] !== UPLOAD_ERR_OK) {
                            $error = "Please upload a valid KYC document (Aadhar/PAN).";
                        } else {
                            $category_id = (int)($_POST['category_id'] ?? 0);
                            $file = $_FILES['kyc_doc'];
                            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

                            if (!in_array($ext, ['jpg','jpeg','png','pdf'])) {
                                $error = "Invalid KYC format. Only JPG, PNG, or PDF allowed.";
                            } elseif ($file['size'] > 5 * 1024 * 1024) {
                                $error = "KYC document too large. Max 5MB allowed.";
                            } else {
                                $upload_dir = __DIR__ . '/uploads/kyc/';
                                if (!is_dir($upload_dir)) @mkdir($upload_dir, 0777, true);
                                $filename = 'kyc_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                                $dest = $upload_dir . $filename;
                                $db_path = 'uploads/kyc/' . $filename;

                                if (move_uploaded_file($file['tmp_name'], $dest)) {
                                    $hashed = password_hash($password, PASSWORD_DEFAULT);
                                    $stmt = $pdo->prepare("INSERT INTO users (name, phone, password, role, category_id, kyc_file, kyc_status, status, availability) VALUES (?, ?, ?, 'mechanic', ?, ?, 'pending', 'pending', 'offline')");
                                    if ($stmt->execute([$name, $phone, $hashed, $category_id, $db_path])) {
                                        $success = "Partner application submitted! Pending admin KYC verification.";
                                    } else {
                                        @unlink($dest);
                                        $error = "Database insertion failed.";
                                    }
                                } else { $error = "Failed to upload KYC document."; }
                            }
                        }
                    }
                }
            }
        }

        // ---------- LOGIN (Password + OTP) ----------
        elseif ($action === 'login') {
            $password = $_POST['password'] ?? '';
            $otp_entered = clean_input($_POST['otp'] ?? '');

            if (empty($phone) || empty($password) || empty($otp_entered)) {
                $error = "Please enter phone, password, and OTP.";
            } else {
                $stmt = $pdo->prepare("SELECT * FROM users WHERE phone = ? LIMIT 1");
                $stmt->execute([$phone]);
                $u = $stmt->fetch();

                if (!$u) {
                    $error = ($role === 'mechanic') ? "Mechanic account not found." : "Mobile number not found.";
                } elseif (!empty($u['is_banned'])) {
                    $error = "Your account has been suspended. Please contact support.";
                } elseif (($role === 'mechanic' && $u['role'] !== 'mechanic') || ($role === 'user' && $u['role'] !== 'customer')) {
                    $error = "This number isn't registered as a " . ($role === 'mechanic' ? 'Mechanic' : 'Customer') . ".";
                } else {
                    // 1. Verify password
                    $passwordOk = !empty($u['password']) && password_verify($password, $u['password']);

                    // 2. Verify OTP — accept demo OTP 1234 OR whatever is saved in DB
                    $otpOk = ($otp_entered === DEMO_OTP) || ($otp_entered === trim($u['login_otp'] ?? ''));

                    if (!$passwordOk && !$otpOk) {
                        $error = "Incorrect password and OTP.";
                    } elseif (!$passwordOk) {
                        $error = "Incorrect password. Please try again.";
                    } elseif (!$otpOk) {
                        $error = "Invalid OTP. Please use 1234 (demo).";
                    } else {
                        // BOTH valid → check mechanic status
                        if ($u['role'] === 'mechanic') {
                            $status = strtolower($u['status'] ?? 'active');
                            $kyc = strtolower($u['kyc_status'] ?? 'pending');
                            if ($status === 'pending' || $kyc === 'pending') {
                                $error = "Account under review. Please wait for Admin to verify your KYC.";
                            } elseif ($status === 'rejected' || $kyc === 'rejected') {
                                $error = "Your application was rejected. Please contact Admin.";
                            } else {
                                try { $pdo->prepare("UPDATE users SET login_otp = NULL, login_otp_expires = NULL WHERE id = ?")->execute([$u['id']]); } catch (PDOException $e) {}

                                $_SESSION['user_id'] = $u['id'];
                                $_SESSION['mechanic_id'] = $u['id'];
                                $_SESSION['mechanic_name'] = $u['name'];
                                $_SESSION['role'] = 'mechanic';
                                header("Location: mechanic/dashboard.php");
                                exit;
                            }
                        } else {
                            try { $pdo->prepare("UPDATE users SET login_otp = NULL, login_otp_expires = NULL WHERE id = ?")->execute([$u['id']]); } catch (PDOException $e) {}

                            $_SESSION['user_id'] = $u['id'];
                            $_SESSION['user_name'] = $u['name'];
                            $_SESSION['role'] = 'customer';
                            header("Location: dashboard.php");
                            exit;
                        }
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Welcome to ServiceFix</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary:#4F46E5; --primary-hover:#4338CA; --mech-primary:#F97316; --bg-color:#0F172A;}
        body { font-family:'Plus Jakarta Sans',sans-serif; background-color:var(--bg-color); color:#334155; min-height:100vh; display:flex; align-items:center; justify-content:center; overflow-x:hidden; position:relative;}
        .bg-shapes { position:fixed; top:0; left:0; width:100%; height:100%; overflow:hidden; z-index:0; pointer-events:none;}
        .shape { position:absolute; filter:blur(80px); opacity:0.6; animation:float 10s ease-in-out infinite;}
        .shape-1 { background:#4F46E5; width:400px; height:400px; top:-10%; left:-10%; border-radius:50%;}
        .shape-2 { background:#F97316; width:300px; height:300px; bottom:-10%; right:-5%; border-radius:50%; animation-delay:-5s;}
        @keyframes float { 0%,100% { transform:translateY(0) scale(1);} 50% { transform:translateY(-30px) scale(1.05);} }
        @keyframes fadeInScale { from { opacity:0; transform:scale(0.95) translateY(10px);} to { opacity:1; transform:scale(1) translateY(0);} }
        .main-container { width:100%; max-width:450px; padding:20px; z-index:10;}
        .glass-card { background:rgba(255,255,255,0.95); backdrop-filter:blur(20px); -webkit-backdrop-filter:blur(20px); border-radius:30px; padding:40px 30px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25); border:1px solid rgba(255,255,255,0.4); position:relative; overflow:hidden; animation:fadeInScale 0.5s ease forwards;}
        .role-btn { background:#F8FAFC; border:2px solid #E2E8F0; border-radius:20px; padding:20px; text-align:left; cursor:pointer; transition:all 0.3s; display:flex; align-items:center; gap:15px; margin-bottom:15px;}
        .role-btn:hover { border-color:var(--primary); background:#EEF2FF; transform:translateY(-3px);}
        .role-icon { width:50px; height:50px; border-radius:15px; display:flex; align-items:center; justify-content:center; font-size:1.5rem; color:white;}
        .role-user .role-icon { background:linear-gradient(135deg,#4F46E5 0%,#7C3AED 100%); box-shadow:0 10px 20px rgba(79,70,229,0.3);}
        .role-mech .role-icon { background:linear-gradient(135deg,#F97316 0%,#EAB308 100%); box-shadow:0 10px 20px rgba(249,115,22,0.3);}
        .role-title { font-weight:800; color:#0F172A; font-size:1.1rem; margin-bottom:2px;}
        .role-desc { font-size:0.85rem; color:#64748B; margin:0;}
        .form-floating > .form-control, .form-select { background:#F8FAFC; border:2px solid #E2E8F0; border-radius:16px; font-weight:600; color:#1E293B; padding-left:50px; transition:all 0.3s; box-shadow:none;}
        .form-select { padding-left:45px; height:58px;}
        .form-floating > .form-control:focus, .form-select:focus { background:#ffffff; border-color:var(--primary); box-shadow:0 0 0 4px rgba(79,70,229,0.1);}
        .form-floating > label { padding-left:50px; color:#94A3B8; font-weight:500;}
        .input-icon { position:absolute; left:18px; top:50%; transform:translateY(-50%); color:#94A3B8; font-size:1.1rem; z-index:10; transition:0.3s;}
        .form-control:focus + .input-icon, .form-floating:focus-within .input-icon { color:var(--primary);}
        .btn-submit { background:var(--primary); color:white; border:none; border-radius:16px; font-weight:700; font-size:1.05rem; padding:16px; width:100%; transition:all 0.3s; box-shadow:0 10px 20px rgba(79,70,229,0.25);}
        .btn-submit:hover { transform:translateY(-2px); box-shadow:0 15px 25px rgba(79,70,229,0.35); color:white;}
        .btn-submit.btn-mech { background:var(--mech-primary); box-shadow:0 10px 20px rgba(249,115,22,0.25);}
        .btn-submit.btn-mech:hover { background:#EA580C; box-shadow:0 15px 25px rgba(249,115,22,0.35);}
        .btn-otp { background:var(--primary); color:#fff; font-weight:700; border-radius:12px; padding:0 18px; border:none; transition:0.3s; height:58px; white-space:nowrap;}
        .btn-otp:hover { background:var(--primary-hover); color:#fff;}
        .btn-otp.btn-mech { background:var(--mech-primary);}
        .btn-otp.btn-mech:hover { background:#EA580C;}
        .auth-view { display:none;}
        .auth-view.active { display:block; animation:fadeInScale 0.4s ease forwards;}
        .back-btn { color:#64748B; text-decoration:none; font-weight:600; font-size:0.9rem; display:inline-flex; align-items:center; transition:0.2s;}
        .back-btn:hover { color:var(--primary);}
        .toggle-link { text-align:center; margin-top:20px; font-weight:600; font-size:0.9rem; color:#64748B; cursor:pointer;}
        .toggle-link span { color:var(--primary); transition:0.2s;}
        .toggle-link span:hover { text-decoration:underline;}
        .twofa-badge { display:inline-flex; align-items:center; gap:6px; background:#FEF3C7; color:#92400E; padding:6px 12px; border-radius:100px; font-size:0.75rem; font-weight:700; margin-bottom:16px;}
        .otp-hint { font-size:0.75rem; color:#16A34A; font-weight:700; margin-top:6px; padding-left:4px;}
    </style>
</head>
<body>

<div class="bg-shapes">
    <div class="shape shape-1"></div>
    <div class="shape shape-2"></div>
</div>

<div class="main-container">
    <div class="glass-card">

        <?php if ($error): ?>
            <div class="alert alert-danger border-0 rounded-3 text-sm fw-medium shadow-sm mb-4 bg-danger bg-opacity-10 text-danger px-3 py-2">
                <i class="fa-solid fa-triangle-exclamation me-2"></i><?= $error ?>
            </div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success border-0 rounded-3 text-sm fw-medium shadow-sm mb-4 bg-success bg-opacity-10 text-success px-3 py-2">
                <i class="fa-solid fa-circle-check me-2"></i><?= $success ?>
            </div>
        <?php endif; ?>

        <!-- VIEW 1: Role Selection -->
        <div id="view-role-selection" class="auth-view active">
            <div class="text-center mb-4">
                <div class="d-inline-flex bg-primary bg-opacity-10 text-primary p-3 rounded-circle mb-3">
                    <i class="fa-solid fa-bolt fs-2"></i>
                </div>
                <h3 class="fw-bold text-dark" style="letter-spacing:-0.5px;">Welcome to ServiceFix</h3>
                <p class="text-muted fw-medium small">How would you like to proceed?</p>
            </div>

            <div class="role-btn role-user" onclick="switchView('view-user-auth')">
                <div class="role-icon"><i class="fa-solid fa-user"></i></div>
                <div>
                    <h5 class="role-title">Customer</h5>
                    <p class="role-desc">I need to book a repair service.</p>
                </div>
            </div>

            <div class="role-btn role-mech" onclick="switchView('view-mech-auth')">
                <div class="role-icon"><i class="fa-solid fa-wrench"></i></div>
                <div>
                    <h5 class="role-title">Mechanic Partner</h5>
                    <p class="role-desc">I want to provide repair services.</p>
                </div>
            </div>
        </div>

        <!-- VIEW 2: Customer Auth -->
        <div id="view-user-auth" class="auth-view">
            <a href="#" class="back-btn mb-4" onclick="switchView('view-role-selection');return false;"><i class="fa-solid fa-arrow-left me-2"></i> Back</a>

            <div class="text-center mb-3">
                <h3 class="fw-bold text-dark" id="userAuthTitle">Customer Login</h3>
                <p class="text-muted fw-medium small" id="userAuthSub">Password + OTP required for security.</p>
            </div>

            <div class="text-center">
                <span class="twofa-badge" id="user2FABadge"><i class="fa-solid fa-shield-halved"></i> 2-Step Verification</span>
            </div>

            <form method="POST" id="userForm">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="role" value="user">
                <input type="hidden" name="action" id="userActionType" value="login">

                <div class="form-floating mb-3 position-relative">
                    <i class="fa-solid fa-phone input-icon"></i>
                    <input type="tel" name="phone" class="form-control" id="userPhone" placeholder="Mobile Number" required pattern="[0-9]{10}">
                    <label for="userPhone">Mobile Number</label>
                </div>

                <div class="form-floating mb-3 position-relative d-none" id="userNameGroup">
                    <i class="fa-regular fa-user input-icon"></i>
                    <input type="text" name="name" class="form-control" id="userName" placeholder="Full Name">
                    <label for="userName">Full Name</label>
                </div>

                <div class="form-floating mb-3 position-relative" id="userPwdGroup">
                    <i class="fa-solid fa-lock input-icon"></i>
                    <input type="password" name="password" class="form-control" id="userPass" placeholder="Password" required>
                    <label for="userPass" id="userPwdLabel">Password</label>
                </div>

                <div class="mb-3" id="userOtpGroup">
                    <label class="form-label small fw-bold text-dark mb-2 ps-1">OTP Verification <span class="text-danger">*</span></label>
                    <div class="d-flex gap-2">
                        <div class="form-floating position-relative flex-grow-1">
                            <i class="fa-solid fa-message input-icon"></i>
                            <input type="text" name="otp" class="form-control" id="userOtp" placeholder="Enter OTP" maxlength="4" inputmode="numeric" required>
                            <label for="userOtp">4-Digit OTP</label>
                        </div>
                        <button type="button" class="btn-otp" id="userOtpBtn" onclick="sendOtp('user')">Send OTP</button>
                    </div>
                    <div class="otp-hint" id="userOtpHint"></div>
                </div>

                <button type="submit" class="btn-submit" id="userSubmitBtn">
                    <i class="fa-solid fa-shield-halved me-2"></i>Verify & Login
                </button>
            </form>

            <div class="toggle-link" id="userToggleLink" onclick="toggleUserFormType()">
                New customer? <span>Create an account</span>
            </div>
        </div>

        <!-- VIEW 3: Mechanic Auth -->
        <div id="view-mech-auth" class="auth-view">
            <a href="#" class="back-btn mb-4" onclick="switchView('view-role-selection');return false;"><i class="fa-solid fa-arrow-left me-2"></i> Back</a>

            <div class="text-center mb-3">
                <h3 class="fw-bold text-dark" id="mechAuthTitle">Partner Login</h3>
                <p class="text-muted fw-medium small" id="mechAuthSub">Password + OTP required for security.</p>
            </div>

            <div class="text-center">
                <span class="twofa-badge" id="mech2FABadge"><i class="fa-solid fa-shield-halved"></i> 2-Step Verification</span>
            </div>

            <form method="POST" enctype="multipart/form-data" id="mechForm">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="role" value="mechanic">
                <input type="hidden" name="action" id="mechActionType" value="login">

                <div class="form-floating mb-3 position-relative">
                    <i class="fa-solid fa-phone input-icon"></i>
                    <input type="tel" name="phone" class="form-control" id="mechPhone" placeholder="Mobile Number" required pattern="[0-9]{10}">
                    <label for="mechPhone">Mobile Number</label>
                </div>

                <div id="mechSignupFields" class="d-none">
                    <div class="form-floating mb-3 position-relative">
                        <i class="fa-regular fa-user input-icon"></i>
                        <input type="text" name="name" class="form-control" id="mechName" placeholder="Full Name">
                        <label for="mechName">Full Name</label>
                    </div>

                    <div class="mb-3 position-relative">
                        <i class="fa-solid fa-layer-group input-icon" style="top:29px;"></i>
                        <select name="category_id" class="form-select" id="mechCategory">
                            <option value="" disabled selected>Select Your Expertise</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= (int)$cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3 bg-light p-2 rounded-3 border">
                        <label class="form-label text-dark small mb-1 px-1">Upload KYC (Aadhar/PAN)</label>
                        <input type="file" name="kyc_doc" id="mechKyc" class="form-control bg-white" accept=".jpg,.jpeg,.png,.pdf">
                    </div>
                </div>

                <div class="form-floating mb-3 position-relative" id="mechPwdGroup">
                    <i class="fa-solid fa-lock input-icon"></i>
                    <input type="password" name="password" class="form-control" id="mechPass" placeholder="Password" required>
                    <label for="mechPass" id="mechPwdLabel">Password</label>
                </div>

                <div class="mb-3" id="mechOtpGroup">
                    <label class="form-label small fw-bold text-dark mb-2 ps-1">OTP Verification <span class="text-danger">*</span></label>
                    <div class="d-flex gap-2">
                        <div class="form-floating position-relative flex-grow-1">
                            <i class="fa-solid fa-message input-icon"></i>
                            <input type="text" name="otp" class="form-control" id="mechOtp" placeholder="Enter OTP" maxlength="4" inputmode="numeric" required>
                            <label for="mechOtp">4-Digit OTP</label>
                        </div>
                        <button type="button" class="btn-otp btn-mech" id="mechOtpBtn" onclick="sendOtp('mechanic')">Send OTP</button>
                    </div>
                    <div class="otp-hint" id="mechOtpHint"></div>
                </div>

                <button type="submit" class="btn-submit btn-mech" id="mechSubmitBtn">
                    <i class="fa-solid fa-shield-halved me-2"></i>Verify & Login
                </button>
            </form>

            <div class="toggle-link" id="mechToggleLink" onclick="toggleMechFormType()">
                Want to join as a Partner? <span>Apply Now</span>
            </div>
        </div>

    </div>
</div>

<script>
    function switchView(viewId) {
        document.querySelectorAll('.auth-view').forEach(el => el.classList.remove('active'));
        document.getElementById(viewId).classList.add('active');
    }

    // ========== SEND OTP (Demo — always 1234) ==========
    function sendOtp(role) {
        const phoneEl = document.getElementById(role === 'mechanic' ? 'mechPhone' : 'userPhone');
        const phone = phoneEl.value.trim();

        if (!phone || phone.length !== 10) {
            alert('Please enter a valid 10-digit mobile number first.');
            phoneEl.focus();
            return;
        }

        // Show demo hint instantly (no waiting, no countdown)
        const hintEl = document.getElementById(role === 'mechanic' ? 'mechOtpHint' : 'userOtpHint');
        hintEl.innerHTML = '<i class="fa-solid fa-check-circle me-1"></i>Demo OTP: <strong><?= DEMO_OTP ?></strong>';

        // Optionally hit backend to save it (not required for demo)
        const formData = new FormData();
        formData.append('ajax_action', 'send_otp');
        formData.append('phone', phone);
        formData.append('role', role);

        fetch(window.location.href, { method: 'POST', body: formData }).catch(() => {});
    }

    // ========== CUSTOMER TOGGLE ==========
    let userIsSignup = false;

    function toggleUserFormType() {
        userIsSignup = !userIsSignup;
        const actionType = document.getElementById('userActionType');
        const title = document.getElementById('userAuthTitle');
        const sub = document.getElementById('userAuthSub');
        const submitBtn = document.getElementById('userSubmitBtn');
        const toggleLink = document.getElementById('userToggleLink');
        const nameGroup = document.getElementById('userNameGroup');
        const badge = document.getElementById('user2FABadge');

        if (userIsSignup) {
            actionType.value = 'register';
            title.innerText = 'Create Account';
            sub.innerText = 'Sign up with OTP verification.';
            submitBtn.innerHTML = '<i class="fa-solid fa-user-plus me-2"></i>Verify & Sign Up';
            toggleLink.innerHTML = 'Already have an account? <span>Sign In</span>';
            nameGroup.classList.remove('d-none');
            document.getElementById('userPwdLabel').innerText = 'Create Password';
            document.getElementById('userName').required = true;
            badge.innerHTML = '<i class="fa-solid fa-shield-halved"></i> OTP Verification Required';
        } else {
            actionType.value = 'login';
            title.innerText = 'Customer Login';
            sub.innerText = 'Password + OTP required for security.';
            submitBtn.innerHTML = '<i class="fa-solid fa-shield-halved me-2"></i>Verify & Login';
            toggleLink.innerHTML = 'New customer? <span>Create an account</span>';
            nameGroup.classList.add('d-none');
            document.getElementById('userPwdLabel').innerText = 'Password';
            document.getElementById('userName').required = false;
            badge.innerHTML = '<i class="fa-solid fa-shield-halved"></i> 2-Step Verification';
        }
    }

    // ========== MECHANIC TOGGLE ==========
    let mechIsSignup = false;

    function toggleMechFormType() {
        mechIsSignup = !mechIsSignup;
        const actionType = document.getElementById('mechActionType');
        const title = document.getElementById('mechAuthTitle');
        const sub = document.getElementById('mechAuthSub');
        const submitBtn = document.getElementById('mechSubmitBtn');
        const toggleLink = document.getElementById('mechToggleLink');
        const signupFields = document.getElementById('mechSignupFields');
        const badge = document.getElementById('mech2FABadge');

        if (mechIsSignup) {
            actionType.value = 'register';
            title.innerText = 'Partner Application';
            sub.innerText = 'Submit details with OTP verification.';
            submitBtn.innerHTML = '<i class="fa-solid fa-paper-plane me-2"></i>Verify & Submit';
            toggleLink.innerHTML = 'Already a partner? <span>Sign In</span>';
            signupFields.classList.remove('d-none');
            document.getElementById('mechPwdLabel').innerText = 'Create Password';
            document.getElementById('mechName').required = true;
            document.getElementById('mechCategory').required = true;
            document.getElementById('mechKyc').required = true;
            badge.innerHTML = '<i class="fa-solid fa-shield-halved"></i> OTP Verification Required';
        } else {
            actionType.value = 'login';
            title.innerText = 'Partner Login';
            sub.innerText = 'Password + OTP required for security.';
            submitBtn.innerHTML = '<i class="fa-solid fa-shield-halved me-2"></i>Verify & Login';
            toggleLink.innerHTML = 'Want to join as a Partner? <span>Apply Now</span>';
            signupFields.classList.add('d-none');
            document.getElementById('mechPwdLabel').innerText = 'Password';
            document.getElementById('mechName').required = false;
            document.getElementById('mechCategory').required = false;
            document.getElementById('mechKyc').required = false;
            badge.innerHTML = '<i class="fa-solid fa-shield-halved"></i> 2-Step Verification';
        }
    }
</script>
</body>
</html>