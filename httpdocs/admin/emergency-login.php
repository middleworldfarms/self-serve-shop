<?php
require_once '../config.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Log errors to a file for debugging
ini_set('log_errors', 1);
ini_set('error_log', '../logs/emergency-login-errors.log');

// Process login attempt
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'], $_POST['password'])) {
    try {
        $db = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Check if users table exists
        $result = $db->query("SHOW TABLES LIKE 'users'");
        $tableExists = ($result->rowCount() > 0);

        if (!$tableExists) {
            // Create users table if it doesn't exist
            $db->exec("
                CREATE TABLE IF NOT EXISTS users (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    username VARCHAR(50) NOT NULL UNIQUE,
                    email VARCHAR(100) NOT NULL,
                    password VARCHAR(255) NOT NULL,
                    role VARCHAR(20) NOT NULL DEFAULT 'admin',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");
            echo "<div style='color:green;'>Users table created successfully.</div>";
        }

        // Create admin user if none exists
        $result = $db->query("SELECT COUNT(*) FROM users");
        if ($result->fetchColumn() == 0) {
            $stmt = $db->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, ?)");
            $stmt->execute([
                'admin',
                'admin@example.com',
                password_hash('admin123', PASSWORD_DEFAULT), // Default password: admin123
                'admin'
            ]);
            echo "<div style='color:green;'>Default admin user created.</div>";
        }

        // Try to log in
        $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$_POST['username']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($_POST['password'], $user['password'])) {
            session_start();
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_user_id'] = $user['id'];
            $_SESSION['admin_username'] = $user['username'];
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            echo "<div style='color:green;'>Login successful. Redirecting to dashboard...</div>";
            echo "<script>setTimeout(function() { window.location.href = 'index.php'; }, 1500);</script>";
            exit;
        } else {
            $error_message = "Invalid username or password.";
        }
    } catch (PDOException $e) {
        $error_message = "Database error: " . $e->getMessage();
        error_log($error_message); // Log the error
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Emergency Admin Login</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 400px; margin: 50px auto; padding: 20px; background: #f5f5f5; }
        form { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #333; margin-top: 0; }
        label { display: block; margin: 10px 0 5px; }
        input { width: 100%; padding: 8px; margin-bottom: 10px; border: 1px solid #ddd; border-radius: 4px; }
        button { background: #4CAF50; color: white; border: none; padding: 10px 15px; border-radius: 4px; cursor: pointer; }
        .warning { background: #fff3cd; padding: 10px; border-left: 4px solid #ffc107; margin-bottom: 20px; }
        .error { color: red; margin-bottom: 10px; }
    </style>
</head>
<body>
    <h1>Emergency Admin Login</h1>

    <div class="warning">
        <strong>Warning:</strong> Delete this file after use for security!
    </div>

    <?php if (!empty($error_message)): ?>
        <div class="error"><?php echo htmlspecialchars($error_message); ?></div>
    <?php endif; ?>

    <form method="post" action="">
        <label for="username">Username:</label>
        <input type="text" id="username" name="username" value="admin" required>

        <label for="password">Password:</label>
        <input type="password" id="password" name="password" value="admin123" required>

        <button type="submit">Login</button>
    </form>

    <p>Default credentials: admin / admin123</p>

    <div class="warning">
        <p>This script will:</p>
        <ol>
            <li>Create the users table if it doesn't exist</li>
            <li>Create a default admin user if none exists</li>
            <li>Log you into the admin dashboard</li>
        </ol>
    </div>
</body>
</html>