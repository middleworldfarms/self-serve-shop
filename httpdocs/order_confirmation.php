<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';

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

// Get order ID from URL parameter
if (isset($_GET['order_id'])) {
    $order_id = $_GET['order_id'];
} else if (isset($_SESSION['last_order_id'])) {
    $order_id = $_SESSION['last_order_id'];
} else {
    header('Location: index.php');
    exit;
}

// Format check - if we have a 14-digit order number, it needs to be
// converted to match our database format (10 digits)
if (strlen($order_id) > 10) {
    // Extract just the last 10 digits which should match our database format
    $order_id = substr($order_id, -10);
    error_log("Reformatted order ID to: " . $order_id);
}

$order_number = isset($_GET['order_number']) ? $_GET['order_number'] : '';
$order_exists = false;

// Try to find order in database
if (!empty($order_id) || !empty($order_number)) {
    try {
        if (!empty($order_id)) {
            $stmt = $db->prepare("SELECT * FROM orders WHERE id = ?");
            $stmt->execute([$order_id]);
        } else {
            $stmt = $db->prepare("SELECT * FROM orders WHERE order_number = ?");
            $stmt->execute([$order_number]);
        }
        
        $order_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($order_data) {
            $order_exists = true;
            $order_id = $order_data['id'];
            $order = $order_data; // Add this line to create $order variable
            $order_number = $order_data['order_number'];
            $payment_method = $order_data['payment_method'];
            $cart_total = $order_data['total_amount'];
            
            // Parse items from order
            if (!empty($order_data['items'])) {
                $cart_items = json_decode($order_data['items'], true);
            }
        }
    } catch (Exception $e) {
        error_log("Database error looking up order: " . $e->getMessage());
    }
}

// Fall back to session data if database lookup fails
if (!$order_exists) {
    // Get order details from session
    $cart_total = isset($_SESSION['cart_total']) ? $_SESSION['cart_total'] : 0;
    $cart_items = isset($_SESSION['cart_items']) ? $_SESSION['cart_items'] : [];
    $payment_method = isset($_SESSION['payment_method']) ? $_SESSION['payment_method'] : 'manual';
}

// If no order ID, redirect to homepage
if (empty($order_id)) {
    header('Location: index.php');
    exit;
}

// Add debug logging
error_log("Order confirmation accessed. order_id=$order_id, session_last_order=" . 
          ($_SESSION['last_order_id'] ?? 'NOT SET'));

// Check if email was sent successfully
$email_sent = isset($_GET['email_sent']) && $_GET['email_sent'] == '1';

// Set up page title for the header
$page_title = 'Order Confirmation';

// Include standard header
require_once 'includes/header.php';
?>

<style>
    .confirmation-container {
        max-width: 700px;
        margin: 0 auto;
        background-color: #fff;
        padding: 30px;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    
    .confirmation-header {
        text-align: center;
        margin-bottom: 30px;
    }
    
    .confirmation-header h1 {
        color: #2e7d32;
        margin-bottom: 10px;
    }
    
    .order-number {
        font-size: 1.2em;
        font-weight: bold;
        color: #333;
        margin-bottom: 20px;
    }
    
    .order-details {
        margin-top: 30px;
        border-top: 1px solid #eee;
        padding-top: 20px;
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
    
    .payment-info {
        margin-top: 30px;
        padding: 15px;
        background-color: #f9f9f9;
        border-radius: 4px;
    }
    
    .continue-shopping {
        text-align: center;
        margin-top: 30px;
    }
    
    .button {
        background-color: <?php echo $primary; ?>;
        color: white;
        border: none;
        padding: 12px 24px;
        border-radius: 4px;
        text-decoration: none;
        display: inline-block;
        font-weight: bold;
    }
</style>

<main>
    <div class="container">
        <div class="confirmation-container">
            <div class="confirmation-header">
                <h1>Thank You for Your Order!</h1>
                <p class="order-number">Order #: <?php echo $order_id; ?></p>
            </div>
            
            <p>Please help yourself to your goods and thanks for shopping at <?php echo htmlspecialchars($shop_name); ?>!</p>
            
            <div class="order-details">
                <h2>Order Details</h2>
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
            
            <?php 
            // Get settings for payment info
            $settings = get_settings();

            if ($payment_method === 'manual'): ?>
            <div class="payment-info">
                <h3>Payment Information</h3>
                <p><?php echo $settings['payment_instructions'] ?? 'Please place your payment in the honor box.'; ?></p>
            </div>
            <?php elseif ($payment_method === 'bank_transfer'): ?>
            <div class="payment-info">
                <h3>Payment Information</h3>
                <p>Please complete your payment by bank transfer using these details:</p>
                <p><?php echo $settings['bank_details'] ?? ''; ?></p>
                <p><strong>Reference:</strong> Order #<?php echo $order_id; ?></p>
            </div>
            <?php endif; ?>
            
            <?php if ($payment_method === 'woo_funds'): ?>
            <div class="payment-info woo-funds">
                <h3>Payment Information</h3>
                <p>Payment completed using your account credit.</p>
                <?php if (isset($_SESSION['woo_funds_balance'])): ?>
                <p>Your remaining account balance: <strong>&pound;<?php echo number_format($_SESSION['woo_funds_balance'], 2); ?></strong></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <div class="receipt-options">
                <h3>Receipt Options</h3>
                
                <?php if ($email_sent): ?>
                <div class="success-message" style="background-color: #DFF2BF; color: #4F8A10; padding: 10px; border-radius: 4px; margin-bottom: 15px; text-align: center;">
                    <p><strong>Success!</strong> The receipt has been emailed successfully.</p>
                </div>
                <?php endif; ?>
                
                <div class="receipt-buttons">
                    <button onclick="window.print();" class="receipt-button">Print Receipt</button>
                    <a href="generate_receipt.php?order_id=<?php echo substr($order_id, -10); ?>" class="receipt-button">Download PDF</a>
                    <button id="email-receipt-btn" class="receipt-button">Email Receipt</button>
                </div>

                <div id="email-receipt-form">
                    <form method="post" action="email_receipt.php">
                        <input type="hidden" name="order_id" value="<?php echo substr($order_id, -10); ?>">
                        <div class="form-group">
                            <label for="email">Email address:</label>
                            <input type="email" name="email" id="email" value="<?php echo htmlspecialchars($order_data['customer_email'] ?? ''); ?>" required>
                        </div>
                        <button type="submit" class="button">Send Receipt</button>
                    </form>
                </div>
            </div>
            
            <div class="continue-shopping">
                <a href="index.php" class="button">Continue Shopping</a>
            </div>
        </div>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const emailBtn = document.getElementById('email-receipt-btn');
    const emailForm = document.getElementById('email-receipt-form');
    
    if (emailBtn && emailForm) {
        emailBtn.addEventListener('click', function() {
            if (emailForm.style.display === 'block') {
                emailForm.style.display = 'none';
                emailBtn.textContent = 'Email Receipt';
            } else {
                emailForm.style.display = 'block';
                emailBtn.textContent = 'Cancel';
            }
        });
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>