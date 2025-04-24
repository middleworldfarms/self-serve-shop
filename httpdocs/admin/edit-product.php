<?php
// filepath: /var/www/vhosts/middleworldfarms.org/self-serve-shop/admin/edit-product.php
require_once '../config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// Authentication check
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

// CSRF protection
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

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

$message = '';
$error = '';
$product = null;

// Check if product ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: manage-products.php');
    exit;
}

$product_id = (int)$_GET['id'];

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
            // Process image upload if present
            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../uploads/products/';
                
                // Ensure directory exists
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                // Process the file
                $filename = time() . '_' . bin2hex(random_bytes(4)) . '_' . basename($_FILES['image']['name']);
                $target = $upload_dir . $filename;
                
                if (move_uploaded_file($_FILES['image']['tmp_name'], $target)) {
                    $product['image'] = 'uploads/products/' . $filename;
                } else {
                    $error = "Could not upload image.";
                }
            }
            
            if (defined('DB_TYPE') && DB_TYPE === 'standalone') {
                // Standalone mode - Update our custom products table
                
                // Use price as regular_price if regular_price is not set
                if ($regular_price <= 0) {
                    $regular_price = $price;
                }
                
                // If price is not set, use regular_price
                if ($price <= 0) {
                    $price = $regular_price;
                }
                
                $sql = "UPDATE sss_products SET name = ?, description = ?, price = ?, regular_price = ?, sale_price = ?, status = ?";
                $params = [$name, $description, $price, $regular_price, $sale_price, $status];
                
                if (!empty($product['image'])) {
                    $sql .= ", image = ?";
                    $params[] = $product['image'];
                }
                
                $sql .= " WHERE id = ?";
                $params[] = $product_id;
                
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                
                $message = "Product updated successfully.";
            } else {
                // WordPress mode - Update WP posts and postmeta tables
                $post_status = $status === 'active' ? 'publish' : 'draft';
                
                // Update post
                $stmt = $db->prepare("UPDATE " . TABLE_PREFIX . "posts SET post_title = ?, post_content = ?, post_status = ? WHERE ID = ?");
                $stmt->execute([$name, $description, $post_status, $product_id]);
                
                // Update postmeta
                // Price (used as display price)
                $update_meta = function($meta_key, $meta_value) use ($db, $product_id) {
                    if ($meta_value === null) {
                        // Delete meta if value is null
                        $stmt = $db->prepare("DELETE FROM " . TABLE_PREFIX . "postmeta WHERE post_id = ? AND meta_key = ?");
                        $stmt->execute([$product_id, $meta_key]);
                    } else {
                        // Check if meta exists
                        $stmt = $db->prepare("SELECT meta_id FROM " . TABLE_PREFIX . "postmeta WHERE post_id = ? AND meta_key = ?");
                        $stmt->execute([$product_id, $meta_key]);
                        
                        if ($stmt->fetch()) {
                            // Update existing meta
                            $stmt = $db->prepare("UPDATE " . TABLE_PREFIX . "postmeta SET meta_value = ? WHERE post_id = ? AND meta_key = ?");
                            $stmt->execute([$meta_value, $product_id, $meta_key]);
                        } else {
                            // Insert new meta
                            $stmt = $db->prepare("INSERT INTO " . TABLE_PREFIX . "postmeta (post_id, meta_key, meta_value) VALUES (?, ?, ?)");
                            $stmt->execute([$product_id, $meta_key, $meta_value]);
                        }
                    }
                }; // Add this closing semicolon for the function expression
                
                $update_meta('_price', $price);
                $update_meta('_regular_price', $regular_price);
                $update_meta('_sale_price', $sale_price);
                
                // Update _price meta based on whether product is on sale
                if ($sale_price !== null && $sale_price > 0) {
                    $update_meta('_price', $sale_price);
                } else {
                    $update_meta('_price', $regular_price);
                }
                
                // Handle image upload for WordPress
                if (!empty($product['image'])) {
                    // First create an attachment post for the image
                    $stmt = $db->prepare("INSERT INTO " . TABLE_PREFIX . "posts (post_author, post_date, post_date_gmt, post_title, post_status, comment_status, ping_status, post_name, post_type, guid) VALUES (1, NOW(), NOW(), ?, 'inherit', 'closed', 'closed', ?, 'attachment', ?)");
                    $image_title = "Product image for $name";
                    $image_name = sanitize_title($image_title);
                    $image_guid = site_url() . '/' . $product['image'];
                    $stmt->execute([$image_title, $image_name, $image_guid]);
                    
                    $attachment_id = $db->lastInsertId();
                    
                    // Add meta for the attachment
                    $stmt = $db->prepare("INSERT INTO " . TABLE_PREFIX . "postmeta (post_id, meta_key, meta_value) VALUES (?, '_wp_attached_file', ?)");
                    $stmt->execute([$attachment_id, $product['image']]);
                    
                    // Set as featured image (product thumbnail)
                    $update_meta('_thumbnail_id', $attachment_id);
                }
                
                $message = "Product updated successfully.";
            }
            
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}

