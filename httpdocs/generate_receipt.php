<?php
// Add error reporting
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();

require_once 'config.php';
require_once __DIR__ . '/../vendor/autoload.php';

// Get order ID from either GET parameter or session
if (isset($_GET['order_id'])) {
    $order_id = (int)$_GET['order_id'];
} else if (isset($_SESSION['last_order_id'])) {
    $order_id = (int)$_SESSION['last_order_id'];
} else {
    die("No order ID provided");
}

if ($order_id <= 0) {
    die("Invalid order ID");
}

// Debug to see what's happening
error_log("Generating receipt for order ID: $order_id");
echo "<!-- Debug: Order ID = " . (isset($_GET['order_id']) ? $_GET['order_id'] : 'Not Set') . " -->";

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

// Get order data with detailed debugging
try {
    // First try direct match on ID and order_number
    $stmt = $db->prepare("SELECT * FROM orders WHERE id = ? OR order_number = ?");
    $stmt->execute([$order_id, $order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        // Try more approaches if direct match fails
        
        // Try with session order ID
        if (isset($_SESSION['last_order_id']) && $_SESSION['last_order_id'] > 0) {
            $stmt = $db->prepare("SELECT * FROM orders WHERE id = ?");
            $stmt->execute([(int)$_SESSION['last_order_id']]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($order) {
                echo "<!-- Found order using session ID -->";
            }
        }
        
        // If still not found, check if this is a formatted order number (with dashes)
        if (!$order) {
            // Show all orders for debugging
            echo "<p>Checking recent orders in database:</p>";
            $recent = $db->query("SELECT id, order_number FROM orders ORDER BY id DESC LIMIT 10");
            echo "<ul>";
            while ($row = $recent->fetch(PDO::FETCH_ASSOC)) {
                echo "<li>ID: {$row['id']} - Order #: {$row['order_number']}</li>";
            }
            echo "</ul>";
            
            die("<p>Order not found. Please <a href='index.php'>return to the shop</a> and contact support.</p>");
        }
    }
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Try to get settings
$settings = [];
try {
    // Check if settings table exists
    $check = $db->query("SHOW TABLES LIKE 'settings'");
    if ($check->rowCount() > 0) {
        $stmt = $db->query("SELECT * FROM settings");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }
} catch (PDOException $e) {
    // Silently continue with default settings
}

// Get shop info from settings or use defaults
$shop_name = $settings['shop_name'] ?? 'MiddleWorld Farm';
$shop_address = $settings['shop_address'] ?? '123 Farm Lane, UK';
$shop_contact = $settings['contact_email'] ?? 'info@middleworld.farm';

// Parse items
$items = json_decode($order['items'], true);

// Create custom temp directory path
$tempDir = __DIR__ . '/tmp/mpdf';

// Make sure the directory exists
if (!file_exists($tempDir)) {
    mkdir($tempDir, 0777, true);
}

// Check if mpdf class exists
if (!class_exists('\Mpdf\Mpdf')) {
    die("mPDF library not found. Please run: composer require mpdf/mpdf");
}

// Debug autoloader
echo "<!-- Autoloader path: " . __DIR__ . '/../vendor/autoload.php' . " -->";

// Try with full namespace reference
try {
    // Create PDF using mPDF with custom temp directory
    $mpdf = new \Mpdf\Mpdf([
        'margin_left' => 15,
        'margin_right' => 15,
        'margin_top' => 15,
        'margin_bottom' => 15,
        'tempDir' => $tempDir
    ]);
} catch (Exception $e) {
    die("Error creating mPDF object: " . $e->getMessage());
}

// Get logo path if available
$logo_path = !empty($settings['shop_logo']) ? __DIR__ . '/' . ltrim($settings['shop_logo'], '/') : '';
$has_logo = !empty($logo_path) && file_exists($logo_path);

// Build HTML for PDF with logo
$html = '
<style>
    body { font-family: Arial, sans-serif; font-size: 12pt; }
    h1 { font-size: 18pt; font-weight: bold; margin-bottom: 5px; }
    .receipt-header { text-align: center; margin-bottom: 20px; }
    .logo { max-width: 200px; max-height: 80px; margin: 0 auto 15px auto; display: block; }
    .shop-details { text-align: center; margin-bottom: 20px; font-size: 10pt; }
    .receipt-meta { margin-bottom: 20px; }
    .receipt-meta div { margin-bottom: 5px; }
    table { width: 100%; border-collapse: collapse; margin: 20px 0; }
    th { background-color: #f5f5f5; text-align: left; }
    th, td { padding: 8px; border-bottom: 1px solid #ddd; }
    .total-row { font-weight: bold; border-top: 2px solid #000; }
    .footer { margin-top: 30px; font-size: 10pt; text-align: center; }
</style>
<div class="receipt-header">';

// Add logo if available
if ($has_logo) {
    $html .= '<img src="' . $logo_path . '" class="logo" alt="' . htmlspecialchars($shop_name) . '">';
}

$html .= '
    <h1>' . htmlspecialchars($shop_name) . '</h1>
    <div class="shop-details">
        ' . htmlspecialchars($shop_address) . '<br>
        ' . htmlspecialchars($shop_contact) . '
    </div>
</div>

<div class="receipt-meta">
    <div><strong>Receipt for Order #' . htmlspecialchars($order['order_number']) . '</strong></div>
    <div>Date: ' . date('F j, Y', strtotime($order['created_at'])) . '</div>
    <div>Payment Method: ' . ucfirst(htmlspecialchars($order['payment_method'])) . '</div>
</div>

<table>
    <tr>
        <th>Item</th>
        <th>Qty</th>
        <th>Price</th>
        <th>Total</th>
    </tr>';

$subtotal = 0;
foreach ($items as $item) {
    $item_total = $item['price'] * $item['quantity'];
    $subtotal += $item_total;
    
    $html .= '
    <tr>
        <td>' . htmlspecialchars($item['name']) . '</td>
        <td>' . (int)$item['quantity'] . '</td>
        <td>&pound;' . number_format($item['price'], 2) . '</td>
        <td>&pound;' . number_format($item_total, 2) . '</td>
    </tr>';
}

$html .= '
    <tr class="total-row">
        <td colspan="3">Total</td>
        <td>&pound;' . number_format($order['total_amount'], 2) . '</td>
    </tr>
</table>

<div class="footer">
    <p>Thank you for shopping with us!</p>
    <p>This receipt was generated on ' . date('F j, Y') . '</p>
</div>';

// Set document information
$mpdf->SetTitle("Receipt - Order #" . $order['order_number']);
$mpdf->SetAuthor($shop_name);
$mpdf->SetCreator($shop_name);

// Write HTML to PDF
$mpdf->WriteHTML($html);

// Output PDF
$mpdf->Output('Receipt-' . $order['order_number'] . '.pdf', 'D');