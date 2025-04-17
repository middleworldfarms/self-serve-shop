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
        switch (PAYMENT_PROVIDER) {
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
                    $order_id, 
                    $cart_total, 
                    'woo_funds',
                    ['customer_email' => $_POST['woo_funds_email']]
                );
                
                if ($payment_result['success']) {
                    // Payment successful - store transaction ID and new balance
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout - <?php echo SHOP_NAME; ?></title>
    <link rel="stylesheet" href="css/styles.css">
    <style>
        .checkout-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-top: 20px;
        }
        
        @media (max-width: 768px) {
            .checkout-container {
                grid-template-columns: 1fr;
            }
        }
        
        .order-summary {
            background-color: #f9f9f9;
            padding: 20px;
            border-radius: 8px;
        }
        
        .order-items {
            list-style: none;
            padding: 0;
            margin: 0 0 20px 0;
        }
        
        .order-items li {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #eee;
        }
        
        .order-total {
            display: flex;
            justify-content: space-between;
            font-weight: bold;
            font-size: 1.2em;
            margin-top: 20px;
            padding-top: 10px;
            border-top: 2px solid #ddd;
        }
        
        .payment-form {
            background-color: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .payment-form h2 {
            margin-top: 0;
        }
        
        .form-row {
            margin-bottom: 20px;
        }
        
        .form-row label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        
        .form-row input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        
        .payment-methods {
            margin-bottom: 20px;
        }
        
        .payment-method {
            margin-bottom: 10px;
        }
        
        .payment-instructions {
            margin-top: 20px;
            padding: 15px;
            background-color: #f0f8ff;
            border-radius: 4px;
            border-left: 4px solid #2196F3;
        }
        
        .payment-error {
            color: #d32f2f;
            background-color: #ffebee;
            padding: 10px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        
        .checkout-navigation {
            display: flex;
            justify-content: space-between;
            margin: 25px 0;
            gap: 15px; /* Ensures space between buttons */
        }

        /* Style both buttons consistently */
        .button {
            padding: 10px 20px;
            border-radius: 4px;
            text-decoration: none;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            color: white; /* White text for both buttons */
        }

        /* Back to Cart button styling - now green like Continue Shopping */
        .back-button {
            background-color: #4caf50; /* Green background */
            color: white;
        }

        .back-button:hover {
            background-color: #388e3c; /* Darker green on hover */
        }

        /* Continue Shopping button styling - same as Back to Cart */
        .continue-button {
            background-color: #4caf50; /* Green background */
            color: white;
        }

        .continue-button:hover {
            background-color: #388e3c; /* Darker green on hover */
        }

        .payment-options {
            margin-bottom: 20px;
        }

        .payment-option {
            margin-bottom: 15px;
            padding: 15px;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
        }

        .payment-option input[type="radio"] {
            margin-right: 10px;
        }

        .payment-option label {
            font-weight: bold;
        }

        .payment-details {
            margin-top: 10px;
            padding: 10px;
            background-color: #f9f9f9;
            border-radius: 4px;
        }

        .payment-instructions {
            white-space: pre-line;
        }

        #card-element {
            padding: 10px;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            background-color: white;
        }

        #card-errors {
            color: #d32f2f;
            margin-top: 10px;
            font-size: 0.9em;
        }
    </style>
