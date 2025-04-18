<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// Database connection (only once!)
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

// Admin authentication check
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

// CSRF protection
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Only run table creation ONCE, or move this to a setup script
function table_exists($tableName) {
    global $db;
    $stmt = $db->query("SHOW TABLES LIKE '$tableName'");
    return $stmt->rowCount() > 0;
}
if (!table_exists('self_serve_settings')) {
    // ... create table code ...
}

// Now include header (which defines get_settings/save_settings)
require_once 'includes/header.php';

$current_settings = get_settings();

$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Access denied. Invalid CSRF token.");
    }

    if (isset($_POST['save_settings'])) {
        $settings = $current_settings;
        foreach ($_POST as $key => $value) {
            if ($key !== 'csrf_token' && $key !== 'save_settings') {
                $settings[$key] = $value;
            }
        }
        $checkboxes = ['enable_manual_payment', 'enable_stripe', 'enable_paypal', 'enable_gocardless'];
        foreach ($checkboxes as $cb) {
            if (!isset($_POST[$cb])) {
                $settings[$cb] = '0';
            }
        }
        if (isset($_FILES['site_logo']) && $_FILES['site_logo']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/uploads/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            $ext = pathinfo($_FILES['site_logo']['name'], PATHINFO_EXTENSION);
            $filename = 'logo_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $target = $upload_dir . $filename;
            if (move_uploaded_file($_FILES['site_logo']['tmp_name'], $target)) {
                $settings['site_logo'] = 'admin/uploads/' . $filename;
            }
        }
        if (save_settings($settings)) {
            $message = "Settings updated successfully.";
            $current_settings = get_settings();
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
        if (empty($email_settings['smtp_host']) || empty($email_settings['smtp_username']) || empty($email_settings['smtp_password'])) {
            $error = "SMTP Host, Username, and Password are required.";
        } else {
            if (save_settings($email_settings)) {
                $message = "Email settings updated successfully.";
                $current_settings = get_settings();
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
                $mail->isSMTP();
                $mail->Host = $current_settings['smtp_host'];
                $mail->SMTPAuth = true;
                $mail->Username = $current_settings['smtp_username'];
                $mail->Password = $current_settings['smtp_password'];
                $mail->SMTPSecure = $current_settings['smtp_encryption'];
                $mail->Port = $current_settings['smtp_port'];
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

$active_tab = $_GET['tab'] ?? 'general';
$allowed_tabs = ['general', 'branding', 'payment', 'email'];
if (!in_array($active_tab, $allowed_tabs)) {
    $active_tab = 'general';
}
?>

<?php require_once 'includes/header.php'; ?>

<style>
body {
    background: #f5f7fa;
}
.admin-container {
    margin: 40px auto;
    max-width: 800px;
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 4px 24px rgba(60,72,88,0.08), 0 1.5px 4px rgba(60,72,88,0.04);
    padding: 36px 36px 24px 36px;
}
h2 {
    margin-bottom: 28px;
    color: #222;
    font-size: 2rem;
    font-weight: 700;
    letter-spacing: -1px;
}
.settings-tabs {
    margin-bottom: 32px;
    display: flex;
    gap: 8px;
    border-bottom: 1.5px solid #e0e4ea;
}
.settings-tabs a {
    display: inline-block;
    padding: 12px 32px 10px 32px;
    background: none;
    color: #555;
    border: none;
    border-radius: 10px 10px 0 0;
    text-decoration: none;
    font-weight: 600;
    font-size: 1.08em;
    transition: background 0.15s, color 0.15s;
    position: relative;
    top: 2px;
}
.settings-tabs a.active {
    background: #fff;
    color: #388E3C;
    border-bottom: 2.5px solid #388E3C;
    box-shadow: 0 2px 8px rgba(56,142,60,0.06);
}
.admin-settings-form {
    background: #f8fafc;
    border-radius: 10px;
    padding: 28px 24px 18px 24px;
    box-shadow: 0 1px 4px rgba(60,72,88,0.04);
    margin-bottom: 32px;
}
.admin-settings-form label {
    display: block;
    margin-top: 18px;
    font-weight: 600;
    color: #333;
    letter-spacing: 0.01em;
}
.admin-settings-form input[type="text"],
.admin-settings-form input[type="email"],
.admin-settings-form input[type="password"],
.admin-settings-form textarea,
.admin-settings-form select {
    width: 100%;
    padding: 10px 12px;
    margin-top: 6px;
    border: 1.5px solid #e0e4ea;
    border-radius: 6px;
    box-sizing: border-box;
    font-size: 1em;
    background: #fff;
    transition: border 0.2s;
}
.admin-settings-form input:focus,
.admin-settings-form textarea:focus,
.admin-settings-form select:focus {
    border-color: #388E3C;
    outline: none;
}
.admin-settings-form button {
    margin-top: 28px;
    padding: 12px 32px;
    background: linear-gradient(90deg, #388E3C 60%, #43A047 100%);
    color: #fff;
    border: none;
    border-radius: 6px;
    font-size: 1.1rem;
    font-weight: 600;
    cursor: pointer;
    box-shadow: 0 2px 8px rgba(56,142,60,0.08);
    transition: background 0.18s;
}
.admin-settings-form button:hover {
    background: linear-gradient(90deg, #256029 60%, #388E3C 100%);
}
.success-message, .error-message {
    margin: 18px 0;
    padding: 14px 22px;
    border-radius: 6px;
    font-weight: 600;
    font-size: 1.08em;
    letter-spacing: 0.01em;
}
.success-message { background: #e8f5e9; color: #256029; border: 1.5px solid #b6e2c1; }
.error-message { background: #ffebee; color: #b71c1c; border: 1.5px solid #f7bdbd; }
.admin-settings-form small {
    color: #888;
    font-size: 0.97em;
    margin-bottom: 10px;
    display: block;
}
@media (max-width: 900px) {
    .admin-container { margin: 10px 0; padding: 10px 2vw; }
    .admin-settings-form { padding: 14px 6px; }
    .settings-tabs a { padding: 10px 10px 8px 10px; font-size: 1em; }
}
</style>

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
        <form method="post" class="admin-settings-form">
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
        <form method="post" enctype="multipart/form-data" class="admin-settings-form">
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
        <form method="post" class="admin-settings-form">
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
        <form method="post" class="admin-settings-form">
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
        <form method="post" class="admin-settings-form">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <label for="test_email">Send Test Email To:</label>
            <input type="email" id="test_email" name="test_email" required>
            <button type="submit" name="send_test_email">Send Test Email</button>
        </form>
    <?php endif; ?>
</div>

<?php require_once 'includes/footer.php'; ?>