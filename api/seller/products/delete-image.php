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
$image_id = filter_var($data['image_id'] ?? null, FILTER_VALIDATE_INT);

// Validate input
if (!$image_id) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid image ID'
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

    // Get image details and verify ownership
    $stmt = $pdo->prepare("
        SELECT pi.*, p.seller_id 
        FROM product_images pi
        JOIN products p ON pi.product_id = p.id
        WHERE pi.id = ? AND p.seller_id = ?
    ");
    $stmt->execute([$image_id, $seller['id']]);
    $image = $stmt->fetch();

    if (!$image) {
        throw new Exception('Image not found or does not belong to seller');
    }

    // Check if this is the only image for the product
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM product_images 
        WHERE product_id = ?
    ");
    $stmt->execute([$image['product_id']]);
    $image_count = $stmt->fetchColumn();

    if ($image_count <= 1) {
        throw new Exception('Cannot delete the only image of a product');
    }

    // Delete image record
    $stmt = $pdo->prepare("DELETE FROM product_images WHERE id = ?");
    $stmt->execute([$image_id]);

    // Delete image file
    $file_path = parse_url($image['image_url'], PHP_URL_PATH);
    if ($file_path) {
        $full_path = $_SERVER['DOCUMENT_ROOT'] . $file_path;
        if (file_exists($full_path)) {
            unlink($full_path);
        }
    }

    // If this was the primary image, set another image as primary
    if ($image['is_primary']) {
        $stmt = $pdo->prepare("
            UPDATE product_images 
            SET is_primary = 1 
            WHERE product_id = ? 
            ORDER BY sort_order ASC 
            LIMIT 1
        ");
        $stmt->execute([$image['product_id']]);
    }

    // Commit transaction
    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Image deleted successfully'
    ]);

} catch (Exception $e) {
    // Rollback transaction on error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error deleting product image: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
