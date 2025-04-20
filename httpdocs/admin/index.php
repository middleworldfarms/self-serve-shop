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

// Handle logout
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

// ---- If logged in, show dashboard ----

require_once 'includes/header.php';

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Get orders data - shows recent activity
try {
    $orders_query = $db->query("SELECT COUNT(*) as total, SUM(total_amount) as revenue FROM orders");
    $orders_data = $orders_query->fetch(PDO::FETCH_ASSOC);
    
    $today = date('Y-m-d');
    $today_orders_query = $db->query("SELECT COUNT(*) as total, SUM(total_amount) as revenue FROM orders WHERE DATE(created_at) = '$today'");
    $today_orders = $today_orders_query->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    $orders_data = ['total' => 0, 'revenue' => 0];
    $today_orders = ['total' => 0, 'revenue' => 0];
}

// Get current settings
$current_settings = function_exists('get_settings') ? get_settings() : [];
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
    .admin-section {
        background: #fff;
        border-radius: 8px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.07);
        padding: 2rem;
        margin: 2rem auto;
        max-width: 900px;
    }
    @media (max-width: 700px) {
        .dashboard-summary {
            flex-direction: column;
            gap: 1rem;
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
    .quick-links {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1.5rem;
        margin: 2rem 0;
    }
    .quick-link {
        background: #f8f8f8;
        border-radius: 8px;
        padding: 1.5rem;
        text-align: center;
        box-shadow: 0 1px 4px rgba(0,0,0,0.04);
        transition: transform 0.2s, box-shadow 0.2s;
    }
    .quick-link:hover {
        transform: translateY(-3px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.08);
    }
    .quick-link a {
        color: #388E3C;
        text-decoration: none;
        font-weight: bold;
        font-size: 1.1rem;
    }
</style>

<div class="admin-container">
    <h1 style="text-align:center; margin-top:1rem;">Admin Dashboard</h1>

    <!-- Dashboard summary cards -->
    <div class="dashboard-summary">
        <div class="dashboard-card">
            <h4>Total Orders</h4>
            <div class="big"><?php echo number_format($orders_data['total'] ?? 0); ?></div>
        </div>
        <div class="dashboard-card">
            <h4>Today's Orders</h4>
            <div class="big"><?php echo number_format($today_orders['total'] ?? 0); ?></div>
        </div>
        <div class="dashboard-card">
            <h4>Today's Revenue</h4>
            <div class="big">£<?php echo number_format(($today_orders['revenue'] ?? 0), 2); ?></div>
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

    <!-- Quick Links Section -->
    <div class="admin-section">
        <h2 style="text-align:center;">Quick Links</h2>
        
        <div class="quick-links">
            <div class="quick-link">
                <a href="settings.php">Shop Settings</a>
                <p>Configure shop name, appearance, and payment options</p>
            </div>
            <div class="quick-link">
                <a href="manage-users.php">User Management</a>
                <p>Add and manage admin users</p>
            </div>
            <div class="quick-link">
                <a href="reports.php">Sales Reports</a>
                <p>View daily and monthly sales data</p>
            </div>
            <div class="quick-link">
                <a href="https://middleworldfarms.org/wp-admin/edit.php?post_type=product" target="_blank">Manage Products</a>
                <p>Add and edit products in WooCommerce</p>
            </div>
        </div>
    </div>

    <!-- QR Code Section -->
    <div class="admin-section">
        <h2 style="text-align:center;">Self-Serve Shop QR Code</h2>
        <p style="text-align:center;">Use this QR code in your shop for customers to scan.</p>
        
        <?php 
        // Get the self-serve URL from settings, with fallback
        $self_serve_url = $current_settings['self_serve_url'] ?? 'https://middleworld.farm';
        
        // Make sure it has a protocol
        if (!preg_match('~^(?:f|ht)tps?://~i', $self_serve_url)) {
            $self_serve_url = 'https://' . $self_serve_url;
        }
        
        // Remove trailing slash if present
        $self_serve_url = rtrim($self_serve_url, '/');
        ?>
        
        <div style="margin: 2rem 0; text-align: center;">
            <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?php echo urlencode($self_serve_url); ?>" alt="Self-Serve Shop QR Code">
        </div>
        <p style="text-align:center;">This QR code links to: <a href="<?php echo htmlspecialchars($self_serve_url); ?>" target="_blank"><?php echo htmlspecialchars($self_serve_url); ?></a></p>
        <div style="text-align: center; margin-top: 1rem;">
            <a href="https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=<?php echo urlencode($self_serve_url); ?>&download=1" class="btn" style="display: inline-block; background: #388E3C; color: white; padding: 8px 16px; text-decoration: none; border-radius: 4px;">Download QR Code</a>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>