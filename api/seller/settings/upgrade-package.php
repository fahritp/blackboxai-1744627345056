<?php
require_once '../../../includes/config.php';
require_once '../../../includes/db.php';
require_once '../../../includes/functions.php';

// Set JSON response header
header('Content-Type: application/json');

// Check if user is logged in and is a seller
if (!is_logged_in() || $_SESSION['user_role'] !== 'seller') {
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized access'
    ]);
    exit;
}

// Get POST data
$data = json_decode(file_get_contents('php://input'), true);
$package_id = filter_var($data['package_id'] ?? null, FILTER_VALIDATE_INT);

// Validate input
if (!$package_id) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid package ID'
    ]);
    exit;
}

try {
    // Start transaction
    $pdo->beginTransaction();

    // Get seller information
    $stmt = $pdo->prepare("
        SELECT s.*, sp.max_products as current_max_products
        FROM sellers s
        LEFT JOIN seller_packages sp ON s.package_id = sp.id
        WHERE s.user_id = ? AND s.status = 'approved'
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $seller = $stmt->fetch();

    if (!$seller) {
        throw new Exception('Seller not found or not approved');
    }

    // Get new package information
    $stmt = $pdo->prepare("
        SELECT * FROM seller_packages 
        WHERE id = ? AND status = 'active'
    ");
    $stmt->execute([$package_id]);
    $new_package = $stmt->fetch();

    if (!$new_package) {
        throw new Exception('Invalid package selected');
    }

    // Check if this is actually an upgrade
    if ($new_package['max_products'] <= $seller['current_max_products']) {
        throw new Exception('New package must have higher product limit');
    }

    // Check if seller has any active subscriptions
    $stmt = $pdo->prepare("
        SELECT * FROM seller_subscriptions 
        WHERE seller_id = ? AND status = 'active'
        ORDER BY end_date DESC 
        LIMIT 1
    ");
    $stmt->execute([$seller['id']]);
    $current_subscription = $stmt->fetch();

    // Calculate subscription dates
    $start_date = date('Y-m-d H:i:s');
    if ($current_subscription) {
        // If there's an active subscription, new one starts after it ends
        $start_date = $current_subscription['end_date'];
        
        // Mark current subscription as expired
        $stmt = $pdo->prepare("
            UPDATE seller_subscriptions 
            SET status = 'expired',
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$current_subscription['id']]);
    }

    // Create new subscription
    $stmt = $pdo->prepare("
        INSERT INTO seller_subscriptions (
            seller_id, package_id, price,
            start_date, end_date,
            status, created_at
        ) VALUES (
            ?, ?, ?,
            ?, DATE_ADD(?, INTERVAL 1 MONTH),
            'active', NOW()
        )
    ");
    $stmt->execute([
        $seller['id'],
        $package_id,
        $new_package['price'],
        $start_date,
        $start_date
    ]);

    // Update seller's package
    $stmt = $pdo->prepare("
        UPDATE sellers 
        SET package_id = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$package_id, $seller['id']]);

    // Create invoice
    $stmt = $pdo->prepare("
        INSERT INTO seller_invoices (
            seller_id, subscription_id,
            amount, status,
            due_date, created_at
        ) VALUES (
            ?, ?,
            ?, 'pending',
            DATE_ADD(NOW(), INTERVAL 3 DAY), NOW()
        )
    ");
    $stmt->execute([
        $seller['id'],
        $pdo->lastInsertId(),
        $new_package['price']
    ]);

    // Commit transaction
    $pdo->commit();

    // Send notification email
    // TODO: Implement email notification system

    echo json_encode([
        'success' => true,
        'message' => 'Package upgraded successfully'
    ]);

} catch (Exception $e) {
    // Rollback transaction on error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error upgrading package: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
