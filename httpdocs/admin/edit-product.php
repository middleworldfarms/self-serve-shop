<?php
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
            
            if ($image_url) {
                $sql .= ", image = ?";
                $params[] = $image_url;
            }
            
            $sql .= " WHERE id = ?";
            $params[] = $product_id;
            
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            
            $message = "Product updated successfully.";
            
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}

// Get the product data
try {
    // Standalone mode
    $stmt = $db->prepare("SELECT * FROM sss_products WHERE id = ?");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$product) {
        header('Location: manage-products.php');
        exit;
    }
    
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
}

require_once 'includes/header.php';
?>

<style>
.admin-container {
    max-width: 900px;
    margin: 2rem auto;
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 4px 24px rgba(60,72,88,0.08), 0 1.5px 4px rgba(60,72,88,0.04);
    padding: 36px;
}
.product-form {
    width: 100%;
}
.form-row {
    margin-bottom: 20px;
}
.form-row label {
    display: block;
    margin-bottom: 5px;
    font-weight: bold;
}
.form-row input[type="text"],
.form-row input[type="number"],
.form-row textarea,
.form-row select {
    width: 100%;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 1em;
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
    background-color: #e8f5e9;
    color: #256029;
    padding: 12px 18px;
    border-radius: 4px;
    margin-bottom: 20px;
    font-weight: bold;
}
.error-message {
    background-color: #ffebee;
    color: #b71c1c;
    padding: 12px 18px;
    border-radius: 4px;
    margin-bottom: 20px;
    font-weight: bold;
}
.back-link {
    display: inline-block;
    margin: 15px 0;
    color: #1976D2;
    text-decoration: none;
}
.back-link:hover {
    text-decoration: underline;
}
button.button {
    background: #388E3C;
    color: white;
    border: none;
    padding: 12px 24px;
    font-size: 1em;
    border-radius: 4px;
    cursor: pointer;
    transition: background 0.2s;
}
button.button:hover {
    background: #256029;
}
</style>

<div class="admin-container">
    <h2>Edit Product</h2>
    
    <a href="manage-products.php" class="back-link">← Back to Products</a>
    
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

<?php require_once 'includes/footer.php'; ?>