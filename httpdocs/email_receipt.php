<?php
// Add error reporting
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once 'includes/email_service.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['order_id'])) {
    die("Invalid request - missing order_id");
}

// Get email from form
$order_id = (int)$_POST['order_id'];
$email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);

// Debug to see what's happening
error_log("Email receipt requested for Order ID: $order_id, Email: $email");

if (!$email) {
    die("Invalid email address");
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

// Update SMTP password in settings
try {
    $stmt = $db->prepare("UPDATE self_serve_settings SET setting_value = 'your-new-password' WHERE setting_key = 'smtp_password'");
    $stmt->execute();
    error_log("SMTP password updated successfully in self_serve_settings");
} catch (PDOException $e) {
    error_log("Failed to update SMTP password: " . $e->getMessage());
}

// Get order data
$stmt = $db->prepare("SELECT * FROM orders WHERE id = ? OR order_number = ?");
$stmt->execute([$order_id, $order_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    // Try one more approach with just order_number
    $stmt = $db->prepare("SELECT * FROM orders WHERE order_number = ?");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$order) {
    error_log("Order not found in database for ID: $order_id");
    die("Order not found");
}

// Try to get settings from the correct table - check both tables
$settings = [];
try {
    // First check the actual structure of the self_serve_settings table
    $check = $db->query("DESCRIBE self_serve_settings");
    $columns = $check->fetchAll(PDO::FETCH_COLUMN);
    
    // If we found the table
    if (in_array('id', $columns)) {
        // Use column names that actually exist in the table
        $stmt = $db->query("SELECT * FROM self_serve_settings");
        $settingsData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Create settings array based on actual structure
        foreach ($settingsData as $row) {
            if (isset($row['key']) && isset($row['value'])) {
                $settings[$row['key']] = $row['value'];
            } 
            else if (isset($row['setting_key']) && isset($row['setting_value'])) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
            else if (isset($row['option_name']) && isset($row['option_value'])) {
                $settings[$row['option_name']] = $row['option_value'];
            }
        }
        error_log("Retrieved settings from self_serve_settings");
    }
    
    // Debug to see what values we're using
    error_log("SMTP Host: " . ($settings['smtp_host'] ?? 'Not set'));
    error_log("SMTP Username: " . ($settings['smtp_username'] ?? 'Not set'));
} catch (PDOException $e) {
    error_log("Settings retrieval error: " . $e->getMessage());
}

$shop_name = $settings['shop_name'] ?? 'MiddleWorld Farm';
$from_email = $settings['contact_email'] ?? 'info@middleworld.farm';

// Parse items
$items = json_decode($order['items'], true);

// Prepare email content
$email_body = '
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; }
        .header { text-align: center; padding: 20px; background: #f5f5f5; }
        .order-details { padding: 20px; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th { background-color: #f0f0f0; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        .total { font-weight: bold; background-color: #f9f9f9; }
        .footer { text-align: center; margin-top: 30px; font-size: 0.9em; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>' . htmlspecialchars($shop_name) . '</h1>
            <h2>Receipt for Order #' . htmlspecialchars($order['order_number']) . '</h2>
        </div>
        
        <div class="order-details">
            <p><strong>Date:</strong> ' . date('F j, Y', strtotime($order['created_at'])) . '</p>
            <p><strong>Payment Method:</strong> ' . ucfirst(htmlspecialchars($order['payment_method'])) . '</p>
            
            <h3>Items Purchased</h3>
            <table>
                <tr>
                    <th>Item</th>
                    <th>Qty</th>
                    <th>Price</th>
                    <th>Total</th>
                </tr>';

foreach ($items as $item) {
    $item_total = $item['price'] * $item['quantity'];
    
    $email_body .= '
                <tr>
                    <td>' . htmlspecialchars($item['name']) . '</td>
                    <td>' . (int)$item['quantity'] . '</td>
                    <td>&pound;' . number_format($item['price'], 2) . '</td>
                    <td>&pound;' . number_format($item_total, 2) . '</td>
                </tr>';
}

$email_body .= '
                <tr class="total">
                    <td colspan="3"><strong>Total</strong></td>
                    <td><strong>&pound;' . number_format($order['total_amount'], 2) . '</strong></td>
                </tr>
            </table>
            
            <p>Thank you for shopping with us!</p>
        </div>
        
        <div class="footer">
            <p>This receipt was sent from ' . htmlspecialchars($shop_name) . ' on ' . date('F j, Y') . '</p>
        </div>
    </div>
</body>
</html>';

// Send email using the email service
if (send_shop_email(
    $email,
    "Your Receipt for Order #{$order['order_number']} - {$shop_name}",
    $email_body,
    strip_tags(str_replace('<br>', "\n", $email_body))
)) {
    header("Location: order_confirmation.php?order_id={$order_id}&email_sent=1");
    exit;
} else {
    echo "<h2>Receipt Email</h2>";
    echo "<p>We're having trouble sending the email right now.</p>";
    echo "<p>You can try again or download the PDF receipt instead.</p>";
    echo "<p><a href='order_confirmation.php?order_id={$order_id}'>Return to order</a></p>";
}