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

// Verify CSRF token
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request'
    ]);
    exit;
}

try {
    // Get seller information
    $stmt = $pdo->prepare("
        SELECT s.*, sp.max_products
        FROM sellers s
        LEFT JOIN seller_packages sp ON s.package_id = sp.id
        WHERE s.user_id = ? AND s.status = 'approved'
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $seller = $stmt->fetch();

    if (!$seller) {
        throw new Exception('Seller not found or not approved');
    }

    // Check product limit
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE seller_id = ?");
    $stmt->execute([$seller['id']]);
    if ($stmt->fetchColumn() >= $seller['max_products']) {
        throw new Exception('You have reached your product limit');
    }

    // Validate required fields
    $name = sanitize_input($_POST['name'] ?? '');
    $category_id = filter_var($_POST['category_id'] ?? null, FILTER_VALIDATE_INT);
    $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_FLOAT);
    $stock_quantity = filter_var($_POST['stock_quantity'] ?? null, FILTER_VALIDATE_INT);
    $short_description = sanitize_input($_POST['short_description'] ?? '');
    $description = sanitize_input($_POST['description'] ?? '');
    $sku = sanitize_input($_POST['sku'] ?? '');

    if (!$name || !$category_id || !$price || !is_numeric($stock_quantity) || 
        !$short_description || !$description) {
        throw new Exception('Please fill in all required fields');
    }

    // Validate price and stock
    if ($price <= 0) {
        throw new Exception('Price must be greater than zero');
    }
    if ($stock_quantity < 0) {
        throw new Exception('Stock quantity cannot be negative');
    }

    // Start transaction
    $pdo->beginTransaction();

    // Create product
    $stmt = $pdo->prepare("
        INSERT INTO products (
            seller_id, category_id, name, sku, price,
            stock_quantity, short_description, description,
            status, created_at
        ) VALUES (
            ?, ?, ?, ?, ?,
            ?, ?, ?,
            'active', NOW()
        )
    ");
    $stmt->execute([
        $seller['id'],
        $category_id,
        $name,
        $sku,
        $price,
        $stock_quantity,
        $short_description,
        $description
    ]);
    $product_id = $pdo->lastInsertId();

    // Handle primary image
    if (isset($_FILES['primary_image']) && $_FILES['primary_image']['error'] === UPLOAD_ERR_OK) {
        $image_info = handle_product_image_upload($_FILES['primary_image']);
        if ($image_info) {
            $stmt = $pdo->prepare("
                INSERT INTO product_images (
                    product_id, image_url, is_primary, sort_order, created_at
                ) VALUES (?, ?, 1, 0, NOW())
            ");
            $stmt->execute([$product_id, $image_info['url']]);
        }
    }

    // Handle additional images
    if (isset($_FILES['additional_images'])) {
        $files = rearray_files($_FILES['additional_images']);
        foreach ($files as $index => $file) {
            if ($file['error'] === UPLOAD_ERR_OK) {
                $image_info = handle_product_image_upload($file);
                if ($image_info) {
                    $stmt = $pdo->prepare("
                        INSERT INTO product_images (
                            product_id, image_url, is_primary, sort_order, created_at
                        ) VALUES (?, ?, 0, ?, NOW())
                    ");
                    $stmt->execute([$product_id, $image_info['url'], $index + 1]);
                }
            }
        }
    }

    // Handle variants
    if (isset($_POST['variants']) && is_array($_POST['variants'])) {
        foreach ($_POST['variants'] as $variant) {
            if (empty($variant['name']) || !isset($variant['price']) || !isset($variant['stock_quantity'])) {
                continue;
            }

            // Create variant
            $stmt = $pdo->prepare("
                INSERT INTO product_variants (
                    product_id, name, sku, price, stock_quantity, created_at
                ) VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $product_id,
                sanitize_input($variant['name']),
                sanitize_input($variant['sku'] ?? ''),
                filter_var($variant['price'], FILTER_VALIDATE_FLOAT),
                filter_var($variant['stock_quantity'], FILTER_VALIDATE_INT)
            ]);
            $variant_id = $pdo->lastInsertId();

            // Create variant options
            if (isset($variant['options']) && is_array($variant['options'])) {
                foreach ($variant['options'] as $option) {
                    if (empty($option['name']) || empty($option['value'])) {
                        continue;
                    }

                    $stmt = $pdo->prepare("
                        INSERT INTO product_variant_options (
                            variant_id, name, value, created_at
                        ) VALUES (?, ?, ?, NOW())
                    ");
                    $stmt->execute([
                        $variant_id,
                        sanitize_input($option['name']),
                        sanitize_input($option['value'])
                    ]);
                }
            }
        }
    }

    // Commit transaction
    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Product created successfully',
        'product_id' => $product_id
    ]);

} catch (Exception $e) {
    // Rollback transaction on error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error creating product: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * Handle product image upload
 * @param array $file The uploaded file information
 * @return array|false The image information or false on failure
 */
function handle_product_image_upload($file) {
    // Validate file type
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
    if (!in_array($file['type'], $allowed_types)) {
        return false;
    }

    // Validate file size (2MB max)
    if ($file['size'] > 2 * 1024 * 1024) {
        return false;
    }

    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid() . '.' . $extension;

    // Create upload directory if it doesn't exist
    $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/products/' . date('Y/m');
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    $filepath = $upload_dir . '/' . $filename;
    $url = '/uploads/products/' . date('Y/m') . '/' . $filename;

    // Move uploaded file
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return [
            'path' => $filepath,
            'url' => $url
        ];
    }

    return false;
}

/**
 * Rearray files array for multiple file uploads
 * @param array $files The $_FILES array
 * @return array Rearranged files array
 */
function rearray_files($files) {
    $result = [];
    foreach ($files as $key => $all) {
        foreach ($all as $i => $val) {
            $result[$i][$key] = $val;
        }
    }
    return $result;
}
