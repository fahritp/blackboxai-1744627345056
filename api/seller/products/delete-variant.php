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
$variant_id = filter_var($data['variant_id'] ?? null, FILTER_VALIDATE_INT);

// Validate input
if (!$variant_id) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid variant ID'
    ]);
    exit;
}

try {
    // Start transaction
    $pdo->beginTransaction();

    // Get seller ID
    $stmt = $pdo->prepare("SELECT id FROM sellers WHERE user_id = ? AND status = 'approved'");
    $stmt->execute([$_SESSION['user_id']]);
    $seller = $stmt->fetch();

    if (!$seller) {
        throw new Exception('Seller not found or not approved');
    }

    // Get variant details and verify ownership
    $stmt = $pdo->prepare("
        SELECT v.*, p.seller_id 
        FROM product_variants v
        JOIN products p ON v.product_id = p.id
        WHERE v.id = ? AND p.seller_id = ?
    ");
    $stmt->execute([$variant_id, $seller['id']]);
    $variant = $stmt->fetch();

    if (!$variant) {
        throw new Exception('Variant not found or does not belong to seller');
    }

    // Check if this variant has any orders
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM order_items 
        WHERE variant_id = ?
    ");
    $stmt->execute([$variant_id]);
    $has_orders = $stmt->fetchColumn() > 0;

    if ($has_orders) {
        throw new Exception('Cannot delete variant with existing orders');
    }

    // Delete variant options
    $stmt = $pdo->prepare("DELETE FROM product_variant_options WHERE variant_id = ?");
    $stmt->execute([$variant_id]);

    // Delete variant from cart items
    $stmt = $pdo->prepare("DELETE FROM cart_items WHERE variant_id = ?");
    $stmt->execute([$variant_id]);

    // Delete variant
    $stmt = $pdo->prepare("DELETE FROM product_variants WHERE id = ?");
    $stmt->execute([$variant_id]);

    // Check if this was the last variant
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM product_variants 
        WHERE product_id = ?
    ");
    $stmt->execute([$variant['product_id']]);
    $variant_count = $stmt->fetchColumn();

    // If this was the last variant, update product price and stock
    if ($variant_count === 0) {
        $stmt = $pdo->prepare("
            UPDATE products 
            SET has_variants = 0 
            WHERE id = ?
        ");
        $stmt->execute([$variant['product_id']]);
    }

    // Commit transaction
    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Variant deleted successfully'
    ]);

} catch (Exception $e) {
    // Rollback transaction on error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error deleting product variant: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
