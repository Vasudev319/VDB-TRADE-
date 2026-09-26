<?php
// htdocs/admin/settings.php
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

$admin_id = $_SESSION['admin_id'];

// --- HANDLE POST ACTIONS (UNIVERSAL SAVE ENGINE) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['flash_message'] = "<div class='alert alert-danger shadow-sm border-0 rounded-3'>Security token expired. Please try again.</div>";
    } else {
        $action = $_POST['action'];

        try {
            if ($action === 'save_global_settings') {
                // Loop through all POST data and save anything that is a setting
                $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");

                foreach ($_POST as $key => $value) {
                    // Ignore system keys
                    if (in_array($key, ['csrf_token', 'action'])) continue;

                    $clean_value = clean_input($value);
                    $stmt->execute([$key, $clean_value, $clean_value]);
                }

                // Handle file upload for payment QR
                if (isset($_FILES['payment_qr_file']) && $_FILES['payment_qr_file']['error'] === UPLOAD_ERR_OK) {
                    $file_tmp = $_FILES['payment_qr_file']['tmp_name'];
                    $file_name = $_FILES['payment_qr_file']['name'];
                    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                    $allowed_exts = ['jpg', 'jpeg', 'png', 'webp'];

                    if (in_array($file_ext, $allowed_exts)) {
                        $upload_dir = '../uploads/qr/';
                        if (!is_dir($upload_dir)) {
                            mkdir($upload_dir, 0777, true);
                        }
                        $new_file_name = 'qr_' . time() . '.' . $file_ext;
                        $dest_path = $upload_dir . $new_file_name;

                        if (move_uploaded_file($file_tmp, $dest_path)) {
                            $db_path = 'uploads/qr/' . $new_file_name;
                            $stmt->execute(['payment_qr', $db_path, $db_path]);
                        }
                    }
                }

                $_SESSION['flash_message'] = "<div class='alert alert-success shadow-sm border-0'><i class='fa-solid fa-check-circle me-2'></i>Settings updated successfully!</div>";
            }
        } catch (PDOException $e) {
            $_SESSION['flash_message'] = "<div class='alert alert-danger shadow-sm border-0'>Database Error: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
    header("Location: settings.php");
    exit;
}

$message = '';
if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
}

