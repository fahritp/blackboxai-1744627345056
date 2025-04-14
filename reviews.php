<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';

// Get product ID from URL
$product_id = filter_input(INPUT_GET, 'product_id', FILTER_VALIDATE_INT);
$rating_filter = filter_input(INPUT_GET, 'rating', FILTER_VALIDATE_INT);
$sort = filter_input(INPUT_GET, 'sort', FILTER_SANITIZE_STRING) ?? 'newest';
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?? 1;
$per_page = 10;

if (!$product_id) {
    header('Location: /products.php');
    exit;
}

try {
    // Get product details
    $stmt = $pdo->prepare("
        SELECT p.*, s.store_name, pi.image_url,
               COUNT(DISTINCT pr.id) as review_count,
               AVG(pr.rating) as avg_rating
        FROM products p
        JOIN sellers s ON p.seller_id = s.id
        LEFT JOIN product_images pi ON p.id = pi.product_id AND pi.is_primary = 1
        LEFT JOIN product_reviews pr ON p.id = pr.product_id AND pr.status = 'approved'
        WHERE p.id = ?
        GROUP BY p.id
    ");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch();

    if (!$product) {
        header('Location: /products.php');
        exit;
    }

    // Get rating distribution
    $stmt = $pdo->prepare("
        SELECT rating, COUNT(*) as count
        FROM product_reviews
        WHERE product_id = ? AND status = 'approved'
        GROUP BY rating
        ORDER BY rating DESC
    ");
    $stmt->execute([$product_id]);
    $rating_distribution = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Build reviews query
    $query = "
        SELECT pr.*, u.name as reviewer_name
        FROM product_reviews pr
        JOIN users u ON pr.user_id = u.id
        WHERE pr.product_id = ? AND pr.status = 'approved'
    ";
    $params = [$product_id];

    if ($rating_filter) {
        $query .= " AND pr.rating = ?";
        $params[] = $rating_filter;
    }

    // Add sorting
    switch ($sort) {
        case 'highest':
            $query .= " ORDER BY pr.rating DESC, pr.created_at DESC";
            break;
        case 'lowest':
            $query .= " ORDER BY pr.rating ASC, pr.created_at DESC";
            break;
        default: // newest
            $query .= " ORDER BY pr.created_at DESC";
    }

    // Get total count for pagination
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM ({$query}) as subquery");
    $count_stmt->execute($params);
    $total_count = $count_stmt->fetchColumn();
    $total_pages = ceil($total_count / $per_page);

    // Adjust page number if out of bounds
    if ($page < 1) $page = 1;
    if ($page > $total_pages) $page = $total_pages;

    // Add pagination
    $query .= " LIMIT ? OFFSET ?";
    $params[] = $per_page;
    $params[] = ($page - 1) * $per_page;

    // Get reviews
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $reviews = $stmt->fetchAll();

} catch (PDOException $e) {
    error_log("Error fetching reviews: " . $e->getMessage());
    header('Location: /products.php');
    exit;
}

require_once 'includes/header.php';
?>

<div class="bg-gray-50 min-h-screen">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Product Information -->
        <div class="bg-white shadow-sm rounded-lg overflow-hidden mb-8">
            <div class="p-6">
                <div class="flex items-center">
                    <div class="flex-shrink-0 w-24 h-24 border border-gray-200 rounded-lg overflow-hidden">
                        <?php if ($product['image_url']): ?>
                        <img src="<?php echo htmlspecialchars($product['image_url']); ?>" 
                             alt="<?php echo htmlspecialchars($product['name']); ?>"
                             class="w-full h-full object-center object-cover">
                        <?php else: ?>
                        <div class="w-full h-full bg-gray-200 flex items-center justify-center">
                            <i class="fas fa-image text-gray-400 text-2xl"></i>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="ml-6">
                        <h1 class="text-2xl font-bold text-gray-900">
                            <?php echo htmlspecialchars($product['name']); ?>
                        </h1>
                        <p class="mt-1 text-sm text-gray-500">
                            Sold by <?php echo htmlspecialchars($product['store_name']); ?>
                        </p>
                        <div class="mt-2 flex items-center">
                            <div class="flex items-center">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                <i class="<?php echo $i <= round($product['avg_rating']) ? 'fas' : 'far'; ?> fa-star text-yellow-400"></i>
                                <?php endfor; ?>
                            </div>
                            <span class="ml-2 text-sm text-gray-600">
                                <?php echo number_format($product['avg_rating'], 1); ?> out of 5
                                (<?php echo $product['review_count']; ?> reviews)
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="lg:grid lg:grid-cols-12 lg:gap-x-8">
            <!-- Rating Distribution -->
            <div class="lg:col-span-4">
                <div class="bg-white shadow-sm rounded-lg overflow-hidden p-6">
                    <h2 class="text-lg font-medium text-gray-900 mb-4">Rating Distribution</h2>
                    
                    <div class="space-y-3">
                        <?php for ($i = 5; $i >= 1; $i--): 
                            $count = $rating_distribution[$i] ?? 0;
                            $percentage = $product['review_count'] ? ($count / $product['review_count'] * 100) : 0;
                        ?>
                        <div class="flex items-center">
                            <a href="?product_id=<?php echo $product_id; ?>&rating=<?php echo $i; ?>" 
                               class="w-24 flex items-center text-sm <?php echo $rating_filter === $i ? 'text-blue-600' : 'text-gray-600'; ?>">
                                <span class="w-3"><?php echo $i; ?></span>
                                <i class="fas fa-star text-yellow-400 ml-1"></i>
                            </a>
                            <div class="flex-1 ml-4">
                                <div class="h-2 rounded-full bg-gray-200 overflow-hidden">
                                    <div class="h-full bg-yellow-400 rounded-full" 
                                         style="width: <?php echo $percentage; ?>%"></div>
                                </div>
                            </div>
                            <span class="ml-4 text-sm text-gray-500 w-16">
                                <?php echo $count; ?> (<?php echo round($percentage); ?>%)
                            </span>
                        </div>
                        <?php endfor; ?>
                    </div>

                    <?php if ($rating_filter): ?>
                    <div class="mt-6">
                        <a href="?product_id=<?php echo $product_id; ?>" 
                           class="text-sm text-blue-600 hover:text-blue-500">
                            Clear filter
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Reviews List -->
            <div class="mt-8 lg:mt-0 lg:col-span-8">
                <!-- Sort Options -->
                <div class="flex justify-end mb-4">
                    <select onchange="window.location.href='?product_id=<?php echo $product_id; ?>&sort=' + this.value + '<?php echo $rating_filter ? '&rating=' . $rating_filter : ''; ?>'"
                            class="rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                        <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest First</option>
                        <option value="highest" <?php echo $sort === 'highest' ? 'selected' : ''; ?>>Highest Rated</option>
                        <option value="lowest" <?php echo $sort === 'lowest' ? 'selected' : ''; ?>>Lowest Rated</option>
                    </select>
                </div>

                <?php if (empty($reviews)): ?>
                <div class="bg-white shadow-sm rounded-lg p-6 text-center">
                    <p class="text-gray-500">No reviews found.</p>
                </div>
                <?php else: ?>
                <div class="space-y-6">
                    <?php foreach ($reviews as $review): ?>
                    <div class="bg-white shadow-sm rounded-lg overflow-hidden">
                        <div class="p-6">
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="flex items-center">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i class="<?php echo $i <= $review['rating'] ? 'fas' : 'far'; ?> fa-star text-yellow-400"></i>
                                        <?php endfor; ?>
                                    </div>
                                    <p class="mt-1 text-sm font-medium text-gray-900">
                                        <?php echo htmlspecialchars($review['reviewer_name']); ?>
                                    </p>
                                </div>
                                <p class="text-sm text-gray-500">
                                    <?php echo date('F j, Y', strtotime($review['created_at'])); ?>
                                </p>
                            </div>
                            <div class="mt-4 text-sm text-gray-700 prose">
                                <?php echo nl2br(htmlspecialchars($review['review'])); ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="mt-8">
                    <nav class="flex justify-center">
                        <ul class="flex items-center space-x-2">
                            <?php if ($page > 1): ?>
                            <li>
                                <a href="?product_id=<?php echo $product_id; ?>&page=<?php echo $page - 1; ?><?php echo $rating_filter ? '&rating=' . $rating_filter : ''; ?><?php echo $sort !== 'newest' ? '&sort=' . $sort : ''; ?>"
                                   class="px-3 py-2 rounded-md bg-white text-gray-500 hover:bg-gray-50">
                                    Previous
                                </a>
                            </li>
                            <?php endif; ?>
                            
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li>
                                <a href="?product_id=<?php echo $product_id; ?>&page=<?php echo $i; ?><?php echo $rating_filter ? '&rating=' . $rating_filter : ''; ?><?php echo $sort !== 'newest' ? '&sort=' . $sort : ''; ?>"
                                   class="px-3 py-2 rounded-md <?php echo $i === $page ? 'bg-blue-600 text-white' : 'bg-white text-gray-500 hover:bg-gray-50'; ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                            <?php endfor; ?>
                            
                            <?php if ($page < $total_pages): ?>
                            <li>
                                <a href="?product_id=<?php echo $product_id; ?>&page=<?php echo $page + 1; ?><?php echo $rating_filter ? '&rating=' . $rating_filter : ''; ?><?php echo $sort !== 'newest' ? '&sort=' . $sort : ''; ?>"
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
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
