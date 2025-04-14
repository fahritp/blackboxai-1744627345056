<?php
require_once '../../../includes/config.php';
require_once '../../../includes/db.php';
require_once '../../../includes/functions.php';

// Verify request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Verify API key
$api_key = $_SERVER['HTTP_X_API_KEY'] ?? '';
if ($api_key !== PAYMENT_PROCESSOR_API_KEY) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    // Get request body
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['payout_id']) || !isset($data['status']) || !isset($data['transaction_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields']);
        exit;
    }

    // Validate status
    $valid_statuses = ['processing', 'completed', 'failed'];
    if (!in_array($data['status'], $valid_statuses)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid status']);
        exit;
    }

    $pdo->beginTransaction();

    // Update payout status
    $stmt = $pdo->prepare("
        UPDATE payouts 
        SET 
            status = ?,
            transaction_id = ?,
            processed_at = CASE WHEN ? = 'completed' THEN NOW() ELSE processed_at END,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([
        $data['status'],
        $data['transaction_id'],
        $data['status'],
        $data['payout_id']
    ]);

    if ($stmt->rowCount() === 0) {
        throw new Exception('Payout not found');
    }

    // If status is failed, mark order items as available for next payout
    if ($data['status'] === 'failed') {
        $stmt = $pdo->prepare("
            DELETE FROM payout_items 
            WHERE payout_id = ?
        ");
        $stmt->execute([$data['payout_id']]);
    }

    // Get payout details for notification
    $stmt = $pdo->prepare("
        SELECT 
            p.*,
            s.user_id,
            b.bank_name,
            b.account_number
        FROM payouts p
        JOIN sellers s ON p.seller_id = s.id
        JOIN seller_bank_accounts b ON p.bank_account_id = b.id
        WHERE p.id = ?
    ");
    $stmt->execute([$data['payout_id']]);
    $payout = $stmt->fetch();

    // Create notification
    $notification_message = '';
    switch ($data['status']) {
        case 'processing':
            $notification_message = 'Your payout of ' . format_price($payout['amount']) . ' is being processed.';
            break;
        case 'completed':
            $notification_message = 'Your payout of ' . format_price($payout['amount']) . ' has been completed.';
            break;
        case 'failed':
            $notification_message = 'Your payout of ' . format_price($payout['amount']) . ' has failed. The amount has been returned to your available balance.';
            break;
    }

    $stmt = $pdo->prepare("
        INSERT INTO notifications (
            user_id,
            type,
            message,
            data,
            created_at
        ) VALUES (?, 'payout_status', ?, ?, NOW())
    ");
    $stmt->execute([
        $payout['user_id'],
        $notification_message,
        json_encode([
            'payout_id' => $payout['id'],
            'status' => $data['status'],
            'amount' => $payout['amount'],
            'bank_name' => $payout['bank_name'],
            'account_number' => mask_account_number($payout['account_number'])
        ])
    ]);

    $pdo->commit();

    // Send response
    echo json_encode([
        'success' => true,
        'message' => 'Payout status updated successfully'
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log("Error updating payout status: " . $e->getMessage());
    
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
