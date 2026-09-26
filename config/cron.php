<?php
// htdocs/config/cron.php
require_once 'db.php';

// --- SECURITY: Prevent random visitors from running this script ---
// In a real production environment, you should change 'secret_key_123' to a random strong password.
$valid_key = getenv('CRON_SECRET') ?: 'secret_key_123';
if (!isset($_GET['key']) || $_GET['key'] !== $valid_key) {
    // If run from a web browser without the correct key, deny access.
    // (If run directly from the server command line, PHP_SAPI is 'cli', which is allowed)
    if (php_sapi_name() !== 'cli') {
        die("Unauthorized access.");
    }
}

try {
    // 1. Find all bookings that were assigned MORE than 10 minutes ago but are still in 'Assigned' status
    $stmt = $pdo->query("
        SELECT id, mechanic_id
        FROM bookings
        WHERE status = 'Assigned'
        AND assigned_at <= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
    ");
    $timed_out_bookings = $stmt->fetchAll();

    $reassigned_count = 0;
    $returned_to_admin_count = 0;

    foreach ($timed_out_bookings as $booking) {
        $booking_id = $booking['id'];
        $current_mechanic = $booking['mechanic_id'];

        // 2. Try to find the *next* available mechanic (who is NOT the current mechanic)
        $mech_stmt = $pdo->prepare("
            SELECT id FROM mechanics
            WHERE is_available = 1
            AND id != ?
            ORDER BY RAND() LIMIT 1
        ");
        $mech_stmt->execute([$current_mechanic]);
        $next_mechanic = $mech_stmt->fetch();

        $pdo->beginTransaction();

        if ($next_mechanic) {
            // Reassign to the new mechanic and reset the timer
            $new_mech_id = $next_mechanic['id'];
            $update = $pdo->prepare("UPDATE bookings SET mechanic_id = ?, assigned_at = NOW() WHERE id = ?");
            $update->execute([$new_mech_id, $booking_id]);

            // Log this action in history
            $log = $pdo->prepare("INSERT INTO booking_history (booking_id, status_updated_to, notes) VALUES (?, 'Assigned', 'Auto-reassigned to a new mechanic due to 10-minute timeout.')");
            $log->execute([$booking_id]);

            $reassigned_count++;
        } else {
            // No other mechanics are available. Return it to 'Pending Assignment' so Admin can see it.
            $update = $pdo->prepare("UPDATE bookings SET status = 'Pending Assignment', mechanic_id = NULL, assigned_at = NULL WHERE id = ?");
            $update->execute([$booking_id]);

            // Log this action in history
            $log = $pdo->prepare("INSERT INTO booking_history (booking_id, status_updated_to, notes) VALUES (?, 'Pending Assignment', 'Timeout: No other mechanics available. Returned to Admin.')");
            $log->execute([$booking_id]);

            $returned_to_admin_count++;
        }

        $pdo->commit();
    }

    // Output result (useful for testing or viewing in Cron Job logs)
    echo "Cron Execution Complete.<br>";
    echo "Jobs Reassigned: $reassigned_count<br>";
    echo "Jobs Returned to Admin: $returned_to_admin_count<br>";

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "Error executing Cron Job: " . $e->getMessage();
}
?>