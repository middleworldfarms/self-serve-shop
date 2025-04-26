<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
require_once 'includes/header.php';

// Initialize cart if not exists
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = array();
}

// Process cart actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Update quantities
    if (isset($_POST['update_cart'])) {
        foreach ($_POST['quantity'] as $product_id => $quantity) {
            $quantity = max(1, (int)$quantity); // Ensure min quantity is 1
            if (isset($_SESSION['cart'][$product_id])) {
                $_SESSION['cart'][$product_id] = $quantity;
            }
        }
    }
    
    // Remove item
    if (isset($_POST['remove_item'])) {
        $product_id = (int)$_POST['remove_item'];
        if (isset($_SESSION['cart'][$product_id])) {
            unset($_SESSION['cart'][$product_id]);
        }
    }
    
    // Clear cart
    if (isset($_POST['clear_cart'])) {
        $_SESSION['cart'] = array();
    }
}

// Include product integration
require_once 'includes/get_products.php';
?>

<main>
    <div class="container">
        <div class="container">
            <h1>Your Cart</h1>
            
            <?php if (empty($_SESSION['cart'])): ?>
                <div class="empty-cart">
                    <h2>Your cart is empty</h2>
                    <p>Browse our products and add items to your cart!</p>
                    <a href="index.php" class="button continue-shopping green-button">View Products</a>
                </div>
            <?php else: ?>
                <form method="post" action="">
                    <table class="cart-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Price</th>
                                <th>Quantity</th>
                                <th>Total</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $cart_total = 0;
                            foreach ($_SESSION['cart'] as $product_id => $quantity) :
                                $product = get_product_details($product_id);
                                $item_total = $product['price'] * $quantity;
                                $cart_total += $item_total;
                            ?>
                            <tr>
                                <td data-label="Product">
                                    <div class="cart-product">
                                        <img src="<?php echo htmlspecialchars(process_image_url($product['image'])); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>" class="cart-item-image">
                                        <span><?php echo $product['name']; ?></span>
                                    </div>
                                </td>
                                <td data-label="Price"><?php echo display_currency($product['price']); ?></td>
                                <td data-label="Quantity">
                                    <input type="number" name="quantity[<?php echo $product_id; ?>]" value="<?php echo $quantity; ?>" min="1" class="quantity-input">
                                </td>
                                <td data-label="Total"><?php echo display_currency($item_total); ?></td>
                                <td data-label="Action">
                                    <button type="submit" name="remove_item" value="<?php echo $product_id; ?>" class="remove-button">Remove</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <tr>
                                <td colspan="3" style="text-align: right;"><strong>Total:</strong></td>
                                <td colspan="2"><strong><?php echo display_currency($cart_total); ?></strong></td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <div class="cart-actions">
                        <button type="submit" name="update_cart" class="button update-button green-button">Update Cart</button>
                        <a href="index.php" class="button continue-button green-button">Continue Shopping</a>
                        <?php if (!empty($_SESSION['cart'])): ?>
                        <a href="checkout.php" class="button checkout-button green-button">Proceed to Checkout</a>
                        <?php endif; ?>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</main>

<?php require_once 'includes/footer.php'; ?>

<style>
.cart-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 30px;
}
.cart-table th, .cart-table td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid #ddd;
}
.cart-product {
    display: flex;
    align-items: center;
}
.cart-product img {
    width: 60px;
    height: 60px;
    object-fit: cover;
    margin-right: 15px;
    border-radius: 4px;
}
.quantity-input {
    width: 60px;
    padding: 5px;
}
.remove-button {
    background-color: #f44336;
    color: white;
    border: none;
    padding: 5px 10px;
    border-radius: 4px;
    cursor: pointer;
}
.empty-cart {
    text-align: center;
    padding: 40px;
    background-color: #f9f9f9;
    border-radius: 8px;
}
.cart-actions {
    display: flex;
    justify-content: space-between;
    margin-top: 20px;
}
.button-group {
    display: flex;
    gap: 10px;
}
.continue-shopping {
    background-color: #607d8b;
}
.update-cart {
    background-color: #3f51b5;
}
.clear-cart {
    background-color: #757575;
}
.checkout {
    background-color: #4caf50;
}

/* Green button styling */
.green-button {
    background-color: #4CAF50 !important;
    color: white !important;
    border: none !important;
    transition: background-color 0.3s ease !important;
    padding: 12px 24px !important;  /* Increased padding */
    font-size: 16px !important;     /* Larger font */
    font-weight: bold !important;   /* Bold text */
    border-radius: 4px !important;  /* Rounded corners */
    text-decoration: none !important;
    display: inline-block !important;
    text-align: center !important;
    cursor: pointer !important;
    min-width: 140px !important;    /* Ensure minimum width */
}

.green-button:hover {
    background-color: #45a049 !important;
    transform: translateY(-2px) !important;  /* Slight lift effect on hover */
    box-shadow: 0 4px 8px rgba(0,0,0,0.1) !important;  /* Shadow for depth */
}

/* Individual button adjustments */
.update-button {
    margin-right: 10px;
}

.checkout-button {
    font-weight: bold;
}

.cart-actions {
    display: flex;
    gap: 15px;  /* Increased gap for more spacing */
    margin-top: 25px;
    justify-content: center;  /* Center the buttons */
}
</style>