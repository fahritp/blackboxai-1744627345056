<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';

// Get search parameters
$query = filter_input(INPUT_GET, 'q', FILTER_SANITIZE_STRING);
$category_id = filter_input(INPUT_GET, 'category', FILTER_VALIDATE_INT);
$min_price = filter_input(INPUT_GET, 'min_price', FILTER_VALIDATE_FLOAT);
$max_price = filter_input(INPUT_GET, 'max_price', FILTER_VALIDATE_FLOAT);
$min_rating = filter_input(INPUT_GET, 'min_rating', FILTER_VALIDATE_INT);
$sort = filter_input(INPUT_GET, 'sort', FILTER_SANITIZE_STRING) ?? 'relevance';
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?? 1;
$per_page = 24;

try {
    // Get all categories for filter
    $categories = $pdo->query("
        SELECT c.*, COUNT(p.id) as product_count
        FROM product_categories c
        LEFT JOIN products p ON c.id = p.category_id AND p.status = 'active'
        WHERE c.status = 'active'
        GROUP BY c.id
        ORDER BY c.name
    ")->fetchAll();

    // Build search query
    $sql = "
        SELECT p.*, pi.image_url, s.store_name, pc.name as category_name,
               COUNT(DISTINCT pr.id) as review_count,
               COALESCE(AVG(pr.rating), 0) as avg_rating
        FROM products p
        LEFT JOIN product_images pi ON p.id = pi.product_id AND pi.is_primary = 1
        JOIN sellers s ON p.seller_id = s.id
        JOIN product_categories pc ON p.category_id = pc.id
        LEFT JOIN product_reviews pr ON p.id = pr.product_id AND pr.status = 'approved'
        WHERE p.status = 'active'
    ";
    $params = [];

    // Add search conditions
    if ($query) {
        $sql .= " AND (
            p.name LIKE ? OR 
            p.description LIKE ? OR
            p.short_description LIKE ?
        )";
        $search_term = "%{$query}%";
        $params = array_merge($params, [$search_term, $search_term, $search_term]);
    }

    if ($category_id) {
        $sql .= " AND p.category_id = ?";
        $params[] = $category_id;
    }

    if ($min_price) {
        $sql .= " AND p.price >= ?";
        $params[] = $min_price;
    }

    if ($max_price) {
        $sql .= " AND p.price <= ?";
        $params[] = $max_price;
    }

    $sql .= " GROUP BY p.id";

    if ($min_rating) {
        $sql .= " HAVING avg_rating >= ?";
        $params[] = $min_rating;
    }

    // Add sorting
    switch ($sort) {
        case 'price_low':
            $sql .= " ORDER BY p.price ASC";
            break;
        case 'price_high':
            $sql .= " ORDER BY p.price DESC";
            break;
        case 'rating':
            $sql .= " ORDER BY avg_rating DESC, review_count DESC";
            break;
        case 'newest':
            $sql .= " ORDER BY p.created_at DESC";
            break;
        default: // relevance
            if ($query) {
                $sql .= " ORDER BY 
                    CASE 
                        WHEN p.name LIKE ? THEN 3
                        WHEN p.short_description LIKE ? THEN 2
                        WHEN p.description LIKE ? THEN 1
                    END DESC,
                    p.rating DESC";
                $search_term = "%{$query}%";
                $params = array_merge($params, [$search_term, $search_term, $search_term]);
            } else {
                $sql .= " ORDER BY p.rating DESC, p.created_at DESC";
            }
    }

    // Get total count for pagination
    $count_sql = preg_replace('/SELECT .+ FROM/', 'SELECT COUNT(DISTINCT p.id) FROM', $sql);
    $count_sql = preg_replace('/ORDER BY .+$/', '', $count_sql);
    $stmt = $pdo->prepare($count_sql);
    $stmt->execute($params);
    $total_count = $stmt->fetchColumn();
    $total_pages = ceil($total_count / $per_page);

    // Adjust page number if out of bounds
    if ($page < 1) $page = 1;
    if ($page > $total_pages) $page = $total_pages;

    // Add pagination
    $sql .= " LIMIT ? OFFSET ?";
    $params[] = $per_page;
    $params[] = ($page - 1) * $per_page;

    // Get products
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll();

    // Get price range for filter
    $price_range = $pdo->query("
        SELECT MIN(price) as min_price, MAX(price) as max_price
        FROM products
        WHERE status = 'active'
    ")->fetch();

} catch (PDOException $e) {
    error_log("Error in search: " . $e->getMessage());
    $products = [];
    $total_pages = 0;
    $price_range = ['min_price' => 0, 'max_price' => 0];
}

require_once 'includes/header.php';
?>

<div class="bg-gray-50">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Search Form -->
        <form action="/search.php" method="GET" class="mb-8">
            <div class="flex gap-4">
                <div class="flex-1">
                    <input type="text" 
                           name="q" 
                           value="<?php echo htmlspecialchars($query ?? ''); ?>"
                           placeholder="Search products..."
                           class="block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                </div>
                <button type="submit"
                        class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    <i class="fas fa-search mr-2"></i>
                    Search
                </button>
            </div>
        </form>

        <div class="lg:grid lg:grid-cols-12 lg:gap-x-8">
            <!-- Filters -->
            <div class="lg:col-span-3">
                <div class="bg-white shadow-sm rounded-lg p-6">
                    <h2 class="text-lg font-medium text-gray-900 mb-4">Filters</h2>
                    
                    <form action="/search.php" method="GET" class="space-y-6">
                        <!-- Preserve search query -->
                        <?php if ($query): ?>
                        <input type="hidden" name="q" value="<?php echo htmlspecialchars($query); ?>">
                        <?php endif; ?>

                        <!-- Categories -->
                        <div>
                            <label class="text-sm font-medium text-gray-700">Categories</label>
                            <div class="mt-2 space-y-2">
                                <?php foreach ($categories as $cat): ?>
                                <div class="flex items-center">
                                    <input type="radio" 
                                           id="category-<?php echo $cat['id']; ?>" 
                                           name="category" 
                                           value="<?php echo $cat['id']; ?>"
                                           <?php echo $category_id == $cat['id'] ? 'checked' : ''; ?>
                                           class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300">
                                    <label for="category-<?php echo $cat['id']; ?>" 
                                           class="ml-2 text-sm text-gray-700">
                                        <?php echo htmlspecialchars($cat['name']); ?>
                                        <span class="text-gray-500">(<?php echo $cat['product_count']; ?>)</span>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Price Range -->
                        <div>
                            <label class="text-sm font-medium text-gray-700">Price Range</label>
                            <div class="mt-2 grid grid-cols-2 gap-4">
                                <div>
                                    <label for="min_price" class="sr-only">Min Price</label>
                                    <input type="number" 
                                           id="min_price" 
                                           name="min_price"
                                           value="<?php echo $min_price ?? ''; ?>"
                                           min="<?php echo floor($price_range['min_price']); ?>"
                                           max="<?php echo ceil($price_range['max_price']); ?>"
                                           placeholder="Min"
                                           class="block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                </div>
                                <div>
                                    <label for="max_price" class="sr-only">Max Price</label>
                                    <input type="number" 
                                           id="max_price" 
                                           name="max_price"
                                           value="<?php echo $max_price ?? ''; ?>"
                                           min="<?php echo floor($price_range['min_price']); ?>"
                                           max="<?php echo ceil($price_range['max_price']); ?>"
                                           placeholder="Max"
                                           class="block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                </div>
                            </div>
                        </div>

                        <!-- Rating Filter -->
                        <div>
                            <label class="text-sm font-medium text-gray-700">Minimum Rating</label>
                            <div class="mt-2">
                                <select name="min_rating"
                                        class="block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                    <option value="">Any Rating</option>
                                    <?php for ($i = 4; $i >= 1; $i--): ?>
                                    <option value="<?php echo $i; ?>" 
                                            <?php echo $min_rating === $i ? 'selected' : ''; ?>>
                                        <?php echo $i; ?>+ Stars
                                    </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Sort Order -->
                        <div>
                            <label class="text-sm font-medium text-gray-700">Sort By</label>
                            <select name="sort"
                                    class="mt-2 block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                <option value="relevance" <?php echo $sort === 'relevance' ? 'selected' : ''; ?>>Relevance</option>
                                <option value="rating" <?php echo $sort === 'rating' ? 'selected' : ''; ?>>Highest Rated</option>
                                <option value="price_low" <?php echo $sort === 'price_low' ? 'selected' : ''; ?>>Price: Low to High</option>
                                <option value="price_high" <?php echo $sort === 'price_high' ? 'selected' : ''; ?>>Price: High to Low</option>
                                <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest First</option>
                            </select>
                        </div>

                        <div class="pt-4 border-t border-gray-200">
                            <button type="submit"
                                    class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                Apply Filters
                            </button>
                            <?php if ($category_id || $min_price || $max_price || $min_rating || $sort !== 'relevance'): ?>
                            <a href="?<?php echo $query ? 'q=' . urlencode($query) : ''; ?>" 
                               class="mt-2 w-full flex justify-center py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                Clear Filters
                            </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Results -->
            <div class="mt-6 lg:mt-0 lg:col-span-9">
                <?php if (empty($products)): ?>
                <div class="bg-white shadow-sm rounded-lg p-6 text-center">
                    <i class="fas fa-search text-4xl text-gray-400 mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900">No products found</h3>
                    <p class="mt-1 text-gray-500">Try adjusting your search or filter criteria</p>
                </div>
                <?php else: ?>
                <!-- Results Count -->
                <div class="mb-4 text-sm text-gray-700">
                    Showing <?php echo ($page - 1) * $per_page + 1; ?>-<?php echo min($page * $per_page, $total_count); ?> 
                    of <?php echo $total_count; ?> results
                    <?php if ($query): ?>
                    for "<?php echo htmlspecialchars($query); ?>"
                    <?php endif; ?>
                </div>

                <!-- Products Grid -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php foreach ($products as $product): ?>
                    <div class="bg-white shadow-sm rounded-lg overflow-hidden">
                        <div class="aspect-w-3 aspect-h-2">
                            <?php if ($product['image_url']): ?>
                            <img src="<?php echo htmlspecialchars($product['image_url']); ?>" 
                                 alt="<?php echo htmlspecialchars($product['name']); ?>"
                                 class="w-full h-48 object-cover">
                            <?php else: ?>
                            <div class="w-full h-48 bg-gray-200 flex items-center justify-center">
                                <i class="fas fa-image text-gray-400 text-4xl"></i>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="p-4">
                            <h3 class="text-sm font-medium text-gray-900">
                                <a href="/product.php?id=<?php echo $product['id']; ?>">
                                    <?php echo htmlspecialchars($product['name']); ?>
                                </a>
                            </h3>

                            <p class="mt-1 text-sm text-gray-500">
                                <?php echo htmlspecialchars($product['store_name']); ?>
                            </p>

                            <div class="mt-2 flex items-center">
                                <div class="flex items-center">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <i class="<?php echo $i <= round($product['avg_rating']) ? 'fas' : 'far'; ?> fa-star text-yellow-400 text-sm"></i>
                                    <?php endfor; ?>
                                </div>
                                <span class="ml-1 text-sm text-gray-500">
                                    (<?php echo $product['review_count']; ?>)
                                </span>
                            </div>

                            <div class="mt-2 flex items-center justify-between">
                                <p class="text-lg font-medium text-gray-900">
                                    <?php echo format_price($product['price']); ?>
                                </p>
                                <?php if (is_logged_in()): ?>
                                <button onclick="addToCart(<?php echo $product['id']; ?>)"
                                        class="text-blue-600 hover:text-blue-700">
                                    <i class="fas fa-cart-plus"></i>
                                </button>
                                <?php endif; ?>
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
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>"
                                   class="px-3 py-2 rounded-md bg-white text-gray-500 hover:bg-gray-50">
                                    Previous
                                </a>
                            </li>
                            <?php endif; ?>
                            
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"
                                   class="px-3 py-2 rounded-md <?php echo $i === $page ? 'bg-blue-600 text-white' : 'bg-white text-gray-500 hover:bg-gray-50'; ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                            <?php endfor; ?>
                            
                            <?php if ($page < $total_pages): ?>
                            <li>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>"
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

<script>
function addToCart(productId) {
    fetch('/api/cart/add.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            product_id: productId,
            quantity: 1
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Product added to cart!');
        } else {
            alert(data.message || 'Error adding product to cart');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error adding product to cart');
    });
}
</script>

<?php require_once 'includes/footer.php'; ?>
