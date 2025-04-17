<?php
require_once 'config.php';

// Initialize cart if not exists
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = array();
}

// Handle adding items to cart
if (isset($_POST['add_to_cart']) && isset($_POST['product_id'])) {
    $product_id = intval($_POST['product_id']);
    $quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 1;
    
    if (isset($_SESSION['cart'][$product_id])) {
        $_SESSION['cart'][$product_id] += $quantity;
    } else {
        $_SESSION['cart'][$product_id] = $quantity;
    }
}

// Handle login
if (isset($_POST['login'])) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    try {
        $db = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME,
            DB_USER,
            DB_PASS
        );
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        error_log("Login attempt: Username = $username");

        $stmt = $db->prepare("SELECT id, username, password, role FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            error_log("User found: " . print_r($user, true));

            if (password_verify($password, $user['password'])) {
                error_log("Password verified successfully.");
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_user_id'] = $user['id'];
                $_SESSION['admin_username'] = $user['username'];
                header('Location: dashboard.php');
                exit;
            } else {
                error_log("Password verification failed.");
                $login_error = "Invalid username or password.";
            }
        } else {
            error_log("User not found.");
            $login_error = "Invalid username or password.";
        }
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        $login_error = "Database error: " . $e->getMessage();
    }
}

// Update your localize_image_url function to be more robust:
function localize_image_url($url) {
    // If empty or null URL, return default image
    if (empty($url)) {
        return '/admin/uploads/Shopping bag.png';
    }
    
    // If already using admin/uploads, return as is
    if (strpos($url, '/admin/uploads/') === 0) {
        return $url;
    }
    
    // For any other URL, extract filename and use admin/uploads path
    $filename = basename(parse_url($url, PHP_URL_PATH));
    
    // Ensure valid filename
    if (empty($filename) || $filename === '/' || strlen($filename) > 255) {
        return '/admin/uploads/Shopping bag.png';
    }
    
    // Return standardized path
    return '/admin/uploads/' . $filename;
}

// Add this function to index.php to ensure all images go to the same directory
function ensure_image_path($url) {
    // If this is already a local path, return it
    if (strpos($url, '/uploads/') === 0 || strpos($url, '/images/') === 0) {
        return $url;
    }
    
    // Extract filename
    $filename = basename(parse_url($url, PHP_URL_PATH));
    
    // Store all product images in /images directory
    $local_path = __DIR__ . '/images/' . $filename;
    $web_path = '/images/' . $filename;
    
    // If image doesn't exist locally, download it
    if (!file_exists($local_path) && filter_var($url, FILTER_VALIDATE_URL)) {
        // Create directory if needed
        if (!is_dir(__DIR__ . '/images/')) {
            mkdir(__DIR__ . '/images/', 0755, true);
        }
        
        // Try to download the image
        $image_data = file_get_contents($url);
        if ($image_data !== false) {
            file_put_contents($local_path, $image_data);
        }
    }
    
    return $web_path;
}

