<?php
require_once '../../../includes/config.php';
require_once '../../../includes/db.php';
require_once '../../../includes/functions.php';

// Check if user is logged in and is a seller
if (!is_logged_in() || $_SESSION['user_role'] !== 'seller') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    // Get seller information
    $stmt = $pdo->prepare("SELECT id FROM sellers WHERE user_id = ? AND status = 'approved'");
    $stmt->execute([$_SESSION['user_id']]);
    $seller = $stmt->fetch();

    if (!$seller) {
        http_response_code(403);
        echo json_encode(['error' => 'Seller account not approved']);
        exit;
    }

    switch ($_SERVER['REQUEST_METHOD']) {
        case 'POST':
            // Add new bank account
            $data = json_decode(file_get_contents('php://input'), true);
            
            // Validate required fields
            $required_fields = ['bank_name', 'account_number', 'account_holder'];
            foreach ($required_fields as $field) {
                if (empty($data[$field])) {
                    http_response_code(400);
                    echo json_encode(['error' => "Missing required field: $field"]);
                    exit;
                }
            }

            // Start transaction
            $pdo->beginTransaction();

            // If this is the first account or marked as primary, update existing primary accounts
            if (empty($data['is_primary'])) {
                // Check if this is the first account
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM seller_bank_accounts WHERE seller_id = ?");
                $stmt->execute([$seller['id']]);
                $data['is_primary'] = ($stmt->fetchColumn() === 0);
            }

            if ($data['is_primary']) {
                $stmt = $pdo->prepare("
                    UPDATE seller_bank_accounts 
                    SET is_primary = FALSE 
                    WHERE seller_id = ?
                ");
                $stmt->execute([$seller['id']]);
            }

            // Insert new bank account
            $stmt = $pdo->prepare("
                INSERT INTO seller_bank_accounts (
                    seller_id,
                    bank_name,
                    account_number,
                    account_holder,
                    routing_number,
                    swift_code,
                    is_primary
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $seller['id'],
                $data['bank_name'],
                $data['account_number'],
                $data['account_holder'],
                $data['routing_number'] ?? null,
                $data['swift_code'] ?? null,
                $data['is_primary'] ?? false
            ]);

            $account_id = $pdo->lastInsertId();

            $pdo->commit();

            // Return new account details
            $stmt = $pdo->prepare("SELECT * FROM seller_bank_accounts WHERE id = ?");
            $stmt->execute([$account_id]);
            $account = $stmt->fetch();

            echo json_encode([
                'success' => true,
                'message' => 'Bank account added successfully',
                'account' => [
                    'id' => $account['id'],
                    'bank_name' => $account['bank_name'],
                    'account_number' => mask_account_number($account['account_number']),
                    'account_holder' => $account['account_holder'],
                    'is_primary' => (bool)$account['is_primary'],
                    'created_at' => $account['created_at']
                ]
            ]);
            break;

        case 'PUT':
            // Update bank account
            if (!isset($_GET['id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Account ID is required']);
                exit;
            }

            $data = json_decode(file_get_contents('php://input'), true);
            
            // Verify account belongs to seller
            $stmt = $pdo->prepare("
                SELECT * FROM seller_bank_accounts 
                WHERE id = ? AND seller_id = ?
            ");
            $stmt->execute([$_GET['id'], $seller['id']]);
            $account = $stmt->fetch();

            if (!$account) {
                http_response_code(404);
                echo json_encode(['error' => 'Bank account not found']);
                exit;
            }

            // Start transaction
            $pdo->beginTransaction();

            // If setting as primary, update other accounts
            if (!empty($data['is_primary']) && !$account['is_primary']) {
                $stmt = $pdo->prepare("
                    UPDATE seller_bank_accounts 
                    SET is_primary = FALSE 
                    WHERE seller_id = ?
                ");
                $stmt->execute([$seller['id']]);
            }

            // Update account
            $updates = [];
            $params = [];
            foreach (['bank_name', 'account_holder', 'routing_number', 'swift_code', 'is_primary'] as $field) {
                if (isset($data[$field])) {
                    $updates[] = "$field = ?";
                    $params[] = $data[$field];
                }
            }

            if (!empty($updates)) {
                $params[] = $_GET['id'];
                $params[] = $seller['id'];

                $stmt = $pdo->prepare("
                    UPDATE seller_bank_accounts 
                    SET " . implode(', ', $updates) . "
                    WHERE id = ? AND seller_id = ?
                ");
                $stmt->execute($params);
            }

            $pdo->commit();

            // Return updated account details
            $stmt = $pdo->prepare("SELECT * FROM seller_bank_accounts WHERE id = ?");
            $stmt->execute([$_GET['id']]);
            $account = $stmt->fetch();

            echo json_encode([
                'success' => true,
                'message' => 'Bank account updated successfully',
                'account' => [
                    'id' => $account['id'],
                    'bank_name' => $account['bank_name'],
                    'account_number' => mask_account_number($account['account_number']),
                    'account_holder' => $account['account_holder'],
                    'is_primary' => (bool)$account['is_primary'],
                    'updated_at' => $account['updated_at']
                ]
            ]);
            break;

        case 'DELETE':
            // Delete bank account
            if (!isset($_GET['id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Account ID is required']);
                exit;
            }

            // Verify account belongs to seller and is not used in any pending payouts
            $stmt = $pdo->prepare("
                SELECT ba.*, 
                    (SELECT COUNT(*) FROM payouts 
                     WHERE bank_account_id = ba.id 
                     AND status IN ('pending', 'processing')) as pending_payouts
                FROM seller_bank_accounts ba
                WHERE ba.id = ? AND ba.seller_id = ?
            ");
            $stmt->execute([$_GET['id'], $seller['id']]);
            $account = $stmt->fetch();

            if (!$account) {
                http_response_code(404);
                echo json_encode(['error' => 'Bank account not found']);
                exit;
            }

            if ($account['pending_payouts'] > 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Cannot delete account with pending payouts']);
                exit;
            }

            // Start transaction
            $pdo->beginTransaction();

            // Delete account
            $stmt = $pdo->prepare("
                DELETE FROM seller_bank_accounts 
                WHERE id = ? AND seller_id = ?
            ");
            $stmt->execute([$_GET['id'], $seller['id']]);

            // If this was the primary account, set another account as primary
            if ($account['is_primary']) {
                $stmt = $pdo->prepare("
                    UPDATE seller_bank_accounts 
                    SET is_primary = TRUE 
                    WHERE seller_id = ? 
                    ORDER BY created_at ASC 
                    LIMIT 1
                ");
                $stmt->execute([$seller['id']]);
            }

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => 'Bank account deleted successfully'
            ]);
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
    }

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log("Error in bank account management: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'error' => 'An error occurred while processing the request',
        'message' => $e->getMessage()
    ]);
}

function mask_account_number($number) {
    $length = strlen($number);
    return str_repeat('*', $length - 4) . substr($number, -4);
}
?>
