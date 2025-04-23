<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';

// Ensure database connection is established
global $db;
if (!isset($db) || !$db instanceof PDO) {
    try {
        $db = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME,
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (PDOException $e) {
        die("Database connection failed: " . $e->getMessage());
    }
}

// Get settings for page title and other variables needed by header
$settings = get_settings();
$page_title = 'Order Confirmation - ' . ($settings['shop_name'] ?? 'Self-Serve Shop');

$order_id = isset($_GET['order_id']) ? $_GET['order_id'] : '';

// If no order ID or not in session, redirect to homepage
if (empty($order_id) || !isset($_SESSION['last_order_id']) || $_SESSION['last_order_id'] != $order_id) {
    header('Location: index.php');
    exit;
}

// Get order details from session or database
$cart_total = isset($_SESSION['cart_total']) ? $_SESSION['cart_total'] : 0;
$cart_items = isset($_SESSION['cart_items']) ? $_SESSION['cart_items'] : [];

// Verify the order exists in the database
try {
    $check_stmt = $db->prepare("SELECT id, payment_method, total_amount, items FROM orders WHERE id = ? OR order_number = ?");
    $check_stmt->execute([$order_id, $order_id]);
    $order_exists = $check_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($order_exists) {
        // Use database values if available
        $cart_total = $order_exists['total_amount'];
        
        // Try to get items from database
        if (!empty($order_exists['items'])) {
            $db_items = json_decode($order_exists['items'], true);
            if (is_array($db_items) && !empty($db_items)) {
                $cart_items = $db_items;
            }
        }
        
        echo "<div style='background:#d4edda; color:#155724; padding:10px; margin-bottom:15px; border-radius:4px;'>
              <strong>Debug:</strong> Order confirmed in database! ID: {$order_exists['id']}, 
              Method: {$order_exists['payment_method']}, Amount: £{$order_exists['total_amount']}
              </div>";
    } else {
        echo "<div style='background:#f8d7da; color:#721c24; padding:10px; margin-bottom:15px; border-radius:4px;'>
              <strong>Warning:</strong> Order not found in database. This may cause reporting issues.
              </div>";
    }
} catch (Exception $e) {
    error_log("Order confirmation database check error: " . $e->getMessage());
}

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
</style>

<div class="container">
    <div class="confirmation-container">
        <div class="confirmation-header">
            <h1>Thank You for Your Order!</h1>
            <p class="order-number">Order #: <?php echo $order_id; ?></p>
        </div>
        
        <p>Please help yourself to your goods and thanks for shopping at <?php echo $settings['shop_name'] ?? 'our shop'; ?>!</p>
        
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
        // Get payment provider from settings
        $payment_provider = $settings['payment_provider'] ?? 'manual';
        
        if ($payment_provider === 'manual'): ?>
        <div class="payment-info">
            <h3>Payment Information</h3>
            <p><?php echo $settings['payment_instructions'] ?? 'Please place your payment in the honor box.'; ?></p>
        </div>
        <?php elseif ($payment_provider === 'bank_transfer'): ?>
        <div class="payment-info">
            <h3>Payment Information</h3>
            <p>Please complete your payment by bank transfer using these details:</p>
            <p><?php echo $settings['bank_details'] ?? ''; ?></p>
            <p><strong>Reference:</strong> Order #<?php echo $order_id; ?></p>
        </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['payment_method']) && $_SESSION['payment_method'] === 'woo_funds'): ?>
        <div class="payment-info woo-funds">
            <h3>Payment Information</h3>
            <p>Payment completed using your account credit.</p>
            <?php if (isset($_SESSION['woo_funds_balance'])): ?>
            <p>Your remaining account balance: <strong>&pound;<?php echo number_format($_SESSION['woo_funds_balance'], 2); ?></strong></p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <div class="continue-shopping">
            <a href="index.php" class="button">Continue Shopping</a>
        </div>
    </div>
</div>

<?php
// Include standard footer
require_once 'includes/footer.php';
?>