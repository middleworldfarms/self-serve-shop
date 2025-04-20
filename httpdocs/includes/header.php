<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
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
    <title><?php echo htmlspecialchars($shop_name); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if (!empty($settings['custom_css'])): ?>
        <style><?php echo $settings['custom_css']; ?></style>
    <?php endif; ?>
    <style>
        .shop-header {
            background: <?php echo $primary; ?>;
            color: #fff;
            padding: 0; /* Remove all padding */
            border-bottom: 4px solid <?php echo $secondary; ?>;
        }
        .shop-nav {
            display: flex;
            gap: 18px;
            padding: 12px 24px 10px; /* Slightly more top padding to balance */
            background: <?php echo $primary; ?>;
            color: #fff;
            justify-content: center;
        }
        .shop-nav a {
            color: #fff;
            text-decoration: none;
            font-weight: bold;
            padding: 10px 20px;
            border-radius: 4px;
            transition: background 0.2s;
        }
        .shop-nav a.active {
            background: <?php echo $secondary; ?>;
        }
        .shop-nav a:hover {
            background: #256029;
        }
        .cart-count {
            font-weight: bold;
            font-size: 0.95em;
            vertical-align: middle;
            margin-left: 4px;
            background: #fff;
            color: <?php echo $primary; ?>;
            border-radius: 50%;
            padding: 2px 8px;
        }
        .shop-header-inner {
            display: grid;
            grid-template-columns: 160px 1fr 160px;
            align-items: center;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 24px 8px; /* Add bottom padding instead of top */
        }
        .shop-header-logo {
            justify-self: start;
        }
        .shop-header-logo img {
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
        .shop-header-title {
            font-size: 2rem;
            justify-self: center;
            text-align: center;
            margin: 0;
            letter-spacing: 1px;
            font-weight: bold;
        }
        body {
            margin: 0;
            padding: 0;
            background: #f5f7fa;
        }
    </style>
</head>
<body>
    <div class="shop-header">
        <div class="shop-nav">
            <a href="/index.php" class="<?php echo ($current_file === 'index.php') ? 'active' : ''; ?>">Shop</a>
            <a href="/cart.php" class="<?php echo ($current_file === 'cart.php') ? 'active' : ''; ?>">
                Cart
                <span class="cart-count">
                    <?php echo isset($_SESSION['cart']) ? array_sum($_SESSION['cart']) : 0; ?>
                </span>
            </a>
            <a href="https://middleworldfarms.org/my-account/" target="_blank">My Account <i class="fa fa-external-link" style="font-size: 0.8em;" aria-hidden="true"></i></a>
        </div>
        <div class="shop-header-inner">
            <div class="shop-header-logo">
                <?php if ($logo): ?>
                    <img src="<?php echo htmlspecialchars($logo); ?>" alt="Site Logo">
                <?php endif; ?>
            </div>
            <div class="shop-header-title">
                <?php echo htmlspecialchars($shop_name); ?>
            </div>
        </div>
    </div>
<!-- Do NOT close </body> or </html> here -->