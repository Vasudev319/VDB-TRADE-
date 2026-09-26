<?php
// htdocs/admin/index.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/db.php';
require_once '../config/security.php';

// Redirect if already logged in as Admin
if (is_admin_logged_in()) {
    header("Location: dashboard.php");
    exit;
}

// --- AUTOMATIC SCHEMA UPDATE ---
// Renames 'username' column to 'email' if it hasn't been done yet
try {
    $check_col = $pdo->query("SHOW COLUMNS FROM admins LIKE 'email'");
    if ($check_col->rowCount() == 0) {
        $pdo->exec("ALTER TABLE admins CHANGE username email VARCHAR(255) NOT NULL");
        // Set a default email for the existing admin account
        $pdo->exec("UPDATE admins SET email = 'admin@servicefix.com' WHERE email = 'admin'");
    }
} catch (PDOException $e) {
    // Fail silently if already updated
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'])) {
        $error = "Security session expired. Please refresh and try again.";
    } else {
        $email = clean_input($_POST['email']);
        $password = $_POST['password'];
        $otp_entered = clean_input($_POST['otp']);

        // 1. Verify OTP first (Simulated as DEMO_OTP)
        if ($otp_entered !== DEMO_OTP) {
            $error = "Access Denied: Invalid 2FA OTP Code.";
        } else {
            // 2. Verify Email and Password
            $stmt = $pdo->prepare("SELECT * FROM admins WHERE email = ?");
            $stmt->execute([$email]);
            $admin = $stmt->fetch();

            if ($admin && password_verify($password, $admin['password'])) {
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_email'] = $admin['email'];
                $_SESSION['role'] = 'admin';

                header("Location: dashboard.php");
                exit;
            } else {
                $error = "Access Denied: Invalid email or password.";
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
    <title>Admin Portal - ServiceFix</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        :root {
            --admin-primary: #3B82F6;
            --admin-glow: #60A5FA;
            --bg-deep: #0B0F19;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-deep);
            color: #E2E8F0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            position: relative;
        }

        /* Cyber Animated Background Orbs */
        .bg-shapes { position: fixed; top: 0; left: 0; width: 100%; height: 100%; overflow: hidden; z-index: 0; pointer-events: none; }
        .shape { position: absolute; filter: blur(90px); opacity: 0.5; animation: float 12s ease-in-out infinite; }
        .shape-1 { background: #1D4ED8; width: 450px; height: 450px; top: -15%; left: -10%; border-radius: 50%; }
        .shape-2 { background: #0F766E; width: 350px; height: 350px; bottom: -10%; right: -5%; border-radius: 50%; animation-delay: -6s; }
        .shape-3 { background: #4338CA; width: 250px; height: 250px; top: 30%; left: 50%; border-radius: 50%; animation-delay: -3s; animation-duration: 15s;}

        @keyframes float {
            0%, 100% { transform: translateY(0) scale(1); }
            50% { transform: translateY(-40px) scale(1.05); }
        }
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(30px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        /* Dark Premium Glassmorphism Card */
        .auth-container {
            width: 100%; max-width: 420px; padding: 20px; z-index: 10;
            animation: slideIn 0.7s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }
        .auth-card {
            background: rgba(30, 41, 59, 0.6);
            backdrop-filter: blur(25px); -webkit-backdrop-filter: blur(25px);
            border-radius: 24px; padding: 45px 35px;
            box-shadow: 0 30px 60px -12px rgba(0, 0, 0, 0.5), inset 0 1px 0 rgba(255,255,255,0.1);
            border: 1px solid rgba(255, 255, 255, 0.08);
            position: relative; overflow: hidden;
        }
        .auth-card::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px;
            background: linear-gradient(90deg, transparent, var(--admin-primary), transparent);
        }

        .brand-logo {
            width: 70px; height: 70px;
            background: linear-gradient(135deg, #1E3A8A 0%, #3B82F6 100%);
            border-radius: 20px; display: inline-flex; align-items: center; justify-content: center;
            font-size: 32px; color: white; margin-bottom: 25px;
            box-shadow: 0 10px 25px rgba(59, 130, 246, 0.4);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .auth-card:hover .brand-logo { transform: translateY(-5px) scale(1.05); box-shadow: 0 15px 35px rgba(59, 130, 246, 0.6); }

        .auth-title { font-weight: 800; font-size: 1.5rem; color: #F8FAFC; letter-spacing: -0.5px; margin-bottom: 8px;}
        .auth-subtitle { color: #94A3B8; font-weight: 500; font-size: 0.9rem; margin-bottom: 35px;}

        /* Dark Mode Form Inputs */
        .form-floating > .form-control {
            background: rgba(15, 23, 42, 0.6); border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 14px; font-weight: 500; color: #F8FAFC;
            padding-left: 55px; transition: all 0.3s;
        }
        .form-floating > .form-control:focus {
            background: rgba(15, 23, 42, 0.9); border-color: var(--admin-primary);
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.15); color: white;
        }
        .form-floating > label { padding-left: 55px; color: #64748B; font-weight: 500; }

        .input-icon {
            position: absolute; left: 20px; top: 50%; transform: translateY(-50%);
            color: #64748B; font-size: 1.1rem; z-index: 10; transition: 0.3s;
        }
        .form-control:focus + .input-icon, .form-floating:focus-within .input-icon { color: var(--admin-glow); }

        .btn-otp {
            background: rgba(255, 255, 255, 0.1); color: #CBD5E1; font-weight: 600;
            border-radius: 14px; padding: 0 15px; border: 1px solid rgba(255, 255, 255, 0.1); transition: 0.3s;
        }
        .btn-otp:hover { background: rgba(255, 255, 255, 0.2); color: white; }

        /* Cyber Button */
        .btn-auth {
            background: linear-gradient(135deg, #2563EB 0%, #1D4ED8 100%);
            color: white; border: none; border-radius: 14px;
            font-weight: 700; font-size: 1rem; padding: 16px; width: 100%;
            transition: all 0.3s; margin-top: 10px; position: relative; overflow: hidden;
            box-shadow: 0 10px 20px rgba(29, 78, 216, 0.3);
        }
        .btn-auth::after {
            content: ''; position: absolute; top: 0; left: -100%; width: 50%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transform: skewX(-20deg); transition: 0.5s;
        }
        .btn-auth:hover { transform: translateY(-2px); box-shadow: 0 15px 30px rgba(29, 78, 216, 0.5); color: white;}
        .btn-auth:hover::after { left: 200%; transition: 0.7s ease-in-out; }

        .alert-cyber {
            background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3);
            color: #FCA5A5; border-radius: 12px; padding: 12px 15px; font-size: 0.85rem; font-weight: 500;
            backdrop-filter: blur(10px); display: flex; align-items: center;
        }

        .return-link {
            display: inline-block; margin-top: 25px; color: #64748B; font-size: 0.85rem;
            text-decoration: none; font-weight: 600; transition: 0.3s;
        }
        .return-link:hover { color: white; }
    </style>
</head>
<body>

<div class="bg-shapes">
    <div class="shape shape-1"></div>
    <div class="shape shape-2"></div>
    <div class="shape shape-3"></div>
</div>

<div class="auth-container">
    <div class="auth-card text-center">

        <div class="brand-logo">
            <i class="fa-solid fa-shield-halved"></i>
        </div>
        <h2 class="auth-title">Command Center</h2>
        <p class="auth-subtitle">Authorized Admin Personnel Only</p>

        <?php if ($error): ?>
            <div class="alert-cyber mb-4 text-start shadow-sm">
                <i class="fa-solid fa-triangle-exclamation fs-5 me-3 text-danger"></i>
                <div><?= $error ?></div>
            </div>
        <?php endif; ?>

        <form method="POST" class="text-start">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">

            <div class="form-floating mb-3 position-relative">
                <i class="fa-solid fa-envelope input-icon"></i>
                <input type="email" name="email" class="form-control" id="loginEmail" placeholder="admin@servicefix.com" required autocomplete="off">
                <label for="loginEmail">Admin Email</label>
            </div>

            <div class="form-floating mb-3 position-relative">
                <i class="fa-solid fa-lock input-icon"></i>
                <input type="password" name="password" class="form-control" id="loginPass" placeholder="Master Password" required>
                <label for="loginPass">Master Password</label>
            </div>

            <div class="d-flex gap-2 mb-4">
                <div class="form-floating position-relative flex-grow-1">
                    <i class="fa-solid fa-key input-icon"></i>
                    <input type="text" name="otp" class="form-control" id="loginOtp" placeholder="Enter OTP" maxlength="4" required>
                    <label for="loginOtp">2FA Code</label>
                </div>
                <button type="button" class="btn-otp" onclick="alert('Admin OTP sent to your email!\n\n(DEMO MODE: Use 1234 to verify)')">Send</button>
            </div>

            <button type="submit" class="btn-auth">
                <i class="fa-solid fa-power-off me-2"></i> Initialize Session
            </button>
        </form>

        <a href="../index.php" class="return-link"><i class="fa-solid fa-arrow-left me-1"></i> Return to Main App</a>
    </div>

    <div class="text-center mt-4 text-white-50" style="font-size: 0.75rem; font-weight: 500; letter-spacing: 1px; z-index: 10; position: relative;">
        SECURE PROTOCOL V2.0 &bull; SERVICEFIX
    </div>
</div>

</body>
</html>