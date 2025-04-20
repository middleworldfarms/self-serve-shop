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
    <div class="container" style="margin-left: 100px; margin-right: 100px;">
        <h1>Your Cart</h1>
        
        <?php if (empty($_SESSION['cart'])): ?>
            <div class="empty-cart">
                <h2>Your cart is empty</h2>
                <p>Browse our products and add items to your cart!</p>
                <a href="index.php" class="button continue-shopping">View Products</a>
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
                            <td>
                                <div class="cart-product">
                                    <img src="<?php echo $product['image']; ?>" alt="<?php echo $product['name']; ?>">
                                    <span><?php echo $product['name']; ?></span>
                                </div>
                            </td>
                            <td><?php echo display_currency($product['price']); ?></td>
                            <td>
                                <input type="number" name="quantity[<?php echo $product_id; ?>]" value="<?php echo $quantity; ?>" min="1" class="quantity-input">
                            </td>
                            <td><?php echo display_currency($item_total); ?></td>
                            <td>
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
                    <div class="button-group">
                        <a href="index.php" class="button continue-shopping">Continue Shopping</a>
                        <button type="submit" name="clear_cart" class="button clear-cart">Clear Cart</button>
                    </div>
                    <div class="button-group">
                        <button type="submit" name="update_cart" class="button update-cart">Update Cart</button>
                        <a href="checkout.php" class="button checkout">Proceed to Checkout</a>
                    </div>
                </div>
            </form>
        <?php endif; ?>
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
</style>