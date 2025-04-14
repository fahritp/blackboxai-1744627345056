<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';

// Check if user is logged in
if (!is_logged_in()) {
    header('Location: /login.php');
    exit;
}

// Get filter parameters
$status = filter_input(INPUT_GET, 'status', FILTER_SANITIZE_STRING);
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?? 1;
$per_page = 10;

try {
    // Build query
    $query = "
        SELECT o.*, COUNT(oi.id) as total_items
        FROM orders o
        LEFT JOIN order_items oi ON o.id = oi.order_id
        WHERE o.user_id = ?
    ";
    $params = [$_SESSION['user_id']];

    if ($status && in_array($status, ['pending', 'processing', 'shipped', 'delivered', 'cancelled'])) {
        $query .= " AND o.status = ?";
        $params[] = $status;
    }

    $query .= " GROUP BY o.id ORDER BY o.created_at DESC";

    // Get total count for pagination
    $count_query = "SELECT COUNT(*) as count FROM ({$query}) as subquery";
    $stmt = $pdo->prepare($count_query);
    $stmt->execute($params);
    $total_count = $stmt->fetch()['count'];
    $total_pages = ceil($total_count / $per_page);

    // Adjust page number if out of bounds
    if ($page < 1) $page = 1;
    if ($page > $total_pages) $page = $total_pages;

    // Add pagination
    $query .= " LIMIT ? OFFSET ?";
    $params[] = $per_page;
    $params[] = ($page - 1) * $per_page;

    // Get orders
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $orders = $stmt->fetchAll();

} catch (PDOException $e) {
    error_log("Error fetching orders: " . $e->getMessage());
    $orders = [];
    $total_pages = 0;
}

require_once 'includes/header.php';
?>

<div class="bg-gray-50 min-h-screen">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-2xl font-bold text-gray-900">My Orders</h1>
            
            <!-- Status Filter -->
            <div class="flex items-center space-x-4">
                <label for="status-filter" class="text-sm font-medium text-gray-700">Filter by status:</label>
                <select id="status-filter" 
                        onchange="window.location.href='?status=' + this.value"
                        class="rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    <option value="">All Orders</option>
                    <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="processing" <?php echo $status === 'processing' ? 'selected' : ''; ?>>Processing</option>
                    <option value="shipped" <?php echo $status === 'shipped' ? 'selected' : ''; ?>>Shipped</option>
                    <option value="delivered" <?php echo $status === 'delivered' ? 'selected' : ''; ?>>Delivered</option>
                    <option value="cancelled" <?php echo $status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                </select>
            </div>
        </div>

        <?php if (empty($orders)): ?>
        <!-- No Orders -->
        <div class="text-center py-12">
            <i class="fas fa-shopping-bag text-6xl text-gray-300 mb-4"></i>
            <h2 class="text-xl font-medium text-gray-900 mb-2">No orders found</h2>
            <p class="text-gray-500 mb-6">You haven't placed any orders yet.</p>
            <a href="/products.php" 
               class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700">
                Start Shopping
            </a>
        </div>
        <?php else: ?>
        <!-- Orders List -->
        <div class="bg-white shadow-sm rounded-lg overflow-hidden">
            <ul class="divide-y divide-gray-200">
                <?php foreach ($orders as $order): ?>
                <li>
                    <div class="p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <h3 class="text-lg font-medium text-gray-900">
                                    Order #<?php echo str_pad($order['id'], 8, '0', STR_PAD_LEFT); ?>
                                </h3>
                                <p class="mt-1 text-sm text-gray-500">
                                    Placed on <?php echo date('F j, Y', strtotime($order['created_at'])); ?>
                                </p>
                            </div>
                            <div class="text-right">
                                <p class="text-lg font-medium text-gray-900">
                                    <?php echo format_price($order['total_amount']); ?>
                                </p>
                                <p class="mt-1">
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

                        <div class="mt-4">
                            <div class="text-sm text-gray-500">
                                <?php echo $order['total_items']; ?> items
                            </div>
                            
                            <!-- Payment Status -->
                            <?php if ($order['payment_method'] === 'bank_transfer'): ?>
                            <div class="mt-2">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                    <?php echo $order['payment_status'] === 'paid' ? 'bg-green-100 text-green-800' : 
                                             ($order['payment_status'] === 'pending' ? 'bg-yellow-100 text-yellow-800' : 
                                             'bg-red-100 text-red-800'); ?>">
                                    Payment: <?php echo ucfirst($order['payment_status']); ?>
                                </span>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="mt-6 flex items-center justify-between">
                            <div class="flex space-x-4">
                                <a href="/order-detail.php?id=<?php echo $order['id']; ?>" 
                                   class="text-sm font-medium text-blue-600 hover:text-blue-500">
                                    View Details
                                </a>
                                <?php if ($order['status'] === 'delivered'): ?>
                                <a href="/review.php?order_id=<?php echo $order['id']; ?>" 
                                   class="text-sm font-medium text-blue-600 hover:text-blue-500">
                                    Write Review
                                </a>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($order['status'] === 'pending' && $order['payment_status'] === 'pending'): ?>
                            <button onclick="cancelOrder(<?php echo $order['id']; ?>)"
                                    class="text-sm font-medium text-red-600 hover:text-red-500">
                                Cancel Order
                            </button>
                            <?php endif; ?>
                        </div>

                        <!-- Shipping Address -->
                        <div class="mt-4 text-sm text-gray-500">
                            <p class="font-medium text-gray-700">Shipping Address:</p>
                            <p><?php echo nl2br(htmlspecialchars($order['shipping_address'])); ?></p>
                            <p><?php echo htmlspecialchars($order['shipping_city']); ?>, <?php echo htmlspecialchars($order['shipping_postal_code']); ?></p>
                            <p>Phone: <?php echo htmlspecialchars($order['shipping_phone']); ?></p>
                        </div>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="mt-8">
            <nav class="flex justify-center">
                <ul class="flex items-center space-x-2">
                    <?php if ($page > 1): ?>
                    <li>
                        <a href="?page=<?php echo $page - 1; ?><?php echo $status ? '&status=' . $status : ''; ?>"
                           class="px-3 py-2 rounded-md bg-white text-gray-500 hover:bg-gray-50">
                            Previous
                        </a>
                    </li>
                    <?php endif; ?>
                    
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <li>
                        <a href="?page=<?php echo $i; ?><?php echo $status ? '&status=' . $status : ''; ?>"
                           class="px-3 py-2 rounded-md <?php echo $i === $page ? 'bg-blue-600 text-white' : 'bg-white text-gray-500 hover:bg-gray-50'; ?>">
                            <?php echo $i; ?>
                        </a>
                    </li>
                    <?php endfor; ?>
                    
                    <?php if ($page < $total_pages): ?>
                    <li>
                        <a href="?page=<?php echo $page + 1; ?><?php echo $status ? '&status=' . $status : ''; ?>"
                           class="px-3 py-2 rounded-md bg-white text-gray-500 hover:bg-gray-50">
                            Next
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
        <?php endif; ?>
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
