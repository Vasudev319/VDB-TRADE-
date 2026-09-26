<?php
// htdocs/mechanic/complete_job.php — COMPLETE JOB FLOW
if (session_status() === PHP_SESSION_NONE) { session_start(); }
ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL);

// ========== BOOTSTRAP ==========
$db_loaded = false;
if (file_exists(__DIR__ . '/../config/db.php')) { require_once __DIR__ . '/../config/db.php'; $db_loaded = true; }
if (file_exists(__DIR__ . '/../config/security.php')) { require_once __DIR__ . '/../config/security.php'; }

if (!function_exists('verify_csrf_token')) { function verify_csrf_token($t) { return true; } }
if (!function_exists('generate_csrf_token')) { function generate_csrf_token() { return $_SESSION['csrf_token'] ??= bin2hex(random_bytes(16)); } }
if (!function_exists('clean_input')) { function clean_input($d) { return htmlspecialchars(trim($d ?? ''), ENT_QUOTES, 'UTF-8'); } }

// ========== SESSION ==========
$mechanic_id = (int)($_SESSION['user_id'] ?? $_SESSION['mechanic_id'] ?? $_SESSION['id'] ?? 0);
if ($mechanic_id <= 0) { header("Location: ../index.php"); die(); }

$booking_id = (int)($_GET['id'] ?? $_POST['booking_id'] ?? 0);
if ($booking_id <= 0) { header("Location: dashboard.php"); die(); }

// Fetch booking
$stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = ? AND mechanic_id = ?");
$stmt->execute([$booking_id, $mechanic_id]);
$booking = $stmt->fetch();

if (!$booking || !in_array($booking['status'], ['In Progress', 'Assigned'])) {
    $_SESSION['flash_message'] = "<div class='alert alert-danger border-0 shadow-sm'>Job not eligible for completion.</div>";
    header("Location: dashboard.php");
    die();
}

// ========== HANDLE POST (Submit Completion) ==========
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'complete_job') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $flash = "<div class='alert alert-danger border-0 shadow-sm'>Security token expired.</div>";
    } else {
        $otp_entered = trim($_POST['completion_otp'] ?? '');
        $notes = clean_input($_POST['completion_notes'] ?? '');

        // Use DEMO_OTP if appropriate or match the DB OTP
        $db_otp = $booking['completion_otp'] ?? '';
        $otp_valid = false;

        if (defined('DEMO_OTP') && $otp_entered === DEMO_OTP) {
            $otp_valid = true;
        } elseif (!empty($db_otp) && $otp_entered === $db_otp) {
            $otp_valid = true;
        }

        if (!$otp_valid) {
            $flash = "<div class='alert alert-danger border-0 shadow-sm'>Invalid OTP. The customer must provide the correct OTP.</div>";
        } elseif (!isset($_FILES['proof_file']) || $_FILES['proof_file']['error'] !== UPLOAD_ERR_OK) {
            $flash = "<div class='alert alert-danger border-0 shadow-sm'>Please upload a clear photo of the completed work.</div>";
        } else {
            // Handle file upload
            $file = $_FILES['proof_file'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','webp'];

            if (!in_array($ext, $allowed)) {
                $flash = "<div class='alert alert-danger border-0 shadow-sm'>Only JPG, PNG, or WebP allowed.</div>";
            } elseif ($file['size'] > 5 * 1024 * 1024) { // 5MB limit
                $flash = "<div class='alert alert-danger border-0 shadow-sm'>File too large (Max 5MB).</div>";
            } else {
                $upload_dir = __DIR__ . '/../uploads/proofs/';
                if (!is_dir($upload_dir)) { mkdir($upload_dir, 0777, true); }

                $filename = 'proof_' . $booking_id . '_' . time() . '.' . $ext;
                $dest = $upload_dir . $filename;

                if (move_uploaded_file($file['tmp_name'], $dest)) {
                    $db_path = 'uploads/proofs/' . $filename;

                    try {
                        $pdo->beginTransaction();

                        $upd = $pdo->prepare("UPDATE bookings SET status = 'Completed_Pending_Verification', completion_proof = ?, completion_notes = ?, completed_at = NOW(), otp_verified = 1 WHERE id = ?");
                        $upd->execute([$db_path, $notes, $booking_id]);

                        $hist = $pdo->prepare("INSERT INTO booking_history (booking_id, status_updated_to, proof_file_path, notes) VALUES (?, 'Completed_Pending_Verification', ?, 'Mechanic uploaded proof & verified OTP')");
                        $hist->execute([$booking_id, $db_path]);

                        $pdo->commit();
                        $_SESSION['flash_message'] = "<div class='alert alert-success border-0 shadow-sm'>Job marked complete and submitted for admin verification!</div>";
                        header("Location: dashboard.php");
                        die();
                    } catch (PDOException $e) {
                        $pdo->rollBack();
                        $flash = "<div class='alert alert-danger border-0 shadow-sm'>Database error: " . htmlspecialchars($e->getMessage()) . "</div>";
                    }
                } else {
                    $flash = "<div class='alert alert-danger border-0 shadow-sm'>Failed to save uploaded file.</div>";
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete Job - VDB TRADE</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f8f9fa; font-family: sans-serif; }
        .card-custom { border-radius: 15px; border: none; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        .btn-custom { border-radius: 10px; font-weight: 600; padding: 12px 20px; }
    </style>
</head>
<body>
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6">
                <div class="card card-custom p-4">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h4 class="fw-bold mb-0"><i class="fa-solid fa-clipboard-check text-success me-2"></i>Complete Job</h4>
                        <a href="dashboard.php" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Back</a>
                    </div>

                    <?= $flash ?>

                    <div class="alert alert-warning border-0 shadow-sm mb-4">
                        <i class="fa-solid fa-triangle-exclamation me-2"></i><strong>Warning:</strong> You must collect the OTP from the customer and upload a clear photo of the completed work to receive payment.
                    </div>

                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="complete_job">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <input type="hidden" name="booking_id" value="<?= $booking_id ?>">

                        <div class="mb-4">
                            <label class="form-label fw-bold">Customer OTP <span class="text-danger">*</span></label>
                            <input type="text" name="completion_otp" class="form-control form-control-lg text-center fw-bold fs-4" placeholder="Enter 4-digit OTP" required maxlength="10">
                            <?php if(defined('DEMO_OTP')): ?>
                                <small class="text-muted mt-1 d-block"><i class="fa-solid fa-info-circle me-1"></i>Demo Mode: Use <strong><?= DEMO_OTP ?></strong> to bypass.</small>
                            <?php endif; ?>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-bold">Work Proof Photo <span class="text-danger">*</span></label>
                            <input type="file" name="proof_file" class="form-control" accept="image/jpeg, image/png, image/webp" required>
                            <small class="text-muted">Take a clear photo showing the repaired vehicle/item.</small>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-bold">Additional Notes (Optional)</label>
                            <textarea name="completion_notes" class="form-control" rows="3" placeholder="Any details about the repair..."></textarea>
                        </div>

                        <button type="submit" class="btn btn-success btn-custom w-100">
                            <i class="fa-solid fa-upload me-2"></i>Submit Completion
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</body>
</html>