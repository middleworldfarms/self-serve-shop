</div> <!-- End of admin-content -->
    </div> <!-- Close admin-wrapper -->
    
    <footer class="admin-footer">
        <?php
        // Get shop name from settings
        global $db;
        $shop_name = 'Self-Serve Shop';
        
        try {
            // Get shop_name from settings if available
            if (isset($current_settings) && isset($current_settings['shop_name'])) {
                $shop_name = $current_settings['shop_name'];
            } else {
                // If not already loaded in current page, fetch from database
                $stmt = $db->query("SELECT setting_value FROM self_serve_settings WHERE setting_name = 'shop_name' LIMIT 1");
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($result) {
                    $shop_name = $result['setting_value'];
                }
            }
        } catch (Exception $e) {
            // If there's an error, use the default
            error_log("Error getting shop name: " . $e->getMessage());
        }
        ?>
        <p><?php echo htmlspecialchars($shop_name); ?> - Open Source Project</p>
        <?php
        $settings = get_settings();
        if (
            !empty($settings['site_logo']) &&
            ($settings['logo_location'] === 'footer' || $settings['logo_location'] === 'both')
        ): ?>
            <img src="/<?php echo htmlspecialchars($settings['site_logo']); ?>" alt="Site Logo" style="max-height:80px;">
        <?php endif; ?>
    </footer>
    
    <style>
        .admin-footer {
            background-color: #4CAF50; /* Match the header background color */
            color: white; /* White text for readability */
            padding: 20px 20px; /* Changed: added 20px top padding */
            text-align: center;
            margin-top: 20px; /* Added: extra margin above the footer */
        }

        .admin-footer p {
            margin: 0;
            font-size: 14px;
        }
    </style>
    
    <!-- Add any additional scripts here -->
    <script>
        // Any global JS can go here
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize any components
            const messages = document.querySelectorAll('.alert-message');
            if (messages.length > 0) {
                messages.forEach(message => {
                    setTimeout(() => {
                        message.style.opacity = '0';
                        setTimeout(() => {
                            message.style.display = 'none';
                        }, 500);
                    }, 5000);
                });
            }
        });
    </script>
</body>
</html>