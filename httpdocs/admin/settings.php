<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Admin authentication check
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

// Add CSRF protection
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require_once '../config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Ensure database connection is established
try {
    if (!isset($db)) {
        $db = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME,
            DB_USER,
            DB_PASS
        );
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Helper function to check if a table exists
function table_exists($tableName) {
    global $db;
    $stmt = $db->query("SHOW TABLES LIKE '$tableName'");
    return $stmt->rowCount() > 0;
}

// Create necessary tables if they don't exist
try {
    if (!table_exists('users')) {
        $db->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(50) NOT NULL UNIQUE,
                email VARCHAR(100) NOT NULL,
                password VARCHAR(255) NOT NULL,
                role VARCHAR(20) NOT NULL DEFAULT 'admin',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $stmt = $db->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, ?)");
        $stmt->execute([
            'admin',
            'admin@example.com',
            password_hash('admin123', PASSWORD_DEFAULT),
            'admin'
        ]);
    }

    if (!table_exists('self_serve_settings')) {
        $db->exec("
            CREATE TABLE IF NOT EXISTS self_serve_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setting_name VARCHAR(100) NOT NULL UNIQUE,
                setting_value TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ");
        $default_settings = [
            ['shop_name', 'Self-Serve Shop'],
            ['shop_organization', 'Your Organization'],
            ['currency_symbol', '$'],
            ['primary_color', '#4CAF50'],
            ['secondary_color', '#388E3C'],
            ['background_color', '#f5f5f5'],
            ['text_color', '#333333'],
            ['header_text', 'Welcome to our Self-Serve Shop'],
            ['footer_text', '&copy; ' . date('Y') . ' Your Organization'],
            ['shop_url', 'https://example.com/'],
            ['shop_description', 'A convenient self-serve shop for our customers.'],
            ['enable_manual_payment', '1'],
            ['payment_instructions', 'Please leave cash in the box or use one of our electronic payment options.'],
            ['smtp_host', ''], // Default SMTP host
            ['smtp_port', '587'], // Default SMTP port
            ['smtp_encryption', 'tls'], // Default encryption
            ['smtp_username', ''], // Default SMTP username
            ['smtp_password', ''], // Default SMTP password
            ['enable_stripe', '0'],
            ['stripe_public_key', ''],
            ['stripe_secret_key', ''],
            ['enable_paypal', '0'],
            ['paypal_client_id', ''],
            ['paypal_secret', ''],
            ['enable_gocardless', '0'],
            ['gocardless_access_token', ''],
            ['gocardless_webhook_secret', ''],
            ['woo_consumer_key', ''],
            ['woo_consumer_secret', '']
        ];
        $stmt = $db->prepare("INSERT INTO self_serve_settings (setting_name, setting_value) VALUES (?, ?)");
        foreach ($default_settings as $setting) {
            $stmt->execute($setting);
        }
    }
} catch (PDOException $e) {
    die("Error creating tables: " . $e->getMessage());
}

// Functions for settings
function get_settings() {
    global $db;
    $settings = [];
    $stmt = $db->query("SELECT setting_name, setting_value FROM self_serve_settings");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_name']] = $row['setting_value'];
    }
    return $settings;
}

