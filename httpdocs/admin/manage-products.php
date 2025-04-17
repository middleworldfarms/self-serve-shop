<?php
// filepath: /var/www/vhosts/middleworldfarms.org/self-serve-shop/admin/manage-products.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config.php';

// Admin authentication
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

// CSRF protection
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$error = '';

// WooCommerce API credentials
$settings = function_exists('get_settings') ? get_settings() : [];
$woo_url = rtrim($settings['shop_url'] ?? '', '/');
$woo_ck = $settings['woo_consumer_key'] ?? '';
$woo_cs = $settings['woo_consumer_secret'] ?? '';

if (isset($_POST['import_woocommerce'])) {
    $endpoint = $woo_url . '/wp-json/wc/v3/products?per_page=100'; // Adjust per_page as needed

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
            $db = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, 
                DB_USER, 
                DB_PASS
            );
            foreach ($woo_products as $product) {
                $name = $product['name'];
                $description = $product['description'];
                $price = $product['price'];
                $regular_price = $product['regular_price'];
                $sale_price = $product['sale_price'];
                $image = !empty($product['images']) ? $product['images'][0]['src'] : '';
                $status = ($product['status'] === 'publish') ? 'active' : 'inactive';

                // Insert or update by name (or use SKU if you prefer)
                $stmt = $db->prepare("SELECT id FROM sss_products WHERE name = ?");
                $stmt->execute([$name]);
                if ($stmt->fetch()) {
                    // Update existing
                    $stmt = $db->prepare("UPDATE sss_products SET description=?, price=?, regular_price=?, sale_price=?, image=?, status=? WHERE name=?");
                    $stmt->execute([$description, $price, $regular_price, $sale_price, $image, $status, $name]);
                } else {
                    // Insert new
                    $stmt = $db->prepare("INSERT INTO sss_products (name, description, price, regular_price, sale_price, image, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$name, $description, $price, $regular_price, $sale_price, $image, $status]);
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
        $db = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, 
            DB_USER, 
            DB_PASS
        );

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
                $db = new PDO(
                    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, 
                    DB_USER, 
                    DB_PASS
                );
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
        $db = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, 
            DB_USER, 
            DB_PASS
        );
        $stmt = $db->prepare("DELETE FROM sss_products WHERE id = ?");
        $stmt->execute([$product_id]);
        $message = "Product deleted successfully.";
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
}

