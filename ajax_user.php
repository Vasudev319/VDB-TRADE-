<?php
// htdocs/ajax_user.php — DYNAMIC AJAX ENDPOINT
if (session_status() === PHP_SESSION_NONE) { session_start(); }
ini_set('display_errors', 0); // AJAX must not show HTML errors
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ========== BOOTSTRAP ==========
$db_loaded = false;
if (file_exists(__DIR__ . '/config/db.php')) { require_once __DIR__ . '/config/db.php'; $db_loaded = true; }
if (file_exists(__DIR__ . '/config/security.php')) { require_once __DIR__ . '/config/security.php'; }

if (!$db_loaded || !isset($pdo)) {
    echo json_encode(['success' => false, 'message' => 'DB unavailable']);
    exit;
}

if (!function_exists('clean_input')) { function clean_input($d) { return htmlspecialchars(trim($d ?? ''), ENT_QUOTES, 'UTF-8'); } }

// ========== SESSION CHECK ==========
$user_id = (int)($_SESSION['user_id'] ?? $_SESSION['customer_id'] ?? 0);
if ($user_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

// ========== ROUTER ==========
$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {

        // ============ GET SUB-PROBLEMS BY CATEGORY ============
        case 'get_problems':
            $cat_id = (int)($_POST['category_id'] ?? $_GET['category_id'] ?? 0);
            if ($cat_id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid category']);
                exit;
            }

            // Try to fetch from problems table
            $problems = [];
            try {
                $stmt = $pdo->prepare("SELECT id, name, description FROM problems WHERE category_id = ? AND status = 'active' ORDER BY name ASC");
                $stmt->execute([$cat_id]);
                $problems = $stmt->fetchAll();
            } catch (PDOException $e) {
                // Fallback: default problems per category
                $problems = [];
            }

            // If no problems table, return common issues as suggestions
            if (empty($problems)) {
                $defaults = [
                    1 => ['AC not cooling', 'Water leakage', 'Strange noise', 'Remote not working', 'Gas refill needed'],
                    2 => ['Tap leaking', 'Low water pressure', 'Drain blocked', 'Toilet flush issue', 'Pipe burst'],
                    3 => ['Switch not working', 'Fan noise', 'Power trip', 'Light flickering', 'Wiring issue'],
                    4 => ['Deep cleaning needed', 'Kitchen cleaning', 'Bathroom cleaning', 'Sofa cleaning', 'Window cleaning'],
                    5 => ['Fridge not cooling', 'Washing machine error', 'Microwave not heating', 'Dishwasher issue'],
                    6 => ['Door repair', 'Furniture assembly', 'Window fixing', 'Lock replacement', 'Wood polishing'],
                ];
                $list = $defaults[$cat_id] ?? ['General inspection', 'Not sure — need diagnosis'];
                foreach ($list as $i => $name) {
                    $problems[] = ['id' => $i + 1, 'name' => $name, 'description' => ''];
                }
            }

            echo json_encode(['success' => true, 'problems' => $problems]);
            break;

        // ============ GET SINGLE BOOKING STATUS (for live tracking) ============
        case 'get_booking_status':
            $booking_id = (int)($_POST['booking_id'] ?? 0);
            if ($booking_id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid booking']);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT b.id, b.status, b.mechanic_id, b.amount, b.completion_otp,
                       m.name AS mechanic_name, m.phone AS mechanic_phone
                FROM bookings b
                LEFT JOIN users m ON b.mechanic_id = m.id
                WHERE b.id = ? AND b.user_id = ?
                LIMIT 1
            ");
            $stmt->execute([$booking_id, $user_id]);
            $b = $stmt->fetch();

            if (!$b) {
                echo json_encode(['success' => false, 'message' => 'Booking not found']);
                exit;
            }

            echo json_encode([
                'success' => true,
                'status' => $b['status'],
                'mechanic' => $b['mechanic_name'] ? [
                    'name' => $b['mechanic_name'],
                    'phone' => $b['mechanic_phone'],
                ] : null,
                'otp' => (strpos(strtolower($b['status']), 'in progress') !== false) ? $b['completion_otp'] : null,
            ]);
            break;

        // ============ GET ACTIVE BOOKINGS COUNT (for badge) ============
        case 'get_active_count':
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE user_id = ? AND status NOT IN ('Completed','Admin Verified','Cancelled')");
            $stmt->execute([$user_id]);
            echo json_encode(['success' => true, 'count' => (int)$stmt->fetchColumn()]);
            break;

        // ============ CANCEL BOOKING (customer-initiated) ============
        case 'cancel_booking':
            $booking_id = (int)($_POST['booking_id'] ?? 0);
            $csrf = $_POST['csrf_token'] ?? '';

            if (!function_exists('verify_csrf_token') || !verify_csrf_token($csrf)) {
                echo json_encode(['success' => false, 'message' => 'Security token expired']);
                exit;
            }

            if ($booking_id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid booking']);
                exit;
            }

            // Only allow cancel if not yet started
            $stmt = $pdo->prepare("SELECT status FROM bookings WHERE id = ? AND user_id = ? LIMIT 1");
            $stmt->execute([$booking_id, $user_id]);
            $b = $stmt->fetch();

            if (!$b) {
                echo json_encode(['success' => false, 'message' => 'Booking not found']);
                exit;
            }

            $cancel_allowed = ['Payment Verification Pending','Pending Assignment','Broadcast','Assigned','Accepted'];
            if (!in_array($b['status'], $cancel_allowed)) {
                echo json_encode(['success' => false, 'message' => 'This booking can no longer be cancelled. Contact support.']);
                exit;
            }

            $pdo->prepare("UPDATE bookings SET status = 'Cancelled' WHERE id = ? AND user_id = ?")
                ->execute([$booking_id, $user_id]);

            echo json_encode(['success' => true, 'message' => 'Booking cancelled.']);
            break;

        // ============ SEARCH CATEGORIES (autocomplete) ============
        case 'search_categories':
            $q = clean_input($_POST['q'] ?? '');
            if (strlen($q) < 2) {
                echo json_encode(['success' => true, 'results' => []]);
                exit;
            }

            $stmt = $pdo->prepare("SELECT id, name, icon FROM categories WHERE name LIKE ? AND status = 'active' LIMIT 8");
            $stmt->execute(["%$q%"]);
            $results = $stmt->fetchAll();

            echo json_encode(['success' => true, 'results' => $results]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action']);
            break;
    }
} catch (PDOException $e) {
    error_log("AJAX error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error']);
} catch (Exception $e) {
    error_log("AJAX error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error']);
}