// Get products directly from database (skip the include file)
function get_direct_products() {
    try {
        $prefix = TABLE_PREFIX;
        $posts_table = $prefix . 'posts';
        $postmeta_table = $prefix . 'postmeta';
        $term_relationships = $prefix . 'term_relationships';
        $term_taxonomy = $prefix . 'term_taxonomy';
        $terms = $prefix . 'terms';
        
        $db = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, 
            DB_USER, 
            DB_PASS
        );
        
        // Get products with prices in a single query
        $products_stmt = $db->query("
            SELECT 
                p.ID, 
                p.post_title,
                thumbnail.meta_value as thumbnail_id,
                MAX(CASE WHEN pm.meta_key = '_regular_price' THEN pm.meta_value END) as regular_price,
                MAX(CASE WHEN pm.meta_key = '_sale_price' THEN pm.meta_value END) as sale_price,
                MAX(CASE WHEN pm.meta_key = '_price' THEN pm.meta_value END) as price
            FROM `{$posts_table}` p
            LEFT JOIN `{$postmeta_table}` thumbnail ON p.ID = thumbnail.post_id 
                AND thumbnail.meta_key = '_thumbnail_id'
            LEFT JOIN `{$postmeta_table}` pm ON p.ID = pm.post_id
                AND pm.meta_key IN ('_regular_price', '_sale_price', '_price')
            WHERE p.post_type = 'product'
            AND p.post_status = 'publish'
            AND p.post_parent = 0
            GROUP BY p.ID, p.post_title, thumbnail.meta_value
            ORDER BY p.post_title ASC
        ");
        
        $products = [];
        
        while ($product = $products_stmt->fetch(PDO::FETCH_ASSOC)) {
            // Use sale price if available, otherwise regular price, then fallback to _price
            $price_value = null;
            if (!empty($product['sale_price'])) {
                $price_value = $product['sale_price'];
            } elseif (!empty($product['regular_price'])) {
                $price_value = $product['regular_price'];
            } elseif (!empty($product['price'])) {
                $price_value = $product['price'];
            }
            
            // Debug info - log to see product details
            error_log("Product: {$product['post_title']} (ID: {$product['ID']}) - Price: " . 
                      var_export($price_value, true) . 
                      " (Regular: {$product['regular_price']}, Sale: {$product['sale_price']}, Price: {$product['price']})");
            
            // Add this debugging right after you fetch the thumbnail_id
            error_log("Product: {$product['post_title']} (ID: {$product['ID']}) - Thumbnail ID: " . 
                      var_export($product['thumbnail_id'], true));
            
            // Skip products without valid prices
            if (!is_numeric($price_value) || floatval($price_value) <= 0) {
                error_log("Skipping product {$product['post_title']} - Invalid price: " . var_export($price_value, true));
                continue;
            }
            
            // Get image URL
            $image_url = null;
            if ($product['thumbnail_id']) {
                $image_stmt = $db->prepare("
                    SELECT guid
                    FROM `{$posts_table}`
                    WHERE ID = ?
                ");
                $image_stmt->execute([$product['thumbnail_id']]);
                $image_url = $image_stmt->fetchColumn();
                error_log("Image lookup for ID {$product['thumbnail_id']} returned: " . var_export($image_url, true));
                
                if (!empty($image_url)) {
                    $image_url = localize_image_url($image_url);
                    error_log("Localized image URL: " . $image_url);
                } else {
                    error_log("No image found for thumbnail ID: {$product['thumbnail_id']}");
                }
            }
            
            // Add product to the array
            $products[] = [
                'id' => $product['ID'],
                'price' => floatval($price_value),
                'name' => $product['post_title'],
                'image' => $image_url ? $image_url : '/admin/uploads/Shopping bag.png'
            ];
        }
        
        error_log("Total products found: " . count($products));
        
        return $products;
    } catch (Exception $e) {
        error_log("Error getting products: " . $e->getMessage());
        return [];
    }
}

// Get products directly
$products = get_direct_products();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Middle World Farms Self-Serve Shop</title>
    <link rel="stylesheet" href="css/styles.css">
</head>
<body>
    <header>
        <h1>Middle World Farms Self-Serve Shop</h1>
        <img src="<?php echo htmlspecialchars(SHOP_LOGO); ?>" alt="<?php echo htmlspecialchars(SHOP_NAME); ?>" class="shop-logo">
        <a href="cart.php" class="cart-icon"><?php echo count($_SESSION['cart']); ?></a>
    </header>
    
    <main>
        <div class="product-grid">
            <?php foreach ($products as $product): ?>
            <div class="product-card" data-id="<?php echo $product['id']; ?>" style="height: 400px; display: flex; flex-direction: column; overflow: hidden;">
                <img src="<?php echo $product['image']; ?>" alt="<?php echo $product['name']; ?>" class="product-image" style="height: 180px; width: 100%; object-fit: cover;">
                <div class="product-info" style="padding: 15px; display: flex; flex-direction: column; flex-grow: 1; position: relative;">
                    <h3 class="product-name" style="margin-top: 0; overflow: hidden; max-height: 95px;"><?php echo $product['name']; ?></h3>
                    <div style="position: absolute; bottom: 15px; left: 15px; right: 15px;">
                        <p class="product-price" style="font-size: 18px; color: black; margin-bottom: 10px; font-weight: bold;">
                            <?php 
                            // Directly use the price from the product array
                            if (isset($product['price']) && is_numeric($product['price']) && $product['price'] > 0) {
                                // Format and display the price
                                echo '£' . number_format((float)$product['price'], 2);
                            } else {
                                // Display an error message if the price is invalid
                                echo '<span style="color: red;">Price not available</span>';
                            }
                            ?>
                        </p>
                        <button class="add-to-cart" data-id="<?php echo $product['id']; ?>" style="width: 100%; padding: 8px 0; background-color: #4CAF50; color: white; border: none; border-radius: 4px; cursor: pointer;">Add to Cart</button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </main>
    
    <div id="product-modal" class="modal">
        <div class="modal-content">
            <span class="close-modal">&times;</span>
            <div id="modal-product-details"></div>
        </div>
    </div>
    
    <footer>
        <p>&copy; <?php echo date('Y'); ?> Middle World Farms. All rights reserved.</p>
    </footer>

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
        
        // Add to cart functionality
        document.querySelectorAll('.add-to-cart').forEach(button => {
            button.addEventListener('click', function(e) {
                e.stopPropagation();
                const productId = this.getAttribute('data-id');
                
                const form = document.createElement('form');
                form.method = 'post';
                form.action = '';
                
                const productIdInput = document.createElement('input');
                productIdInput.type = 'hidden';
                productIdInput.name = 'product_id';
                productIdInput.value = productId;
                
                const addToCartInput = document.createElement('input');
                addToCartInput.type = 'hidden';
                addToCartInput.name = 'add_to_cart';
                addToCartInput.value = '1';
                
                form.appendChild(productIdInput);
                form.appendChild(addToCartInput);
                document.body.appendChild(form);
                form.submit();
            });
        });
        
        // Close modal when clicking outside
        window.addEventListener('click', function(e) {
            const modal = document.getElementById('product-modal');
            if (e.target === modal) {
                modal.style.display = 'none';
            }
        });
    </script>
</body>
</html>
