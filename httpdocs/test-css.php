<?php
require_once 'config.php';

// Connect to database
try {
    $db = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection error: " . $e->getMessage());
}

// Get custom CSS
$stmt = $db->query("SELECT setting_value FROM self_serve_settings WHERE setting_name = 'custom_css'");
$custom_css = $stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CSS Test</title>
    <style>
    /* Base styles for testing */
    body { 
        font-family: Arial, sans-serif; 
        padding: 20px;
    }
    .test-box {
        border: 1px solid #ccc;
        padding: 20px;
        margin: 20px 0;
    }
    
    /* Custom CSS from database */
    <?php echo $custom_css; ?>
    </style>
</head>
<body>
    <h1>CSS Test Page</h1>
    
    <div class="test-box">
        <h2>Custom CSS Test Box</h2>
        <p>This box should be styled according to your custom CSS.</p>
        <button class="btn">Test Button</button>
    </div>
    
    <div>
        <h3>Your Custom CSS:</h3>
        <pre style="background:#f5f5f5;padding:10px;overflow:auto;"><?php echo htmlspecialchars($custom_css); ?></pre>
    </div>
    
    <div>
        <h3>Check Frontend Templates</h3>
        <p>Make sure these files include the custom CSS:</p>
        <ul>
            <li><code>/includes/header.php</code> - Should include custom CSS in &lt;head&gt;</li>
            <li><code>/index.php</code> - Should load settings</li>
            <li><code>/product.php</code> - Should load settings</li>
        </ul>
    </div>
</body>
</html>