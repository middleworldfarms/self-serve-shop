<?php
require_once '../config.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Add CSRF protection
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Check if user is logged in
$is_authenticated = false;

// Check session first
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    $is_authenticated = true;
}

// Handle login
if (isset($_POST['login'])) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    try {
        $db = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME,
            DB_USER,
            DB_PASS
        );
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Get the user from the database
        $stmt = $db->prepare("SELECT id, username, password, role FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Check if user exists and password is correct
        if ($user) {
            error_log("Username: $username");
            error_log("Password entered: $password");
            error_log("Password hash from DB: " . $user['password']);
            error_log("Password correct: " . (password_verify($password, $user['password']) ? 'true' : 'false'));
            error_log("Role from DB: " . $user['role']);
            error_log("Is admin: " . ($user['role'] === 'admin' || $user['role'] === 'administrator' ? 'true' : 'false'));

            $passwordCorrect = password_verify($password, $user['password']);
            $isAdmin = ($user['role'] === 'admin' || $user['role'] === 'administrator');

            if ($isAdmin && $passwordCorrect) {
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_user_id'] = $user['id'];
                $_SESSION['admin_username'] = $user['username'];
                $is_authenticated = true;
            } else {
                $login_error = "Invalid username or password.";
            }
        } else {
            $login_error = "Invalid username or password.";
        }
    } catch (PDOException $e) {
        $login_error = "Database error: " . $e->getMessage();
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    unset($_SESSION['admin_logged_in']);
    header('Location: index.php');
    exit;
}

// Handle product availability updates
if ($is_authenticated && isset($_POST['update_availability'])) {
    $available_products = $_POST['available_products'] ?? [];

    try {
        $prefix = TABLE_PREFIX;
        $postmeta_table = $prefix . 'postmeta';

        $db = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME,
            DB_USER,
            DB_PASS
        );
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Reset all products to unavailable
        $reset_stmt = $db->prepare("
            UPDATE `{$postmeta_table}` 
            SET meta_value = 'no' 
            WHERE meta_key = '_self_serve_available'
        ");
        $reset_stmt->execute();

        // Set selected products as available
        foreach ($available_products as $product_id) {
            $product_id = intval($product_id);

            // Check if meta exists
            $check_stmt = $db->prepare("
                SELECT meta_id FROM `{$postmeta_table}` 
                WHERE post_id = :post_id AND meta_key = '_self_serve_available'
            ");
            $check_stmt->bindParam(':post_id', $product_id, PDO::PARAM_INT);
            $check_stmt->execute();

            if ($check_stmt->rowCount() > 0) {
                // Update existing meta
                $update_stmt = $db->prepare("
                    UPDATE `{$postmeta_table}` 
                    SET meta_value = 'yes' 
                    WHERE post_id = :post_id AND meta_key = '_self_serve_available'
                ");
                $update_stmt->bindParam(':post_id', $product_id, PDO::PARAM_INT);
                $update_stmt->execute();
            } else {
                // Insert new meta
                $insert_stmt = $db->prepare("
                    INSERT INTO `{$postmeta_table}` (post_id, meta_key, meta_value) 
                    VALUES (:post_id, '_self_serve_available', 'yes')
                ");
                $insert_stmt->bindParam(':post_id', $product_id, PDO::PARAM_INT);
                $insert_stmt->execute();
            }
        }

        $update_success = true;
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        $update_error = "There was an error updating product availability.";
    }
}

// Handle reset password success message
$reset_success = isset($_GET['reset']) && $_GET['reset'] === 'success';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Self-Serve Shop</title>
    <link rel="stylesheet" href="../css/styles.css">
    <link rel="stylesheet" href="/css/fix-stray-price.css">
    <style>
        .admin-container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 2rem;
            background-color: white;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
        }
         
        .login-form {
            max-width: 400px;
            margin: 0 auto;
        }
        
        .admin-actions {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2rem;
        }
        
        .product-list {
            border: 1px solid #eee;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .product-item {
            display: flex;
            align-items: center;
            padding: 1rem;
            border-bottom: 1px solid #eee;
        }
        
        .product-item:last-child {
            border-bottom: none;
        }
        
        .product-checkbox {
            margin-right: 1rem;
        }
        
        .product-image {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 4px;
            margin-right: 1rem;
        }
        
        .product-name {
            flex: 1;
        }
        
        .product-price {
            margin: 0 1rem;
            font-weight: bold;
        }
        
        .success-message {
            background-color: #d4edda;
            color: #155724;
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1rem;
        }
        
        .error-message {
            background-color: #f8d7da;
            color: #721c24;
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1rem;
        }
        
        .warning-message {
            background-color: #FEEFB3;
            color: #9F6000;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 20px;
        }

        .admin-dashboard {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
            padding-bottom: 30px;
            border-bottom: 1px solid #eee;
        }

        .admin-action {
            background-color: #f9f9f9;
            border-radius: 8px;
            padding: 20px;
            border: 1px solid #eee;
            transition: all 0.3s ease;
        }

        .admin-action:hover {
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transform: translateY(-3px);
        }

        .admin-action h3 {
            margin-top: 0;
            color: #333;
        }

        .admin-action p {
            color: #666;
            margin-bottom: 15px;
        }
        
        .section-title {
            margin-top: 2rem;
            padding-top: 1rem;
            border-top: 1px solid #eee;
        }

        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 2rem;
            background-color: #f8f9fa;
        }

        .header-links {
            text-align: right;
        }

        .back-link {
            display: inline-block;
            padding: 8px 15px;
            background-color: #6c757d;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            transition: background-color 0.2s;
        }

        .back-link:hover {
            background-color: #5a6268;
        }

        .admin-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 2rem;
            background-color: #f8f9fa;
        }

        .header-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
        }

        .header-right {
            text-align: right;
        }

        .logout-link {
            display: inline-block;
            padding: 8px 15px;
            background-color: #6c757d;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            transition: background-color 0.2s;
        }

        .logout-link:hover {
            background-color: #5a6268;
        }

        .password-container {
            position: relative;
            display: flex;
            width: 100%;
        }

        .password-container input[type="password"],
        .password-container input[type="text"] {
            flex: 1;
            width: 100%;
        }

        .password-toggle {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: #666;
            font-size: 16px;
        }

        .password-toggle:focus {
            outline: none;
        }
    </style>
    <script src="/js/minimal-fix.js"></script>
