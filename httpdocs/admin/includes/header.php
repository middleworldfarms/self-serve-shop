<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../config.php';

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

// Only redirect if not on login or reset pages
// TEMPORARILY DISABLE THIS BLOCK TO REGAIN ACCESS
// $current_file = basename($_SERVER['PHP_SELF']);
// if (!in_array($current_file, ['index.php', 'reset-password.php', 'emergency-login.php', 'new-password.php'])) {
//     if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
//         header('Location: index.php');
//         exit;
//     }
// }

$settings = function_exists('get_settings') ? get_settings() : [];
$primary = $settings['primary_color'] ?? '#4CAF50';
$secondary = $settings['secondary_color'] ?? '#388E3C';
$shop_name = $settings['shop_name'] ?? 'Self-Serve Shop';
$logo = !empty($settings['site_logo']) ? '/' . ltrim($settings['site_logo'], '/') : '';
$current_file = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($shop_name); ?> Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if (!empty($settings['custom_css'])): ?>
        <style><?php echo $settings['custom_css']; ?></style>
    <?php endif; ?>
    <style>
        .admin-header {
            background: <?php echo $primary; ?>;
            color: #fff;
            padding: 0; /* Remove all padding */
            border-bottom: 4px solid <?php echo $secondary; ?>;
        }
        
        .admin-header-inner {
            display: grid;
            grid-template-columns: 160px 1fr 160px;
            align-items: center;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 24px 8px; /* Add bottom padding instead of top */
        }
        
        .admin-header-logo {
            justify-self: start;
        }
        .admin-header-logo img {
            max-height: 96px;
            max-width: 150px;
            width: auto;
            height: auto;
            margin-bottom: 0;
            margin-top: -48px; /* Push up even more */
            display: block;
            position: relative;
            z-index: 2;
        }
        .admin-header-title {
            justify-self: center;
            text-align: center;
            font-size: 2rem; /* Increased from 1.3rem */
            margin: 0;
            letter-spacing: 1px;
            font-weight: bold;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.2); /* Optional: adds a subtle shadow for better readability */
        }
        .admin-navbar {
            display: flex;
            gap: 18px;
            padding: 12px 24px 10px; /* Adjust the navbar to balance the layout */
            background: <?php echo $primary; ?>;
            color: #fff;
            justify-content: center;
        }
        .admin-navbar a {
            color: #fff;
            text-decoration: none;
            font-weight: bold;
            padding: 10px 20px;
            border-radius: 4px;
            transition: background 0.2s;
        }
        .admin-navbar a.active {
            background: <?php echo $secondary; ?>;
        }
        .admin-navbar a:hover {
            background: #256029;
        }
        body {
            margin: 0;
            padding: 0;
        }
        .shop-header-title {
            justify-self: center;
            text-align: center;
            font-size: 2.6rem;    /* Make it bigger */
            margin: 0 auto;       /* Center horizontally */
            letter-spacing: 1px;
            font-weight: bold;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.2);
            line-height: 1.2;
            padding: 12px 0 8px 0;
            width: 100%;
            display: block;
        }
    </style>
</head>
<body>
    <nav class="admin-navbar">
        <a href="/admin/index.php" class="<?php echo ($current_file === 'index.php') ? 'active' : ''; ?>">Dashboard</a>
        <a href="/admin/manage-products.php" class="<?php echo ($current_file === 'manage-products.php') ? 'active' : ''; ?>">Products</a>
        <a href="/admin/manage-users.php" class="<?php echo ($current_file === 'manage-users.php') ? 'active' : ''; ?>">Users</a>
        <a href="/admin/settings.php" class="<?php echo ($current_file === 'settings.php') ? 'active' : ''; ?>">Settings</a>
        <a href="/" target="_blank" style="background:#1976d2;">View Shop</a>
        <a href="/admin/logout.php" style="background:#b71c1c;">Logout</a>
    </nav>
    <div class="admin-header">
        <div class="admin-header-inner">
            <div class="admin-header-logo">
                <?php if ($logo): ?>
                    <img src="<?php echo htmlspecialchars($logo); ?>" alt="Site Logo">
                <?php endif; ?>
            </div>
            <div class="shop-header-title">
                <?php
                if (!empty($settings['header_text'])) {
                    echo htmlspecialchars($settings['header_text']);
                } else {
                    echo htmlspecialchars($shop_name);
                }
                ?>
            </div>
        </div>
    </div>
<!-- Do NOT close </body> or </html> here -->