</head>
<body>
    <header>
        <div class="header-content">
            <div class="logo">
                <a href="index.php"><?php echo SHOP_NAME; ?></a>
                <?php if (!empty(SHOP_LOGO)): ?>
                    <img src="<?php echo htmlspecialchars(SHOP_LOGO); ?>" alt="<?php echo htmlspecialchars(SHOP_NAME); ?>" class="shop-logo">
                <?php endif; ?>
            </div>
            <nav>
                <a href="index.php">Products</a>
                <a href="cart.php">Cart</a>
            </nav>
        </div>
    </header>
    
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
                        
                        <?php if (PAYMENT_PROVIDER === 'manual'): ?>
                            <div class="payment-instructions">
                                <h3>Instructions for Payment</h3>
                                <p><?php echo PAYMENT_INSTRUCTIONS; ?></p>
                            </div>
                        <?php elseif (PAYMENT_PROVIDER === 'bank_transfer'): ?>
                            <div class="payment-instructions">
                                <h3>Bank Transfer Details</h3>
                                <p><?php echo BANK_DETAILS; ?></p>
                                <p>Please include your order number as the payment reference.</p>
                            </div>
                        <?php endif; ?>
                        
                        <div id="payment-section">
                            <h2>Payment Method</h2>
                            
                            <div class="payment-options">
                                <?php if (ENABLE_MANUAL_PAYMENT): ?>
                                <div class="payment-option">
                                    <input type="radio" id="payment-manual" name="payment_method" value="manual" checked>
                                    <label for="payment-manual">Honor Box Cash Payment</label>
                                    
                                    <div class="payment-details" id="manual-payment-details">
                                        <div class="payment-instructions">
                                            <?php echo nl2br(htmlspecialchars(PAYMENT_INSTRUCTIONS)); ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (ENABLE_STRIPE_PAYMENT && !empty(STRIPE_PUBLIC_KEY)): ?>
                                <div class="payment-option">
                                    <input type="radio" id="payment-stripe" name="payment_method" value="stripe" <?php echo !ENABLE_MANUAL_PAYMENT ? 'checked' : ''; ?>>
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
                                
                                <?php if (ENABLE_PAYPAL_PAYMENT && !empty(PAYPAL_CLIENT_ID)): ?>
                                <div class="payment-option">
                                    <input type="radio" id="payment-paypal" name="payment_method" value="paypal" <?php echo (!ENABLE_MANUAL_PAYMENT && !ENABLE_STRIPE_PAYMENT) ? 'checked' : ''; ?>>
                                    <label for="payment-paypal">Pay with PayPal</label>
                                    
                                    <div class="payment-details" id="paypal-payment-details">
                                        <div id="paypal-button-container"></div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($current_settings['enable_woo_funds']) && $current_settings['enable_woo_funds'] === '1'): ?>
                                <div class="payment-option">
                                    <input type="radio" id="payment-woo-funds" name="payment_method" value="woo_funds" <?php echo (!ENABLE_MANUAL_PAYMENT && !ENABLE_STRIPE_PAYMENT && !ENABLE_PAYPAL_PAYMENT && !ENABLE_APPLE_PAY_PAYMENT) ? 'checked' : ''; ?>>
                                    <label for="payment-woo-funds">Pay with Account Credit</label>
                                    
                                    <div class="payment-details" id="woo-funds-payment-details">
                                        <div id="woo-funds-form">
                                            <p>Use the credit from your MiddleWorld Farms account to pay for this order.</p>
                                            <div class="form-row">
                                                <label for="woo-funds-email">Confirm Your Account Email:</label>
                                                <input type="email" id="woo-funds-email" name="woo_funds_email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($current_settings['enable_gocardless']) && $current_settings['enable_gocardless'] === '1'): ?>
                                <div class="payment-option">
                                    <input type="radio" id="payment-gocardless" name="payment_method" value="gocardless">
                                    <label for="payment-gocardless">Pay with Direct Debit (GoCardless)</label>
                                    
                                    <div class="payment-details" id="gocardless-payment-details">
                                        <p>Set up a Direct Debit payment with GoCardless. This is ideal for recurring purchases.</p>
                                        <div class="gocardless-info">
                                            <img src="assets/img/direct-debit.png" alt="Direct Debit" class="payment-logo" style="height: 40px;">
                                            <ul>
                                                <li>Safe and secure - protected by the Direct Debit Guarantee</li>
                                                <li>Simple setup process - takes just a minute</li>
                                                <li>Easily manage all your payments in one place</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <?php if (!ENABLE_MANUAL_PAYMENT && !ENABLE_STRIPE_PAYMENT && !ENABLE_PAYPAL_PAYMENT): ?>
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
    
    <footer>
        <div class="container">
            <p>&copy; <?php echo date('Y'); ?> <?php echo SHOP_NAME; ?>. All rights reserved.</p>
        </div>
    </footer>
    
    <!-- Add Stripe JS only if Stripe payments are enabled -->
    <?php if (ENABLE_STRIPE_PAYMENT && !empty(STRIPE_PUBLIC_KEY)): ?>
    <script src="https://js.stripe.com/v3/"></script>
    <script>
        // Initialize Stripe
        var stripe = Stripe('<?php echo htmlspecialchars(STRIPE_PUBLIC_KEY); ?>');
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
    <?php if (ENABLE_PAYPAL_PAYMENT && !empty(PAYPAL_CLIENT_ID)): ?>
    <script src="https://www.paypal.com/sdk/js?client-id=<?php echo htmlspecialchars(PAYPAL_CLIENT_ID); ?>&currency=GBP"></script>
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