</head>
<body>
    <header class="admin-header">
        <div class="header-container">
            <h1>Middle World Farms Self-Serve Shop Admin</h1>
            <?php if ($is_authenticated) : ?>
            <div class="header-right">
                <a href="?logout=1" class="logout-link">Logout</a>
            </div>
            <?php endif; ?>
        </div>
    </header>
    
    <main>
        <?php if (!$is_authenticated) : ?>
        <!-- Login Form -->
        <div class="admin-container login-form">
            <h2>Admin Login</h2>
            
            <?php if (isset($login_error)) : ?>
            <div class="error-message">
                <?php echo $login_error; ?>
            </div>
            <?php endif; ?>
            
            <?php if ($reset_success): ?>
                <div class="success-message" style="background-color: #DFF2BF; color: #4F8A10; padding: 10px; border-radius: 4px; margin-bottom: 20px;">
                    Your password has been reset successfully. Please log in with your new password.
                </div>
            <?php endif; ?>
            
            <form method="post" action="">
                <div class="form-row">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" required>
                </div>
                
                <div class="form-row">
                    <label for="password">Password</label>
                    <div class="password-container">
                        <input type="password" id="password" name="password" required>
                        <button type="button" class="password-toggle" id="togglePassword">👁️</button>
                    </div>
                </div>
                
                <div class="form-row" style="text-align: center; margin-top: 15px;">
                    <a href="reset-password.php">Forgot your password?</a>
                </div>
                
                <button type="submit" name="login">Login</button>
            </form>
        </div>
        <?php else : ?>
        <!-- Admin Dashboard -->
        <div class="admin-container">
            <?php if (isset($update_success)) : ?>
            <div class="success-message">
                Product availability has been updated successfully!
            </div>
            <?php endif; ?>
            
            <?php if (isset($update_error)) : ?>
            <div class="error-message">
                <?php echo $update_error; ?>
            </div>
            <?php endif; ?>
            
            <?php if ($is_authenticated && isset($_SESSION['admin_username']) && $_SESSION['admin_username'] === 'admin'): ?>
            <div class="warning-message">
                <strong>Security Notice:</strong> You're using the default admin account. Please change your password and consider creating a new admin account with a different username for better security.
                <p><a href="manage-users.php" class="button">Manage Users</a></p>
            </div>
            <?php endif; ?>
            
            <!-- MOVED: Admin Dashboard to the top -->
            <div class="admin-dashboard">
                <div class="admin-action">
                    <h3>Manage Products</h3>
                    <p>Add, edit, and delete products displayed in the shop</p>
                    <a href="manage-products.php" class="button">Manage Products</a>
                </div>
                
                <div class="admin-action">
                    <h3>Manage Users</h3>
                    <p>Add, edit, and delete user accounts</p>
                    <a href="manage-users.php" class="button">Manage Users</a>
                </div>
                
                <div class="admin-action">
                    <h3>Shop Settings</h3>
                    <p>Configure shop settings including database connection</p>
                    <a href="settings.php" class="button">Settings</a>
                </div>
                
                <div class="admin-action">
                    <h3>View Shop</h3>
                    <p>Go to the shop front page</p>
                    <a href="../index.php" class="button">View Shop</a>
                </div>
            </div>
            
            <!-- Product Availability Section -->
            <h2 class="section-title">Manage Available Products</h2>
            <p>Select the products that are available in your self-serve shop today.</p>
            
            <div class="admin-actions">
                <div>
                    <p>Products marked as available will be shown to customers in the shop.</p>
                </div>
                <div>
                    <button id="select-all" type="button">Select All</button>
                    <button id="deselect-all" type="button">Deselect All</button>
                </div>
            </div>
            
            <form method="post" action="">
                <div class="product-list">
                    <?php
                    // Include WooCommerce product integration
                    require_once '../includes/get_products.php';
                    
                    // Get all products from WooCommerce
                    $all_products = get_all_woocommerce_products();
                    
                    if (empty($all_products)) {
                        echo '<div style="padding: 1rem; text-align: center;">No products found. Please check your database connection.</div>';
                    } else {
                        foreach ($all_products as $product) :
                    ?>
                    <div class="product-item">
                        <input type="checkbox" id="product-<?php echo $product['id']; ?>" 
                               name="available_products[]" value="<?php echo $product['id']; ?>"
                               class="product-checkbox"
                               <?php echo (isset($product['available']) && $product['available']) ? 'checked' : ''; ?>>
                        <img src="<?php echo $product['image']; ?>" alt="<?php echo $product['name']; ?>" class="product-image">
                        <label for="product-<?php echo $product['id']; ?>" class="product-name"><?php echo $product['name']; ?></label>
                        <span class="product-price"><?php echo display_currency($product['price']); ?></span>
                    </div>
                    <?php 
                        endforeach;
                    } 
                    ?>
                </div>
                
                <div style="margin-top: 2rem; text-align: center;">
                    <button type="submit" name="update_availability">Update Available Products</button>
                </div>
            </form>
            
            <div style="margin-top: 3rem; border-top: 1px solid #eee; padding-top: 2rem; text-align: center;">
                <h3>Self-Serve Shop QR Code</h3>
                <p>Use this QR code in your shop for customers to scan.</p>
                <div style="margin: 2rem 0;">
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?php echo urlencode('https://middleworldfarms.org/self-serve-shop/'); ?>" alt="Self-Serve Shop QR Code">
                </div>
                <p>This QR code links to: <a href="https://middleworldfarms.org/self-serve-shop/" target="_blank">https://middleworldfarms.org/self-serve-shop/</a></p>
            </div>
        </div>
        <?php endif; ?>
    </main>
    
    <footer>
        <p>&copy; <?php echo date('Y'); ?> Middle World Farms. All rights reserved.</p>
    </footer>
    
    <?php if ($is_authenticated) : ?>
    <script>
        // Select/Deselect all functionality
        document.getElementById('select-all').addEventListener('click', function() {
            const checkboxes = document.querySelectorAll('.product-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = true;
            });
        });
        
        document.getElementById('deselect-all').addEventListener('click', function() {
            const checkboxes = document.querySelectorAll('.product-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = false;
            });
        });
    </script>
    <?php endif; ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Password toggle functionality
        const togglePassword = document.getElementById('togglePassword');
        const passwordField = document.getElementById('password');
        
        if (togglePassword && passwordField) {
            togglePassword.addEventListener('click', function() {
                if (passwordField.type === 'password') {
                    passwordField.type = 'text';
                    this.textContent = '🔒';
                } else {
                    passwordField.type = 'password';
                    this.textContent = '👁️';
                }
            });
        }
    });
    </script>
</body>
</html>