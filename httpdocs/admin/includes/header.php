<?php
// Ensure admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title . ' - ' : ''; ?>Self-Serve Shop Admin</title>
    
    <?php
    // Only include these stylesheets when we're on the self-serve shop subdomain
    $current_domain = $_SERVER['HTTP_HOST'];
    if ($current_domain == 'self-serve-shop.middleworldfarms.org'):
    ?>
    <link rel="stylesheet" href="../assets/css/admin.css">
    <link rel="stylesheet" href="../css/styles.css">
    <link rel="stylesheet" href="../assets/css/all.min.css">
    <?php endif; ?>
    
    <script src="../js/admin.js" defer></script>
    
    <?php if ($current_domain == 'self-serve-shop.middleworldfarms.org'): ?>
    <style>
        /* Scope all admin styles with .admin-wrapper to prevent affecting the main site */
        .admin-wrapper .admin-header {
            background-color: #4CAF50;
            color: white;
            padding: 15px 0;
            margin-bottom: 20px;
            position: relative;
        }
        
        .admin-wrapper .admin-header h1 {
            color: white;
            font-size: 24px;
            margin: 0;
            text-align: center;
        }
        
        .admin-wrapper .admin-header .back-link {
            color: white;
            text-decoration: none;
            font-size: 14px;
            position: absolute;
            left: 20px;
            top: 50%;
            transform: translateY(-50%);
        }
        
        .admin-wrapper .admin-header .back-link:hover {
            text-decoration: underline;
        }
        
        .admin-wrapper .admin-header .logout-link {
            color: white;
            text-decoration: none;
            font-size: 14px;
            position: absolute;
            right: 20px;
            top: 50%;
            transform: translateY(-50%);
        }
        
        .admin-wrapper .admin-header .logout-link:hover {
            text-decoration: underline;
        }
    </style>
    <?php if (isset($current_settings) && !empty($current_settings['custom_css'])): ?>
    <style>
    /* Scope custom CSS to only affect the self-serve shop admin */
    .admin-wrapper {
        <?php echo $current_settings['custom_css']; ?>
    }
    </style>
    <?php endif; ?>
    <?php endif; ?>
    <?php if (!empty($settings['custom_css'])): ?>
    <style><?php echo $settings['custom_css']; ?></style>
    <?php endif; ?>
</head>
<body>
    <div class="admin-wrapper">
        <header class="admin-header">
            <div class="header-container">
                <div class="header-left">
                    <h1>Self-Serve Shop Admin</h1>
                    <?php
                    $settings = get_settings();
                    if (
                        !empty($settings['site_logo']) &&
                        ($settings['logo_location'] === 'header' || $settings['logo_location'] === 'both')
                    ): ?>
                        <img src="/<?php echo htmlspecialchars($settings['site_logo']); ?>" alt="Site Logo" style="max-height:80px;">
                    <?php endif; ?>
                    <a href="index.php" class="back-link">Back to Dashboard</a>
                </div>
                <div class="header-right">
                    <a href="?logout=1" class="logout-link">Logout</a>
                </div>
            </div>
        </header>
        
        <div class="admin-content">
            <!-- Content will be inserted here -->
