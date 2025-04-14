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
$product_id = filter_var($data['product_id'] ?? null, FILTER_VALIDATE_INT);

// Validate input
if (!$product_id) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid product ID'
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

    // Verify product ownership
    $stmt = $pdo->prepare("
        SELECT id 
        FROM products 
        WHERE id = ? AND seller_id = ?
    ");
    $stmt->execute([$product_id, $seller['id']]);
    
    if (!$stmt->fetch()) {
        throw new Exception('Product not found or does not belong to seller');
    }

    // Check if product has any orders
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM order_items 
        WHERE product_id = ?
    ");
    $stmt->execute([$product_id]);
    $has_orders = $stmt->fetchColumn() > 0;

    if ($has_orders) {
        // If product has orders, just mark it as inactive instead of deleting
        $stmt = $pdo->prepare("
            UPDATE products 
            SET status = 'inactive', 
                updated_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$product_id]);
    } else {
        // Delete product variants
        $stmt = $pdo->prepare("
            DELETE FROM product_variant_options 
            WHERE variant_id IN (
                SELECT id FROM product_variants WHERE product_id = ?
            )
        ");
        $stmt->execute([$product_id]);

        $stmt = $pdo->prepare("
            DELETE FROM product_variants 
            WHERE product_id = ?
        ");
        $stmt->execute([$product_id]);

        // Delete product images
        // First, get all image URLs to delete files later
        $stmt = $pdo->prepare("
            SELECT image_url 
            FROM product_images 
            WHERE product_id = ?
        ");
        $stmt->execute([$product_id]);
        $images = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Delete image records from database
        $stmt = $pdo->prepare("
            DELETE FROM product_images 
            WHERE product_id = ?
        ");
        $stmt->execute([$product_id]);

        // Delete product reviews
        $stmt = $pdo->prepare("
            DELETE FROM product_reviews 
            WHERE product_id = ?
        ");
        $stmt->execute([$product_id]);

        // Delete cart items
        $stmt = $pdo->prepare("
            DELETE FROM cart_items 
            WHERE product_id = ?
        ");
        $stmt->execute([$product_id]);

        // Finally, delete the product
        $stmt = $pdo->prepare("
            DELETE FROM products 
            WHERE id = ?
        ");
        $stmt->execute([$product_id]);

        // Delete image files
        foreach ($images as $image_url) {
            // Extract file path from URL
            $file_path = parse_url($image_url, PHP_URL_PATH);
            if ($file_path) {
                $full_path = $_SERVER['DOCUMENT_ROOT'] . $file_path;
                if (file_exists($full_path)) {
                    unlink($full_path);
                }
            }
        }
    }

    // Commit transaction
    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => $has_orders ? 'Product has been marked as inactive' : 'Product deleted successfully'
    ]);

} catch (Exception $e) {
    // Rollback transaction on error
    $pdo->rollBack();
    error_log("Error deleting product: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
