<?php
// htdocs/book.php — CUSTOMER SERVICE BOOKING (bulletproof)
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


ensure_column($pdo, 'categories', 'icon', "VARCHAR(100) NULL");
ensure_column($pdo, 'categories', 'description', "TEXT NULL");
ensure_column($pdo, 'categories', 'base_price', "DECIMAL(10,2) DEFAULT 0");
ensure_column($pdo, 'categories', 'status', "VARCHAR(20) DEFAULT 'active'");

$bk_cols = [
    'problem_description'=>"TEXT NULL",'landmark'=>"VARCHAR(150) NULL",
    'contact_name'=>"VARCHAR(120) NULL",'contact_phone'=>"VARCHAR(20) NULL",
    'payment_method'=>"VARCHAR(50) NULL",'booking_ref'=>"VARCHAR(50) NULL",
];
foreach ($bk_cols as $c=>$d) ensure_column($pdo,'bookings',$c,$d);

$pay_cols = [
    'proof_file_path'=>"VARCHAR(255) NULL",
    'payment_method'=>"VARCHAR(50) DEFAULT 'Online'",
    'status'=>"VARCHAR(30) DEFAULT 'Pending'",
];
foreach ($pay_cols as $c=>$d) ensure_column($pdo,'payments',$c,$d);

// Create upload folder
if (!is_dir(__DIR__ . '/uploads')) @mkdir(__DIR__ . '/uploads', 0777, true);
if (!is_dir(__DIR__ . '/uploads/payments')) @mkdir(__DIR__ . '/uploads/payments', 0777, true);

// ========== LOAD CUSTOMER ==========
$customer = ['name'=>'','phone'=>'','area'=>'','address'=>''];
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $row = $stmt->fetch();
    if ($row) $customer = $row;
} catch (PDOException $e) {}

// ========== LOAD CATEGORIES (fallback if status column missing) ==========
$categories = [];
try {
    $categories = $pdo->query("SELECT * FROM categories WHERE status = 'active' ORDER BY name ASC")->fetchAll();
} catch (PDOException $e) {
    try {
        $categories = $pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll();
    } catch (PDOException $e2) { $categories = []; }
}

// Auto-seed if empty
if (count($categories) === 0) {
    try {
        $defaults = [
            ['AC Repair', 'fa-snowflake', 'AC servicing, gas refill, installation'],
            ['Plumbing', 'fa-faucet', 'Pipe leaks, tap repair, bathroom fittings'],
            ['Electrical', 'fa-bolt', 'Wiring, fan, switch, light repairs'],
            ['Home Cleaning', 'fa-broom', 'Deep cleaning, kitchen, bathroom'],
            ['Appliance Repair', 'fa-blender', 'Fridge, washing machine, microwave'],
            ['Carpentry', 'fa-hammer', 'Furniture repair, door, window'],
        ];
        $ins = $pdo->prepare("INSERT INTO categories (name, icon, description, status) VALUES (?, ?, ?, 'active')");
        foreach ($defaults as $d) $ins->execute($d);
        $categories = $pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll();
    } catch (PDOException $e) {}
}

// ========== LOAD ADMIN PAYMENT QR ==========
$admin_qr = ''; $admin_upi = '';
try {
    $v = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='payment_qr'")->fetchColumn();
    if ($v !== false && $v !== null && trim($v) !== '') $admin_qr = trim($v);
    $u = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='admin_upi_id'")->fetchColumn();
    if ($u !== false && $u !== null && trim($u) !== '') $admin_upi = trim($u);
} catch (PDOException $e) {}
if (empty($admin_qr)) {
    $c = glob(__DIR__ . '/uploads/qr/*.{png,jpg,jpeg,webp}', GLOB_BRACE);
    if (!empty($c)) $admin_qr = 'uploads/qr/' . basename($c[0]);
}
$qr_display_src = '';
if (!empty($admin_qr)) {
    if (strpos($admin_qr, 'http') === 0) $qr_display_src = $admin_qr;
    elseif (strpos($admin_qr, 'uploads/') === 0) $qr_display_src = $admin_qr;
    else $qr_display_src = ltrim($admin_qr, '/');
}