// Fetch all settings into a clean array
$settings = [];
try {
    $stmt_settings = $pdo->query("SELECT setting_key, setting_value FROM settings");
    while ($row = $stmt_settings->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (PDOException $e) {
    die("Error loading settings.");
}

// Helper function to safely output setting values
function get_setting($key, $default = '', $settings_array = []) {
    return htmlspecialchars($settings_array[$key] ?? $default);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Master Configuration - Admin</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        :root { --admin-nav: #0F172A; --admin-bg: #F1F5F9; --primary: #4F46E5; }
        body { font-family: 'Inter', sans-serif; background: var(--admin-bg); color: #334155; padding-bottom: 50px; }

        .navbar { background-color: var(--admin-nav) !important; padding: 15px 0; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .navbar-brand { font-weight: 800; color: #fff !important; }
        .nav-link { color: #94A3B8 !important; font-weight: 500; padding: 8px 16px !important; border-radius: 8px; transition: 0.3s;}
        .nav-link:hover, .nav-link.active { background-color: rgba(79, 70, 229, 0.2); color: #fff !important; }

        .card-custom { background: #fff; border-radius: 20px; padding: 30px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); border: 1px solid rgba(226, 232, 240, 0.8);}
        .form-label { font-weight: 600; color: #475569; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px;}
        .form-control, .form-select { border-radius: 10px; padding: 10px 15px; border: 1px solid #CBD5E1; background-color: #F8FAFC; transition: 0.2s;}
        .form-control:focus, .form-select:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15); background-color: #fff;}

        /* Vertical Tabs Styling */
        .nav-pills-custom .nav-link { color: #475569; font-weight: 600; text-align: left; padding: 12px 20px; border-radius: 12px; margin-bottom: 5px; transition: all 0.2s;}
        .nav-pills-custom .nav-link:hover { background: #E2E8F0; color: #0F172A; }
        .nav-pills-custom .nav-link.active { background: var(--primary); color: white; box-shadow: 0 4px 10px rgba(79, 70, 229, 0.3); }
        .nav-pills-custom .nav-link i { width: 25px; text-align: center; }

        .section-header { border-bottom: 2px solid #F1F5F9; padding-bottom: 15px; margin-bottom: 25px; }

        #flash-msg-container { animation: slideDown 0.5s ease forwards; }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg sticky-top mb-4">
    <div class="container">
        <a class="navbar-brand" href="dashboard.php"><i class="fa-solid fa-bolt text-warning me-2"></i>ServiceFix</a>
        <div class="collapse navbar-collapse" id="adminNav">
            <ul class="navbar-nav ms-auto mb-2 mb-lg-0 gap-2">
                <li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
                <li class="nav-item"><a class="nav-link" href="bookings.php">Bookings</a></li>
                <li class="nav-item"><a class="nav-link" href="mechanics.php">Mechanics</a></li>
                <li class="nav-item"><a class="nav-link" href="payments.php">Verify Payments</a></li>
                <li class="nav-item"><a class="nav-link active" href="settings.php">Settings</a></li>
                <li class="nav-item ms-lg-3"><a href="logout.php" class="btn btn-danger btn-sm mt-1 px-4 fw-bold rounded-pill">Logout</a></li>
            </ul>
        </div>
    </div>
</nav>

<div class="container pb-5">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <h3 class="fw-bold text-dark mb-1">Master Configuration</h3>
            <p class="text-muted small mb-0">Control every aspect of your platform from one place.</p>
        </div>
        <button type="button" class="btn btn-primary fw-bold px-4 rounded-pill shadow-sm" onclick="document.getElementById('globalSettingsForm').submit();">
            <i class="fa-solid fa-floppy-disk me-2"></i> Save All Settings
        </button>
    </div>

    <div id="flash-msg-container"><?= $message ?></div>

    <form method="POST" id="globalSettingsForm" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
        <input type="hidden" name="action" value="save_global_settings">

        <div class="row g-4">
            <!-- Sidebar Navigation -->
            <div class="col-lg-3">
                <div class="nav flex-column nav-pills nav-pills-custom bg-white p-3 rounded-4 shadow-sm border" role="tablist" aria-orientation="vertical">
                    <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#tab-general" type="button"><i class="fa-solid fa-globe"></i> General</button>
                    <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-commission" type="button"><i class="fa-solid fa-percent"></i> Commission & Fees</button>
                    <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-booking" type="button"><i class="fa-solid fa-calendar-check"></i> Booking Flow</button>
                    <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-mechanic" type="button"><i class="fa-solid fa-users-gear"></i> Mechanic Rules</button>
                    <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-security" type="button"><i class="fa-solid fa-shield-halved"></i> OTP & Security</button>
                    <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-system" type="button"><i class="fa-solid fa-server"></i> System & Maint.</button>
                </div>
            </div>

            <!-- Content Area -->
            <div class="col-lg-9">
                <div class="tab-content card-custom">

                    <!-- 1. GENERAL SETTINGS -->
                    <div class="tab-pane fade show active" id="tab-general" role="tabpanel">
                        <h5 class="fw-bold text-dark section-header"><i class="fa-solid fa-globe text-primary me-2"></i> General Settings</h5>
                        <div class="row g-4">
                            <div class="col-md-6">
                                <label class="form-label">Site Name</label>
                                <input type="text" name="site_name" class="form-control" value="<?= get_setting('site_name', 'ServiceFix', $settings) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Contact Email</label>
                                <input type="email" name="contact_email" class="form-control" value="<?= get_setting('contact_email', 'support@servicefix.com', $settings) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Support Phone</label>
                                <input type="text" name="contact_phone" class="form-control" value="<?= get_setting('contact_phone', '', $settings) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">System Timezone</label>
                                <select name="timezone" class="form-select">
                                    <option value="Asia/Kolkata" <?= get_setting('timezone', '', $settings) == 'Asia/Kolkata' ? 'selected' : '' ?>>Asia/Kolkata (IST)</option>
                                    <option value="UTC" <?= get_setting('timezone', '', $settings) == 'UTC' ? 'selected' : '' ?>>UTC</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- 2. COMMISSION & SETTLEMENT -->
                    <div class="tab-pane fade" id="tab-commission" role="tabpanel">
                        <h5 class="fw-bold text-dark section-header"><i class="fa-solid fa-percent text-success me-2"></i> Commission & Settlement Rules</h5>
                        <div class="row g-4">
                            <div class="col-md-6">
                                <label class="form-label">Platform Commission Rate (%)</label>
                                <input type="number" step="0.1" name="commission_rate" class="form-control" value="<?= get_setting('commission_rate', '15', $settings) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Commission Model</label>
                                <select name="commission_model" class="form-select">
                                    <option value="deduct_mechanic" <?= get_setting('commission_model', '', $settings) == 'deduct_mechanic' ? 'selected' : '' ?>>Deduct from Mechanic Earnings</option>
                                    <option value="add_customer" <?= get_setting('commission_model', '', $settings) == 'add_customer' ? 'selected' : '' ?>>Add to Customer Final Price</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Settlement Cycle</label>
                                <select name="settlement_cycle" class="form-select">
                                    <option value="weekly" <?= get_setting('settlement_cycle', '', $settings) == 'weekly' ? 'selected' : '' ?>>Weekly</option>
                                    <option value="monthly" <?= get_setting('settlement_cycle', '', $settings) == 'monthly' ? 'selected' : '' ?>>Monthly</option>
                                    <option value="manual" <?= get_setting('settlement_cycle', '', $settings) == 'manual' ? 'selected' : '' ?>>Manual Offline Only</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Minimum Payout/Due Alert (₹)</label>
                                <input type="number" name="min_payout" class="form-control" value="<?= get_setting('min_payout', '500', $settings) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Payment QR Image</label>
                                <input type="file" name="payment_qr_file" class="form-control" accept="image/*">
                                <?php
                                    $current_qr = get_setting('payment_qr', '', $settings);
                                    if (!empty($current_qr)):
                                ?>
                                    <div class="mt-2">
                                        <img src="../<?= htmlspecialchars($current_qr) ?>" alt="Current QR" style="max-height: 100px; border: 1px solid #ddd; border-radius: 8px; padding: 5px; background: #fff;">
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- 3. BOOKING FLOW -->
                    <div class="tab-pane fade" id="tab-booking" role="tabpanel">
                        <h5 class="fw-bold text-dark section-header"><i class="fa-solid fa-calendar-check text-info me-2"></i> Booking Flow & Logic</h5>
                        <div class="row g-4">
                            <div class="col-md-6">
                                <label class="form-label">Assignment Logic</label>
                                <select name="assignment_logic" class="form-select">
                                    <option value="broadcast" <?= get_setting('assignment_logic', '', $settings) == 'broadcast' ? 'selected' : '' ?>>Broadcast (First to Accept)</option>
                                    <option value="manual" <?= get_setting('assignment_logic', '', $settings) == 'manual' ? 'selected' : '' ?>>Admin Manual Assign Only</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Verification Fee / Visit Charge (₹)</label>
                                <input type="number" name="service_fee" class="form-control" value="<?= get_setting('service_fee', '49', $settings) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Cancellation Policy</label>
                                <select name="cancellation_policy" class="form-select">
                                    <option value="anytime" <?= get_setting('cancellation_policy', '', $settings) == 'anytime' ? 'selected' : '' ?>>Allow Anytime</option>
                                    <option value="before_assigned" <?= get_setting('cancellation_policy', '', $settings) == 'before_assigned' ? 'selected' : '' ?>>Only Before Mechanic Assigned</option>
                                    <option value="none" <?= get_setting('cancellation_policy', '', $settings) == 'none' ? 'selected' : '' ?>>No Cancellations</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- 4. MECHANIC RULES -->
                    <div class="tab-pane fade" id="tab-mechanic" role="tabpanel">
                        <h5 class="fw-bold text-dark section-header"><i class="fa-solid fa-users-gear text-warning me-2"></i> Mechanic & Partner Rules</h5>
                        <div class="row g-4">
                            <div class="col-md-6">
                                <label class="form-label">Partner Onboarding</label>
                                <select name="mechanic_onboarding" class="form-select">
                                    <option value="require_kyc" <?= get_setting('mechanic_onboarding', '', $settings) == 'require_kyc' ? 'selected' : '' ?>>Require Admin KYC Approval</option>
                                    <option value="auto_approve" <?= get_setting('mechanic_onboarding', '', $settings) == 'auto_approve' ? 'selected' : '' ?>>Auto-Approve (No KYC)</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Max Active Jobs Per Mechanic</label>
                                <input type="number" name="max_active_jobs" class="form-control" value="<?= get_setting('max_active_jobs', '3', $settings) ?>">
                            </div>
                        </div>
                    </div>

                    <!-- 5. OTP & SECURITY -->
                    <div class="tab-pane fade" id="tab-security" role="tabpanel">
                        <h5 class="fw-bold text-dark section-header"><i class="fa-solid fa-shield-halved text-danger me-2"></i> Security & OTP Settings</h5>
                        <div class="row g-4">
                            <div class="col-md-6">
                                <label class="form-label">Login Method Requirement</label>
                                <select name="login_requirement" class="form-select">
                                    <option value="both" <?= get_setting('login_requirement', '', $settings) == 'both' ? 'selected' : '' ?>>Password + OTP (Highest Security)</option>
                                    <option value="password_only" <?= get_setting('login_requirement', '', $settings) == 'password_only' ? 'selected' : '' ?>>Password Only</option>
                                    <option value="otp_only" <?= get_setting('login_requirement', '', $settings) == 'otp_only' ? 'selected' : '' ?>>OTP Only</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">OTP Expiry Time (Minutes)</label>
                                <input type="number" name="otp_expiry_mins" class="form-control" value="<?= get_setting('otp_expiry_mins', '5', $settings) ?>">
                            </div>
                        </div>
                    </div>

                    <!-- 6. SYSTEM & MAINTENANCE -->
                    <div class="tab-pane fade" id="tab-system" role="tabpanel">
                        <h5 class="fw-bold text-dark section-header"><i class="fa-solid fa-server text-secondary me-2"></i> System State & Maintenance</h5>
                        <div class="row g-4">
                            <div class="col-md-12">
                                <div class="p-4 bg-danger bg-opacity-10 border border-danger border-opacity-25 rounded-3">
                                    <div class="form-check form-switch fs-5">
                                        <input class="form-check-input" type="checkbox" name="maintenance_mode" value="1" id="maintMode" <?= get_setting('maintenance_mode', '0', $settings) == '1' ? 'checked' : '' ?>>
                                        <label class="form-check-label fw-bold text-danger ms-2" for="maintMode">Enable Maintenance Mode</label>
                                    </div>
                                    <p class="small text-muted mt-2 mb-0">When enabled, customers and mechanics will see a "Down for Maintenance" screen. Only Admins can log in.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    setTimeout(() => {
        const flashMsg = document.getElementById('flash-msg-container');
        if (flashMsg && flashMsg.innerHTML.trim() !== '') {
            flashMsg.style.transition = 'opacity 0.5s ease';
            flashMsg.style.opacity = '0';
            setTimeout(() => flashMsg.innerHTML = '', 500);
        }
    }, 3000);
</script>
</body>
</html>