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
    header('Location: /orders.php');
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
        header('Location: /orders.php');
        exit;
    }

    // Fetch order items grouped by seller
    $stmt = $pdo->prepare("
        SELECT oi.*, p.name as product_name, p.id as product_id,
               pi.image_url, s.store_name, s.id as seller_id,
               pv.name as variant_name
        FROM order_items oi
        JOIN products p ON oi.product_id = p.id
        LEFT JOIN product_images pi ON p.id = pi.product_id AND pi.is_primary = 1
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
                'items' => [],
                'subtotal' => 0
            ];
        }
        $sellers[$item['seller_id']]['items'][] = $item;
        $sellers[$item['seller_id']]['subtotal'] += $item['price'] * $item['quantity'];
    }

} catch (PDOException $e) {
    error_log("Error fetching order details: " . $e->getMessage());
    header('Location: /orders.php');
    exit;
}

require_once 'includes/header.php';
?>

<div class="bg-gray-50 min-h-screen">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Order Header -->
        <div class="flex justify-between items-start mb-8">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">
                    Order #<?php echo str_pad($order['id'], 8, '0', STR_PAD_LEFT); ?>
                </h1>
                <p class="mt-2 text-sm text-gray-600">
                    Placed on <?php echo date('F j, Y', strtotime($order['created_at'])); ?>
                </p>
            </div>
            <a href="/orders.php" class="text-blue-600 hover:text-blue-500">
                <i class="fas fa-arrow-left mr-2"></i>
                Back to Orders
            </a>
        </div>

        <div class="lg:grid lg:grid-cols-12 lg:gap-x-12">
            <!-- Main Content -->
            <div class="lg:col-span-8">
                <!-- Order Status -->
                <div class="bg-white shadow-sm rounded-lg overflow-hidden mb-8">
                    <div class="p-6">
                        <h2 class="text-lg font-medium text-gray-900 mb-4">Order Status</h2>
                        <div class="relative">
                            <!-- Status Timeline -->
                            <div class="absolute left-5 top-5 h-full w-0.5 bg-gray-200"></div>
                            
                            <div class="space-y-8">
                                <?php
                                $statuses = ['pending', 'processing', 'shipped', 'delivered'];
                                $current_status_index = array_search($order['status'], $statuses);
                                foreach ($statuses as $index => $status):
                                    $is_completed = $index <= $current_status_index;
                                    $is_current = $index === $current_status_index;
                                ?>
                                <div class="relative flex items-center">
                                    <div class="absolute left-0 w-10 h-10 flex items-center justify-center">
                                        <div class="w-4 h-4 rounded-full <?php echo $is_completed ? 'bg-blue-600' : 'bg-gray-200'; ?>"></div>
                                    </div>
                                    <div class="ml-12">
                                        <div class="flex items-center">
                                            <p class="text-sm font-medium <?php echo $is_current ? 'text-blue-600' : 'text-gray-900'; ?>">
                                                <?php echo ucfirst($status); ?>
                                            </p>
                                            <?php if ($is_current): ?>
                                            <span class="ml-2.5 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                Current Status
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($status === 'shipped' && $is_completed): ?>
                                        <p class="mt-1 text-sm text-gray-500">
                                            Tracking Number: <?php echo $order['tracking_number'] ?? 'Not available'; ?>
                                        </p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Order Items by Seller -->
                <?php foreach ($sellers as $seller): ?>
                <div class="bg-white shadow-sm rounded-lg overflow-hidden mb-8">
                    <div class="p-6">
                        <h2 class="text-lg font-medium text-gray-900 mb-4">
                            <?php echo htmlspecialchars($seller['name']); ?>
                        </h2>
                        
                        <div class="space-y-6">
                            <?php foreach ($seller['items'] as $item): ?>
                            <div class="flex">
                                <!-- Product Image -->
                                <div class="flex-shrink-0 w-24 h-24 border border-gray-200 rounded-lg overflow-hidden">
                                    <?php if ($item['image_url']): ?>
                                    <img src="<?php echo htmlspecialchars($item['image_url']); ?>" 
                                         alt="<?php echo htmlspecialchars($item['product_name']); ?>"
                                         class="w-full h-full object-center object-cover">
                                    <?php else: ?>
                                    <div class="w-full h-full bg-gray-200 flex items-center justify-center">
                                        <i class="fas fa-image text-gray-400 text-2xl"></i>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Product Details -->
                                <div class="ml-6 flex-1">
                                    <div class="flex justify-between">
                                        <div>
                                            <h3 class="text-sm font-medium text-gray-900">
                                                <a href="/product.php?id=<?php echo $item['product_id']; ?>">
                                                    <?php echo htmlspecialchars($item['product_name']); ?>
                                                </a>
                                            </h3>
                                            <?php if ($item['variant_name']): ?>
                                            <p class="mt-1 text-sm text-gray-500">
                                                Variant: <?php echo htmlspecialchars($item['variant_name']); ?>
                                            </p>
                                            <?php endif; ?>
                                            <p class="mt-1 text-sm text-gray-500">
                                                Quantity: <?php echo $item['quantity']; ?>
                                            </p>
                                        </div>
                                        <p class="text-sm font-medium text-gray-900">
                                            <?php echo format_price($item['price'] * $item['quantity']); ?>
                                        </p>
                                    </div>

                                    <?php if ($order['status'] === 'delivered'): ?>
                                    <div class="mt-4">
                                        <a href="/review.php?order_id=<?php echo $order_id; ?>&product_id=<?php echo $item['product_id']; ?>" 
                                           class="text-sm font-medium text-blue-600 hover:text-blue-500">
                                            Write a Review
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Seller Subtotal -->
                        <div class="mt-6 pt-6 border-t border-gray-200">
                            <div class="flex justify-between text-sm font-medium">
                                <span class="text-gray-600">Subtotal</span>
                                <span class="text-gray-900"><?php echo format_price($seller['subtotal']); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Order Summary -->
            <div class="lg:col-span-4">
                <div class="bg-white shadow-sm rounded-lg overflow-hidden sticky top-8">
                    <div class="p-6">
                        <h2 class="text-lg font-medium text-gray-900 mb-4">Order Summary</h2>
                        
                        <div class="space-y-4">
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-600">Subtotal</span>
                                <span class="text-gray-900"><?php echo format_price($order['total_amount']); ?></span>
                            </div>
                            
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-600">Shipping</span>
                                <span class="text-gray-900">Free</span>
                            </div>
                            
                            <div class="pt-4 border-t border-gray-200">
                                <div class="flex justify-between text-base font-medium">
                                    <span class="text-gray-900">Total</span>
                                    <span class="text-gray-900"><?php echo format_price($order['total_amount']); ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Payment Information -->
                        <div class="mt-6 pt-6 border-t border-gray-200">
                            <h3 class="text-sm font-medium text-gray-900 mb-2">Payment Information</h3>
                            <div class="text-sm text-gray-600">
                                <p>Method: <?php echo ucfirst(str_replace('_', ' ', $order['payment_method'])); ?></p>
                                <p class="mt-1">
                                    Status: 
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                        <?php echo $order['payment_status'] === 'paid' ? 'bg-green-100 text-green-800' : 
                                                 ($order['payment_status'] === 'pending' ? 'bg-yellow-100 text-yellow-800' : 
                                                 'bg-red-100 text-red-800'); ?>">
                                        <?php echo ucfirst($order['payment_status']); ?>
                                    </span>
                                </p>
                            </div>
                        </div>

                        <!-- Shipping Information -->
                        <div class="mt-6 pt-6 border-t border-gray-200">
                            <h3 class="text-sm font-medium text-gray-900 mb-2">Shipping Information</h3>
                            <div class="text-sm text-gray-600">
                                <p><?php echo htmlspecialchars($order['customer_name']); ?></p>
                                <p><?php echo nl2br(htmlspecialchars($order['shipping_address'])); ?></p>
                                <p><?php echo htmlspecialchars($order['shipping_city']); ?>, <?php echo htmlspecialchars($order['shipping_postal_code']); ?></p>
                                <p>Phone: <?php echo htmlspecialchars($order['shipping_phone']); ?></p>
                            </div>
                        </div>

                        <?php if ($order['status'] === 'pending' && $order['payment_status'] === 'pending'): ?>
                        <!-- Cancel Order Button -->
                        <div class="mt-6">
                            <button onclick="cancelOrder(<?php echo $order_id; ?>)"
                                    class="w-full flex justify-center items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                                Cancel Order
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function cancelOrder(orderId) {
    if (!confirm('Are you sure you want to cancel this order?')) {
        return;
    }

    fetch('/api/orders/cancel.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            order_id: orderId
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert(data.message || 'Error cancelling order');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error cancelling order');
    });
}
</script>

<?php require_once 'includes/footer.php'; ?>
