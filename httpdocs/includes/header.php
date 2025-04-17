<?php
require_once __DIR__ . '/../admin/config.php';
$settings = function_exists('get_settings') ? get_settings() : [];
$primary = $settings['primary_color'] ?? '#4CAF50';
$shop_name = $settings['shop_name'] ?? 'Self-Serve Shop';
$logo = !empty($settings['site_logo']) ? '/' . ltrim($settings['site_logo'], '/') : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($shop_name); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if (!empty($settings['custom_css'])): ?>
        <style><?php echo $settings['custom_css']; ?></style>
    <?php endif; ?>
    <style>
        .shop-header {
            background: <?php echo $primary; ?>;
            color: #fff;
            padding: 18px 0 12px 0;
            text-align: center;
        }
        .shop-header img {
            max-height: 70px;
            margin-bottom: 6px;
        }
        .shop-header h1 {
            margin: 0;
            font-size: 2rem;
        }
        .shop-nav {
            margin-top: 10px;
        }
        .shop-nav a {
            color: #fff;
            text-decoration: none;
            margin: 0 18px;
            font-weight: bold;
        }
        .shop-nav a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="shop-header">
        <?php if ($logo): ?>
            <img src="<?php echo htmlspecialchars($logo); ?>" alt="Site Logo">
        <?php endif; ?>
        <h1><?php echo htmlspecialchars($shop_name); ?></h1>
        <div class="shop-nav">
            <a href="/index.php">Shop</a>
            <a href="/cart.php">Cart</a>
            <a href="/account.php">My Account</a>
        </div>
    </div>