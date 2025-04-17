<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';

$order_id = isset($_GET['order_id']) ? $_GET['order_id'] : '';

// If no order ID or not in session, redirect to homepage
if (empty($order_id) || !isset($_SESSION['last_order_id']) || $_SESSION['last_order_id'] != $order_id) {
    header('Location: index.php');
    exit;
}

// Get order details from session
$cart_total = isset($_SESSION['cart_total']) ? $_SESSION['cart_total'] : 0;
$cart_items = isset($_SESSION['cart_items']) ? $_SESSION['cart_items'] : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Confirmation - <?php echo SHOP_NAME; ?></title>
    <link rel="stylesheet" href="css/styles.css">
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
</head>
<body>
    <header>
        <div class="header-content">
            <div class="logo">
                <a href="index.php"><?php echo SHOP_NAME; ?></a>
                <img src="<?php echo htmlspecialchars(SHOP_LOGO); ?>" alt="<?php echo htmlspecialchars(SHOP_NAME); ?>" class="shop-logo">
            </div>
            <nav>
                <a href="index.php">Products</a>
                <a href="cart.php">Cart</a>
            </nav>
        </div>
    </header>
    
    <main>
        <div class="container">
            <div class="confirmation-container">
                <div class="confirmation-header">
                    <h1>Thank You for Your Order!</h1>
                    <p class="order-number">Order #: <?php echo $order_id; ?></p>
                </div>
                
                <p>Please help yourself to your goods and thanks for shopping at <?php echo SHOP_NAME; ?>!</p>
                
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
                
                <?php if (PAYMENT_PROVIDER === 'manual'): ?>
                <div class="payment-info">
                    <h3>Payment Information</h3>
                    <p><?php echo PAYMENT_INSTRUCTIONS; ?></p>
                </div>
                <?php elseif (PAYMENT_PROVIDER === 'bank_transfer'): ?>
                <div class="payment-info">
                    <h3>Payment Information</h3>
                    <p>Please complete your payment by bank transfer using these details:</p>
                    <p><?php echo BANK_DETAILS; ?></p>
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
    </main>
    
    <footer>
        <div class="container">
            <p>&copy; <?php echo date('Y'); ?> <?php echo SHOP_NAME; ?>. All rights reserved.</p>
        </div>
    </footer>
</body>
</html>