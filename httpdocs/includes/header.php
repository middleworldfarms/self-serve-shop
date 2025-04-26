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
    <script>
    // Add this right at the beginning of the <head> tag
    document.documentElement.style.marginTop = '0';
    document.documentElement.style.paddingTop = '0';
    </script>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($shop_name); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Debug info moved here as comment -->
    <?php if(isset($_SESSION['cart'])): /* Cart debug info moved inside HTML */ ?>
    <!-- Cart debug: <?php echo print_r($_SESSION['cart'], true) . ' Sum: ' . array_sum($_SESSION['cart']); ?> -->
    <?php else: ?>
    <!-- Cart empty -->
    <?php endif; ?>
    <style>
    :root {
        --primary-color: <?php echo htmlspecialchars($primary); ?>;
        --secondary-color: <?php echo htmlspecialchars($secondary); ?>;
    }
    </style>
    <link rel="stylesheet" href="/css/styles.css?v=5">
    <?php if (!empty($settings['custom_css'])): ?>
        <style><?php echo $settings['custom_css']; ?></style>
    <?php endif; ?>
</head>
<body>
    <div class="shop-header" style="background:<?php echo htmlspecialchars($primary); ?>;color:#fff;padding:0;border-bottom:4px solid <?php echo htmlspecialchars($secondary); ?>;">
        <!-- Add mobile cart icon on LEFT -->
        <a href="/cart.php" class="mobile-cart-icon" aria-label="Shopping Cart">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg>
            <span class="mobile-cart-count">
                <?php echo isset($_SESSION['cart']) ? array_sum($_SESSION['cart']) : '0'; ?>
            </span>
        </a>
        
        <!-- Hamburger menu toggle (RIGHT) -->
        <button class="menu-toggle" id="menu-toggle" aria-label="Toggle menu" aria-expanded="false">
            <span class="bar"></span>
            <span class="bar"></span>
            <span class="bar"></span>
        </button>
        
        <!-- Add an ID to the nav for targeting with JavaScript -->
        <div class="shop-nav" id="mobile-menu" style="display:flex;gap:18px;padding:12px 24px 10px;background:<?php echo htmlspecialchars($primary); ?>;color:#fff;justify-content:center;">
            <a href="/index.php" class="<?php echo ($current_file === 'index.php') ? 'active' : ''; ?>">Shop</a>
            <a href="/cart.php" class="<?php echo ($current_file === 'cart.php') ? 'active' : ''; ?>">
                Cart
                <span class="cart-count" style="display:inline-block !important;font-weight:bold;font-size:0.95em;margin-left:4px;background:#fff;color:<?php echo htmlspecialchars($primary); ?>;border-radius:50%;padding:2px 8px;min-width:16px;text-align:center;">
                    <?php 
                    // Simple direct calculation of cart items
                    echo isset($_SESSION['cart']) ? array_sum($_SESSION['cart']) : '0';
                    ?>
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
                <?php
                if (!empty($settings['header_text'])) {
                    echo htmlspecialchars($settings['header_text']);
                } else {
                    echo htmlspecialchars($shop_name);
                }
                ?>
            </div>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const menuToggle = document.getElementById('menu-toggle');
            const mobileMenu = document.getElementById('mobile-menu');
            
            if (menuToggle && mobileMenu) {
                menuToggle.addEventListener('click', function() {
                    mobileMenu.classList.toggle('active');
                    menuToggle.classList.toggle('active');
                    
                    // Update aria-expanded attribute for accessibility
                    const expanded = menuToggle.getAttribute('aria-expanded') === 'true';
                    menuToggle.setAttribute('aria-expanded', !expanded);
                });
                
                // Close menu when clicking outside
                document.addEventListener('click', function(event) {
                    if (!mobileMenu.contains(event.target) && !menuToggle.contains(event.target) && mobileMenu.classList.contains('active')) {
                        mobileMenu.classList.remove('active');
                        menuToggle.classList.remove('active');
                        menuToggle.setAttribute('aria-expanded', 'false');
                    }
                });
            }
        });
        </script>
    </div>
<!-- Do NOT close </body> or </html> here -->