</div> <!-- End of admin-content -->
    </div> <!-- Close admin-wrapper -->
    <footer class="admin-footer">
        <?php
        $footer_logo = !empty($settings['site_logo']) &&
            (empty($settings['logo_location']) || $settings['logo_location'] === 'footer' || $settings['logo_location'] === 'both')
            ? '/' . ltrim($settings['site_logo'], '/') : '';
        ?>
        <p><?php echo htmlspecialchars($shop_name); ?> - Open Source Project</p>
        <?php if ($footer_logo): ?>
            <img src="<?php echo htmlspecialchars($footer_logo); ?>" alt="Site Logo" style="max-height:80px;">
        <?php endif; ?>
    </footer>
    <style>
        .admin-footer {
            background-color: <?php echo $primary; ?>;
            color: white;
            padding: 20px 20px;
            text-align: center;
            margin-top: 20px;
        }
        .admin-footer p {
            margin: 0;
            font-size: 14px;
        }
    </style>
</body>
</html>