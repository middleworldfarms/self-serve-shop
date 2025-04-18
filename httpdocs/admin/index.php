<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$error = '';

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

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'], $_POST['password'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    try {
        $stmt = $db->prepare("SELECT id, username, password, role FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_username'] = $user['username'];
            $_SESSION['admin_user_id'] = $user['id'];
            $_SESSION['admin_role'] = $user['role'];
            header('Location: index.php');
            exit;
        } else {
            $error = "Invalid username or password.";
        }
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
}

// Handle logout (if you want to allow ?logout=1)
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header('Location: index.php');
    exit;
}

// If not logged in, show login form
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Login</title>
    <style>
        body { font-family: sans-serif; background: #f4f4f4; }
        .login-container {
            max-width: 400px; margin: 80px auto; background: #fff; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        .login-container h2 { text-align: center; }
        .login-container label { display: block; margin-top: 1rem; }
        .login-container input { width: 100%; padding: 0.5rem; margin-top: 0.5rem; }
        .login-container button { width: 100%; padding: 0.7rem; margin-top: 1.5rem; background: #388E3C; color: #fff; border: none; border-radius: 4px; font-size: 1.1rem; }
        .error-message { color: #b71c1c; text-align: center; margin-top: 1rem; }
    </style>
</head>
<body>
    <div class="login-container">
        <h2>Admin Login</h2>
        <?php if ($error): ?>
            <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <form method="post" autocomplete="off">
            <label for="username">Username:</label>
            <input type="text" name="username" id="username" required autofocus>
            <label for="password">Password:</label>
            <input type="password" name="password" id="password" required>
            <button type="submit">Login</button>
        </form>
    </div>
</body>
</html>
<?php
    exit;
}

// ---- If logged in, show dashboard as before ----

require_once 'includes/header.php';

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$is_authenticated = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;

// Handle product availability updates
if ($is_authenticated && isset($_POST['update_availability'])) {
    $available_products = $_POST['available_products'] ?? [];
    try {
        $postmeta_table = 'postmeta';
        $reset_stmt = $db->prepare("UPDATE `$postmeta_table` SET meta_value = 'no' WHERE meta_key = '_self_serve_available'");
        $reset_stmt->execute();
        foreach ($available_products as $product_id) {
            $product_id = intval($product_id);
            $check_stmt = $db->prepare("SELECT meta_id FROM `$postmeta_table` WHERE post_id = :post_id AND meta_key = '_self_serve_available'");
            $check_stmt->bindParam(':post_id', $product_id, PDO::PARAM_INT);
            $check_stmt->execute();
            if ($check_stmt->rowCount() > 0) {
                $update_stmt = $db->prepare("UPDATE `$postmeta_table` SET meta_value = 'yes' WHERE post_id = :post_id AND meta_key = '_self_serve_available'");
                $update_stmt->bindParam(':post_id', $product_id, PDO::PARAM_INT);
                $update_stmt->execute();
            } else {
                $insert_stmt = $db->prepare("INSERT INTO `$postmeta_table` (post_id, meta_key, meta_value) VALUES (:post_id, '_self_serve_available', 'yes')");
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

// Get products
require_once '../includes/get_products.php';
$all_products = get_all_woocommerce_products();
?>

<style>
    .dashboard-summary {
        display: flex;
        gap: 2rem;
        justify-content: center;
        margin: 2rem 0 2.5rem 0;
    }
    .dashboard-card {
        background: #fff;
        border-radius: 8px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.07);
        padding: 1.5rem 2.5rem;
        text-align: center;
        min-width: 180px;
    }
    .dashboard-card h4 {
        margin: 0 0 0.5rem 0;
        color: #388E3C;
    }
    .dashboard-card .big {
        font-size: 2.2rem;
        font-weight: bold;
        color: #222;
    }
    .product-availability-section {
        background: #fff;
        border-radius: 8px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.07);
        padding: 2rem;
        margin: 2rem auto;
        max-width: 900px;
    }
    .product-list {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 1.5rem;
        margin-top: 1.5rem;
    }
    .product-item {
        background: #f8f8f8;
        border-radius: 8px;
        padding: 1rem;
        display: flex;
        flex-direction: column;
        align-items: center;
        box-shadow: 0 1px 4px rgba(0,0,0,0.04);
        position: relative;
    }
    .product-item input[type="checkbox"] {
        position: absolute;
        top: 12px;
        left: 12px;
        transform: scale(1.3);
    }
    .product-image {
        width: 80px;
        height: 80px;
        object-fit: contain;
        margin-bottom: 0.5rem;
    }
    .product-name {
        font-weight: bold;
        margin-bottom: 0.3rem;
        text-align: center;
    }
    .product-price {
        color: #388E3C;
        font-weight: bold;
        margin-bottom: 0.5rem;
    }
    .product-actions {
        display: flex;
        gap: 1rem;
        margin-top: 1rem;
    }
    .availability-controls {
        display: flex;
        justify-content: flex-end;
        gap: 1rem;
        margin-bottom: 1rem;
    }
    .success-message, .error-message {
        padding: 12px 18px;
        border-radius: 4px;
        margin-bottom: 18px;
        font-weight: bold;
        text-align: center;
    }
    .success-message {
        background: #e6f4ea;
        color: #256029;
    }
    .error-message {
        background: #fdecea;
        color: #b71c1c;
    }
    @media (max-width: 700px) {
        .dashboard-summary {
            flex-direction: column;
            gap: 1rem;
        }
        .product-list {
            grid-template-columns: 1fr;
        }
    }
    .admin-container {
        max-width: 1100px;
        margin: 2rem auto;
        background: #fff;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        padding: 2rem;
    }
</style>

<div class="admin-container">
    <h1 style="text-align:center; margin-top:2rem;">Admin Dashboard</h1>

    <!-- Dashboard summary cards -->
    <div class="dashboard-summary">
        <div class="dashboard-card">
            <h4>Products</h4>
            <div class="big"><?php echo is_array($all_products) ? count($all_products) : 0; ?></div>
        </div>
        <div class="dashboard-card">
            <h4>Available Today</h4>
            <div class="big">
                <?php
                $available_count = 0;
                if (is_array($all_products)) {
                    foreach ($all_products as $product) {
                        if (!empty($product['available'])) $available_count++;
                    }
                }
                echo $available_count;
                ?>
            </div>
        </div>
        <div class="dashboard-card">
            <h4>Admins</h4>
            <div class="big">
                <?php
                $stmt = $db->query("SELECT COUNT(*) FROM users WHERE role='admin' OR role='administrator'");
                echo $stmt ? $stmt->fetchColumn() : 0;
                ?>
            </div>
        </div>
    </div>

    <!-- Product Availability Section -->
    <div class="product-availability-section">
        <h2 style="text-align:center;">Manage Available Products</h2>
        <p style="text-align:center;">Tick the products that are available in your self-serve shop today.</p>

        <?php if (isset($update_success)) : ?>
            <div class="success-message">Product availability has been updated successfully!</div>
        <?php endif; ?>
        <?php if (isset($update_error)) : ?>
            <div class="error-message"><?php echo $update_error; ?></div>
        <?php endif; ?>

        <form method="post" action="">
            <div class="availability-controls">
                <button id="select-all" type="button">Select All</button>
                <button id="deselect-all" type="button">Deselect All</button>
            </div>
            <div class="product-list">
                <?php
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
                <button type="submit" name="update_availability" style="font-size:1.1rem;">Update Available Products</button>
            </div>
        </form>
    </div>

    <!-- QR Code Section -->
    <div style="margin-top: 3rem; border-top: 1px solid #eee; padding-top: 2rem; text-align: center;">
        <h3>Self-Serve Shop QR Code</h3>
        <p>Use this QR code in your shop for customers to scan.</p>
        <div style="margin: 2rem 0;">
            <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?php echo urlencode('https://middleworldfarms.org/self-serve-shop/'); ?>" alt="Self-Serve Shop QR Code">
        </div>
        <p>This QR code links to: <a href="https://middleworldfarms.org/self-serve-shop/" target="_blank">https://middleworldfarms.org/self-serve-shop/</a></p>
    </div>
</div>

<script>
    // Select/Deselect all functionality
    document.getElementById('select-all').addEventListener('click', function() {
        document.querySelectorAll('.product-checkbox').forEach(cb => cb.checked = true);
    });
    document.getElementById('deselect-all').addEventListener('click', function() {
        document.querySelectorAll('.product-checkbox').forEach(cb => cb.checked = false);
    });
</script>

<?php require_once 'includes/footer.php'; ?>
</html>