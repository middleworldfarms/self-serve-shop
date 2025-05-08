<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
// session_start(); // REMOVE or comment out this line

// Database connection
try {
    $db = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME,
        DB_USER,
        DB_PASS
    );
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Helper function to generate unique order numbers
function generateOrderNumber() {
    $today = date('dmy');
    $rand = substr(mt_rand(100000, 999999), 0, 4);
    return $today . $rand;
}

if (!defined('SHOP_LOGO')) define('SHOP_LOGO', 'uploads/shop_logo_67e7cabb88d2f.png');

// Redirect if cart is empty
if (!isset($_SESSION['cart']) || empty($_SESSION['cart'])) {
    header('Location: cart.php');
    exit;
}

// Get settings for page title and other variables needed by header
$settings = get_settings();
$page_title = 'Checkout - ' . ($settings['shop_name'] ?? 'Self-Serve Shop');

// Fix all payment processing variables
$stripe_test_mode = isset($settings['stripe_test_mode']) && $settings['stripe_test_mode'] == '1';
$stripe_publishable_key = $stripe_test_mode ? ($settings['stripe_test_publishable_key'] ?? '') : ($settings['stripe_publishable_key'] ?? '');
$stripe_secret_key = $stripe_test_mode ? ($settings['stripe_test_secret_key'] ?? '') : ($settings['stripe_secret_key'] ?? '');

// For PayPal
$paypal_test_mode = isset($settings['paypal_test_mode']) && $settings['paypal_test_mode'] == '1';
$paypal_client_id = $paypal_test_mode ? ($settings['paypal_test_client_id'] ?? '') : ($settings['paypal_client_id'] ?? '');
$paypal_secret = $paypal_test_mode ? ($settings['paypal_test_secret'] ?? '') : ($settings['paypal_secret'] ?? '');

// For GoCardless
$gocardless_test_mode = isset($settings['gocardless_test_mode']) && $settings['gocardless_test_mode'] == '1';
$gocardless_access_token = $gocardless_test_mode ? ($settings['gocardless_test_access_token'] ?? '') : ($settings['gocardless_access_token'] ?? '');
$gocardless_webhook_secret = $gocardless_test_mode ? ($settings['gocardless_test_webhook_secret'] ?? '') : ($settings['gocardless_webhook_secret'] ?? '');

// Handle GoCardless redirect completion
if (isset($_GET['gocardless_complete']) && $_GET['gocardless_complete'] === '1' && isset($_SESSION['gocardless_flow_id'])) {
    $order_id = $_SESSION['gocardless_order_id'] ?? $_GET['order_id'] ?? null;
    $redirect_flow_id = $_SESSION['gocardless_flow_id'];
    
    if ($order_id) {
        // Get order details
        $stmt = $db->prepare("SELECT total, customer_id FROM orders WHERE id = ?");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($order) {
            require_once 'payments/process-payment.php';
            $payment_result = processPayment(
                $order_id,
                $order['total'],
                'gocardless',
                [
                    'redirect_flow_id' => $redirect_flow_id,
                    'customer_id' => $order['customer_id']
                ]
            );
            
            if ($payment_result['success']) {
                // Clear session variables
                unset($_SESSION['gocardless_flow_id']);
                unset($_SESSION['gocardless_order_id']);
                
                // Clear cart after successful order
                $_SESSION['cart'] = [];
                
                // Redirect to thank you page
                header('Location: order_confirmation.php?order_id=' . $order_id);
                exit;
            } else {
                $payment_error = 'Direct Debit setup failed: ' . ($payment_result['error'] ?? 'Unknown error');
            }
        }
    }
}

// Calculate cart total
require_once 'includes/get_products.php';
$cart_total = 0;
$cart_items = [];

foreach ($_SESSION['cart'] as $product_id => $quantity) {
    $product = get_product_details($product_id);
    $item_total = $product['price'] * $quantity;
    $cart_total += $item_total;
    
    $cart_items[] = [
        'id' => $product_id,
        'name' => $product['name'],
        'price' => $product['price'],
        'quantity' => $quantity,
        'image' => $product['image'] ?? null
    ];
}

// Set total in session for payment processing
$_SESSION['cart_total'] = $cart_total;
$_SESSION['cart_items'] = $cart_items;