// ========== HANDLE FORM SUBMIT ==========
$error = ''; $success = '';
$preselected_cat = (int)($_GET['category'] ?? 0);
$today = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Security token expired. Please refresh.";
    } else {
        try {
            $category_id    = (int)($_POST['category_id'] ?? 0);
            $problem        = clean_input($_POST['problem_description'] ?? '');
            $booking_date   = $_POST['booking_date'] ?? '';
            $booking_time   = $_POST['booking_time'] ?? '';
            $area           = clean_input($_POST['area'] ?? '');
            $address        = clean_input($_POST['address'] ?? '');
            $landmark       = clean_input($_POST['landmark'] ?? '');
            $notes          = clean_input($_POST['notes'] ?? '');
            $contact_name   = clean_input($_POST['contact_name'] ?? '');
            $contact_phone  = clean_input($_POST['contact_phone'] ?? '');
            $amount         = (float)($_POST['amount'] ?? 0);
            $payment_method = clean_input($_POST['payment_method'] ?? 'Online');
            $txn_id         = clean_input($_POST['transaction_id'] ?? '');

            // Validation
            if ($category_id <= 0)               $error = "Please select a service category.";
            elseif (empty($problem))             $error = "Please describe your problem.";
            elseif (empty($booking_date))        $error = "Please choose a preferred date.";
            elseif (strtotime($booking_date) < strtotime($today)) $error = "Booking date cannot be in the past.";
            elseif (empty($booking_time))        $error = "Please choose a preferred time slot.";
            elseif (empty($contact_name))        $error = "Contact name is required.";
            elseif (!preg_match('/^[0-9]{10}$/', $contact_phone)) $error = "Contact phone must be 10 digits.";
            elseif (empty($address))             $error = "Please enter your full address.";
            elseif ($amount <= 0)                $error = "Amount must be greater than 0.";
            else {
                // Category name
                $catName = 'Service';
                try {
                    $s = $pdo->prepare("SELECT name FROM categories WHERE id = ? LIMIT 1");
                    $s->execute([$category_id]);
                    $cn = $s->fetchColumn();
                    if ($cn) $catName = $cn;
                } catch (PDOException $e) {}

                // Booking ref
                $booking_ref = 'BKG' . date('ymd') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));

                // Handle proof upload
                $proof_path = '';
                if (!empty($_FILES['payment_proof']['name']) && $_FILES['payment_proof']['error'] === UPLOAD_ERR_OK) {
                    $upload_dir = __DIR__ . '/uploads/payments/';
                    if (!is_dir($upload_dir)) @mkdir($upload_dir, 0777, true);

                    $ext = strtolower(pathinfo($_FILES['payment_proof']['name'], PATHINFO_EXTENSION));
                    $allowed = ['jpg','jpeg','png','webp','pdf'];

                    if (!in_array($ext, $allowed)) {
                        $error = "Proof must be JPG, PNG, WebP, or PDF.";
                    } elseif ($_FILES['payment_proof']['size'] > 5 * 1024 * 1024) {
                        $error = "Proof file too large (max 5MB).";
                    } else {
                        $filename = 'pay_' . time() . '_' . rand(1000,9999) . '.' . $ext;
                        if (move_uploaded_file($_FILES['payment_proof']['tmp_name'], $upload_dir . $filename)) {
                            $proof_path = 'uploads/payments/' . $filename;
                        } else {
                            $error = "Failed to save uploaded file. Check folder permissions.";
                        }
                    }
                } else {
                    $error = "Please upload your payment screenshot as proof.";
                }

                if (empty($error)) {
                    $pdo->beginTransaction();

                    $stmt = $pdo->prepare("INSERT INTO bookings
                        (booking_ref, user_id, category_id, service_type, problem_description,
                         booking_date, booking_time, status, area, address, landmark, notes, amount,
                         contact_name, contact_phone, payment_method, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'Payment Verification Pending', ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    $stmt->execute([
                        $booking_ref, $user_id, $category_id, $catName, $problem,
                        $booking_date, $booking_time, $area, $address, $landmark, $notes, $amount,
                        $contact_name, $contact_phone, $payment_method
                    ]);

                    $booking_id = (int)$pdo->lastInsertId();

                    $pdo->prepare("INSERT INTO payments (booking_id, customer_id, amount, payment_method, transaction_id, status, proof_file_path) VALUES (?, ?, ?, ?, ?, 'Pending', ?)")
                        ->execute([$booking_id, $user_id, $amount, $payment_method, $txn_id, $proof_path]);

                    try {
                        $pdo->prepare("INSERT INTO booking_history (booking_id, status_updated_to, notes) VALUES (?, 'Payment Verification Pending', 'Booking created. Awaiting admin payment verification.')")
                            ->execute([$booking_id]);
                    } catch (PDOException $e) {}

                    $pdo->commit();

                    // Flash + redirect
                    $_SESSION['flash_message'] = "<div class='alert alert-success border-0 shadow-sm'><i class='fa-solid fa-circle-check me-2'></i>Booking <strong>#{$booking_ref}</strong> submitted! Admin will verify your payment shortly.</div>";
                    header("Location: track.php?id=" . $booking_id);
                    exit;
                }
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "System error: " . htmlspecialchars($e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book a Service — AutoCare</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --nav:#0B1120; --bg:#F5F7FB; --primary:#6366F1; --primary-dark:#4F46E5; --border:#E5E7EB; --dark:#0F172A; --muted:#64748B; --success:#10B981;}
        * { box-sizing: border-box;}
        body { font-family:'Inter',sans-serif; background:var(--bg); color:#334155; padding-bottom:60px; -webkit-font-smoothing:antialiased;}

        .navbar { background:linear-gradient(180deg,#0B1120 0%,#111827 100%) !important; padding:14px 0; box-shadow:0 4px 20px rgba(0,0,0,0.08); border-bottom:1px solid rgba(255,255,255,0.05);}
        .navbar-brand { font-weight:800; color:#fff !important; letter-spacing:-0.5px;}
        .nav-link { color:#94A3B8 !important; font-weight:500; padding:8px 16px !important; border-radius:10px; transition:all .2s; font-size:0.9rem;}
        .nav-link:hover { color:#fff !important; background:rgba(255,255,255,0.06);}
        .nav-link.active { color:#fff !important; background:linear-gradient(135deg,var(--primary),var(--primary-dark)); box-shadow:0 4px 12px rgba(99,102,241,0.35);}

        .page-header { background:linear-gradient(135deg,#4F46E5 0%,#7C3AED 100%); border-radius:22px; padding:30px 32px; color:#fff; position:relative; overflow:hidden; margin-bottom:26px; box-shadow:0 20px 40px -15px rgba(79,70,229,0.5);}
        .page-header::before { content:''; position:absolute; width:300px; height:300px; border-radius:50%; background:rgba(255,255,255,0.08); top:-100px; right:-60px;}
        .page-header h2 { font-weight:800; letter-spacing:-0.6px; position:relative; z-index:1;}
        .page-header p { color:rgba(255,255,255,0.85); position:relative; z-index:1; margin-bottom:0; font-size:0.92rem;}
        .step-pill { display:inline-flex; align-items:center; gap:8px; background:rgba(255,255,255,0.15); padding:6px 14px; border-radius:100px; font-size:0.78rem; font-weight:600; margin-bottom:12px; position:relative; z-index:1;}

        .panel { background:#fff; border-radius:18px; border:1px solid var(--border); box-shadow:0 1px 3px rgba(0,0,0,0.02); padding:24px; margin-bottom:20px;}
        .panel-title { font-weight:700; color:var(--dark); font-size:1rem; display:flex; align-items:center; gap:10px; margin-bottom:20px; padding-bottom:14px; border-bottom:1px solid #F1F5F9;}
        .panel-icon { width:32px; height:32px; border-radius:9px; background:#EEF2FF; color:#4F46E5; display:flex; align-items:center; justify-content:center; font-size:0.85rem;}

        .form-label { font-weight:700; font-size:0.78rem; color:#475569; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:6px;}
        .form-control, .form-select { border-radius:12px; padding:12px 16px; border:2px solid var(--border); font-weight:500; font-size:0.92rem; transition:0.25s; background:#F8FAFC; color:#0F172A;}
        .form-control:focus, .form-select:focus { border-color:var(--primary); background:#fff; box-shadow:0 0 0 4px rgba(99,102,241,0.1);}
        .form-control::placeholder { color:#94A3B8;}
        textarea.form-control { min-height: 100px; resize: vertical;}
        .form-control.is-invalid, .form-select.is-invalid { border-color:#EF4444; background:#FEF2F2;}
        .field-error { color:#DC2626; font-size:0.75rem; font-weight:600; margin-top:4px; display:none;}

        .cat-pick { display:grid; grid-template-columns: repeat(auto-fill, minmax(140px,1fr)); gap:10px; margin-top:6px;}
        .cat-opt { background:#F8FAFC; border:2px solid var(--border); border-radius:14px; padding:14px 12px; text-align:center; cursor:pointer; transition:all .2s; position:relative; display:block;}
        .cat-opt:hover { border-color:#CBD5E1; transform:translateY(-2px);}
        .cat-opt input { display:none;}
        .cat-opt:has(input:checked) { border-color:var(--primary); background:#F5F7FF; box-shadow:0 4px 12px -4px rgba(99,102,241,0.25);}
        .cat-opt i { font-size:1.4rem; color:var(--primary); display:block; margin-bottom:8px;}
        .cat-opt .cat-name { font-weight:700; font-size:0.85rem; color:var(--dark);}
        .cat-opt .cat-desc { font-size:0.7rem; color:var(--muted); margin-top:3px; line-height:1.3;}

        .qr-panel { background:linear-gradient(135deg,#F5F7FF 0%,#EEF2FF 100%); border:2px solid #C7D2FE; border-radius:16px; padding:20px;}
        .qr-img { background:#fff; padding:12px; border-radius:12px; border:1px solid var(--border); max-height:220px; box-shadow:0 8px 20px -6px rgba(99,102,241,0.25);}
        .qr-label { font-size:0.75rem; font-weight:700; text-transform:uppercase; letter-spacing:0.6px; color:#4F46E5; margin-bottom:6px;}
        .upi-id { background:#fff; border:1px dashed #C7D2FE; border-radius:10px; padding:8px 12px; font-family:monospace; font-weight:600; color:#0F172A; display:inline-flex; align-items:center; gap:8px; cursor:pointer;}
        .upi-id:hover { background:#F5F7FF;}
        .copy-toast { font-size:0.7rem; color:var(--success); margin-left:6px;}

        .upload-box { border:2px dashed #CBD5E1; border-radius:14px; padding:20px; text-align:center; cursor:pointer; transition:all .2s; background:#F8FAFC;}
        .upload-box:hover { border-color:var(--primary); background:#F5F7FF;}
        .upload-box.has-file { border-color:var(--success); background:#F0FDF4;}
        .upload-box i { font-size:2rem; color:#94A3B8; margin-bottom:8px; display:block;}
        .upload-box.has-file i { color:var(--success);}
        .upload-box .title { font-weight:700; color:var(--dark); font-size:0.9rem;}
        .upload-box .hint { font-size:0.75rem; color:var(--muted); margin-top:2px;}
        .preview-thumb { max-width: 100%; max-height: 180px; border-radius:10px; margin-top:10px; display:none;}

        .btn-submit { background:linear-gradient(135deg,var(--primary) 0%,var(--primary-dark) 100%); color:#fff; border:none; border-radius:14px; font-weight:700; font-size:1rem; padding:16px 30px; width:100%; transition:all .25s; box-shadow:0 10px 20px -6px rgba(99,102,241,0.5);}
        .btn-submit:hover { transform:translateY(-2px); box-shadow:0 15px 25px -6px rgba(99,102,241,0.6); color:#fff;}
        .btn-submit:disabled { opacity:0.7; cursor:not-allowed; transform:none;}

        .info-chip { background:#EFF6FF; border:1px solid #BFDBFE; border-radius:10px; padding:10px 14px; font-size:0.82rem; color:#1E40AF; font-weight:500; display:flex; gap:8px; align-items:flex-start; margin-bottom:16px;}
        .info-chip i { color:#3B82F6; margin-top:2px;}

        .alert { border-radius:12px; padding:14px 18px; font-weight:500;}

        .summary-row { display:flex; justify-content:space-between; padding:10px 0; border-bottom:1px dashed #E2E8F0; font-size:0.88rem; gap:10px;}
        .summary-row:last-child { border-bottom:none; font-weight:700; font-size:1rem; padding-top:14px;}
        .summary-row .lbl { color:var(--muted); font-weight:500; flex-shrink:0;}
        .summary-row .val { color:var(--dark); font-weight:600; text-align:right; word-break:break-word;}
        .summary-row.total .val { color:var(--primary-dark); font-size:1.2rem; font-weight:800;}

        .sticky-col { position: sticky; top: 80px; }
        @media (max-width: 991px) { .sticky-col { position: static;}}
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
                <li class="nav-item"><a class="nav-link active" href="book.php"><i class="fa-solid fa-plus me-1"></i>Book Service</a></li>
                <li class="nav-item"><a class="nav-link" href="track.php"><i class="fa-solid fa-location-dot me-1"></i>Track</a></li>
                <li class="nav-item"><a class="nav-link" href="history.php"><i class="fa-solid fa-clock-rotate-left me-1"></i>History</a></li>
                <li class="nav-item"><a class="nav-link" href="profile.php"><i class="fa-solid fa-user me-1"></i>Profile</a></li>
                <li class="nav-item ms-lg-2"><a href="logout.php" class="btn btn-danger btn-sm px-3 fw-bold rounded-pill"><i class="fa-solid fa-power-off me-1"></i>Logout</a></li>
            </ul>
        </div>
    </div>
</nav>

<div class="container pb-5">

    <div class="page-header">
        <span class="step-pill"><i class="fa-solid fa-bolt"></i> Step 1 of 2 — Book Your Service</span>
        <h2 class="mb-1">Book a Verified Mechanic</h2>
        <p>Fill in your details, make the payment, and our team will verify it and assign the nearest mechanic.</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation me-2"></i><?= $error ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><i class="fa-solid fa-circle-check me-2"></i><?= $success ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" id="bookingForm" novalidate>
        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">

        <div class="row g-4">
            <!-- LEFT COLUMN -->
            <div class="col-lg-8">

                <!-- STEP 1: SERVICE -->
                <div class="panel">
                    <div class="panel-title">
                        <div class="panel-icon"><i class="fa-solid fa-list-check"></i></div>
                        <span>1. Select Service Category</span>
                    </div>

                    <div class="cat-pick" id="catPick">
                        <?php if (count($categories) > 0): ?>
                            <?php foreach ($categories as $c):
                                $icon = $c['icon'] ?? 'fa-tools';
                                if (strpos($icon,'fa-') !== 0) $icon = 'fa-' . $icon;
                                $checked = ($preselected_cat === (int)$c['id']) ? 'checked' : '';
                            ?>
                                <label class="cat-opt">
                                    <input type="radio" name="category_id" value="<?= (int)$c['id'] ?>" <?= $checked ?>>
                                    <div>
                                        <i class="fa-solid <?= htmlspecialchars($icon) ?>"></i>
                                        <div class="cat-name"><?= htmlspecialchars($c['name']) ?></div>
                                        <div class="cat-desc"><?= htmlspecialchars($c['description'] ?? '') ?></div>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-muted small p-3 w-100">No categories available yet. Please contact support.</div>
                        <?php endif; ?>
                    </div>
                    <div class="field-error" id="err_cat">Please select a service category.</div>
                </div>

                <!-- STEP 2: PROBLEM -->
                <div class="panel">
                    <div class="panel-title">
                        <div class="panel-icon"><i class="fa-solid fa-clipboard-question"></i></div>
                        <span>2. Describe Your Problem</span>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">What needs to be fixed? *</label>
                        <textarea name="problem_description" id="problem" class="form-control" rows="4" placeholder="E.g., Split AC making loud noise and not cooling properly. Outdoor unit may need cleaning or gas refill."></textarea>
                        <div class="field-error" id="err_problem">Please describe your problem.</div>
                    </div>

                    <div class="mb-0">
                        <label class="form-label">Additional Notes (optional)</label>
                        <input type="text" name="notes" class="form-control" placeholder="E.g., Ring the doorbell twice, gate code is 1234">
                    </div>
                </div>

                <!-- STEP 3: DATE / TIME -->
                <div class="panel">
                    <div class="panel-title">
                        <div class="panel-icon"><i class="fa-solid fa-calendar-day"></i></div>
                        <span>3. Preferred Date & Time</span>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Date *</label>
                            <input type="date" name="booking_date" id="bookingDate" class="form-control" min="<?= $today ?>" value="<?= $today ?>">
                            <div class="field-error" id="err_date">Please choose a valid date.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Time Slot *</label>
                            <select name="booking_time" id="bookingTime" class="form-select">
                                <option value="">Choose a time slot</option>
                                <option value="09:00:00">09:00 AM – 11:00 AM</option>
                                <option value="11:00:00">11:00 AM – 01:00 PM</option>
                                <option value="13:00:00">01:00 PM – 03:00 PM</option>
                                <option value="15:00:00">03:00 PM – 05:00 PM</option>
                                <option value="17:00:00">05:00 PM – 07:00 PM</option>
                                <option value="19:00:00">07:00 PM – 09:00 PM</option>
                            </select>
                            <div class="field-error" id="err_time">Please choose a time slot.</div>
                        </div>
                    </div>
                </div>

                <!-- STEP 4: ADDRESS -->
                <div class="panel">
                    <div class="panel-title">
                        <div class="panel-icon"><i class="fa-solid fa-location-dot"></i></div>
                        <span>4. Service Address</span>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Contact Name *</label>
                            <input type="text" name="contact_name" id="contactName" class="form-control" placeholder="Full name" value="<?= htmlspecialchars($customer['name'] ?? '') ?>">
                            <div class="field-error" id="err_name">Contact name is required.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Contact Phone *</label>
                            <input type="tel" name="contact_phone" id="contactPhone" class="form-control" placeholder="10-digit" pattern="[0-9]{10}" inputmode="numeric" maxlength="10" value="<?= htmlspecialchars($customer['phone'] ?? '') ?>">
                            <div class="field-error" id="err_phone">Enter a valid 10-digit phone number.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Area / Locality</label>
                            <input type="text" name="area" class="form-control" placeholder="e.g., Andheri West" value="<?= htmlspecialchars($customer['area'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Full Address *</label>
                            <textarea name="address" id="address" class="form-control" rows="2" placeholder="Flat no, building, street name..."><?= htmlspecialchars($customer['address'] ?? '') ?></textarea>
                            <div class="field-error" id="err_address">Please enter your full address.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Landmark (optional)</label>
                            <input type="text" name="landmark" class="form-control" placeholder="E.g., Near City Mall">
                        </div>
                    </div>
                </div>

            </div>

            <!-- RIGHT COLUMN -->
            <div class="col-lg-4">
                <div class="sticky-col">

                    <!-- PAYMENT -->
                    <div class="panel">
                        <div class="panel-title">
                            <div class="panel-icon"><i class="fa-solid fa-credit-card"></i></div>
                            <span>5. Make Payment</span>
                        </div>

                        <?php if (!empty($qr_display_src)): ?>
                            <div class="qr-panel text-center mb-3">
                                <div class="qr-label"><i class="fa-solid fa-shield-halved me-1"></i>Scan & Pay</div>
                                <img src="<?= htmlspecialchars($qr_display_src) ?>" alt="Payment QR" class="qr-img">
                                <?php if (!empty($admin_upi)): ?>
                                    <div class="mt-3">
                                        <div class="qr-label">Or pay via UPI</div>
                                        <div class="upi-id" onclick="copyUpi('<?= htmlspecialchars($admin_upi) ?>')">
                                            <i class="fa-solid fa-copy"></i>
                                            <span><?= htmlspecialchars($admin_upi) ?></span>
                                            <span class="copy-toast" id="copyToast"></span>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="info-chip mb-3">
                                <i class="fa-solid fa-circle-info"></i>
                                <span>Admin hasn't set up a payment QR yet. Pay cash to the mechanic after service.</span>
                            </div>
                        <?php endif; ?>

                        <div class="mb-3">
                            <label class="form-label">Payment Method *</label>
                            <select name="payment_method" id="paymentMethod" class="form-select">
                                <option value="Online">Online (UPI / QR)</option>
                                <option value="Cash">Cash (pay mechanic)</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Amount Paid (₹) *</label>
                            <input type="number" step="0.01" min="1" name="amount" id="amountInput" class="form-control fw-bold text-success fs-5" placeholder="0.00">
                            <div class="field-error" id="err_amount">Enter amount greater than 0.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Transaction ID (optional)</label>
                            <input type="text" name="transaction_id" class="form-control" placeholder="UPI / Bank ref no.">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Payment Screenshot *</label>
                            <div class="upload-box" id="uploadBox" onclick="document.getElementById('proofFile').click()">
                                <i class="fa-solid fa-cloud-arrow-up" id="uploadIcon"></i>
                                <div class="title" id="uploadTitle">Click to upload proof</div>
                                <div class="hint" id="uploadHint">JPG, PNG, WebP, or PDF — Max 5MB</div>
                            </div>
                            <input type="file" name="payment_proof" id="proofFile" accept=".jpg,.jpeg,.png,.webp,.pdf" style="display:none;" onchange="handleFileSelect(this)">
                            <div class="field-error" id="err_file">Please upload a payment screenshot.</div>
                            <img id="proofPreview" class="preview-thumb" alt="Preview">
                        </div>
                    </div>

                    <!-- SUMMARY -->
                    <div class="panel">
                        <div class="panel-title">
                            <div class="panel-icon"><i class="fa-solid fa-receipt"></i></div>
                            <span>Booking Summary</span>
                        </div>

                        <div class="summary-row">
                            <span class="lbl">Service</span>
                            <span class="val" id="sumService">—</span>
                        </div>
                        <div class="summary-row">
                            <span class="lbl">Date & Time</span>
                            <span class="val" id="sumDateTime">—</span>
                        </div>
                        <div class="summary-row">
                            <span class="lbl">Method</span>
                            <span class="val" id="sumMethod">—</span>
                        </div>
                        <div class="summary-row total">
                            <span class="lbl">Amount</span>
                            <span class="val" id="sumAmount">₹0.00</span>
                        </div>

                        <button type="submit" class="btn-submit mt-3" id="submitBtn">
                            <i class="fa-solid fa-paper-plane me-2"></i>Submit Booking
                        </button>

                        <div class="info-chip mt-3 mb-0" style="background:#FEF3C7;border-color:#FDE68A;color:#92400E;">
                            <i class="fa-solid fa-clock" style="color:#D97706;"></i>
                            <span>After you submit, admin will verify your payment and broadcast the job to available mechanics.</span>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </form>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const form = document.getElementById('bookingForm');

    // ============ LIVE SUMMARY ============
    function updateSummary() {
        const catInput = form.querySelector('input[name="category_id"]:checked');
        const catLabel = catInput ? catInput.parentElement.querySelector('.cat-name') : null;
        document.getElementById('sumService').textContent = catLabel ? catLabel.textContent : '—';

        const d = document.getElementById('bookingDate').value;
        const tEl = document.getElementById('bookingTime');
        const t = tEl.value;
        if (d && t) {
            const timeText = tEl.options[tEl.selectedIndex].text;
            document.getElementById('sumDateTime').textContent = d + ' • ' + timeText;
        } else document.getElementById('sumDateTime').textContent = '—';

        const mEl = document.getElementById('paymentMethod');
        document.getElementById('sumMethod').textContent = mEl.options[mEl.selectedIndex].text;

        const a = parseFloat(document.getElementById('amountInput').value || 0);
        document.getElementById('sumAmount').textContent = '₹' + a.toFixed(2);
    }
    form.addEventListener('input', updateSummary);
    form.addEventListener('change', updateSummary);
    updateSummary();

    // ============ FILE UPLOAD HANDLER ============
    function handleFileSelect(input) {
        const preview = document.getElementById('proofPreview');
        const box = document.getElementById('uploadBox');
        const title = document.getElementById('uploadTitle');
        const hint = document.getElementById('uploadHint');
        const err = document.getElementById('err_file');

        if (input.files && input.files[0]) {
            const file = input.files[0];
            err.style.display = 'none';
            box.classList.add('has-file');
            title.textContent = file.name;
            hint.textContent = (file.size / 1024).toFixed(0) + ' KB';

            if (file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = e => {
                    preview.src = e.target.result;
                    preview.style.display = 'block';
                };
                reader.readAsDataURL(file);
            } else {
                preview.style.display = 'none';
            }
        }
    }

    // ============ CLIENT-SIDE VALIDATION ============
    function showError(fieldId, errId, show) {
        const f = document.getElementById(fieldId);
        const e = document.getElementById(errId);
        if (show) {
            f.classList.add('is-invalid');
            e.style.display = 'block';
        } else {
            f.classList.remove('is-invalid');
            e.style.display = 'none';
        }
    }

    function validateForm() {
        let valid = true;
        let firstError = null;

        // Category
        const cat = form.querySelector('input[name="category_id"]:checked');
        if (!cat) {
            document.getElementById('err_cat').style.display = 'block';
            valid = false;
            if (!firstError) firstError = document.getElementById('catPick');
        } else {
            document.getElementById('err_cat').style.display = 'none';
        }

        // Problem
        const problem = document.getElementById('problem');
        if (!problem.value.trim()) {
            showError('problem', 'err_problem', true);
            valid = false;
            if (!firstError) firstError = problem;
        } else showError('problem', 'err_problem', false);

        // Date
        const date = document.getElementById('bookingDate');
        if (!date.value) {
            showError('bookingDate', 'err_date', true);
            valid = false;
            if (!firstError) firstError = date;
        } else showError('bookingDate', 'err_date', false);

        // Time
        const time = document.getElementById('bookingTime');
        if (!time.value) {
            showError('bookingTime', 'err_time', true);
            valid = false;
            if (!firstError) firstError = time;
        } else showError('bookingTime', 'err_time', false);

        // Name
        const name = document.getElementById('contactName');
        if (!name.value.trim()) {
            showError('contactName', 'err_name', true);
            valid = false;
            if (!firstError) firstError = name;
        } else showError('contactName', 'err_name', false);

        // Phone
        const phone = document.getElementById('contactPhone');
        if (!/^[0-9]{10}$/.test(phone.value.trim())) {
            showError('contactPhone', 'err_phone', true);
            valid = false;
            if (!firstError) firstError = phone;
        } else showError('contactPhone', 'err_phone', false);

        // Address
        const address = document.getElementById('address');
        if (!address.value.trim()) {
            showError('address', 'err_address', true);
            valid = false;
            if (!firstError) firstError = address;
        } else showError('address', 'err_address', false);

        // Amount
        const amt = parseFloat(document.getElementById('amountInput').value || 0);
        if (amt <= 0) {
            showError('amountInput', 'err_amount', true);
            valid = false;
            if (!firstError) firstError = document.getElementById('amountInput');
        } else showError('amountInput', 'err_amount', false);

        // File
        const file = document.getElementById('proofFile');
        if (!file.files || !file.files[0]) {
            document.getElementById('err_file').style.display = 'block';
            valid = false;
            if (!firstError) firstError = document.getElementById('uploadBox');
        } else {
            document.getElementById('err_file').style.display = 'none';
        }

        if (!valid && firstError) {
            firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        return valid;
    }

    // ============ COPY UPI ============
    function copyUpi(text) {
        navigator.clipboard.writeText(text).then(() => {
            const t = document.getElementById('copyToast');
            t.textContent = '✓ Copied';
            setTimeout(() => t.textContent = '', 1800);
        });
    }

    // ============ SUBMIT ============
    form.addEventListener('submit', (e) => {
        if (!validateForm()) {
            e.preventDefault();
            return false;
        }
        const btn = document.getElementById('submitBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Submitting...';
    });

    // Phone input - allow only digits
    document.getElementById('contactPhone').addEventListener('input', function() {
        this.value = this.value.replace(/\D/g, '').slice(0, 10);
    });
</script>
</body>
</html>