function save_settings($settings) {
    global $db;
    try {
        $db->beginTransaction();
        foreach ($settings as $name => $value) {
            $stmt = $db->prepare("INSERT INTO self_serve_settings (setting_name, setting_value) VALUES (?, ?)
                                  ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmt->execute([$name, $value]);
        }
        $db->commit();
        return true;
    } catch (PDOException $e) {
        $db->rollBack();
        error_log("Settings save error: " . $e->getMessage());
        return false;
    }
}

// Get current settings
$current_settings = get_settings();

$woo_ck = $current_settings['woo_consumer_key'] ?? '';
$woo_cs = $current_settings['woo_consumer_secret'] ?? '';

error_log("Current Settings: " . print_r($current_settings, true));

// Process form submission
$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Access denied. Invalid CSRF token.");
    }

    if (isset($_POST['save_settings'])) {
        $settings = [
            'shop_name' => $_POST['shop_name'] ?? '',
            'shop_organization' => $_POST['shop_organization'] ?? '',
            'currency_symbol' => $_POST['currency_symbol'] ?? '$',
            'primary_color' => $_POST['primary_color'] ?? '#4CAF50',
            'secondary_color' => $_POST['secondary_color'] ?? '#388E3C',
            'background_color' => $_POST['background_color'] ?? '#f5f5f5',
            'text_color' => $_POST['text_color'] ?? '#333333',
            'header_text' => $_POST['header_text'] ?? '',
            'footer_text' => $_POST['footer_text'] ?? '',
            'shop_url' => $_POST['shop_url'] ?? '',
            'enable_manual_payment' => isset($_POST['enable_manual_payment']) ? '1' : '0',
            'payment_instructions' => $_POST['payment_instructions'] ?? '',
            'site_logo' => $_POST['site_logo'] ?? '',
            'logo_location' => $_POST['logo_location'] ?? '',
            'custom_css' => $_POST['custom_css'] ?? '',
            'enable_stripe' => isset($_POST['enable_stripe']) ? '1' : '0',
            'stripe_public_key' => $_POST['stripe_public_key'] ?? '',
            'stripe_secret_key' => $_POST['stripe_secret_key'] ?? '',
            'enable_paypal' => isset($_POST['enable_paypal']) ? '1' : '0',
            'paypal_client_id' => $_POST['paypal_client_id'] ?? '',
            'paypal_secret' => $_POST['paypal_secret'] ?? '',
            'enable_gocardless' => isset($_POST['enable_gocardless']) ? '1' : '0',
            'gocardless_access_token' => $_POST['gocardless_access_token'] ?? '',
            'gocardless_webhook_secret' => $_POST['gocardless_webhook_secret'] ?? '',
            'woo_consumer_key' => $_POST['woo_consumer_key'] ?? '',
            'woo_consumer_secret' => $_POST['woo_consumer_secret'] ?? ''
        ];

        // Handle logo upload
        if (isset($_FILES['site_logo']) && $_FILES['site_logo']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/uploads/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            $ext = pathinfo($_FILES['site_logo']['name'], PATHINFO_EXTENSION);
            $filename = 'logo_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $target = $upload_dir . $filename;
            if (move_uploaded_file($_FILES['site_logo']['tmp_name'], $target)) {
                // Save relative path for use in HTML
                $settings['site_logo'] = 'admin/uploads/' . $filename;
            }
        } else {
            // Keep existing logo if not uploading a new one
            $settings['site_logo'] = $current_settings['site_logo'] ?? '';
        }

        // Save logo location and custom CSS
        $settings['logo_location'] = $_POST['logo_location'] ?? 'header';
        $settings['custom_css'] = $_POST['custom_css'] ?? '';

        if (save_settings($settings)) {
            $message = "Settings updated successfully.";
            $current_settings = get_settings(); // Refresh settings after save
        } else {
            $error = "Error saving settings. Please try again.";
        }
    }

    if (isset($_POST['save_email_settings'])) {
        $email_settings = [
            'smtp_host' => $_POST['smtp_host'] ?? '',
            'smtp_port' => $_POST['smtp_port'] ?? '587',
            'smtp_encryption' => $_POST['smtp_encryption'] ?? 'tls',
            'smtp_username' => $_POST['smtp_username'] ?? '',
            'smtp_password' => $_POST['smtp_password'] ?? ''
        ];

        // Validate required fields
        if (empty($email_settings['smtp_host']) || empty($email_settings['smtp_username']) || empty($email_settings['smtp_password'])) {
            $error = "SMTP Host, Username, and Password are required.";
        } else {
            if (save_settings($email_settings)) {
                $message = "Email settings updated successfully.";
                $current_settings = get_settings(); // Refresh settings after save
            } else {
                $error = "Error saving email settings. Please try again.";
            }
        }
    }

    if (isset($_POST['send_test_email'])) {
        $test_email = $_POST['test_email'] ?? '';

        if (empty($test_email)) {
            $error = "Please enter a valid email address.";
        } else {
            require_once __DIR__ . '/../../vendor/autoload.php';

            $mail = new PHPMailer(true);

            try {
                // SMTP configuration
                $mail->isSMTP();
                $mail->Host = $current_settings['smtp_host'];
                $mail->SMTPAuth = true;
                $mail->Username = $current_settings['smtp_username'];
                $mail->Password = $current_settings['smtp_password'];
                $mail->SMTPSecure = $current_settings['smtp_encryption'];
                $mail->Port = $current_settings['smtp_port'];

                // Email content
                $mail->setFrom($current_settings['smtp_username'], 'Self-Serve Shop');
                $mail->addAddress($test_email);
                $mail->Subject = 'Test Email';
                $mail->Body = 'This is a test email from the Self-Serve Shop.';

                $mail->send();
                $message = "Test email sent successfully to $test_email.";
            } catch (Exception $e) {
                $error = "Failed to send test email: " . $mail->ErrorInfo;
            }
        }
    }
}

