<?php
// filepath: /var/www/vhosts/middleworldfarms.org/self-serve-shop/admin/manage-products.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

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

// Add this setting with value '1' to preserve status by default
$stmt = $db->prepare("INSERT IGNORE INTO self_serve_settings (setting_name, setting_value) VALUES (?, ?)");
$stmt->execute(['sync_preserve_status', '1']);

// Admin authentication
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

// CSRF protection
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Ensure products table exists
$db->query("
    CREATE TABLE IF NOT EXISTS sss_products (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        description TEXT,
        price DECIMAL(10,2) NOT NULL,
        regular_price DECIMAL(10,2) NOT NULL,
        sale_price DECIMAL(10,2) NULL,
        image VARCHAR(255),
        status ENUM('active', 'inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        woocommerce_id INT(11) NULL,
        woo_product_id INT(11) NULL
    ) ENGINE=InnoDB;
");

require_once 'includes/header.php';
?>

<style>
.admin-container {
    max-width: 1100px;
    margin: 2rem auto;
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 4px 24px rgba(60,72,88,0.08), 0 1.5px 4px rgba(60,72,88,0.04);
    padding: 36px 36px 24px 36px;
}
.admin-layout {
    display: flex;
    flex-wrap: nowrap;  /* Change from flex-wrap: wrap */
    gap: 24px;
}
.sidebar {
    flex: 0 0 30%;  /* Change from 40% to 30% */
    background: #f8f8f8;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    padding: 24px 18px;
    min-height: 400px;
    margin-bottom: 24px;
}
.sidebar-section {
    margin-bottom: 32px;
}
.sidebar-section h3 {
    font-size: 1.1em;
    margin-bottom: 10px;
    color: #388E3C;
}
.button, .add-product-button {
    display: inline-block;
    background: #388E3C;
    color: #fff !important;
    padding: 8px 18px;
    border: none;
    border-radius: 4px;
    text-decoration: none;
    margin-bottom: 10px;
    font-size: 1em;
    cursor: pointer;
    transition: background 0.2s;
}
.button:hover, .add-product-button:hover {
    background: #256029;
}
.bulk-actions-sidebar {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.select-all-container {
    margin-top: 8px;
}
.main-content {
    flex: 1;
    background: #fff;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    padding: 24px;
    min-width: 0;
}
.product-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 10px;
}
.product-table th, .product-table td {
    border-bottom: 1px solid #e0e0e0;
    padding: 10px 8px;
    text-align: left;
    vertical-align: middle;
}
.product-table th {
    background: #f4f4f4;
    font-weight: bold;
}

/* Change from center to left alignment */
th.actions-header {
    text-align: left;
}

.product-image {
    width: 60px;
    height: 60px;
    object-fit: cover;
    border-radius: 4px;
    background: #fafafa;
}
.status-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 0.95em;
    font-weight: bold;
}
.status-active {
    background: #e8f5e9;
    color: #256029;
}
.status-inactive {
    background: #ffebee;
    color: #b71c1c;
}
.actions .button {
    margin-right: 6px;
    margin-bottom: 0;
}
.edit-button {
    background: #1976D2;
}
.edit-button:hover {
    background: #125ea2;
}
.delete-button {
    background: #D32F2F;
}
.delete-button:hover {
    background: #a32121;
}
.edit-button, .delete-button {
    min-width: 65px;  /* Slightly smaller width */
    text-align: center;
    display: inline-block;
    box-sizing: border-box;
}

/* Add this new style for the actions column */
td.actions {
    text-align: center;  /* Center everything in the actions cell */
    padding-right: 10px; /* Add right padding to offset buttons */
}

/* And update the form styling */
td.actions form {
    display: inline-block;
    vertical-align: middle;
    margin: 0 2px;  /* Add small margin for spacing */
}

.success-message, .error-message {
    margin: 18px 0;
    padding: 12px 18px;
    border-radius: 4px;
    font-weight: bold;
}
.success-message { background: #e8f5e9; color: #256029; }
.error-message { background: #ffebee; color: #b71c1c; }
.empty-state {
    text-align: center;
    padding: 40px 0;
    color: #888;
}
@media (max-width: 900px) {
    .admin-container { margin: 20px 10px; }
    .admin-layout { flex-direction: column; }
    .sidebar { width: 100%; flex: none; margin-bottom: 18px; }  /* Add flex: none */
    .main-content { padding: 10px; }
}
.bulk-actions-container {
    background: #f9f9f9;
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    padding: 12px 16px;
    margin-bottom: 20px;
}

.bulk-actions-container h3 {
    font-size: 1.1em;
    margin-bottom: 10px;
    color: #388E3C;
}

.bulk-actions-row {
    display: flex;
    align-items: center;
    gap: 16px;
}

.bulk-action-select {
    display: flex;
    align-items: center;
    gap: 8px;

}

.select-all-container {
    display: flex;
    align-items: center;
    gap: 4px;
}
</style>

<?php
$message = '';
$error = '';

// WooCommerce API credentials
$settings = function_exists('get_settings') ? get_settings() : [];
$woo_url = rtrim($settings['shop_url'] ?? '', '/');
$woo_ck = $settings['woo_consumer_key'] ?? '';
$woo_cs = $settings['woo_consumer_secret'] ?? '';

// WooCommerce Import
if (isset($_POST['import_woocommerce'])) {
    $endpoint = $woo_url . '/wp-json/wc/v3/products?per_page=100';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, $woo_ck . ":" . $woo_cs);
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200 && $response) {
        $woo_products = json_decode($response, true);
        $imported = 0;
        if (is_array($woo_products)) {
            foreach ($woo_products as $product) {
                $woo_id = $product['id']; // Get the WooCommerce product ID
                $name = $product['name'];
                $description = $product['description'];
                $price = $product['price'];
                $regular_price = $product['regular_price'];
                $sale_price = $product['sale_price'];
                $image = !empty($product['images']) ? $product['images'][0]['src'] : '';
                
                // Check if product exists by name
                $stmt = $db->prepare("SELECT id FROM sss_products WHERE name = ?");
                $stmt->execute([$name]);
                
                if ($stmt->fetch()) {
                    // Update existing product
                    $stmt = $db->prepare("UPDATE sss_products SET woocommerce_id = ?, woo_product_id = ?, 
                                         price = ?, regular_price = ?, sale_price = ? WHERE name = ?");
                    $stmt->execute([$woo_id, $woo_id, $price, $regular_price, $sale_price, $name]);
                } else {
                    // Insert new product
                    $stmt = $db->prepare("INSERT INTO sss_products (name, woocommerce_id, woo_product_id, 
                                         description, price, regular_price, sale_price, image, status) 
                                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')");
                    $stmt->execute([$name, $woo_id, $woo_id, $description, $price, $regular_price, $sale_price, $image]);
                }
                $imported++;
            }
            $message = "$imported products imported from WooCommerce.";
        } else {
            $error = "No products found or invalid response from WooCommerce.";
        }
    } else {
        $error = "Failed to fetch products from WooCommerce. HTTP code: $http_code";
    }
}

// Handle bulk actions
if (isset($_POST['bulk_action']) && isset($_POST['selected_products']) && !empty($_POST['selected_products'])) {
    $action = $_POST['bulk_action'];
    $selected_products = $_POST['selected_products'];
    if (!is_array($selected_products)) {
        $selected_products = [$selected_products];
    }
    $selected_products = array_map('intval', $selected_products);

    try {
        if ($action === 'delete') {
            $count = 0;
            foreach ($selected_products as $product_id) {
                $stmt = $db->prepare("DELETE FROM sss_products WHERE id = ?");
                $stmt->execute([$product_id]);
                $count++;
            }
            $message = $count . " product(s) deleted successfully.";
        } elseif ($action === 'set_active' || $action === 'set_inactive') {
            $new_status = ($action === 'set_active') ? 'active' : 'inactive';
            $count = 0;
            foreach ($selected_products as $product_id) {
                $stmt = $db->prepare("UPDATE sss_products SET status = ? WHERE id = ?");
                $stmt->execute([$new_status, $product_id]);
                $count++;
            }
            $message = $count . " product(s) set to " . $new_status . " successfully.";
        }
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
}

// Handle CSV Import
if (isset($_POST['import_products']) && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file'];
    $fileType = pathinfo($file['name'], PATHINFO_EXTENSION);
    if (strtolower($fileType) === 'csv') {
        if ($file['error'] === UPLOAD_ERR_OK) {
            try {
                $handle = fopen($file['tmp_name'], 'r');
                $header = fgetcsv($handle);
                $header = array_map('trim', $header);
                $header = array_map('strtolower', $header);
                $columnMap = [];
                $requiredColumns = ['name', 'price'];
                $allColumns = ['name', 'description', 'price', 'regular_price', 'sale_price', 'image', 'status'];
                foreach ($allColumns as $column) {
                    $pos = array_search($column, $header);
                    if ($pos !== false) {
                        $columnMap[$column] = $pos;
                    }
                }
                $missingColumns = [];
                foreach ($requiredColumns as $column) {
                    if (!isset($columnMap[$column])) {
                        $missingColumns[] = $column;
                    }
                }
                if (!empty($missingColumns)) {
                    $error = "Required columns missing in CSV: " . implode(', ', $missingColumns);
                } else {
                    $count = 0;
                    $skipped = 0;
                    while (($data = fgetcsv($handle)) !== false) {
                        $name = isset($columnMap['name']) ? trim($data[$columnMap['name']]) : '';
                        $description = isset($columnMap['description']) ? trim($data[$columnMap['description']]) : '';
                        $price = isset($columnMap['price']) ? floatval(trim($data[$columnMap['price']])) : 0;
                        $regular_price = isset($columnMap['regular_price']) ? floatval(trim($data[$columnMap['regular_price']])) : $price;
                        $sale_price = isset($columnMap['sale_price']) ? floatval(trim($data[$columnMap['sale_price']])) : 0;
                        $image = isset($columnMap['image']) ? trim($data[$columnMap['image']]) : '';
                        $status = isset($columnMap['status']) ? trim($data[$columnMap['status']]) : 'active';
                        if (empty($name) || $price <= 0) {
                            $skipped++;
                            continue;
                        }
                        $status = strtolower($status);
                        if ($status !== 'active' && $status !== 'inactive') {
                            $status = 'active';
                        }
                        $stmt = $db->prepare("
                            INSERT INTO sss_products 
                            (name, description, price, regular_price, sale_price, image, status) 
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $name, 
                            $description, 
                            $price, 
                            $regular_price,
                            $sale_price > 0 ? $sale_price : null,
                            $image,
                            $status
                        ]);
                        $count++;
                    }
                    fclose($handle);
                    if ($count > 0) {
                        $message = "$count products imported successfully. ";
                        if ($skipped > 0) {
                            $message .= "$skipped products were skipped due to validation errors.";
                        }
                    } else {
                        $error = "No valid products found in the CSV file.";
                    }
                }
            } catch (PDOException $e) {
                $error = "Database error during import: " . $e->getMessage();
            } catch (Exception $e) {
                $error = "Error processing CSV file: " . $e->getMessage();
            }
        } else {
            $error = "Error uploading file: " . getFileUploadErrorMessage($file['error']);
        }
    } else {
        $error = "Please upload a CSV file.";
    }
}

function getFileUploadErrorMessage($errorCode) {
    switch ($errorCode) {
        case UPLOAD_ERR_INI_SIZE:
            return "The uploaded file exceeds the upload_max_filesize directive in php.ini.";
        case UPLOAD_ERR_FORM_SIZE:
            return "The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form.";
        case UPLOAD_ERR_PARTIAL:
            return "The uploaded file was only partially uploaded.";
        case UPLOAD_ERR_NO_FILE:
            return "No file was uploaded.";
        case UPLOAD_ERR_NO_TMP_DIR:
            return "Missing a temporary folder.";
        case UPLOAD_ERR_CANT_WRITE:
            return "Failed to write file to disk.";
        case UPLOAD_ERR_EXTENSION:
            return "A PHP extension stopped the file upload.";
        default:
            return "Unknown upload error.";
    }
}

// Handle individual product deletion
if (isset($_POST['delete_product']) && isset($_POST['product_id'])) {
    $product_id = (int)$_POST['product_id'];
    try {
        $stmt = $db->prepare("DELETE FROM sss_products WHERE id = ?");
        $stmt->execute([$product_id]);
        $message = "Product deleted successfully.";
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
}

// Get all products
try {
    $stmt = $db->query("SELECT * FROM sss_products ORDER BY name");
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
    $products = [];
}
?>

<div class="admin-container">
    <h2>Manage Products</h2>
    <?php if ($message): ?>
        <div class="success-message"><?php echo $message; ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="error-message"><?php echo $error; ?></div>
    <?php endif; ?>

    <div class="admin-layout">
        <!-- Sidebar with tools -->
        <div class="sidebar">
            <a href="add-product.php" class="button add-product-button">+ Add New Product</a>
            
            <!-- Import section -->
            <div class="sidebar-section">
                <h3>Import Products</h3>
                <form method="post" action="" enctype="multipart/form-data">
                    <div class="form-row">
                        <label for="csv_file">Upload CSV File</label>
                        <input type="file" id="csv_file" name="csv_file" accept=".csv">
                    </div>
                    <div class="form-info">
                        <p>CSV must include at least "name" and "price" columns.</p>
                    </div>
                    <button type="submit" name="import_products" class="button">Import Products</button>
                </form>
            </div>
            
            <!-- WooCommerce Import -->
            <div class="sidebar-section">
                <h3>Import from WooCommerce</h3>
                <form method="post" action="">
                    <button type="submit" name="import_woocommerce" class="button">Import from WooCommerce</button>
                </form>
            </div>
            
            <!-- Export section -->
            <div class="sidebar-section">
                <h3>Export Products</h3>
                <a href="export-products.php" class="button">Export Products as CSV</a>
            </div>
            
            <!-- Template section -->
            <div class="sidebar-section">
                <h3>CSV Template</h3>
                <a href="download-template.php" class="button">Download CSV Template</a>
            </div>
        </div>
        
        <!-- Main content with products table -->
        <form method="post" action="" id="bulk-form" class="main-content">
            <!-- Add bulk actions here -->
            <div class="bulk-actions-container">
                <h3>Bulk Actions</h3>
                <div class="bulk-actions-row">
                    <div class="bulk-action-select">
                        <label for="bulk_action">Action:</label>
                        <select id="bulk_action" name="bulk_action">
                            <option value="">Choose an action...</option>
                            <option value="set_active">Set as Active</option>
                            <option value="set_inactive">Set as Inactive</option>
                            <option value="delete">Delete</option>
                        </select>
                    </div>
                    
                    <div class="select-all-container">
                        <input type="checkbox" id="select-all" />
                        <label for="select-all">Select All Products</label>
                    </div>
                    
                    <button type="submit" id="apply-button" class="button">Apply</button>
                </div>
            </div>
            
            <?php if (empty($products)): ?>
                <div class="empty-state">
                    <h2>No Products Found</h2>
                    <p>Start by adding your first product.</p>
                </div>
            <?php else: ?>
                <table class="product-table">
                    <thead>
                        <tr>
                            <th width="30px">Select</th>
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
                                    <input type="checkbox" name="selected_products[]" value="<?php echo $product['id']; ?>" class="product-checkbox">
                                </td>
                                <td>
                                    <?php if (!empty($product['image'])): ?>
                                        <img src="<?php echo htmlspecialchars($product['image']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>" class="product-image">
                                    <?php else: ?>
                                        <div class="product-image" style="background-color:#eee;display:flex;align-items:center;justify-content:center;">No Image</div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($product['name']); ?></td>
                                <td>
                                    <?php if (isset($product['sale_price']) && $product['sale_price'] > 0): ?>
                                        <span style="text-decoration: line-through;">&pound;<?php echo number_format($product['regular_price'], 2); ?></span>
                                        <span style="color: #F44336; font-weight: bold;">&pound;<?php echo number_format($product['sale_price'], 2); ?></span>
                                    <?php else: ?>
                                        &pound;<?php echo number_format($product['regular_price'] ?? $product['price'], 2); ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo $product['status']; ?>">
                                        <?php echo ucfirst($product['status']); ?>
                                    </span>
                                </td>
                                <td class="actions">
                                    <a href="edit-product.php?id=<?php echo $product['id']; ?>" class="button edit-button">Edit</a>
                                    <form method="post" action="" onsubmit="return confirm('Are you sure you want to delete this product?');" style="display:inline">
                                        <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                                        <button type="submit" name="delete_product" class="button delete-button">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </form>
    </div><!-- Close admin-layout -->
</div>

<script>
document.getElementById('select-all').addEventListener('change', function() {
    var checkboxes = document.querySelectorAll('.product-checkbox');
    for (var i = 0; i < checkboxes.length; i++) {
        checkboxes[i].checked = this.checked;
    }
});

// Add form validation
document.getElementById('bulk-form').addEventListener('submit', function(e) {
    var action = document.getElementById('bulk_action').value;
    var checkedBoxes = document.querySelectorAll('.product-checkbox:checked');
    
    if (action === '') {
        alert('Please select an action from the dropdown.');
        e.preventDefault();
        return;
    }
    
    if (checkedBoxes.length === 0) {
        alert('Please select at least one product.');
        e.preventDefault();
        return;
    }
    
    // For debugging
    console.log('Action: ' + action);
    console.log('Selected products: ' + checkedBoxes.length);
});
</script>

<?php require_once 'includes/footer.php'; ?>