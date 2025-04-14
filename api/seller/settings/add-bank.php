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
$bank_name = filter_input(INPUT_POST, 'bank_name', FILTER_SANITIZE_STRING);
$account_number = filter_input(INPUT_POST, 'account_number', FILTER_SANITIZE_STRING);
$account_holder = filter_input(INPUT_POST, 'account_holder', FILTER_SANITIZE_STRING);

// Validate required fields
if (!$bank_name || !$account_number || !$account_holder) {
    echo json_encode([
        'success' => false,
        'message' => 'Please fill in all required fields'
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

    // Check if account number already exists
    $stmt = $pdo->prepare("
        SELECT id 
        FROM seller_bank_accounts 
        WHERE seller_id = ? AND account_number = ?
    ");
    $stmt->execute([$seller['id'], $account_number]);
    if ($stmt->fetch()) {
        throw new Exception('This account number is already registered');
    }

    // Check if this is the first bank account (make it primary if so)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM seller_bank_accounts WHERE seller_id = ?");
    $stmt->execute([$seller['id']]);
    $is_first = $stmt->fetchColumn() === 0;

    // Add bank account
    $stmt = $pdo->prepare("
        INSERT INTO seller_bank_accounts (
            seller_id, bank_name,
            account_number, account_holder,
            is_primary, status,
            created_at
        ) VALUES (
            ?, ?,
            ?, ?,
            ?, 'active',
            NOW()
        )
    ");
    $stmt->execute([
        $seller['id'],
        $bank_name,
        $account_number,
        $account_holder,
        $is_first ? 1 : 0
    ]);

    // Add to verification queue if needed
    // TODO: Implement bank account verification system

    // Commit transaction
    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Bank account added successfully'
    ]);

} catch (Exception $e) {
    // Rollback transaction on error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error adding bank account: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
