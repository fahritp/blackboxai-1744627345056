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

// Get product ID from query string
$product_id = filter_input(INPUT_GET, 'product_id', FILTER_VALIDATE_INT);

// Validate input
if (!$product_id) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid product ID'
    ]);
    exit;
}

try {
    // Get seller information
    $stmt = $pdo->prepare("SELECT id FROM sellers WHERE user_id = ? AND status = 'approved'");
    $stmt->execute([$_SESSION['user_id']]);
    $seller = $stmt->fetch();

    if (!$seller) {
        throw new Exception('Seller not found or not approved');
    }

    // Get product details and verify ownership
    $stmt = $pdo->prepare("
        SELECT id, name, stock_quantity 
        FROM products 
        WHERE id = ? AND seller_id = ?
    ");
    $stmt->execute([$product_id, $seller['id']]);
    $product = $stmt->fetch();

    if (!$product) {
        throw new Exception('Product not found or does not belong to seller');
    }

    // Get variant stock information
    $stmt = $pdo->prepare("
        SELECT id, name, stock_quantity
        FROM product_variants
        WHERE product_id = ?
        ORDER BY name
    ");
    $stmt->execute([$product_id]);
    $variants = $stmt->fetchAll();

    // Prepare response
    $response = [
        'success' => true,
        'stock' => [
            'main' => $product['stock_quantity'],
            'variants' => $variants
        ]
    ];

    echo json_encode($response);

} catch (Exception $e) {
    error_log("Error getting stock information: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
