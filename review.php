<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';

// Check if user is logged in
if (!is_logged_in()) {
    header('Location: /login.php');
    exit;
}

// Get parameters from URL
$order_id = filter_input(INPUT_GET, 'order_id', FILTER_VALIDATE_INT);
$product_id = filter_input(INPUT_GET, 'product_id', FILTER_VALIDATE_INT);

if (!$order_id || !$product_id) {
    header('Location: /orders.php');
    exit;
}

try {
    // Verify that the order belongs to the user and is delivered
    $stmt = $pdo->prepare("
        SELECT o.*, oi.product_id, p.name as product_name, p.id as product_id,
               pi.image_url, s.store_name
        FROM orders o
        JOIN order_items oi ON o.id = oi.order_id
        JOIN products p ON oi.product_id = p.id
        LEFT JOIN product_images pi ON p.id = pi.product_id AND pi.is_primary = 1
        JOIN sellers s ON p.seller_id = s.id
        WHERE o.id = ? AND o.user_id = ? AND p.id = ? AND o.status = 'delivered'
    ");
    $stmt->execute([$order_id, $_SESSION['user_id'], $product_id]);
    $order_product = $stmt->fetch();

    if (!$order_product) {
        set_flash_message('error', 'You can only review products from delivered orders.');
        header('Location: /orders.php');
        exit;
    }

    // Check if review already exists
    $stmt = $pdo->prepare("
        SELECT * FROM product_reviews 
        WHERE order_id = ? AND product_id = ? AND user_id = ?
    ");
    $stmt->execute([$order_id, $product_id, $_SESSION['user_id']]);
    $existing_review = $stmt->fetch();

} catch (PDOException $e) {
    error_log("Error in review page: " . $e->getMessage());
    header('Location: /orders.php');
    exit;
}

require_once 'includes/header.php';
?>

<div class="bg-gray-50 min-h-screen">
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="mb-8">
            <a href="/order-detail.php?id=<?php echo $order_id; ?>" class="text-blue-600 hover:text-blue-500">
                <i class="fas fa-arrow-left mr-2"></i>
                Back to Order
            </a>
        </div>

        <div class="bg-white shadow-sm rounded-lg overflow-hidden">
            <!-- Product Information -->
            <div class="p-6 border-b border-gray-200">
                <div class="flex items-center">
                    <div class="flex-shrink-0 w-24 h-24 border border-gray-200 rounded-lg overflow-hidden">
                        <?php if ($order_product['image_url']): ?>
                        <img src="<?php echo htmlspecialchars($order_product['image_url']); ?>" 
                             alt="<?php echo htmlspecialchars($order_product['product_name']); ?>"
                             class="w-full h-full object-center object-cover">
                        <?php else: ?>
                        <div class="w-full h-full bg-gray-200 flex items-center justify-center">
                            <i class="fas fa-image text-gray-400 text-2xl"></i>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="ml-6">
                        <h2 class="text-lg font-medium text-gray-900">
                            <?php echo htmlspecialchars($order_product['product_name']); ?>
                        </h2>
                        <p class="mt-1 text-sm text-gray-500">
                            Sold by <?php echo htmlspecialchars($order_product['store_name']); ?>
                        </p>
                    </div>
                </div>
            </div>

            <?php if ($existing_review): ?>
            <!-- Existing Review -->
            <div class="p-6">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Your Review</h3>
                
                <div class="space-y-4">
                    <div class="flex items-center">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                        <i class="<?php echo $i <= $existing_review['rating'] ? 'fas' : 'far'; ?> fa-star text-yellow-400"></i>
                        <?php endfor; ?>
                        <span class="ml-2 text-sm text-gray-600">
                            Posted on <?php echo date('F j, Y', strtotime($existing_review['created_at'])); ?>
                        </span>
                    </div>
                    
                    <p class="text-gray-700">
                        <?php echo nl2br(htmlspecialchars($existing_review['review'])); ?>
                    </p>

                    <?php if ($existing_review['status'] === 'pending'): ?>
                    <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <i class="fas fa-exclamation-triangle text-yellow-400"></i>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm text-yellow-700">
                                    Your review is pending approval by our moderators.
                                </p>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php else: ?>
            <!-- Review Form -->
            <div class="p-6">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Write a Review</h3>
                
                <form action="/api/reviews/create.php" method="POST" class="space-y-6">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="order_id" value="<?php echo $order_id; ?>">
                    <input type="hidden" name="product_id" value="<?php echo $product_id; ?>">

                    <!-- Rating -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Rating</label>
                        <div class="flex items-center space-x-1">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                            <button type="button" 
                                    onclick="setRating(<?php echo $i; ?>)"
                                    class="text-2xl text-gray-400 hover:text-yellow-400 focus:outline-none"
                                    id="star-<?php echo $i; ?>">
                                <i class="far fa-star"></i>
                            </button>
                            <?php endfor; ?>
                        </div>
                        <input type="hidden" name="rating" id="rating-input" required>
                    </div>

                    <!-- Review Text -->
                    <div>
                        <label for="review" class="block text-sm font-medium text-gray-700 mb-2">
                            Your Review
                        </label>
                        <textarea id="review" 
                                  name="review" 
                                  rows="4" 
                                  required
                                  placeholder="Share your experience with this product..."
                                  class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm"></textarea>
                        <p class="mt-2 text-sm text-gray-500">
                            Your review will be visible to other customers after approval.
                        </p>
                    </div>

                    <!-- Submit Button -->
                    <div class="flex justify-end">
                        <button type="submit"
                                class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                            Submit Review
                        </button>
                    </div>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function setRating(rating) {
    document.getElementById('rating-input').value = rating;
    
    // Update star icons
    for (let i = 1; i <= 5; i++) {
        const star = document.getElementById(`star-${i}`);
        if (i <= rating) {
            star.innerHTML = '<i class="fas fa-star"></i>';
            star.classList.add('text-yellow-400');
            star.classList.remove('text-gray-400');
        } else {
            star.innerHTML = '<i class="far fa-star"></i>';
            star.classList.remove('text-yellow-400');
            star.classList.add('text-gray-400');
        }
    }
}
</script>

<?php require_once 'includes/footer.php'; ?>
