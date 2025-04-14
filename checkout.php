<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';

// Redirect if not logged in
if (!is_logged_in()) {
    header('Location: /login.php');
    exit;
}

try {
    // Fetch cart items with product details
    $stmt = $pdo->prepare("
        SELECT ci.*, p.name, p.price, p.stock_quantity, pi.image_url,
               s.store_name, s.id as seller_id, pv.name as variant_name, 
               pv.price as variant_price
        FROM cart_items ci
        JOIN products p ON ci.product_id = p.id
        LEFT JOIN product_images pi ON p.id = pi.product_id AND pi.is_primary = 1
        JOIN sellers s ON p.seller_id = s.id
        LEFT JOIN product_variants pv ON ci.variant_id = pv.id
        WHERE ci.user_id = ?
        ORDER BY s.id, ci.created_at DESC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $cart_items = $stmt->fetchAll();

    // Group items by seller
    $sellers = [];
    $subtotal = 0;
    foreach ($cart_items as $item) {
        $price = $item['variant_price'] ?? $item['price'];
        $item_total = $price * $item['quantity'];
        $subtotal += $item_total;

        if (!isset($sellers[$item['seller_id']])) {
            $sellers[$item['seller_id']] = [
                'name' => $item['store_name'],
                'items' => [],
                'subtotal' => 0
            ];
        }
        $sellers[$item['seller_id']]['items'][] = $item;
        $sellers[$item['seller_id']]['subtotal'] += $item_total;
    }

    // Calculate shipping and total
    $shipping = 0; // You can implement shipping calculation logic here
    $total = $subtotal + $shipping;

    // Get user's previous orders for address autofill
    $stmt = $pdo->prepare("
        SELECT shipping_address, shipping_city, shipping_postal_code, shipping_phone
        FROM orders
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $last_order = $stmt->fetch();

} catch (PDOException $e) {
    error_log("Error in checkout: " . $e->getMessage());
    set_flash_message('error', 'An error occurred. Please try again later.');
    header('Location: /cart.php');
    exit;
}

// Handle empty cart
if (empty($cart_items)) {
    header('Location: /cart.php');
    exit;
}

require_once 'includes/header.php';
?>

<div class="bg-gray-50">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <h1 class="text-3xl font-extrabold tracking-tight text-gray-900 mb-8">Checkout</h1>

        <!-- Checkout Steps -->
        <div class="flex items-center justify-center mb-8">
            <nav class="flex items-center space-x-4" aria-label="Progress">
                <button class="step-button active" data-step="1">
                    <span class="step-number">1</span>
                    <span class="step-text">Review Order</span>
                </button>
                <div class="h-0.5 w-12 bg-gray-200"></div>
                <button class="step-button" data-step="2">
                    <span class="step-number">2</span>
                    <span class="step-text">Shipping</span>
                </button>
                <div class="h-0.5 w-12 bg-gray-200"></div>
                <button class="step-button" data-step="3">
                    <span class="step-number">3</span>
                    <span class="step-text">Payment</span>
                </button>
            </nav>
        </div>

        <div class="lg:grid lg:grid-cols-12 lg:gap-x-12 lg:items-start">
            <!-- Main Content -->
            <div class="lg:col-span-7">
                <!-- Step 1: Review Order -->
                <div class="step-content active" id="step-1">
                    <div class="bg-white shadow-sm rounded-lg">
                        <?php foreach ($sellers as $seller_id => $seller): ?>
                        <div class="p-6 <?php echo $seller_id !== array_key_first($sellers) ? 'border-t' : ''; ?>">
                            <h3 class="text-lg font-medium text-gray-900 mb-4">
                                <?php echo htmlspecialchars($seller['name']); ?>
                            </h3>
                            
                            <div class="space-y-4">
                                <?php foreach ($seller['items'] as $item): ?>
                                <div class="flex items-center">
                                    <div class="flex-shrink-0 w-20 h-20 border border-gray-200 rounded-lg overflow-hidden">
                                        <?php if ($item['image_url']): ?>
                                        <img src="<?php echo htmlspecialchars($item['image_url']); ?>" 
                                             alt="<?php echo htmlspecialchars($item['name']); ?>"
                                             class="w-full h-full object-center object-cover">
                                        <?php else: ?>
                                        <div class="w-full h-full bg-gray-200 flex items-center justify-center">
                                            <i class="fas fa-image text-gray-400 text-2xl"></i>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="ml-4 flex-1">
                                        <div class="flex justify-between">
                                            <div>
                                                <h4 class="text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($item['name']); ?>
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
                                            <p class="text-sm font-medium text-gray-900">
                                                <?php 
                                                $price = $item['variant_price'] ?? $item['price'];
                                                echo format_price($price * $item['quantity']); 
                                                ?>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <div class="mt-4 pt-4 border-t border-gray-200">
                                <div class="flex justify-between text-sm">
                                    <span class="font-medium text-gray-900">Subtotal</span>
                                    <span class="font-medium text-gray-900">
                                        <?php echo format_price($seller['subtotal']); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="mt-6">
                        <button onclick="nextStep(2)" 
                                class="w-full bg-blue-600 border border-transparent rounded-md shadow-sm py-3 px-4 text-base font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                            Continue to Shipping
                        </button>
                    </div>
                </div>

                <!-- Step 2: Shipping Details -->
                <div class="step-content hidden" id="step-2">
                    <div class="bg-white shadow-sm rounded-lg p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-6">Shipping Information</h3>
                        
                        <form id="shipping-form" class="space-y-6">
                            <div>
                                <label for="shipping_address" class="block text-sm font-medium text-gray-700">
                                    Address
                                </label>
                                <textarea id="shipping_address" 
                                          name="shipping_address" 
                                          rows="3" 
                                          required
                                          class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm"><?php echo htmlspecialchars($last_order['shipping_address'] ?? ''); ?></textarea>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="shipping_city" class="block text-sm font-medium text-gray-700">
                                        City
                                    </label>
                                    <input type="text" 
                                           id="shipping_city" 
                                           name="shipping_city" 
                                           required
                                           value="<?php echo htmlspecialchars($last_order['shipping_city'] ?? ''); ?>"
                                           class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                </div>

                                <div>
                                    <label for="shipping_postal_code" class="block text-sm font-medium text-gray-700">
                                        Postal Code
                                    </label>
                                    <input type="text" 
                                           id="shipping_postal_code" 
                                           name="shipping_postal_code" 
                                           required
                                           value="<?php echo htmlspecialchars($last_order['shipping_postal_code'] ?? ''); ?>"
                                           class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                </div>
                            </div>

                            <div>
                                <label for="shipping_phone" class="block text-sm font-medium text-gray-700">
                                    Phone Number
                                </label>
                                <input type="tel" 
                                       id="shipping_phone" 
                                       name="shipping_phone" 
                                       required
                                       value="<?php echo htmlspecialchars($last_order['shipping_phone'] ?? ''); ?>"
                                       class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                            </div>
                        </form>

                        <div class="mt-6 flex items-center justify-between">
                            <button onclick="prevStep(1)"
                                    class="text-sm font-medium text-blue-600 hover:text-blue-500">
                                <i class="fas fa-arrow-left mr-2"></i>
                                Back to Review
                            </button>
                            <button onclick="validateAndContinue()"
                                    class="bg-blue-600 border border-transparent rounded-md shadow-sm py-2 px-4 text-base font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                Continue to Payment
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Step 3: Payment -->
                <div class="step-content hidden" id="step-3">
                    <div class="bg-white shadow-sm rounded-lg p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-6">Payment Method</h3>
                        
                        <form id="payment-form" class="space-y-6">
                            <div class="space-y-4">
                                <div class="flex items-center">
                                    <input type="radio" 
                                           id="payment_bank_transfer" 
                                           name="payment_method" 
                                           value="bank_transfer"
                                           checked
                                           class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300">
                                    <label for="payment_bank_transfer" class="ml-3">
                                        <span class="block text-sm font-medium text-gray-700">Bank Transfer</span>
                                        <span class="block text-sm text-gray-500">
                                            Transfer to our bank account
                                        </span>
                                    </label>
                                </div>
                                
                                <div class="flex items-center">
                                    <input type="radio" 
                                           id="payment_cod" 
                                           name="payment_method" 
                                           value="cod"
                                           class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300">
                                    <label for="payment_cod" class="ml-3">
                                        <span class="block text-sm font-medium text-gray-700">Cash on Delivery</span>
                                        <span class="block text-sm text-gray-500">
                                            Pay when you receive your order
                                        </span>
                                    </label>
                                </div>
                            </div>
                        </form>

                        <div class="mt-6 flex items-center justify-between">
                            <button onclick="prevStep(2)"
                                    class="text-sm font-medium text-blue-600 hover:text-blue-500">
                                <i class="fas fa-arrow-left mr-2"></i>
                                Back to Shipping
                            </button>
                            <button onclick="placeOrder()"
                                    class="bg-blue-600 border border-transparent rounded-md shadow-sm py-2 px-4 text-base font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                Place Order
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Order Summary -->
            <div class="mt-10 lg:mt-0 lg:col-span-5">
                <div class="bg-gray-50 rounded-lg px-4 py-6 sm:p-6 lg:p-8">
                    <h2 class="text-lg font-medium text-gray-900">Order Summary</h2>
                    
                    <div class="mt-6 space-y-4">
                        <div class="flex items-center justify-between">
                            <dt class="text-sm text-gray-600">Subtotal</dt>
                            <dd class="text-sm font-medium text-gray-900"><?php echo format_price($subtotal); ?></dd>
                        </div>
                        
                        <div class="flex items-center justify-between">
                            <dt class="text-sm text-gray-600">Shipping</dt>
                            <dd class="text-sm font-medium text-gray-900"><?php echo format_price($shipping); ?></dd>
                        </div>
                        
                        <div class="border-t border-gray-200 pt-4 flex items-center justify-between">
                            <dt class="text-base font-medium text-gray-900">Order total</dt>
                            <dd class="text-base font-medium text-gray-900"><?php echo format_price($total); ?></dd>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.step-button {
    @apply flex items-center;
}

.step-number {
    @apply w-8 h-8 rounded-full border-2 flex items-center justify-center text-sm font-medium mr-2;
}

.step-text {
    @apply text-sm font-medium;
}

.step-button {
    @apply text-gray-500;
}

.step-button .step-number {
    @apply border-gray-300 bg-white;
}

.step-button.active {
    @apply text-blue-600;
}

.step-button.active .step-number {
    @apply border-blue-600 bg-blue-600 text-white;
}

.step-button.completed {
    @apply text-green-600;
}

.step-button.completed .step-number {
    @apply border-green-600 bg-green-600 text-white;
}
</style>

<script>
let currentStep = 1;
const totalSteps = 3;

function updateSteps() {
    // Update step buttons
    document.querySelectorAll('.step-button').forEach((button, index) => {
        const step = index + 1;
        button.classList.remove('active', 'completed');
        if (step === currentStep) {
            button.classList.add('active');
        } else if (step < currentStep) {
            button.classList.add('completed');
        }
    });

    // Show/hide step content
    document.querySelectorAll('.step-content').forEach((content, index) => {
        content.classList.toggle('hidden', index + 1 !== currentStep);
    });
}

function nextStep(step) {
    if (step <= totalSteps) {
        currentStep = step;
        updateSteps();
    }
}

function prevStep(step) {
    if (step > 0) {
        currentStep = step;
        updateSteps();
    }
}

function validateAndContinue() {
    const form = document.getElementById('shipping-form');
    if (form.checkValidity()) {
        nextStep(3);
    } else {
        form.reportValidity();
    }
}

function placeOrder() {
    const shippingForm = document.getElementById('shipping-form');
    const paymentForm = document.getElementById('payment-form');
    
    if (!shippingForm.checkValidity()) {
        prevStep(2);
        shippingForm.reportValidity();
        return;
    }

    const formData = new FormData();
    formData.append('shipping_address', shippingForm.shipping_address.value);
    formData.append('shipping_city', shippingForm.shipping_city.value);
    formData.append('shipping_postal_code', shippingForm.shipping_postal_code.value);
    formData.append('shipping_phone', shippingForm.shipping_phone.value);
    formData.append('payment_method', paymentForm.payment_method.value);

    // Submit order
    fetch('/api/orders/create.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.location.href = `/order-confirmation.php?id=${data.order_id}`;
        } else {
            alert(data.message || 'Error placing order. Please try again.');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error placing order. Please try again.');
    });
}
</script>

<?php require_once 'includes/footer.php'; ?>
