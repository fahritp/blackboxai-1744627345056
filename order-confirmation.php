<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';

// Check if user is logged in
if (!is_logged_in()) {
    header('Location: /login.php');
    exit;
}

// Get order ID from URL
$order_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$order_id) {
    header('Location: /');
    exit;
}

try {
    // Fetch order details
    $stmt = $pdo->prepare("
        SELECT o.*, u.name as customer_name, u.email as customer_email
        FROM orders o
        JOIN users u ON o.user_id = u.id
        WHERE o.id = ? AND o.user_id = ?
    ");
    $stmt->execute([$order_id, $_SESSION['user_id']]);
    $order = $stmt->fetch();

    if (!$order) {
        header('Location: /');
        exit;
    }

    // Fetch order items grouped by seller
    $stmt = $pdo->prepare("
        SELECT oi.*, p.name as product_name, p.id as product_id,
               s.store_name, s.id as seller_id,
               pv.name as variant_name
        FROM order_items oi
        JOIN products p ON oi.product_id = p.id
        JOIN sellers s ON oi.seller_id = s.id
        LEFT JOIN product_variants pv ON oi.variant_id = pv.id
        WHERE oi.order_id = ?
        ORDER BY s.id
    ");
    $stmt->execute([$order_id]);
    $items = $stmt->fetchAll();

    // Group items by seller
    $sellers = [];
    foreach ($items as $item) {
        if (!isset($sellers[$item['seller_id']])) {
            $sellers[$item['seller_id']] = [
                'name' => $item['store_name'],
                'items' => []
            ];
        }
        $sellers[$item['seller_id']]['items'][] = $item;
    }

} catch (PDOException $e) {
    error_log("Error fetching order details: " . $e->getMessage());
    header('Location: /');
    exit;
}

require_once 'includes/header.php';
?>

<div class="bg-gray-50 min-h-screen">
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <!-- Success Message -->
        <div class="text-center mb-12">
            <div class="rounded-full bg-green-100 h-20 w-20 flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-check-circle text-4xl text-green-600"></i>
            </div>
            <h1 class="text-3xl font-extrabold text-gray-900">Thank you for your order!</h1>
            <p class="mt-2 text-lg text-gray-600">
                Your order has been placed successfully.
            </p>
        </div>

        <!-- Order Details -->
        <div class="bg-white shadow-sm rounded-lg overflow-hidden">
            <!-- Order Header -->
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex justify-between items-center">
                    <div>
                        <h2 class="text-lg font-medium text-gray-900">
                            Order #<?php echo str_pad($order['id'], 8, '0', STR_PAD_LEFT); ?>
                        </h2>
                        <p class="mt-1 text-sm text-gray-600">
                            Placed on <?php echo date('F j, Y', strtotime($order['created_at'])); ?>
                        </p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm font-medium text-gray-900">
                            Total Amount:
                            <span class="text-lg ml-1">
                                <?php echo format_price($order['total_amount']); ?>
                            </span>
                        </p>
                        <p class="mt-1 text-sm text-gray-600">
                            Status: 
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium capitalize
                                <?php echo $order['status'] === 'pending' ? 'bg-yellow-100 text-yellow-800' : 
                                         ($order['status'] === 'processing' ? 'bg-blue-100 text-blue-800' : 
                                         ($order['status'] === 'shipped' ? 'bg-purple-100 text-purple-800' : 
                                         ($order['status'] === 'delivered' ? 'bg-green-100 text-green-800' : 
                                         'bg-red-100 text-red-800'))); ?>">
                                <?php echo $order['status']; ?>
                            </span>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Payment Instructions -->
            <?php if ($order['payment_method'] === 'bank_transfer' && $order['payment_status'] === 'pending'): ?>
            <div class="px-6 py-4 bg-blue-50 border-b border-gray-200">
                <h3 class="text-sm font-medium text-blue-900">Payment Instructions</h3>
                <p class="mt-2 text-sm text-blue-700">
                    Please transfer the total amount to our bank account:
                </p>
                <div class="mt-3 bg-white rounded-md p-4 border border-blue-200">
                    <p class="text-sm text-gray-600">Bank: <span class="font-medium text-gray-900">Bank Central Asia (BCA)</span></p>
                    <p class="text-sm text-gray-600">Account Number: <span class="font-medium text-gray-900">1234567890</span></p>
                    <p class="text-sm text-gray-600">Account Name: <span class="font-medium text-gray-900">PT Takasimura</span></p>
                    <p class="mt-2 text-sm text-gray-600">
                        Please include your Order ID (<?php echo str_pad($order['id'], 8, '0', STR_PAD_LEFT); ?>) in the transfer description.
                    </p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Shipping Information -->
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-sm font-medium text-gray-900">Shipping Information</h3>
                <div class="mt-2 text-sm text-gray-600">
                    <p class="font-medium text-gray-900"><?php echo htmlspecialchars($order['customer_name']); ?></p>
                    <p><?php echo nl2br(htmlspecialchars($order['shipping_address'])); ?></p>
                    <p><?php echo htmlspecialchars($order['shipping_city']); ?>, <?php echo htmlspecialchars($order['shipping_postal_code']); ?></p>
                    <p>Phone: <?php echo htmlspecialchars($order['shipping_phone']); ?></p>
                </div>
            </div>

            <!-- Order Items -->
            <?php foreach ($sellers as $seller): ?>
            <div class="px-6 py-4 <?php echo $seller['seller_id'] !== array_key_last($sellers) ? 'border-b border-gray-200' : ''; ?>">
                <h3 class="text-sm font-medium text-gray-900 mb-4">
                    <?php echo htmlspecialchars($seller['name']); ?>
                </h3>
                
                <div class="space-y-4">
                    <?php foreach ($seller['items'] as $item): ?>
                    <div class="flex items-start">
                        <div class="flex-1">
                            <h4 class="text-sm font-medium text-gray-900">
                                <?php echo htmlspecialchars($item['product_name']); ?>
                            </h4>
                            <?php if ($item['variant_name']): ?>
                            <p class="mt-1 text-sm text-gray-500">
                                Variant: <?php echo htmlspecialchars($item['variant_name']); ?>
                            </p>
                            <?php endif; ?>
                            <p class="mt-1 text-sm text-gray-500">
                                Quantity: <?php echo $item['quantity']; ?>
                            </p>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-medium text-gray-900">
                                <?php echo format_price($item['price'] * $item['quantity']); ?>
                            </p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Actions -->
        <div class="mt-8 flex justify-center space-x-4">
            <a href="/orders.php" 
               class="inline-flex items-center px-4 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                View All Orders
            </a>
            <a href="/products.php" 
               class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                Continue Shopping
            </a>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