// Get all products
try {
    $db = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, 
        DB_USER, 
        DB_PASS
    );
    // Create table if not exists
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
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB;
    ");
    $stmt = $db->query("SELECT * FROM sss_products ORDER BY name");
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
    <title>Manage Products - Self-Serve Shop Admin</title>
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
        .product-image {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 4px;
        }
        .actions {
            display: flex;
            gap: 10px;
            align-items: center; /* This ensures vertical alignment */
        }
        .actions .button {
            height: 36px;          /* Set a fixed height for all buttons */
            line-height: 36px;     /* Center text vertically */
            padding: 0 15px;       /* Horizontal padding */
            margin: 0;             /* Remove any margin differences */
            box-sizing: border-box; /* Include padding in height calculation */
            display: inline-block;
            text-align: center;
        }
        .edit-button {
            background-color: #4CAF50 !important; /* Same green as header and add button */
            color: white !important;
        }
        .edit-button:hover {
            background-color: #45a049 !important; /* Slightly darker green on hover */
        }
        .delete-button {
            background-color: #F44336;
            height: 36px !important;
            line-height: 36px !important;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
        }
        .status-active {
            background-color: #DFF2BF;
            color: #4F8A10;
        }
        .status-inactive {
            background-color: #FFDDDD;
            color: #D8000C;
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
        .add-product-button {
            margin-bottom: 35px;
            background-color: #4CAF50;
            color: white;
            font-weight: bold;
            padding: 12px 15px;
            margin-top: -2px; /* Changed from -4px to -2px to move it DOWN by 2px */
        }

        .add-product-button:hover {
            background-color: #45a049;
        }
        .empty-state {
            text-align: center;
            padding: 40px;
            background-color: #f9f9f9;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .bulk-actions {
            margin-bottom: 20px;
            padding: 15px;
            background-color: #f5f5f5;
            border-radius: 5px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .select-all-container {
            margin: 0 10px;
        }
        .import-export-section {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
            padding: 20px;
            background-color: #f5f5f5;
            border-radius: 8px;
        }
        .import-section, .export-section, .template-section {
            background-color: white;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .import-form .form-row {
            margin-bottom: 15px;
        }
        .form-info {
            font-size: 0.9em;
            color: #666;
            margin-bottom: 15px;
        }
        .form-info p {
            margin: 5px 0;
        }
        .admin-header {
            background-color: #4CAF50; /* Green background to match settings.php */
            color: white;
            padding: 15px 0;
            margin-bottom: 20px;
            position: relative; /* Allow absolute positioning of children */
        }

        .header-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
            padding: 0 20px;
            max-width: 1200px;
            margin: 0 auto;
            text-align: center; /* Center text within header */
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 20px;
            flex: 1; /* Take up available space */
            justify-content: center; /* Center the title and link */
        }

        .header-left h1 {
            margin: 0;
            color: white;
            font-size: 24px;
            text-align: center; /* Center the title text */
        }

        /* Position the back link to the left edge */
        .back-link {
            position: absolute;
            left: 20px;
        }

        /* Position the logout link to the right edge */
        .logout-link {
            position: absolute;
            right: 20px;
        }

        .admin-layout {
            position: relative;
            display: block;
            clear: both;
        }

        .sidebar {
            float: left;
            width: 300px;
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-right: 20px;
            padding-top: 4px; /* Adjust the top padding to help move button up */
        }

        .main-content {
            margin-left: 320px; /* This is 300px width of sidebar + 20px margin */
        }

        /* Add this to ensure proper clearing of floats */
        .admin-layout:after {
            content: "";
            display: table;
            clear: both;
        }

        .sidebar-section {
            background-color: white;
            padding: 15px;
            border-radius: 5px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 25px; /* Increased from 20px to 25px */
        }

        .sidebar-section h3 {
            margin-top: 0;
            font-size: 16px;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }

        /* Make buttons in sidebar full width */
        .sidebar .button {
            width: 100%;
            text-align: center;
            margin-top: 10px;
            margin-bottom: 10px;
        }

        .bulk-actions-sidebar {
            padding-top: 15px;
        }

        .bulk-actions-sidebar .button {
            margin-top: 15px;
        }

        .select-all-container {
            margin: 10px 0;
        }

        /* Add these CSS rules with !important to override any other styles */
        .sidebar a.add-product-button {
            margin-bottom: 40px !important; 
            margin-top: -5px !important; /* Changed from -10px to -8px to move it DOWN by 2px */
            display: block !important;
        }

        /* Add specific styling for the first sidebar-section (which should be bulk actions) */
        .sidebar > div:first-of-type,
        .sidebar > .sidebar-section:first-of-type {
            margin-top: 0 !important;
        }

        .admin-container {
            max-width: 1000px; /* Match settings.php (it's 1200px currently) */
            margin: 0 auto;
            padding: 2rem;
            background-color: white;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
        }

        /* Add a title for the page, similar to settings.php */
        .admin-container > h2 {
            margin-top: 0;
            margin-bottom: 20px;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
    </style>
</head>
<body>
    <header class="admin-header">
        <div class="header-container">
            <div class="header-left">
                <h1>Manage Products</h1>
                <a href="index.php" class="back-link">Return to Dashboard</a>
            </div>
            <div class="header-right">
                <a href="?logout=1" class="logout-link">Logout</a>
            </div>
        </div>
    </header>
    
    <main>
        <div class="admin-container">
            <h2>Manage Products</h2>
            
            <?php if ($message): ?>
                <div class="success-message"><?php echo $message; ?></div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="error-message"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <div class="admin-layout">
                <form method="post" action="" id="bulk-form" enctype="multipart/form-data">
                    <!-- Sidebar with tools -->
                    <div class="sidebar">
                        <a href="add-product.php" class="button add-product-button">+ Add New Product</a>
                        
                        <!-- Bulk actions now in sidebar -->
                        <div class="sidebar-section">
                            <h3>Bulk Actions</h3>
                            <div class="bulk-actions-sidebar">
                                <div>
                                    <label for="bulk_action">Action:</label>
                                    <select id="bulk_action" name="bulk_action" style="width: 100%;">
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
                        
                        <!-- Import section -->
                        <div class="sidebar-section">
                            <h3>Import Products</h3>
                            <div class="form-row">
                                <label for="csv_file">Upload CSV File</label>
                                <input type="file" id="csv_file" name="csv_file" accept=".csv">
                            </div>
                            <div class="form-info">
                                <p>CSV must include at least "name" and "price" columns.</p>
                            </div>
                            <button type="submit" name="import_products" class="button">Import Products</button>
                        </div>
                        
                        <!-- WooCommerce Import -->
                        <div class="sidebar-section">
                            <h3>Import from WooCommerce</h3>
                            <form method="post">
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
                    <div class="main-content">
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
                    </div><!-- Close main-content -->
                </form>
            </div><!-- Close admin-layout -->
        </div>
    </main>
    
    <footer>
        <p>&copy; <?php echo date('Y'); ?> Middle World Farms. All rights reserved.</p>
    </footer>
    
    <script>
        // Toggle role select dropdown when bulk action changes
        document.getElementById('bulk_action').addEventListener('change', function() {
            // For future expansion if needed
        });
        
        // Select all checkboxes functionality
        document.getElementById('select-all').addEventListener('change', function() {
            var checkboxes = document.querySelectorAll('.product-checkbox');
            checkboxes.forEach(function(checkbox) {
                checkbox.checked = this.checked;
            }, this);
        });
        
        // Form submission validation
        document.getElementById('bulk-form').addEventListener('submit', function(e) {
            var action = document.getElementById('bulk_action').value;
            var checkboxes = document.querySelectorAll('.product-checkbox:checked');
            
            if (action === '') {
                e.preventDefault();
                alert('Please select an action to perform.');
                return false;
            }
            
            if (checkboxes.length === 0) {
                e.preventDefault();
                alert('Please select at least one product.');
                return false;
            }
            
            if (action === 'delete') {
                if (!confirm('Are you sure you want to delete the selected products?')) {
                    e.preventDefault();
                    return false;
                }
            }
        });
    </script>
</body>
</html>