// Get the product data
try {
    if (defined('DB_TYPE') && DB_TYPE === 'standalone') {
        // Standalone mode
        $stmt = $db->prepare("SELECT * FROM sss_products WHERE id = ?");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        // WordPress mode
        $stmt = $db->prepare("
            SELECT p.ID, p.post_title as name, p.post_content as description, p.post_status,
                   MAX(CASE WHEN pm.meta_key = '_price' THEN pm.meta_value END) as price,
                   MAX(CASE WHEN pm.meta_key = '_regular_price' THEN pm.meta_value END) as regular_price,
                   MAX(CASE WHEN pm.meta_key = '_sale_price' THEN pm.meta_value END) as sale_price,
                   MAX(CASE WHEN pm.meta_key = '_thumbnail_id' THEN pm.meta_value END) as thumbnail_id
            FROM " . TABLE_PREFIX . "posts p
            LEFT JOIN " . TABLE_PREFIX . "postmeta pm ON p.ID = pm.post_id
            WHERE p.ID = ? AND p.post_type = 'product'
            GROUP BY p.ID
        ");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($product) {
            // Get product image
            if ($product['thumbnail_id']) {
                $stmt = $db->prepare("SELECT guid FROM " . TABLE_PREFIX . "posts WHERE ID = ?");
                $stmt->execute([$product['thumbnail_id']]);
                $image = $stmt->fetchColumn();
                $product['image'] = $image;
            }
            
            // Convert post_status to active/inactive
            $product['status'] = ($product['post_status'] === 'publish') ? 'active' : 'inactive';
        }
    }
    
    if (!$product) {
        header('Location: manage-products.php');
        exit;
    }
    
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
}

require_once 'includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Product - Middle World Farms Self-Serve Shop Admin</title>
    <link rel="stylesheet" href="../css/styles.css">
    <style>
        .product-form {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .image-preview {
            max-width: 200px;
            max-height: 200px;
            margin: 10px 0;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 5px;
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
    </style>
</head>
<body>
    <header>
        <h1>Edit Product</h1>
        <nav>
            <a href="manage-products.php" class="back-link">← Back to Products</a>
            <a href="index.php" class="back-link">Dashboard</a>
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
            
            <div class="product-form">
                <form method="post" action="" enctype="multipart/form-data">
                    <div class="form-row">
                        <label for="name">Product Name</label>
                        <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($product['name']); ?>" required>
                    </div>
                    
                    <div class="form-row">
                        <label for="description">Description</label>
                        <textarea id="description" name="description" rows="5"><?php echo htmlspecialchars($product['description']); ?></textarea>
                    </div>
                    
                    <div class="form-row">
                        <label for="regular_price">Regular Price (£)</label>
                        <input type="number" id="regular_price" name="regular_price" min="0" step="0.01" value="<?php echo htmlspecialchars($product['regular_price']); ?>" required>
                    </div>
                    
                    <div class="form-row">
                        <label for="sale_price">Sale Price (£, leave empty for no sale)</label>
                        <input type="number" id="sale_price" name="sale_price" min="0" step="0.01" value="<?php echo htmlspecialchars($product['sale_price'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-row">
                        <label for="status">Status</label>
                        <select id="status" name="status">
                            <option value="active" <?php echo $product['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $product['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    
                    <div class="form-row">
                        <label for="image">Product Image</label>
                        <?php if (!empty($product['image'])): ?>
                            <div>
                                <p>Current image:</p>
                                <img src="<?php echo htmlspecialchars(process_image_url($product['image'])); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>" class="image-preview">
                            </div>
                        <?php endif; ?>
                        <input type="file" id="image" name="image" accept="image/*">
                        <small>Upload a new image to replace the current one, or leave empty to keep it.</small>
                    </div>
                    
                    <button type="submit" class="button">Update Product</button>
                </form>
            </div>
        </div>
    </main>
    
    <footer>
        <p>&copy; <?php echo date('Y'); ?> Middle World Farms Self-Serve Shop. All rights reserved.</p>
    </footer>
</body>
</html>

<?php require_once 'includes/footer.php'; ?>