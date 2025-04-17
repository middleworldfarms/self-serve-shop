<?php
// Add to the top of each admin file
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

// Add CSRF protection
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// filepath: /var/www/vhosts/middleworldfarms.org/self-serve-shop/admin/add-product.php
require_once '../config.php';

// Check if user is logged in as admin
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

$message = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
    $price = isset($_POST['price']) ? (float)$_POST['price'] : 0;
    $regular_price = isset($_POST['regular_price']) ? (float)$_POST['regular_price'] : 0;
    $sale_price = isset($_POST['sale_price']) && !empty($_POST['sale_price']) ? (float)$_POST['sale_price'] : null;
    $status = isset($_POST['status']) ? ($_POST['status'] === 'active' ? 'active' : 'inactive') : 'active';
    
    // Validate inputs
    if (empty($name)) {
        $error = "Product name is required.";
    } elseif ($price <= 0 && $regular_price <= 0) {
        $error = "Product price must be greater than zero.";
    } else {
        try {
            $db = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, 
                DB_USER, 
                DB_PASS
            );
            
            // Process image upload if present
            $image_url = null;
            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $uploads_dir = '../uploads/';
                
                // Create uploads directory if it doesn't exist
                if (!is_dir($uploads_dir)) {
                    mkdir($uploads_dir, 0755, true);
                }
                
                // Generate unique filename
                $tmp_name = $_FILES['image']['tmp_name'];
                $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
                $filename = uniqid('product_') . '.' . $ext;
                $target_file = $uploads_dir . $filename;
                
                if (move_uploaded_file($tmp_name, $target_file)) {
                    $image_url = 'uploads/' . $filename; // Relative URL
                }
            }
            
            if (defined('DB_TYPE') && DB_TYPE === 'standalone') {
                // Standalone mode - Insert into our custom products table
                $stmt = $db->prepare("
                    INSERT INTO sss_products (name, description, price, regular_price, sale_price, image, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                
                // Use price as regular_price if regular_price is not set
                if ($regular_price <= 0) {
                    $regular_price = $price;
                }
                
                // If price is not set, use regular_price
                if ($price <= 0) {
                    $price = $regular_price;
                }
                
                $stmt->execute([$name, $description, $price, $regular_price, $sale_price, $image_url, $status]);
                $message = "Product added successfully.";
            } else {
                // WordPress mode - Insert into WP posts and postmeta tables
                $post_status = $status === 'active' ? 'publish' : 'draft';
                
                // First, insert the post
                $stmt = $db->prepare("
                    INSERT INTO " . TABLE_PREFIX . "posts 
                    (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_name, post_modified, post_modified_gmt, post_parent, menu_order, post_type) 
                    VALUES (1, NOW(), NOW(), ?, ?, ?, ?, 'closed', 'closed', ?, NOW(), NOW(), 0, 0, 'product')
                ");
                
                $post_name = sanitize_title($name); // Convert title to slug
                $stmt->execute([$description, $name, '', $post_status, $post_name]);
                $product_id = $db->lastInsertId();
                
                // Now add product meta
                $meta_keys = [
                    '_regular_price' => $regular_price,
                    '_price' => $price
                ];
                
                if (!empty($sale_price)) {
                    $meta_keys['_sale_price'] = $sale_price;
                }
                
                foreach ($meta_keys as $key => $value) {
                    $stmt = $db->prepare("
                        INSERT INTO " . TABLE_PREFIX . "postmeta 
                        (post_id, meta_key, meta_value) 
                        VALUES (?, ?, ?)
                    ");
                    $stmt->execute([$product_id, $key, $value]);
                }
                
                // Set product as virtual (no shipping)
                $stmt = $db->prepare("
                    INSERT INTO " . TABLE_PREFIX . "postmeta 
                    (post_id, meta_key, meta_value) 
                    VALUES (?, '_virtual', 'yes')
                ");
                $stmt->execute([$product_id]);
                
                // Set visibility
                $stmt = $db->prepare("
                    INSERT INTO " . TABLE_PREFIX . "postmeta 
                    (post_id, meta_key, meta_value) 
                    VALUES (?, '_visibility', 'visible')
                ");
                $stmt->execute([$product_id]);
                
                // Add image if uploaded
                if ($image_url) {
                    // First, create an attachment post for the image
                    $stmt = $db->prepare("
                        INSERT INTO " . TABLE_PREFIX . "posts 
                        (post_author, post_date, post_date_gmt, post_title, post_status, comment_status, ping_status, post_name, post_modified, post_modified_gmt, post_parent, menu_order, post_type) 
                        VALUES (1, NOW(), NOW(), ?, ?, 'inherit', 'closed', 'closed', ?, NOW(), NOW(), ?, 0, 'attachment')
                    ");
                    $image_title = sanitize_title(basename($image_url));
                    $stmt->execute([$image_title, $image_title, $product_id]);
                    $attachment_id = $db->lastInsertId();
                    
                    // Add attachment metadata
                    $stmt = $db->prepare("
                        INSERT INTO " . TABLE_PREFIX . "postmeta 
                        (post_id, meta_key, meta_value) 
                        VALUES (?, '_wp_attached_file', ?)
                    ");
                    $stmt->execute([$attachment_id, $image_url]);
                    
                    // Associate attachment with product
                    $stmt = $db->prepare("
                        INSERT INTO " . TABLE_PREFIX . "postmeta 
                        (post_id, meta_key, meta_value) 
                        VALUES (?, '_thumbnail_id', ?)
                    ");
                    $stmt->execute([$product_id, $attachment_id]);
                }
                
                $message = "Product added successfully.";
            }
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}

// Handle product deletion
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $product_id = (int)$_GET['delete'];
    
    try {
        $db = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, 
            DB_USER, 
            DB_PASS
        );
        
        if (defined('DB_TYPE') && DB_TYPE === 'standalone') {
            // Standalone mode - delete from our custom products table
            $stmt = $db->prepare("DELETE FROM sss_products WHERE id = ?");
            $stmt->execute([$product_id]);
        } else {
            // WordPress mode - mark product as trash in WP posts
            $stmt = $db->prepare("UPDATE " . TABLE_PREFIX . "posts SET post_status = 'trash' WHERE ID = ? AND post_type = 'product'");
            $stmt->execute([$product_id]);
        }
        
        $message = "Product deleted successfully.";
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
}

// Handle bulk actions
if (isset($_POST['bulk_action']) && isset($_POST['product_ids']) && is_array($_POST['product_ids'])) {
    $action = $_POST['bulk_action'];
    $product_ids = array_map('intval', $_POST['product_ids']);
    
    if (!empty($product_ids)) {
        try {
            $db = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, 
                DB_USER, 
                DB_PASS
            );
            
            if ($action === 'delete') {
                if (defined('DB_TYPE') && DB_TYPE === 'standalone') {
                    // Standalone mode - delete from our custom products table
                    $placeholders = str_repeat('?,', count($product_ids) - 1) . '?';
                    $stmt = $db->prepare("DELETE FROM sss_products WHERE id IN ($placeholders)");
                    $stmt->execute($product_ids);
                } else {
                    // WordPress mode - mark products as trash in WP posts
                    $placeholders = str_repeat('?,', count($product_ids) - 1) . '?';
                    $stmt = $db->prepare("UPDATE " . TABLE_PREFIX . "posts SET post_status = 'trash' WHERE ID IN ($placeholders) AND post_type = 'product'");
                    $stmt->execute($product_ids);
                }
                
                $message = count($product_ids) . " products deleted successfully.";
            } elseif ($action === 'activate' || $action === 'deactivate') {
                $status = ($action === 'activate') ? 'publish' : 'draft';
                
                if (defined('DB_TYPE') && DB_TYPE === 'standalone') {
                    // Standalone mode - update status in our custom products table
                    $status_value = ($action === 'activate') ? 'active' : 'inactive';
                    $placeholders = str_repeat('?,', count($product_ids) - 1) . '?';
                    $stmt = $db->prepare("UPDATE sss_products SET status = ? WHERE id IN ($placeholders)");
                    array_unshift($product_ids, $status_value);
                    $stmt->execute($product_ids);
                } else {
                    // WordPress mode - update post_status in WP posts
                    $placeholders = str_repeat('?,', count($product_ids) - 1) . '?';
                    $stmt = $db->prepare("UPDATE " . TABLE_PREFIX . "posts SET post_status = ? WHERE ID IN ($placeholders) AND post_type = 'product'");
                    array_unshift($product_ids, $status);
                    $stmt->execute($product_ids);
                }
                
                $message = count($product_ids) . " products " . ($action === 'activate' ? 'activated' : 'deactivated') . " successfully.";
            }
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}

// Get products
try {
    $db = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, 
        DB_USER, 
        DB_PASS
    );
    
    if (defined('DB_TYPE') && DB_TYPE === 'standalone') {
        // Standalone mode - get from our custom products table
        $stmt = $db->query("SELECT * FROM sss_products ORDER BY name ASC");
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // WordPress mode - get from WP posts and postmeta
        $stmt = $db->query("
            SELECT 
                p.ID as id, 
                p.post_title as name,
                p.post_status as status,
                p.post_date as created_at,
                MAX(CASE WHEN pm.meta_key = '_regular_price' THEN pm.meta_value END) as regular_price,
                MAX(CASE WHEN pm.meta_key = '_sale_price' THEN pm.meta_value END) as sale_price,
                MAX(CASE WHEN pm.meta_key = '_price' THEN pm.meta_value END) as price,
                MAX(CASE WHEN pm.meta_key = '_thumbnail_id' THEN pm.meta_value END) as thumbnail_id
            FROM " . TABLE_PREFIX . "posts p
            LEFT JOIN " . TABLE_PREFIX . "postmeta pm ON p.ID = pm.post_id
            WHERE p.post_type = 'product'
            AND p.post_status IN ('publish', 'draft', 'pending')
            GROUP BY p.ID
            ORDER BY p.post_title ASC
        ");
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Convert WordPress status to our format
        foreach ($products as &$product) {
            $product['status'] = $product['status'] === 'publish' ? 'active' : 'inactive';
            
            // Use sale price if available, otherwise regular price
            if (!empty($product['sale_price'])) {
                $product['price'] = $product['sale_price'];
            } elseif (!empty($product['regular_price'])) {
                $product['price'] = $product['regular_price'];
            }
        }
    }
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
    $products = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Products - <?php echo SITE_TITLE; ?></title>
    <link rel="stylesheet" href="../css/styles.css">
    <style>
        .product-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .product-table th, .product-table td {
            padding: 10px;
            border: 1px solid #ddd;
            text-align: left;
        }
        .product-table th {
            background-color: #f5f5f5;
            font-weight: bold;
        }
        .product-table tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .status-active {
            color: #4CAF50;
            font-weight: bold;
        }
        .status-inactive {
            color: #F44336;
        }
        .product-image {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 4px;
        }
        .actions {
            display: flex;
            gap: 5px;
        }
        .edit-button {
            background-color: #2196F3;
        }
        .delete-button {
            background-color: #F44336;
        }
        .bulk-actions {
            margin-bottom: 15px;
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .success-message {
            background-color: #DFF2BF;
            color: #4F8A10;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .error-message {
            background-color: #FFDDDD;
            color: #D8000C;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .admin-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <header>
        <h1>Manage Products</h1>
        <nav>
            <a href="index.php" class="back-link">← Back to Dashboard</a>
            <a href="index.php?logout=1" class="logout-link">Logout</a>
        </nav>
    </header>
    
    <main>
        <div class="admin-container">
            <?php if ($message): ?>
                <div class="success-message"><?php echo $message; ?></div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="error-message"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <div class="admin-buttons">
                <a href="add-product.php" class="button">Add New Product</a>
                <a href="shop-view.php" class="button" target="_blank">Preview Shop</a>
            </div>
            
            <?php if (!empty($products)): ?>
                <form method="post" action="" id="products-form">
                    <div class="bulk-actions">
                        <select name="bulk_action">
                            <option value="">Bulk Actions</option>
                            <option value="activate">Activate</option>
                            <option value="deactivate">Deactivate</option>
                            <option value="delete">Delete</option>
                        </select>
                        <button type="submit" class="button" onclick="return confirm('Are you sure you want to perform this action on the selected products?')">Apply</button>
                    </div>
                    
                    <table class="product-table">
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="select-all"></th>
                                <th>Image</th>
                                <th>Name</th>
                                <th>Price</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $product): ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" name="product_ids[]" value="<?php echo $product['id']; ?>" class="product-checkbox">
                                    </td>
                                    <td>
                                        <?php 
                                        $image_url = '';
                                        if (defined('DB_TYPE') && DB_TYPE === 'standalone') {
                                            $image_url = !empty($product['image']) ? '../' . $product['image'] : '../img/placeholder.jpg';
                                        } else {
                                            // For WordPress, check if thumbnail exists
                                            if (!empty($product['thumbnail_id'])) {
                                                try {
                                                    $img_stmt = $db->prepare("
                                                        SELECT guid FROM " . TABLE_PREFIX . "posts 
                                                        WHERE ID = ? AND post_type = 'attachment'
                                                    ");
                                                    $img_stmt->execute([$product['thumbnail_id']]);
                                                    $image_url = $img_stmt->fetchColumn();
                                                } catch (PDOException $e) {
                                                    $image_url = '../img/placeholder.jpg';
                                                }
                                            } else {
                                                $image_url = '../img/placeholder.jpg';
                                            }
                                        }
                                        ?>
                                        <img src="<?php echo htmlspecialchars($image_url); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>" class="product-image">
                                    </td>
                                    <td><?php echo htmlspecialchars($product['name']); ?></td>
                                    <td><?php echo display_currency($product['price']); ?></td>
                                    <td>
                                        <span class="status-<?php echo $product['status']; ?>">
                                            <?php echo ucfirst($product['status']); ?>
                                        </span>
                                    </td>
                                    <td class="actions">
                                        <a href="edit-product.php?id=<?php echo $product['id']; ?>" class="button edit-button">Edit</a>
                                        <a href="?delete=<?php echo $product['id']; ?>" class="button delete-button" onclick="return confirm('Are you sure you want to delete this product?')">Delete</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </form>
            <?php else: ?>
                <p>No products found. <a href="add-product.php">Add your first product</a>.</p>
            <?php endif; ?>
        </div>
    </main>
    
    <footer>
        <p>&copy; <?php echo date('Y'); ?> <?php echo SITE_TITLE; ?>. All rights reserved.</p>
    </footer>
    
    <script>
        // Select all checkbox functionality
        document.getElementById('select-all').addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.product-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
        });
    </script>
</body>
</html>