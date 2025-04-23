<?php
require_once '../../config.php';
require_once '../includes/auth.php';

// Ensure user is authenticated as admin
if (!is_admin()) {
    header('Location: ../login.php');
    exit;
}

// Get WooCommerce API credentials from settings
$settings = get_settings();
$woo_url = $settings['woo_shop_url'] ?? '';
$woo_consumer_key = $settings['woo_consumer_key'] ?? '';
$woo_consumer_secret = $settings['woo_consumer_secret'] ?? '';

// Check if credentials exist
if (empty($woo_url) || empty($woo_consumer_key) || empty($woo_consumer_secret)) {
    $_SESSION['error'] = "WooCommerce API credentials are not configured.";
    header('Location: ../settings.php');
    exit;
}

// Get selected sync options
$options = $_POST['sync_options'] ?? ['names', 'descriptions', 'prices', 'images', 'new_products'];
// Note: 'status' is intentionally not included in default options
$sync_mode = $_POST['sync_mode'] ?? 'all';

// Initialize counters for feedback
$updated = 0;
$added = 0;
$skipped = 0;

// Initialize WooCommerce API
$woo_products_endpoint = rtrim($woo_url, '/') . '/wp-json/wc/v3/products';
$params = [
    'per_page' => 100,
    'status' => 'publish,private',
];

// Make API request
$ch = curl_init();
$url = $woo_products_endpoint . '?' . http_build_query($params);
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_USERPWD, $woo_consumer_key . ':' . $woo_consumer_secret);

$response = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($status !== 200) {
    $_SESSION['error'] = "Failed to connect to WooCommerce API. Status: $status";
    header('Location: ../settings.php');
    exit;
}

$woo_products = json_decode($response, true);

if (empty($woo_products) || !is_array($woo_products)) {
    $_SESSION['error'] = "No products found in WooCommerce or invalid API response.";
    header('Location: ../settings.php');
    exit;
}

// Process each WooCommerce product
foreach ($woo_products as $product) {
    // Add debugging here
    error_log("Syncing product: " . $product['name'] . ", Status option included: " . (in_array('status', $options) ? 'Yes' : 'No'));
    error_log("Product status in WooCommerce: " . $product['status']);
    
    // Check if product exists in local database
    $stmt = $db->prepare("SELECT * FROM sss_products WHERE name = ?");
    $stmt->execute([$product['name']]);
    $existing_product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $product_data = [
        'name' => $product['name'],
        'description' => $product['description'],
        'price' => $product['price'],
        'regular_price' => $product['regular_price'],
        'sale_price' => $product['sale_price'],
        'image' => !empty($product['images']) ? $product['images'][0]['src'] : ''
    ];
    
    // Only update status if specifically requested
    if (in_array('status', $options)) {
        $product_data['status'] = $product['status'] === 'publish' ? 'active' : 'inactive';
    } elseif ($existing_product) {
        // Keep existing status
        $product_data['status'] = $existing_product['status'];
    } else {
        // Default for new products
        $product_data['status'] = 'inactive'; // Conservative default
    }
    
    // Update or insert
    if ($existing_product) {
        // Build update query with only selected fields
        $updates = [];
        $params = [];
        
        foreach ($options as $option) {
            if ($option === 'names') {
                $updates[] = "name = :name";
                $params[':name'] = $product_data['name'];
            }
            if ($option === 'descriptions') {
                $updates[] = "description = :description";
                $params[':description'] = $product_data['description'];
            }
            if ($option === 'prices') {
                $updates[] = "price = :price";
                $updates[] = "regular_price = :regular_price";
                $updates[] = "sale_price = :sale_price";
                $params[':price'] = $product_data['price'];
                $params[':regular_price'] = $product_data['regular_price'];
                $params[':sale_price'] = $product_data['sale_price'];
            }
            if ($option === 'images') {
                $updates[] = "image = :image";
                $params[':image'] = $product_data['image'];
            }
        }
        
        // Always include status in the update based on our rules above
        $updates[] = "status = :status";
        $params[':status'] = $product_data['status'];
        
        $params[':id'] = $existing_product['id'];
        
        $sql = "UPDATE sss_products SET " . implode(", ", $updates) . " WHERE id = :id";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $updated++;
    } else if (in_array('new_products', $options)) {
        // Insert new product
        $stmt = $db->prepare("INSERT INTO sss_products (name, description, price, regular_price, sale_price, image, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $product_data['name'], 
            $product_data['description'], 
            $product_data['price'],
            $product_data['regular_price'],
            $product_data['sale_price'],
            $product_data['image'],
            $product_data['status']
        ]);
        $added++;
    } else {
        $skipped++;
    }
}

// Set success message
$_SESSION['success'] = "Sync completed! Updated: $updated, Added: $added, Skipped: $skipped";
header('Location: ../settings.php');
exit;
?>

<style>
/* Add to your admin CSS or inline in the settings page */
.sync-options {
    background-color: #f9f9f9;
    padding: 20px;
    border-radius: 5px;
    margin-bottom: 20px;
    border: 1px solid #e0e0e0;
}

.sync-options h3 {
    margin-top: 0;
    border-bottom: 1px solid #e0e0e0;
    padding-bottom: 10px;
    margin-bottom: 15px;
}

.form-group {
    margin-bottom: 12px;
}

.help-text {
    font-size: 0.85em;
    color: #666;
    margin-left: 10px;
}

.form-group label {
    display: inline-block;
    margin-right: 15px;
}
</style>
DELETE FROM products