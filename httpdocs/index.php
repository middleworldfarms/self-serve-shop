<?php
require_once 'config.php';

// Initialize cart if not exists
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = array();
}

// Initialize cart_items if not exists
if (!isset($_SESSION['cart_items'])) {
    $_SESSION['cart_items'] = array();
}

// Handle adding items to cart
if (isset($_POST['add_to_cart']) && isset($_POST['product_id'])) {
    $product_id = intval($_POST['product_id']);
    $quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 1;
    
    // Get full product details including WooCommerce ID
    try {
        $db = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME,
            DB_USER,
            DB_PASS
        );
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $stmt = $db->prepare("SELECT id, name, price, regular_price, sale_price, image, woocommerce_id FROM sss_products WHERE id = ?");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($product) {
            // Update cart quantity
            if (isset($_SESSION['cart'][$product_id])) {
                $_SESSION['cart'][$product_id] += $quantity;
            } else {
                $_SESSION['cart'][$product_id] = $quantity;
            }
            
            // Store product details in cart_items
            $price = ($product['sale_price'] > 0) ? $product['sale_price'] : ($product['regular_price'] > 0 ? $product['regular_price'] : $product['price']);
            $_SESSION['cart_items'][$product_id] = [
                'id' => $product['id'],
                'name' => $product['name'],
                'price' => floatval($price),
                'quantity' => $quantity,
                'woocommerce_id' => $product['woocommerce_id'] // CRUCIAL: Include WooCommerce ID
            ];
        }
    } catch (Exception $e) {
        error_log("Error adding product to cart: " . $e->getMessage());
    }
    
    // Redirect to the same page (or to a specific page)
    // This creates a GET request in the browser history instead of a POST request
    header('Location: ' . $_SERVER['PHP_SELF'] . (isset($_GET['category']) ? '?category=' . urlencode($_GET['category']) : ''));
    exit;
}

// Include header AFTER cart has been updated
require_once 'includes/header.php';

// Get products directly from database
function get_direct_products() {
    try {
        $db = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME,
            DB_USER,
            DB_PASS
        );
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Add woocommerce_id to the SELECT statement
        $stmt = $db->query("SELECT id, name, price, regular_price, sale_price, image, woocommerce_id FROM sss_products WHERE status = 'active' ORDER BY name ASC");
        $products = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $price = ($row['sale_price'] > 0) ? $row['sale_price'] : ($row['regular_price'] > 0 ? $row['regular_price'] : $row['price']);
            $products[] = [
                'id' => $row['id'],
                'price' => floatval($price),
                'name' => $row['name'],
                'image' => $row['image'] ? $row['image'] : '/admin/uploads/Shopping bag.png',
                'woocommerce_id' => $row['woocommerce_id'] // CRUCIAL: Include WooCommerce ID
            ];
        }
        return $products;
    } catch (Exception $e) {
        return [];
    }
}

$products = get_direct_products();
?>

<main>
    <div class="product-grid">
        <?php foreach ($products as $product): ?>
        <div class="product-card" data-id="<?php echo $product['id']; ?>">
            <img src="<?php echo htmlspecialchars(process_image_url($product['image'])); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>" class="product-image">
            <div class="product-info">
                <h3 class="product-name"><?php echo $product['name']; ?></h3>
                <p class="product-price">
                    <?php 
                    if (isset($product['price']) && is_numeric($product['price']) && $product['price'] > 0) {
                        echo '£' . number_format((float)$product['price'], 2);
                    } else {
                        echo '<span style="color: red;">Price not available</span>';
                    }
                    ?>
                </p>
                <form method="post" action="">
                    <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                    <input type="hidden" name="add_to_cart" value="1">
                    <button type="submit">Add to Cart</button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</main>

<div id="product-modal" class="modal" style="display:none;">
    <div class="modal-content">
        <span class="close-modal">&times;</span>
        <div id="modal-product-details"></div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

<script>
    // Product modal functionality
    document.querySelectorAll('.product-card').forEach(card => {
        card.addEventListener('click', function(e) {
            if (!e.target.classList.contains('add-to-cart')) {
                const productId = this.getAttribute('data-id');
                fetch('product-detail.php?id=' + productId)
                    .then(response => response.text())
                    .then(html => {
                        document.getElementById('modal-product-details').innerHTML = html;
                        document.getElementById('product-modal').style.display = 'flex';
                    });
            }
        });
    });

    // Close modal
    document.querySelector('.close-modal').addEventListener('click', function() {
        document.getElementById('product-modal').style.display = 'none';
    });

    // Close modal when clicking outside
    window.addEventListener('click', function(e) {
        const modal = document.getElementById('product-modal');
        if (e.target === modal) {
            modal.style.display = 'none';
        }
    });
</script>
<style>
.product-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 28px;
    justify-content: flex-start;
    margin: 30px auto;
    max-width: 1200px;
}
.product-card {
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(60,72,88,0.08);
    padding: 18px 18px 14px 18px;
    width: 240px;
    display: flex;
    flex-direction: column;
    align-items: center;
}
.product-info {
    width: 100%;
    display: flex;
    flex-direction: column;
    flex-grow: 1;
    align-items: stretch;
}
.product-card form {
    width: 100%;
    margin-top: auto;
}
.product-card button[type="submit"] {
    width: 100%;
    padding: 10px 0;
    background-color: #4CAF50;
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 1em;
    box-sizing: border-box;
}
.product-image {
    width: 100%;
    max-width: 180px;
    height: 140px;
    object-fit: cover;
    border-radius: 6px;
    margin-bottom: 12px;
    background: #f8f8f8;
}
.product-card h3 {
    margin: 0 0 8px 0;
    font-size: 1.1em;
    text-align: center;
}
.product-price {
    font-size: 1.15em;
    font-weight: bold;
    color: #388E3C;
    margin-bottom: 10px;
}
</style>