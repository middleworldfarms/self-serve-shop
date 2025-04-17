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

// filepath: /var/www/vhosts/middleworldfarms.org/self-serve-shop/admin/export-products.php
require_once '../config.php';

// Check if user is logged in as admin
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

// Set headers for CSV download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="products-export-' . date('Y-m-d') . '.csv"');

// Create output stream
$output = fopen('php://output', 'w');

// Output CSV header row
fputcsv($output, ['name', 'description', 'price', 'regular_price', 'sale_price', 'image', 'status']);

try {
    $db = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, 
        DB_USER, 
        DB_PASS
    );
    
    if (defined('DB_TYPE') && DB_TYPE === 'standalone') {
        // Standalone mode - Get from our custom table
        $stmt = $db->query("SELECT * FROM sss_products ORDER BY name");
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [
                $row['name'],
                $row['description'],
                $row['price'],
                $row['regular_price'],
                $row['sale_price'],
                $row['image'],
                $row['status']
            ]);
        }
    } else {
        // WordPress mode - Get from WP tables
        $stmt = $db->query("
            SELECT p.ID, p.post_title as name, p.post_content as description, p.post_status,
                   MAX(CASE WHEN pm.meta_key = '_price' THEN pm.meta_value END) as price,
                   MAX(CASE WHEN pm.meta_key = '_regular_price' THEN pm.meta_value END) as regular_price,
                   MAX(CASE WHEN pm.meta_key = '_sale_price' THEN pm.meta_value END) as sale_price,
                   MAX(CASE WHEN pm.meta_key = '_thumbnail_id' THEN pm.meta_value END) as thumbnail_id
            FROM " . TABLE_PREFIX . "posts p
            LEFT JOIN " . TABLE_PREFIX . "postmeta pm ON p.ID = pm.post_id
            WHERE p.post_type = 'product'
            AND p.post_status IN ('publish', 'draft')
            GROUP BY p.ID
            ORDER BY p.post_title
        ");
        
        while ($product = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // Get image URL if available
            $image_url = '';
            if ($product['thumbnail_id']) {
                $img_stmt = $db->prepare("SELECT guid FROM " . TABLE_PREFIX . "posts WHERE ID = ?");
                $img_stmt->execute([$product['thumbnail_id']]);
                $image_url = $img_stmt->fetchColumn();
            }
            
            $status = ($product['post_status'] === 'publish') ? 'active' : 'inactive';
            
            fputcsv($output, [
                $product['name'],
                $product['description'],
                $product['price'],
                $product['regular_price'],
                $product['sale_price'],
                $image_url,
                $status
            ]);
        }
    }
} catch (PDOException $e) {
    // In case of error, output a message in the CSV
    fputcsv($output, ['Error exporting data: ' . $e->getMessage()]);
}

fclose($output);
exit;