// Handle tab switching
$active_tab = $_GET['tab'] ?? 'general';
$allowed_tabs = ['general', 'branding', 'payment', 'content', 'users', 'order_logs', 'email'];
if (!in_array($active_tab, $allowed_tabs)) {
    $active_tab = 'general';
}

require_once 'includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        body {
            background: #f5f5f5;
            font-family: Arial, sans-serif;
        }
        .admin-container {
            max-width: 700px;
            margin: 40px auto;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            padding: 32px 32px 24px 32px;
        }
        h2 {
            margin-top: 0;
            color: #388E3C;
            letter-spacing: 1px;
        }
        .settings-tabs {
            display: flex;
            border-bottom: 2px solid #e0e0e0;
            margin-bottom: 32px;
        }
        .settings-tabs a {
            padding: 12px 28px;
            text-decoration: none;
            color: #388E3C;
            font-weight: bold;
            border-radius: 8px 8px 0 0;
            margin-right: 8px;
            background: #f5f5f5;
            transition: background 0.2s, color 0.2s;
        }
        .settings-tabs a.active {
            background: #388E3C;
            color: #fff;
            border-bottom: 2px solid #fff;
        }
        form {
            margin-bottom: 24px;
        }
        label {
            display: block;
            margin-bottom: 6px;
            font-weight: bold;
            color: #333;
        }
        input[type="text"], input[type="email"], input[type="password"], select, textarea {
            width: 100%;
            padding: 9px 12px;
            margin-bottom: 18px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 1rem;
            background: #fafafa;
            transition: border 0.2s;
        }
        input[type="text"]:focus, input[type="email"]:focus, input[type="password"]:focus, select:focus, textarea:focus {
            border-color: #388E3C;
            outline: none;
        }
        button[type="submit"] {
            background: #388E3C;
            color: #fff;
            border: none;
            padding: 10px 28px;
            border-radius: 4px;
            font-size: 1rem;
            font-weight: bold;
            cursor: pointer;
            transition: background 0.2s;
        }
        button[type="submit"]:hover {
            background: #2e7031;
        }
        .success-message, .error-message {
            padding: 12px 18px;
            border-radius: 4px;
            margin-bottom: 18px;
            font-weight: bold;
        }
        .success-message {
            background: #e6f4ea;
            color: #256029;
        }
        .error-message {
            background: #fdecea;
            color: #b71c1c;
        }
        /* Remove bottom margin from WooCommerce fields so help text sits close */
        .woo-field {
            margin-bottom: 2px !important;
        }
        @media (max-width: 600px) {
            .admin-container {
                padding: 12px;
            }
            .settings-tabs a {
                padding: 10px 10px;
                font-size: 0.95rem;
            }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <h2>Shop Settings</h2>
        <?php if ($message): ?>
            <div class="success-message"><?php echo $message; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="error-message"><?php echo $error; ?></div>
        <?php endif; ?>

        <div class="settings-tabs">
            <a href="?tab=general" class="<?php echo $active_tab === 'general' ? 'active' : ''; ?>">General</a>
            <a href="?tab=branding" class="<?php echo $active_tab === 'branding' ? 'active' : ''; ?>">Branding</a>
            <a href="?tab=payment" class="<?php echo $active_tab === 'payment' ? 'active' : ''; ?>">Payment</a>
            <a href="?tab=email" class="<?php echo $active_tab === 'email' ? 'active' : ''; ?>">Email Settings</a>
        </div>

        <?php if ($active_tab === 'general'): ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <label for="shop_name">Shop Name:</label>
                <input type="text" id="shop_name" name="shop_name" value="<?php echo htmlspecialchars($current_settings['shop_name'] ?? '', ENT_QUOTES); ?>" required>

                <label for="shop_url">Shop URL:</label>
                <input type="text" id="shop_url" name="shop_url" value="<?php echo htmlspecialchars($current_settings['shop_url'] ?? '', ENT_QUOTES); ?>">

                <label for="woo_consumer_key">WooCommerce Consumer Key:</label>
                <input class="woo-field" type="text" id="woo_consumer_key" name="woo_consumer_key" value="<?php echo htmlspecialchars($current_settings['woo_consumer_key'] ?? '', ENT_QUOTES); ?>">
                <small style="color:#666; display:block; margin-top:2px; margin-bottom:22px;">Leave blank if you don't use WooCommerce integration.</small>

                <label for="woo_consumer_secret">WooCommerce Consumer Secret:</label>
                <input class="woo-field" type="text" id="woo_consumer_secret" name="woo_consumer_secret" value="<?php echo htmlspecialchars($current_settings['woo_consumer_secret'] ?? '', ENT_QUOTES); ?>">
                <small style="color:#666; display:block; margin-top:2px; margin-bottom:22px;">Leave blank if you don't use WooCommerce integration.</small>

                <label for="shop_organization">Organization:</label>
                <input type="text" id="shop_organization" name="shop_organization" value="<?php echo htmlspecialchars($current_settings['shop_organization'] ?? '', ENT_QUOTES); ?>">

                <label for="currency_symbol">Currency Symbol:</label>
                <input type="text" id="currency_symbol" name="currency_symbol" value="<?php echo htmlspecialchars($current_settings['currency_symbol'] ?? '', ENT_QUOTES); ?>">

                <button type="submit" name="save_settings">Save General Settings</button>
            </form>
        <?php elseif ($active_tab === 'branding'): ?>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                <label for="primary_color">Primary Color:</label>
                <input type="text" id="primary_color" name="primary_color" value="<?php echo htmlspecialchars($current_settings['primary_color'] ?? '', ENT_QUOTES); ?>">

                <label for="secondary_color">Secondary Color:</label>
                <input type="text" id="secondary_color" name="secondary_color" value="<?php echo htmlspecialchars($current_settings['secondary_color'] ?? '', ENT_QUOTES); ?>">

                <label for="background_color">Background Color:</label>
                <input type="text" id="background_color" name="background_color" value="<?php echo htmlspecialchars($current_settings['background_color'] ?? '', ENT_QUOTES); ?>">

                <label for="text_color">Text Color:</label>
                <input type="text" id="text_color" name="text_color" value="<?php echo htmlspecialchars($current_settings['text_color'] ?? '', ENT_QUOTES); ?>">

                <label for="header_text">Header Text:</label>
                <input type="text" id="header_text" name="header_text" value="<?php echo htmlspecialchars($current_settings['header_text'] ?? '', ENT_QUOTES); ?>">

                <label for="footer_text">Footer Text:</label>
                <input type="text" id="footer_text" name="footer_text" value="<?php echo htmlspecialchars($current_settings['footer_text'] ?? '', ENT_QUOTES); ?>">

                <label for="site_logo">Site Logo:</label>
                <input type="file" id="site_logo" name="site_logo" accept="image/*">
                <?php if (!empty($current_settings['site_logo'])): ?>
                    <div style="margin:10px 0;">
                        <img src="<?php echo htmlspecialchars($current_settings['site_logo']); ?>" alt="Site Logo" style="max-width:180px;max-height:80px;">
                    </div>
                <?php endif; ?>

                <label for="logo_location">Display Logo In:</label>
                <select id="logo_location" name="logo_location">
                    <option value="header" <?php echo (isset($current_settings['logo_location']) && $current_settings['logo_location'] === 'header') ? 'selected' : ''; ?>>Header Only</option>
                    <option value="footer" <?php echo (isset($current_settings['logo_location']) && $current_settings['logo_location'] === 'footer') ? 'selected' : ''; ?>>Footer Only</option>
                    <option value="both" <?php echo (isset($current_settings['logo_location']) && $current_settings['logo_location'] === 'both') ? 'selected' : ''; ?>>Header & Footer</option>
                </select>

                <label for="custom_css">Custom CSS:</label>
                <textarea id="custom_css" name="custom_css" rows="5" placeholder="Enter custom CSS here..."><?php echo htmlspecialchars($current_settings['custom_css'] ?? '', ENT_QUOTES); ?></textarea>

                <button type="submit" name="save_settings">Save Branding Settings</button>
            </form>
        <?php elseif ($active_tab === 'payment'): ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                <h4>Manual Payment</h4>
                <label>
                    <input type="checkbox" name="enable_manual_payment" value="1" <?php echo (isset($current_settings['enable_manual_payment']) && $current_settings['enable_manual_payment'] == '1') ? 'checked' : ''; ?>>
                    Enable Manual Payment
                </label>
                <label for="payment_instructions">Manual Payment Instructions:</label>
                <textarea id="payment_instructions" name="payment_instructions" rows="3"><?php echo htmlspecialchars($current_settings['payment_instructions'] ?? '', ENT_QUOTES); ?></textarea>

                <hr>

                <h4>Stripe</h4>
                <label>
                    <input type="checkbox" name="enable_stripe" value="1" <?php echo (isset($current_settings['enable_stripe']) && $current_settings['enable_stripe'] == '1') ? 'checked' : ''; ?>>
                    Enable Stripe Payments
                </label>
                <label for="stripe_public_key">Stripe Public Key:</label>
                <input type="text" id="stripe_public_key" name="stripe_public_key" value="<?php echo htmlspecialchars($current_settings['stripe_public_key'] ?? '', ENT_QUOTES); ?>">
                <label for="stripe_secret_key">Stripe Secret Key:</label>
                <input type="text" id="stripe_secret_key" name="stripe_secret_key" value="<?php echo htmlspecialchars($current_settings['stripe_secret_key'] ?? '', ENT_QUOTES); ?>">

                <hr>

                <h4>PayPal</h4>
                <label>
                    <input type="checkbox" name="enable_paypal" value="1" <?php echo (isset($current_settings['enable_paypal']) && $current_settings['enable_paypal'] == '1') ? 'checked' : ''; ?>>
                    Enable PayPal Payments
                </label>
                <label for="paypal_client_id">PayPal Client ID:</label>
                <input type="text" id="paypal_client_id" name="paypal_client_id" value="<?php echo htmlspecialchars($current_settings['paypal_client_id'] ?? '', ENT_QUOTES); ?>">
                <label for="paypal_secret">PayPal Secret:</label>
                <input type="text" id="paypal_secret" name="paypal_secret" value="<?php echo htmlspecialchars($current_settings['paypal_secret'] ?? '', ENT_QUOTES); ?>">

                <hr>

                <h4>GoCardless</h4>
                <label>
                    <input type="checkbox" name="enable_gocardless" value="1" <?php echo (isset($current_settings['enable_gocardless']) && $current_settings['enable_gocardless'] == '1') ? 'checked' : ''; ?>>
                    Enable GoCardless Payments
                </label>
                <label for="gocardless_access_token">GoCardless Access Token:</label>
                <input type="text" id="gocardless_access_token" name="gocardless_access_token" value="<?php echo htmlspecialchars($current_settings['gocardless_access_token'] ?? '', ENT_QUOTES); ?>">
                <label for="gocardless_webhook_secret">GoCardless Webhook Secret:</label>
                <input type="text" id="gocardless_webhook_secret" name="gocardless_webhook_secret" value="<?php echo htmlspecialchars($current_settings['gocardless_webhook_secret'] ?? '', ENT_QUOTES); ?>">

                <button type="submit" name="save_settings">Save Payment Settings</button>
            </form>
        <?php elseif ($active_tab === 'email'): ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <label for="smtp_host">SMTP Host:</label>
                <input type="text" id="smtp_host" name="smtp_host" value="<?php echo htmlspecialchars($current_settings['smtp_host'] ?? '', ENT_QUOTES); ?>" required>

                <label for="smtp_port">SMTP Port:</label>
                <input type="text" id="smtp_port" name="smtp_port" value="<?php echo htmlspecialchars($current_settings['smtp_port'] ?? '', ENT_QUOTES); ?>" required>

                <label for="smtp_encryption">Encryption:</label>
                <select id="smtp_encryption" name="smtp_encryption">
                    <option value="tls" <?php echo (isset($current_settings['smtp_encryption']) && $current_settings['smtp_encryption'] === 'tls') ? 'selected' : ''; ?>>TLS</option>
                    <option value="ssl" <?php echo (isset($current_settings['smtp_encryption']) && $current_settings['smtp_encryption'] === 'ssl') ? 'selected' : ''; ?>>SSL</option>
                </select>

                <label for="smtp_username">SMTP Username:</label>
                <input type="text" id="smtp_username" name="smtp_username" value="<?php echo htmlspecialchars($current_settings['smtp_username'] ?? '', ENT_QUOTES); ?>" required>

                <label for="smtp_password">SMTP Password:</label>
                <input type="password" id="smtp_password" name="smtp_password" value="<?php echo htmlspecialchars($current_settings['smtp_password'] ?? '', ENT_QUOTES); ?>" required>

                <button type="submit" name="save_email_settings">Save Email Settings</button>
            </form>

            <h3>Test Email</h3>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <label for="test_email">Send Test Email To:</label>
                <input type="email" id="test_email" name="test_email" required>
                <button type="submit" name="send_test_email">Send Test Email</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>

<?php require_once 'includes/footer.php'; ?>