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
$bank_id = filter_var($data['bank_id'] ?? null, FILTER_VALIDATE_INT);

// Validate input
if (!$bank_id) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid bank account ID'
    ]);
    exit;
}

try {
    // Start transaction
    $pdo->beginTransaction();

    // Get seller information
    $stmt = $pdo->prepare("SELECT id FROM sellers WHERE user_id = ? AND status = 'approved'");
    $stmt->execute([$_SESSION['user_id']]);
    $seller = $stmt->fetch();

    if (!$seller) {
        throw new Exception('Seller not found or not approved');
    }

    // Verify bank account ownership
    $stmt = $pdo->prepare("
        SELECT id 
        FROM seller_bank_accounts 
        WHERE id = ? AND seller_id = ? AND status = 'active'
    ");
    $stmt->execute([$bank_id, $seller['id']]);
    if (!$stmt->fetch()) {
        throw new Exception('Bank account not found or does not belong to seller');
    }

    // Remove primary status from all bank accounts
    $stmt = $pdo->prepare("
        UPDATE seller_bank_accounts 
        SET is_primary = 0,
            updated_at = NOW()
        WHERE seller_id = ?
    ");
    $stmt->execute([$seller['id']]);

    // Set new primary bank account
    $stmt = $pdo->prepare("
        UPDATE seller_bank_accounts 
        SET is_primary = 1,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$bank_id]);

    // Commit transaction
    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Primary bank account updated successfully'
    ]);

} catch (Exception $e) {
    // Rollback transaction on error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error setting primary bank account: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
