<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';

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
$stripe_publishable_key = $stripe_test_mode ? $settings['stripe_test_publishable_key'] : $settings['stripe_publishable_key'];
$stripe_secret_key = $stripe_test_mode ? $settings['stripe_test_secret_key'] : $settings['stripe_secret_key'];

// For PayPal
$paypal_test_mode = isset($settings['paypal_test_mode']) && $settings['paypal_test_mode'] == '1';
$paypal_client_id = $paypal_test_mode ? $settings['paypal_test_client_id'] : $settings['paypal_client_id'];
$paypal_secret = $paypal_test_mode ? $settings['paypal_test_secret'] : $settings['paypal_secret'];

// For GoCardless
$gocardless_test_mode = isset($settings['gocardless_test_mode']) && $settings['gocardless_test_mode'] == '1';
$gocardless_access_token = $gocardless_test_mode ? $settings['gocardless_test_access_token'] : $settings['gocardless_access_token'];
$gocardless_webhook_secret = $gocardless_test_mode ? $settings['gocardless_test_webhook_secret'] : $settings['gocardless_webhook_secret'];

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
        'quantity' => $quantity
    ];
}

// Set total in session for payment processing
$_SESSION['cart_total'] = $cart_total;
$_SESSION['cart_items'] = $cart_items;