// Handle payment submission
$payment_success = false;
$payment_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Only validate name/email for non-manual payment methods
    $payment_method = $_POST['payment_method'] ?? 'manual';
    
    // Skip validation for manual/cash payments
    if ($payment_method !== 'manual' && (empty($_POST['name']) || empty($_POST['email']))) {
        $payment_error = 'Please fill in all required fields.';
    } else {
        // Process different payment methods based on configured provider
        switch ($payment_method) {
            case 'manual':
                // For honor/cash box payments
                $payment_success = true;
                $payment_status = 'pending'; // Honor box payments start as pending
                
                // Generate order number
                $order_number = generateOrderNumber();
                
                error_log("Manual payment: name=" . ($_POST['name'] ?? '') . ", email=" . ($_POST['email'] ?? ''));
                
                try {
                    // First check if the orders table has the right columns
                    $table_check = $db->prepare("SHOW COLUMNS FROM orders LIKE 'items'");
                    $table_check->execute();
                    
                    if ($table_check->rowCount() == 0) {
                        // Add the missing column
                        $db->exec("ALTER TABLE orders ADD COLUMN items TEXT AFTER payment_status");
                    }
                    
                    // Make sure cart items can be JSON encoded
                    $clean_cart_items = [];
                    foreach ($cart_items as $item) {
                        $clean_cart_items[] = [
                            'id' => $item['id'],
                            'name' => $item['name'],
                            'price' => (float)$item['price'],
                            'quantity' => (int)$item['quantity'],
                            'image' => $item['image'] ?? null
                        ];
                    }
                    
                    // Insert the order into database
                    $stmt = $db->prepare("
                        INSERT INTO orders (
                            order_number, customer_name, customer_email, 
                            total_amount, payment_method, payment_status, 
                            items, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    
                    $stmt->execute([
                        $order_number,
                        $_POST['name'] ?? 'Guest Customer', 
                        $_POST['email'] ?? '',
                        $cart_total,
                        'manual',
                        $payment_status,
                        json_encode($clean_cart_items)
                    ]);
                    
                    $order_id = $db->lastInsertId();
                    
                    error_log("Manual payment order inserted, ID: " . $order_id);
                    
                    // Store order information for confirmation page
                    $_SESSION['last_order'] = [
                        'id' => $order_id,
                        'order_number' => $order_number,
                        'total' => $cart_total,
                        'payment_method' => 'manual'
                    ];
                    
                    // Important: Store last order ID in session
                    $_SESSION['last_order_id'] = $order_id;
                    
                    // Clear the cart
                    $_SESSION['cart'] = [];
                    
                    // Create WooCommerce order
                    require_once 'includes/create_woocommerce_order.php';
                    create_woocommerce_order($order_id);
                    
                    // Redirect to confirmation page
                    header("Location: order_confirmation.php?order_id={$order_id}&order_number={$order_number}");
                    exit;
                    
                } catch (PDOException $e) {
                    error_log("Order creation failed: " . $e->getMessage());
                    $payment_error = "Error processing your order. Please try again.";
                }
                break;
                
            case 'paypal':
                // PayPal integration would go here
                $payment_error = 'PayPal integration coming soon.';
                break;
                
            case 'stripe':
                require_once __DIR__ . '/../vendor/autoload.php';
                \Stripe\Stripe::setApiKey($stripe_secret_key);

                $paymentMethodId = $_POST['stripe_payment_method_id'] ?? null;
                $amount = (int)round($cart_total * 100); // Amount in pence/cents
                $currency = 'gbp'; // Change if needed

                if (!$paymentMethodId) {
                    $payment_error = 'Stripe payment failed: No payment method provided.';
                } else {
                    try {
                        $paymentIntent = \Stripe\PaymentIntent::create([
                            'amount' => $amount,
                            'currency' => $currency,
                            'payment_method' => $paymentMethodId,
                            'confirm' => true,
                            'receipt_email' => $_POST['email'] ?? null,
                            'automatic_payment_methods' => [
                                'enabled' => true,
                                'allow_redirects' => 'never'
                            ],
                        ]);
                        if ($paymentIntent->status === 'succeeded') {
                            // Insert the order into database (similar to your manual payment logic)
                            $order_number = generateOrderNumber();
                            $clean_cart_items = [];
                            foreach ($cart_items as $item) {
                                $clean_cart_items[] = [
                                    'id' => $item['id'],
                                    'name' => $item['name'],
                                    'price' => (float)$item['price'],
                                    'quantity' => (int)$item['quantity'],
                                    'image' => $item['image'] ?? null
                                ];
                            }
                            $stmt = $db->prepare("
                                INSERT INTO orders (
                                    order_number, customer_name, customer_email, 
                                    total_amount, payment_method, payment_status, 
                                    items, created_at
                                ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                            ");
                            $stmt->execute([
                                $order_number,
                                $_POST['name'] ?? 'Guest Customer', 
                                $_POST['email'] ?? '',
                                $cart_total,
                                'stripe',
                                'paid',
                                json_encode($clean_cart_items)
                            ]);
                            $order_id = $db->lastInsertId();

                            // Store order information for confirmation page
                            $_SESSION['last_order'] = [
                                'id' => $order_id,
                                'order_number' => $order_number,
                                'total' => $cart_total,
                                'payment_method' => 'stripe'
                            ];
                            $_SESSION['last_order_id'] = $order_id;

                            // Clear the cart
                            $_SESSION['cart'] = [];

                            // Create WooCommerce order
                            require_once 'includes/create_woocommerce_order.php';
                            create_woocommerce_order($order_id);

                            // Redirect to confirmation page
                            header("Location: order_confirmation.php?order_id={$order_id}&order_number={$order_number}");
                            exit;
                        } else {
                            $payment_error = 'Stripe payment not completed: ' . $paymentIntent->status;
                        }
                    } catch (Exception $e) {
                        $payment_error = 'Stripe error: ' . $e->getMessage();
                    }
                }
                break;
                
            case 'bank_transfer':
                // Bank transfer just shows instructions
                $order_id = time() . rand(1000, 9999);
                $_SESSION['last_order_id'] = $order_id;
                $payment_success = true;
                
                // Clear cart after successful order
                $_SESSION['cart'] = [];
                
                // Redirect to thank you page
                header('Location: order_confirmation.php?order_id=' . $order_id);
                exit;
                break;
                
            case 'woo_funds':
                // Process WooCommerce Funds payment
                require_once 'payments/process-payment.php';
                
                $payment_result = processPayment(
                    time() . rand(1000, 9999), // Generate order ID
                    $cart_total,
                    'woo_funds',
                    [
                        'customer_email' => $_POST['woo_funds_email'],
                        'password' => $_POST['woo_funds_password'] // Make sure this is passed!
                    ]
                );
                
                if ($payment_result['success']) {
                    // Payment successful - store transaction ID and new balance
                    $order_id = $payment_result['order_id'] ?? time() . rand(1000, 9999);
                    $_SESSION['last_order_id'] = $order_id;
                    $transaction_id = $payment_result['transaction_id'] ?? '';
                    $new_balance = $payment_result['new_balance'] ?? 0;
                    
                    // Store in session for confirmation page
                    $_SESSION['woo_funds_balance'] = $new_balance;
                    
                    // Clear cart after successful order
                    $_SESSION['cart'] = [];
                    
                    // Redirect to thank you page
                    header('Location: order_confirmation.php?order_id=' . $order_id);
                    exit;
                } else {
                    // Payment failed
                    $payment_error = 'Account credit payment failed: ' . ($payment_result['error'] ?? 'Unknown error');
                }
                break;
                
            case 'gocardless':
                // Process GoCardless payment
                require_once 'payments/process-payment.php';
                
                $customer_id = null;
                // If customer is registered, try to find an existing mandate
                if (isset($_SESSION['customer_id'])) {
                    $customer_id = $_SESSION['customer_id'];
                    
                    // Check for existing mandate
                    $stmt = $db->prepare("SELECT method_details FROM customer_payment_methods WHERE customer_id = ? AND payment_method = 'gocardless'");
                    $stmt->execute([$customer_id]);
                    $payment_method = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($payment_method) {
                        $method_details = json_decode($payment_method['method_details'], true);
                        $mandate_id = $method_details['mandate_id'] ?? null;
                        
                        if ($mandate_id) {
                            // Use existing mandate
                            $payment_result = processPayment(
                                $order_id, 
                                $cart_total, 
                                'gocardless',
                                ['mandate_id' => $mandate_id]
                            );
                            
                            if ($payment_result['success']) {
                                // Payment successful
                                $_SESSION['cart'] = [];
                                header('Location: order_confirmation.php?order_id=' . $order_id);
                                exit;
                            } else {
                                $payment_error = 'Direct Debit payment failed: ' . ($payment_result['error'] ?? 'Unknown error');
                            }
                        }
                    }
                }
                
                // No existing mandate or mandate failed, start new flow
                $payment_result = processPayment(
                    $order_id, 
                    $cart_total, 
                    'gocardless',
                    [
                        'email' => $_POST['email'] ?? '',
                        'first_name' => $_POST['first_name'] ?? '',
                        'last_name' => $_POST['last_name'] ?? '',
                        'customer_id' => $customer_id
                    ]
                );
                
                if (isset($payment_result['redirect'])) {
                    // Redirect to GoCardless
                    header('Location: ' . $payment_result['redirect']);
                    exit;
                } else {
                    // Something went wrong
                    $payment_error = 'Failed to set up Direct Debit: ' . ($payment_result['error'] ?? 'Unknown error');
                }
                break;
                
            default:
                $payment_error = 'Payment method not configured.';
                break;
        }
    }
}

// Include the standard header
require_once 'includes/header.php';
?>

<link rel="stylesheet" href="/css/styles.css">

<main>
    <div class="container">
        <h1>Checkout</h1>
        <div class="checkout-navigation">
            <a href="cart.php" class="button back-button">← Back to Cart</a>
            <a href="index.php" class="button continue-button">Continue Shopping</a>
        </div>
        
        <?php if ($payment_error): ?>
            <div class="payment-error">
                <?php echo $payment_error; ?>
            </div>
        <?php endif; ?>
        
        <div class="checkout-container">
            <div class="order-summary">
                <h2>Order Summary</h2>
                <ul class="order-items">
                    <?php foreach ($cart_items as $item) : ?>
                    <li>
                        <img src="<?php echo htmlspecialchars($item['image']); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>" class="checkout-item-image">
                        <span class="item-name"><?php echo $item['name']; ?> <span class="item-quantity">× <?php echo $item['quantity']; ?></span></span>
                        <span class="item-price">&pound;<?php echo number_format($item['price'] * $item['quantity'], 2); ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <div class="order-total">
                    <span>Total:</span>
                    <span>&pound;<?php echo number_format($cart_total, 2); ?></span>
                </div>
            </div>
            
            <div class="payment-form">
                <h2>Payment Details</h2>
                
                <form method="post" action="" id="checkout-form">
                    <div class="form-row customer-info-fields">
                        <label for="name">Name</label>
                        <input type="text" id="name" name="name">
                        <small class="field-note">Not required for cash payments</small>
                    </div>
                    
                    <div class="form-row customer-info-fields">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email">
                        <small class="field-note">Not required for cash payments</small>
                    </div>
                    
                    <div id="payment-section">
                        <h2>Payment Method</h2>
                        <div class="payment-options">
                            <?php if (isset($settings['enable_manual_payment']) && $settings['enable_manual_payment'] == '1'): ?>
                            <div class="payment-option">
                                <input type="radio" id="payment-manual" name="payment_method" value="manual" checked>
                                <label for="payment-manual">Honor Box Cash Payment</label>
                                <div class="payment-details" id="manual-payment-details">
                                    <div class="payment-instructions">
                                        <?php echo nl2br(htmlspecialchars($settings['payment_instructions'] ?? '')); ?>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            <?php if (isset($settings['enable_stripe']) && $settings['enable_stripe'] == '1' && !empty($stripe_publishable_key)): ?>
                            <div class="payment-option">
                                <input type="radio" id="payment-stripe" name="payment_method" value="stripe">
                                <label for="payment-stripe">
                                    Pay Online with Card
                                    <img src="/uploads/logos/images.jpeg" alt="Stripe" style="height:32px;vertical-align:middle;margin-left:8px;">
                                </label>
                                <div class="payment-details" id="stripe-payment-details">
                                    <div id="stripe-payment-form">
                                        <div id="card-element"></div>
                                        <div id="card-errors" role="alert"></div>
                                        <div class="form-row" style="margin-top:15px;">
                                            <label for="stripe-postcode">Post code</label>
                                            <input type="text" id="stripe-postcode" name="stripe_postcode" maxlength="10" autocomplete="postal-code">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            <?php if (isset($settings['enable_paypal']) && $settings['enable_paypal'] == '1' && !empty($paypal_client_id)): ?>
                            <div class="payment-option">
                                <input type="radio" id="payment-paypal" name="payment_method" value="paypal">
                                <label for="payment-paypal">Pay with PayPal</label>
                                <div class="payment-details" id="paypal-payment-details">
                                    <div id="paypal-button-container"></div>
                                </div>
                            </div>
                            <?php endif; ?>
                            <?php if (isset($settings['enable_woo_funds']) && $settings['enable_woo_funds'] == '1'): ?>
                            <div class="payment-option">
                                <input type="radio" id="payment-woo-funds" name="payment_method" value="woo_funds">
                                <label for="payment-woo-funds">Pay with Account Credit</label>
                                <div class="payment-details" id="woo-funds-payment-details">
                                    <div id="woo-funds-form">
                                        <div class="woo-funds-instructions" style="margin-bottom: 1em; color: #388E3C;">
                                            Enter your account email and password below to pay using your available account credit.<br>
                                            <strong>This is the login for your account on the main site.</strong>
                                        </div>
                                        <p>Use the credit from your account to pay for this order.</p>
                                        <div class="form-row">
                                            <label for="woo-funds-email">Your Account Email:</label>
                                            <input type="email" id="woo-funds-email" name="woo_funds_email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                                        </div>
                                        <div class="form-row">
                                            <label for="woo-funds-password">Account Password:</label>
                                            <input type="password" id="woo-funds-password">
                                            <small><a href="<?php echo htmlspecialchars($settings['woo_shop_url'] ?? '', ENT_QUOTES); ?>/my-account/lost-password/" target="_blank">Forgot password?</a></small>
                                        </div>
                                        <div class="form-row" style="margin-top: 15px; display: flex; align-items: center; gap: 12px;">
                                            <a href="<?php echo htmlspecialchars($settings['woo_shop_url'] ?? '', ENT_QUOTES); ?>/my-account/" target="_blank" class="button secondary">Log in to your account</a>
                                            <span class="woo-funds-note" style="color:#666; font-size: 0.98em;">
                                                <em>(Only needed if you want to check your account details or balance)</em>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- GDPR consent just above Place Order -->
                    <div class="form-row" id="gdpr-row" style="display:flex;align-items:center;gap:10px;">
                        <input type="checkbox" name="gdpr_consent" id="gdpr_consent" required>
                        <label for="gdpr_consent">
                            I agree to the
                            <a href="<?php echo htmlspecialchars($settings['privacy_policy_url'] ?? '/privacy-policy.php'); ?>" target="_blank" rel="noopener">
                                Privacy Policy
                            </a>
                            and understand how my data will be used.
                        </label>
                    </div>
                    
                    <button type="submit" name="place_order" class="button primary">Place Order</button>
                </form>
            </div>
        </div>
    </div>
</main>

<?php require_once 'includes/footer.php'; ?>

<!-- Stripe JS -->
<?php if (isset($settings['enable_stripe']) && $settings['enable_stripe'] == '1' && !empty($stripe_publishable_key)): ?>
<script src="https://js.stripe.com/v3/"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var stripe = Stripe('<?php echo htmlspecialchars($stripe_publishable_key); ?>');
    var elements = stripe.elements();
    var card = elements.create('card', { hidePostalCode: true });
    card.mount('#card-element');

    var form = document.getElementById('checkout-form');
    form.addEventListener('submit', async function(e) {
        if (document.getElementById('payment-stripe') && document.getElementById('payment-stripe').checked) {
            e.preventDefault();
            const postcode = document.getElementById('stripe-postcode').value;
            const {paymentMethod, error} = await stripe.createPaymentMethod({
                type: 'card',
                card: card,
                billing_details: {
                    name: document.getElementById('name').value,
                    email: document.getElementById('email').value,
                    address: { postal_code: postcode }
                }
            });
            if (error) {
                document.getElementById('card-errors').textContent = error.message;
                return;
            }
            // Add paymentMethod.id to a hidden input and submit the form
            let input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'stripe_payment_method_id';
            input.value = paymentMethod.id;
            form.appendChild(input);
            form.submit();
        }
    });
});
</script>
<?php endif; ?>

<!-- PayPal JS -->
<?php if (isset($settings['enable_paypal']) && $settings['enable_paypal'] == '1' && !empty($paypal_client_id)): ?>
<script src="https://www.paypal.com/sdk/js?client-id=<?php echo htmlspecialchars($paypal_client_id); ?>&currency=GBP"></script>
<script>
    paypal.Buttons({
        createOrder: function(data, actions) {
            return actions.order.create({
                purchase_units: [{
                    amount: {
                        value: '<?php echo number_format($cart_total, 2, '.', ''); ?>'
                    }
                }]
            });
        },
        onApprove: function(data, actions) {
            return actions.order.capture().then(function(orderData) {
                document.getElementById('paypal_order_id').value = orderData.id;
                document.getElementById('checkout-form').submit();
            });
        }
    }).render('#paypal-button-container');
</script>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const manualRadio = document.getElementById('payment-manual');
    const stripeRadio = document.getElementById('payment-stripe');
    const paypalRadio = document.getElementById('payment-paypal');
    const wooFundsRadio = document.getElementById('payment-woo-funds');
    const manualDetails = document.getElementById('manual-payment-details');
    const stripeDetails = document.getElementById('stripe-payment-details');
    const paypalDetails = document.getElementById('paypal-payment-details');
    const wooFundsDetails = document.getElementById('woo-funds-payment-details');
    // Add more if you have more payment methods

    function updatePaymentDetails() {
        if (manualRadio) {
            manualDetails.style.display = manualRadio.checked ? 'block' : 'none';
            const customerInfoFields = document.querySelectorAll('.customer-info-fields');
            customerInfoFields.forEach(field => {
                field.style.display = manualRadio.checked ? 'none' : 'block';
            });
            const nameInput = document.getElementById('name');
            const emailInput = document.getElementById('email');
            if (nameInput) nameInput.required = !manualRadio.checked;
            if (emailInput) emailInput.required = !manualRadio.checked;
        }
        if (stripeRadio) {
            stripeDetails.style.display = stripeRadio.checked ? 'block' : 'none';
            const postcodeInput = document.getElementById('stripe-postcode');
            if (postcodeInput) {
                postcodeInput.required = stripeRadio.checked;
            }
        }
        if (paypalRadio) {
            paypalDetails.style.display = paypalRadio.checked ? 'block' : 'none';
        }
        if (wooFundsRadio) {
            wooFundsDetails.style.display = wooFundsRadio.checked ? 'block' : 'none';
        }
        // GDPR row visibility and required attribute
        const gdprRow = document.getElementById('gdpr-row');
        const gdprCheckbox = document.getElementById('gdpr_consent');
        if (manualRadio && gdprRow && gdprCheckbox) {
            if (manualRadio.checked) {
                gdprRow.style.display = 'none';
                gdprCheckbox.required = false;
            } else {
                gdprRow.style.display = 'flex';
                gdprCheckbox.required = true;
            }
        }
    }

    if (manualRadio) manualRadio.addEventListener('change', updatePaymentDetails);
    if (stripeRadio) stripeRadio.addEventListener('change', updatePaymentDetails);
    if (paypalRadio) paypalRadio.addEventListener('change', updatePaymentDetails);
    if (wooFundsRadio) wooFundsRadio.addEventListener('change', updatePaymentDetails);

    updatePaymentDetails();

    // Woo Funds email autofill
    const customerEmailField = document.getElementById('email');
    const wooFundsEmailField = document.getElementById('woo-funds-email');
    if (customerEmailField && wooFundsEmailField) {
        customerEmailField.addEventListener('input', function() {
            wooFundsEmailField.value = this.value;
        });
    }

    // Minimal robust submit handler
    document.getElementById('checkout-form').addEventListener('submit', function(e) {
        const wooFundsRadio = document.getElementById('payment-woo-funds');
        if (wooFundsRadio && wooFundsRadio.checked) {
            const wooFundsEmail = document.getElementById('woo-funds-email').value;
            if (!wooFundsEmail) {
                e.preventDefault();
                alert('Please enter your account email address.');
            }
        }
    });
});
</script>
</body>
</html>