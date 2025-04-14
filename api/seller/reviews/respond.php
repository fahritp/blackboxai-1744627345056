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
$review_id = filter_input(INPUT_POST, 'review_id', FILTER_VALIDATE_INT);
$response = filter_input(INPUT_POST, 'response', FILTER_SANITIZE_STRING);

// Validate input
if (!$review_id || !$response) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid input'
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

    // Get review and verify ownership
    $stmt = $pdo->prepare("
        SELECT r.*, p.seller_id, u.email as customer_email
        FROM reviews r
        JOIN products p ON r.product_id = p.id
        JOIN users u ON r.user_id = u.id
        WHERE r.id = ? AND p.seller_id = ?
    ");
    $stmt->execute([$review_id, $seller['id']]);
    $review = $stmt->fetch();

    if (!$review) {
        throw new Exception('Review not found or does not belong to seller');
    }

    // Check if review already has a response
    if ($review['seller_response']) {
        throw new Exception('Review already has a response');
    }

    // Update review with seller's response
    $stmt = $pdo->prepare("
        UPDATE reviews 
        SET seller_response = ?,
            response_date = NOW(),
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$response, $review_id]);

    // Create notification for customer
    $stmt = $pdo->prepare("
        INSERT INTO notifications (
            user_id, type,
            title, message,
            reference_id, reference_type,
            created_at
        ) VALUES (
            ?, 'review_response',
            'Seller responded to your review',
            ?, ?, 'review',
            NOW()
        )
    ");
    $stmt->execute([
        $review['user_id'],
        "The seller has responded to your review of " . $review['product_name'],
        $review_id
    ]);

    // Send email notification to customer
    // TODO: Implement email notification system
    /*
    send_email(
        $review['customer_email'],
        'Seller responded to your review',
        'The seller has responded to your review...'
    );
    */

    // Commit transaction
    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Response submitted successfully'
    ]);

} catch (Exception $e) {
    // Rollback transaction on error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error responding to review: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