// Handle payment submission
$payment_success = false;
$payment_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Simple validation
    if (empty($_POST['name']) || empty($_POST['email'])) {
        $payment_error = 'Please fill in all required fields.';
    } else {
        // Process different payment methods based on configured provider
        $payment_method = $_POST['payment_method'] ?? 'manual';
        switch ($payment_method) {
            case 'manual':
                // For manual payments, just record the order
                $order_id = time() . rand(1000, 9999);
                $_SESSION['last_order_id'] = $order_id;
                $payment_success = true;
                
                // Clear cart after successful order
                $_SESSION['cart'] = [];
                
                // Redirect to thank you page
                header('Location: order_confirmation.php?order_id=' . $order_id);
                exit;
                break;
                
            case 'paypal':
                // PayPal integration would go here
                $payment_error = 'PayPal integration coming soon.';
                break;
                
            case 'stripe':
                // Stripe integration would go here
                $payment_error = 'Stripe integration coming soon.';
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
                        'password' => $_POST['woo_funds_password']
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

<!-- Update the container style in your CSS section -->
<style>
    /* Add this to the top of your existing styles */
    .container {
        max-width: calc(100% - 200px); /* 100px on each side */
        margin-left: 100px;
        margin-right: 100px;
        width: auto;
    }
    
    /* Make it responsive for mobile */
    @media (max-width: 768px) {
        .container {
            max-width: calc(100% - 40px);
            margin-left: 20px;
            margin-right: 20px;
        }
    }
    
    /* Your existing styles remain below */
    .checkout-container {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 30px;
        margin: 30px 0;
    }
    
    .order-summary {
        background: #f9f9f9;
        padding: 20px;
        border-radius: 8px;
        border: 1px solid #e0e0e0;
        height: fit-content;
    }
    
    .payment-form {
        background: white;
        padding: 25px;
        border-radius: 8px;
        border: 1px solid #e0e0e0;
    }
    
    .payment-options {
        margin-top: 25px;
    }
    
    .payment-option {
        margin-bottom: 15px;
        padding: 15px;
        border: 1px solid #e5e5e5;
        border-radius: 8px;
        background: #f9f9f9;
    }
    
    .payment-option label {
        font-weight: 600;
        margin-left: 8px;
    }
    
    .payment-details {
        margin-top: 15px;
        padding: 15px;
        background: white;
        border-radius: 5px;
        border: 1px solid #e0e0e0;
        display: none;
    }
    
    /* Form styling improvements */
    .form-row {
        margin-bottom: 20px;
    }
    
    .form-row label {
        display: block;
        margin-bottom: 5px;
        font-weight: 500;
    }
    
    .form-row input[type="text"],
    .form-row input[type="email"],
    .form-row input[type="password"] {
        width: 100%;
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 4px;
        font-size: 16px;
    }
    
    /* Responsive adjustments */
    @media (max-width: 768px) {
        .checkout-container {
            grid-template-columns: 1fr;
        }
        
        .order-summary {
            order: 1;
        }
        
        .payment-form {
            order: 2;
        }
    }
    
    .checkout-navigation {
        display: flex;
        justify-content: space-between;
        margin-bottom: 30px;
        gap: 15px;
    }
    
    /* Direct color values instead of CSS variables */
    .checkout-navigation .button {
        display: inline-block;
        padding: 12px 20px;
        font-size: 16px;
        font-weight: 600;
        text-decoration: none;
        text-align: center;
        border-radius: 4px;
        cursor: pointer;
        transition: all 0.2s ease;
        min-width: 160px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        border: none;
        background-color: #4CAF50 !important; /* Direct green color */
        color: white !important; /* White text */
    }
    
    .checkout-navigation .button:hover {
        background-color: #388E3C !important; /* Darker green on hover */
        text-decoration: none;
    }
    
    /* Optional: Add a back arrow icon to the back button */
    .back-button::before {
        content: "← ";
        display: inline-block;
        margin-right: 3px;
    }
    
    @media (max-width: 600px) {
        .checkout-navigation {
            flex-direction: column;
        }
    }

    /* Make the Place Order button match the navigation buttons */
    button.button.primary {
        display: inline-block;
        padding: 12px 20px;
        font-size: 16px;
        font-weight: 600;
        text-decoration: none;
        text-align: center;
        border-radius: 4px;
        cursor: pointer;
        transition: all 0.2s ease;
        min-width: 160px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        border: none;
        background-color: #4CAF50 !important; /* Direct green color */
        color: white !important; /* White text */
        margin-top: 20px;
        width: 100%; /* Make it full width */
    }
    
    button.button.primary:hover {
        background-color: #388E3C !important; /* Darker green on hover */
    }
</style>
    
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
                    <div class="form-row">
                        <label for="name">Name</label>
                        <input type="text" id="name" name="name" required>
                    </div>
                    
                    <div class="form-row">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" required>
                    </div>
                    
                    <?php if (isset($settings['enable_manual_payment']) && $settings['enable_manual_payment'] == '1'): ?>
                        <!-- Manual payment instructions -->
                        <div class="manual-payment-info">
                            <h3>Manual Payment Instructions</h3>
                            <div class="payment-instructions">
                                <?php echo nl2br(htmlspecialchars($settings['payment_instructions'] ?? 'Please contact the shop for payment instructions.')); ?>
                            </div>
                        </div>
                    <?php elseif (isset($settings['payment_provider']) && $settings['payment_provider'] === 'bank_transfer'): ?>
                        <div class="payment-instructions">
                            <h3>Bank Transfer Details</h3>
                            <p><?php echo BANK_DETAILS; ?></p>
                            <p>Please include your order number as the payment reference.</p>
                        </div>
                    <?php endif; ?>
                    
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
                                <input type="radio" id="payment-stripe" name="payment_method" value="stripe" 
                                    <?php echo !(isset($settings['enable_manual_payment']) && $settings['enable_manual_payment'] == '1') ? 'checked' : ''; ?>>
                                <label for="payment-stripe">Pay Online with Card</label>
                                
                                <div class="payment-details" id="stripe-payment-details">
                                    <div id="stripe-payment-form">
                                        <!-- Stripe Elements placeholder -->
                                        <div id="card-element"></div>
                                        <div id="card-errors" role="alert"></div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (isset($settings['enable_paypal']) && $settings['enable_paypal'] == '1' && !empty($paypal_client_id)): ?>
                            <div class="payment-option">
                                <input type="radio" id="payment-paypal" name="payment_method" value="paypal"
                                    <?php echo !(isset($settings['enable_manual_payment']) && $settings['enable_manual_payment'] == '1') && 
                                        !(isset($settings['enable_stripe']) && $settings['enable_stripe'] == '1') ? 'checked' : ''; ?>>
                                <label for="payment-paypal">Pay with PayPal</label>
                                
                                <div class="payment-details" id="paypal-payment-details">
                                    <div id="paypal-button-container"></div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (isset($settings['enable_woo_funds']) && $settings['enable_woo_funds'] == '1'): ?>
                            <div class="payment-option">
                                <input type="radio" id="payment-woo-funds" name="payment_method" value="woo_funds"
                                    <?php echo !(isset($settings['enable_manual_payment']) && $settings['enable_manual_payment'] == '1') && 
                                        !(isset($settings['enable_stripe']) && $settings['enable_stripe'] == '1') &&
                                        !(isset($settings['enable_paypal']) && $settings['enable_paypal'] == '1') ? 'checked' : ''; ?>>
                                <label for="payment-woo-funds">Pay with Account Credit</label>
                                
                                <div class="payment-details" id="woo-funds-payment-details">
                                    <div id="woo-funds-form">
                                        <p>Use the credit from your account to pay for this order.</p>
                                        <div class="form-row">
                                            <label for="woo-funds-email">Your Account Email:</label>
                                            <input type="email" id="woo-funds-email" name="woo_funds_email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                                        </div>
                                        <div class="form-row">
                                            <label for="woo-funds-password">Account Password:</label>
                                            <input type="password" id="woo-funds-password" name="woo_funds_password">
                                            <small><a href="<?php echo htmlspecialchars($settings['woo_shop_url'] ?? '', ENT_QUOTES); ?>/my-account/lost-password/" target="_blank">Forgot password?</a></small>
                                        </div>
                                        <div class="form-row" style="margin-top: 15px;">
                                            <a href="<?php echo htmlspecialchars($settings['woo_shop_url'] ?? '', ENT_QUOTES); ?>/my-account/" target="_blank" class="button secondary">Log in to your account</a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <?php 
                        $manual_enabled = isset($settings['enable_manual_payment']) && $settings['enable_manual_payment'] == '1';
                        $stripe_enabled = isset($settings['enable_stripe']) && $settings['enable_stripe'] == '1';
                        $paypal_enabled = isset($settings['enable_paypal']) && $settings['enable_paypal'] == '1';
                        $gocardless_enabled = isset($settings['enable_gocardless']) && $settings['enable_gocardless'] == '1';
                        $woo_funds_enabled = isset($settings['enable_woo_funds']) && $settings['enable_woo_funds'] == '1';

                        if (!$manual_enabled && !$stripe_enabled && !$paypal_enabled && !$gocardless_enabled && !$woo_funds_enabled): 
                        ?>
                        <div class="payment-error">
                            No payment methods are currently available. Please contact the shop administrator.
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <input type="hidden" name="paypal_order_id" id="paypal_order_id" value="">
                    
                    <button type="submit" name="place_order" class="button primary">Place Order</button>
                </form>
            </div>
        </div>
    </div>
</main>

<?php require_once 'includes/footer.php'; ?>

<!-- Add Stripe JS only if Stripe payments are enabled -->
<?php if (isset($settings['enable_stripe']) && $settings['enable_stripe'] == '1' && !empty($stripe_publishable_key)): ?>
<script src="https://js.stripe.com/v3/"></script>
<script>
    var stripe = Stripe('<?php echo htmlspecialchars($stripe_publishable_key); ?>');
    var elements = stripe.elements();
    
    // Create card Element and mount it
    var card = elements.create('card');
    card.mount('#card-element');
    
    // Handle real-time validation errors
    card.on('change', function(event) {
        var displayError = document.getElementById('card-errors');
        if (event.error) {
            displayError.textContent = event.error.message;
        } else {
            displayError.textContent = '';
        }
    });
</script>
<?php endif; ?>

<!-- Add PayPal JS only if PayPal is enabled -->
<?php if (isset($settings['enable_paypal']) && $settings['enable_paypal'] == '1' && !empty($paypal_client_id)): ?>
<script src="https://www.paypal.com/sdk/js?client-id=<?php echo htmlspecialchars($paypal_client_id); ?>&currency=GBP"></script>
<script>
    paypal.Buttons({
        // Set up the transaction
        createOrder: function(data, actions) {
            return actions.order.create({
                purchase_units: [{
                    amount: {
                        value: '<?php echo number_format($cart_total, 2, '.', ''); ?>'
                    }
                }]
            });
        },
        
        // Finalize the transaction
        onApprove: function(data, actions) {
            return actions.order.capture().then(function(orderData) {
                // Successful capture! Set hidden field with PayPal info
                document.getElementById('paypal_order_id').value = orderData.id;
                
                // Submit the form to complete the order
                document.getElementById('checkout-form').submit();
            });
        }
    }).render('#paypal-button-container');
</script>
<?php endif; ?>

<script>
// Show/hide payment details based on selected method
document.addEventListener('DOMContentLoaded', function() {
    const manualRadio = document.getElementById('payment-manual');
    const stripeRadio = document.getElementById('payment-stripe');
    const paypalRadio = document.getElementById('payment-paypal');
    const wooFundsRadio = document.getElementById('payment-woo-funds');
    const gocardlessRadio = document.getElementById('payment-gocardless');
    const manualDetails = document.getElementById('manual-payment-details');
    const stripeDetails = document.getElementById('stripe-payment-details');
    const paypalDetails = document.getElementById('paypal-payment-details');
    const wooFundsDetails = document.getElementById('woo-funds-payment-details');
    const gocardlessDetails = document.getElementById('gocardless-payment-details');
    
    function updatePaymentDetails() {
        if (manualRadio) {
            manualDetails.style.display = manualRadio.checked ? 'block' : 'none';
        }
        if (stripeRadio) {
            stripeDetails.style.display = stripeRadio.checked ? 'block' : 'none';
        }
        if (paypalRadio) {
            paypalDetails.style.display = paypalRadio.checked ? 'block' : 'none';
        }
        if (wooFundsRadio) {
            wooFundsDetails.style.display = wooFundsRadio.checked ? 'block' : 'none';
        }
        if (gocardlessRadio) {
            gocardlessDetails.style.display = gocardlessRadio.checked ? 'block' : 'none';
        }
    }
    
    if (manualRadio) manualRadio.addEventListener('change', updatePaymentDetails);
    if (stripeRadio) stripeRadio.addEventListener('change', updatePaymentDetails);
    if (paypalRadio) paypalRadio.addEventListener('change', updatePaymentDetails);
    if (wooFundsRadio) wooFundsRadio.addEventListener('change', updatePaymentDetails);
    if (gocardlessRadio) gocardlessRadio.addEventListener('change', updatePaymentDetails);
    
    // Initialize
    updatePaymentDetails();
    
    // Ensure woo-funds email matches customer email field
    const customerEmailField = document.getElementById('email');
    const wooFundsEmailField = document.getElementById('woo-funds-email');
    
    if (customerEmailField && wooFundsEmailField) {
        customerEmailField.addEventListener('input', function() {
            wooFundsEmailField.value = this.value;
        });
    }
    
    // Form validation for WooCommerce Funds
    document.getElementById('checkout-form').addEventListener('submit', function(e) {
        if (wooFundsRadio && wooFundsRadio.checked) {
            const wooFundsEmail = document.getElementById('woo-funds-email').value;
            if (!wooFundsEmail) {
                e.preventDefault();
                alert('Please enter your account email address.');
                return false;
            }
        }
    });
});
</script>
</body>
</html>