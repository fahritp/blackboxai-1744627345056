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
$product_id = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);
$stock = $_POST['stock'] ?? [];

// Validate input
if (!$product_id || empty($stock)) {
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

    // Verify product ownership
    $stmt = $pdo->prepare("SELECT id FROM products WHERE id = ? AND seller_id = ?");
    $stmt->execute([$product_id, $seller['id']]);
    if (!$stmt->fetch()) {
        throw new Exception('Product not found or does not belong to seller');
    }

    // Update main product stock
    if (isset($stock['main'])) {
        $main_stock = filter_var($stock['main'], FILTER_VALIDATE_INT);
        if ($main_stock === false || $main_stock < 0) {
            throw new Exception('Invalid main product stock quantity');
        }

        $stmt = $pdo->prepare("
            UPDATE products 
            SET stock_quantity = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$main_stock, $product_id]);

        // Log stock update
        $stmt = $pdo->prepare("
            INSERT INTO inventory_log (
                product_id, variant_id,
                old_quantity, new_quantity,
                action, created_by,
                created_at
            ) VALUES (
                ?, NULL,
                (SELECT stock_quantity FROM products WHERE id = ?),
                ?, 'update', ?,
                NOW()
            )
        ");
        $stmt->execute([$product_id, $product_id, $main_stock, $_SESSION['user_id']]);
    }

    // Update variant stock
    if (isset($stock['variant']) && is_array($stock['variant'])) {
        foreach ($stock['variant'] as $variant_id => $quantity) {
            $variant_id = filter_var($variant_id, FILTER_VALIDATE_INT);
            $quantity = filter_var($quantity, FILTER_VALIDATE_INT);

            if (!$variant_id || $quantity === false || $quantity < 0) {
                throw new Exception('Invalid variant stock quantity');
            }

            // Verify variant belongs to product
            $stmt = $pdo->prepare("
                SELECT stock_quantity 
                FROM product_variants 
                WHERE id = ? AND product_id = ?
            ");
            $stmt->execute([$variant_id, $product_id]);
            $variant = $stmt->fetch();

            if (!$variant) {
                throw new Exception('Invalid variant');
            }

            // Update variant stock
            $stmt = $pdo->prepare("
                UPDATE product_variants 
                SET stock_quantity = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$quantity, $variant_id]);

            // Log variant stock update
            $stmt = $pdo->prepare("
                INSERT INTO inventory_log (
                    product_id, variant_id,
                    old_quantity, new_quantity,
                    action, created_by,
                    created_at
                ) VALUES (
                    ?, ?,
                    ?, ?, 
                    'update', ?,
                    NOW()
                )
            ");
            $stmt->execute([
                $product_id,
                $variant_id,
                $variant['stock_quantity'],
                $quantity,
                $_SESSION['user_id']
            ]);
        }
    }

    // Check if any stock levels are below threshold
    $stmt = $pdo->prepare("
        SELECT p.name, p.stock_quantity, p.low_stock_threshold
        FROM products p
        WHERE p.id = ? 
        AND p.stock_quantity <= p.low_stock_threshold
        UNION ALL
        SELECT CONCAT(p.name, ' - ', pv.name), pv.stock_quantity, p.low_stock_threshold
        FROM products p
        JOIN product_variants pv ON p.id = pv.product_id
        WHERE p.id = ?
        AND pv.stock_quantity <= p.low_stock_threshold
    ");
    $stmt->execute([$product_id, $product_id]);
    $low_stock_items = $stmt->fetchAll();

    // Send low stock notifications if needed
    if (!empty($low_stock_items)) {
        foreach ($low_stock_items as $item) {
            // TODO: Implement notification system
            // This could be email notifications, dashboard alerts, etc.
        }
    }

    // Commit transaction
    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Stock updated successfully'
    ]);

} catch (Exception $e) {
    // Rollback transaction on error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error updating stock: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
