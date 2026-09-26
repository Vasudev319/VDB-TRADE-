<?php
// htdocs/mechanic/index.php

// Safe Session Start
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/db.php';
require_once '../config/security.php';

// Redirect if already logged in
if (is_mechanic_logged_in()) {
    header("Location: dashboard.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'])) {
        $error = "Session expired. Please refresh the page.";
    } else {
        $phone = clean_input($_POST['phone']);
        $password = $_POST['password'];

        $stmt = $pdo->prepare("SELECT * FROM mechanics WHERE phone = ?");
        $stmt->execute([$phone]);
        $mechanic = $stmt->fetch();

        if ($mechanic && password_verify($password, $mechanic['password'])) {
            $_SESSION['mechanic_id'] = $mechanic['id'];
            $_SESSION['mechanic_name'] = $mechanic['name'];
            $_SESSION['role'] = 'mechanic';

            header("Location: dashboard.php");
            exit;
        } else {
            $error = "Invalid mobile number or password.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Mechanic Partner - ServiceFix</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        :root {
            --mech-primary: #F97316;
            --mech-gradient: linear-gradient(135deg, #F97316 0%, #EAB308 100%);
            --bg-dark: #0F172A; /* Solid Dark Color */
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--bg-dark);
            color: #F8FAFC;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* LIGHTWEIGHT CARD (No Blur, No Heavy Animations) */
        .auth-container {
            width: 100%; max-width: 400px; padding: 20px;
        }

        .auth-card {
            background-color: #1E293B; /* Solid Color instead of transparent glass */
            border-radius: 24px; padding: 40px 30px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            border: 1px solid #334155;
            position: relative; overflow: hidden;
        }

        .auth-card::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 5px;
            background: var(--mech-gradient);
        }

        .brand-logo {
            width: 65px; height: 65px;
            background: var(--mech-gradient);
            border-radius: 20px; display: inline-flex; align-items: center; justify-content: center;
            font-size: 28px; color: white; margin-bottom: 20px;
        }

        .auth-title { font-weight: 800; font-size: 1.5rem; color: #FFFFFF; letter-spacing: -0.5px; margin-bottom: 5px;}
        .auth-subtitle { color: #94A3B8; font-weight: 500; font-size: 0.95rem; margin-bottom: 30px;}

        /* Form Inputs */
        .form-floating > .form-control {
            background-color: #0F172A; border: 1px solid #334155;
            border-radius: 12px; font-weight: 600; color: #F8FAFC;
            padding-left: 55px; box-shadow: none !important;
        }
        .form-floating > .form-control:focus {
            border-color: var(--mech-primary);
        }
        .form-floating > label { padding-left: 55px; color: #64748B; font-weight: 500; }

        .input-icon {
            position: absolute; left: 20px; top: 50%; transform: translateY(-50%);
            color: #64748B; font-size: 1.1rem; z-index: 10;
        }
        .form-control:focus + .input-icon, .form-floating:focus-within .input-icon { color: var(--mech-primary); }

        /* Button */
        .btn-auth {
            background: var(--mech-gradient);
            color: white; border: none; border-radius: 12px;
            font-weight: 800; font-size: 1rem; padding: 16px; width: 100%;
            margin-top: 10px; transition: 0.2s;
        }
        .btn-auth:hover { opacity: 0.9; color: white;}

        .alert-cyber {
            background-color: #450a0a; border: 1px solid #991b1b;
            color: #fca5a5; border-radius: 12px; padding: 12px 15px; font-size: 0.85rem; font-weight: 600;
            display: flex; align-items: center;
        }

        .return-link {
            display: inline-block; margin-top: 25px; color: #94A3B8; font-size: 0.85rem;
            text-decoration: none; font-weight: 600;
        }
        .return-link:hover { color: var(--mech-primary); text-decoration: underline;}
    </style>
</head>
<body>

<div class="auth-container">
    <div class="auth-card text-center">

        <div class="brand-logo">
            <i class="fa-solid fa-wrench"></i>
        </div>

        <h2 class="auth-title">Partner Portal</h2>
        <p class="auth-subtitle">Login to manage your service jobs.</p>

        <?php if ($error): ?>
            <div class="alert-cyber mb-4 text-start">
                <i class="fa-solid fa-triangle-exclamation fs-5 me-3"></i>
                <div><?= $error ?></div>
            </div>
        <?php endif; ?>

        <form method="POST" class="text-start">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">

            <div class="form-floating mb-3 position-relative">
                <i class="fa-solid fa-mobile-screen input-icon"></i>
                <input type="tel" name="phone" class="form-control" id="loginPhone" placeholder="Mobile Number" required pattern="[0-9]{10}" autocomplete="off">
                <label for="loginPhone">Registered Mobile No.</label>
            </div>

            <div class="form-floating mb-4 position-relative">
                <i class="fa-solid fa-lock input-icon"></i>
                <input type="password" name="password" class="form-control" id="loginPass" placeholder="Password" required>
                <label for="loginPass">Password</label>
            </div>

            <button type="submit" class="btn-auth">
                Login to Workspace <i class="fa-solid fa-arrow-right ms-2"></i>
            </button>
        </form>

        <a href="../index.php" class="return-link"><i class="fa-solid fa-arrow-left me-1"></i> Back to ServiceFix</a>
    </div>
</div>

</body>
</html>