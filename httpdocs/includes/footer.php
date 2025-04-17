<?php
$settings = function_exists('get_settings') ? get_settings() : [];
$primary = $settings['primary_color'] ?? '#4CAF50';
?>
    <footer style="background: <?php echo $primary; ?>; color: #fff; text-align: center; padding: 18px 0; margin-top: 40px;">
        <p>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($settings['shop_name'] ?? 'Self-Serve Shop'); ?></p>
    </footer>
</body>
</html>