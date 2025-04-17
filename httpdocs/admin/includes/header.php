<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: index.php');
    exit;
}
require_once '../config.php';
$settings = function_exists('get_settings') ? get_settings() : [];
$primary = $settings['primary_color'] ?? '#4CAF50';
$secondary = $settings['secondary_color'] ?? '#388E3C';
$shop_name = $settings['shop_name'] ?? 'Self-Serve Shop';
$logo = !empty($settings['site_logo']) ? '/' . ltrim($settings['site_logo'], '/') : '';
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
            padding: 24px 0 16px 0;
            text-align: center;
            border-bottom: 4px solid <?php echo $secondary; ?>;
        }
        .admin-header img {
            max-height: 80px;
            margin-bottom: 8px;
        }
        .admin-header h1 {
            margin: 0;
            font-size: 2rem;
            letter-spacing: 1px;
        }
        .admin-nav-btns {
            margin: 18px 0 0 0;
            display: flex;
            justify-content: center;
            gap: 18px;
        }
        .admin-nav-btns a {
            background: <?php echo $secondary; ?>;
            color: #fff;
            padding: 10px 28px;
            border-radius: 4px;
            text-decoration: none;
            font-weight: bold;
            font-size: 1rem;
            transition: background 0.2s;
            border: none;
            display: inline-block;
        }
        .admin-nav-btns a:hover {
            background: #256029;
        }
        body {
            margin: 0;
            padding: 0;
        }
    </style>
</head>
<body>
    <div class="admin-header">
        <?php if ($logo): ?>
            <img src="<?php echo htmlspecialchars($logo); ?>" alt="Site Logo">
        <?php endif; ?>
        <h1><?php echo htmlspecialchars($shop_name); ?> Admin</h1>
        <div class="admin-nav-btns">
            <a href="/admin/index.php">Back to Dashboard</a>
            <a href="/admin/logout.php">Logout</a>
        </div>
    </div>
    <div class="admin-wrapper">
        <div class="admin-content">
