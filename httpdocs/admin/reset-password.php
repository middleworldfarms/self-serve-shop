<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../config.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// TEMPORARILY REMOVED ADMIN CHECK FOR PASSWORD RECOVERY
// We'll add a secret URL parameter instead for minimal security
$allowed_token = "mwf2024reset"; // Simple security token

if (!isset($_GET['token']) || $_GET['token'] !== $allowed_token) {
    die("Access denied. Please use the correct URL with security token.");
}

// Database connection (only once!)
try {
    $db = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME,
        DB_USER,
        DB_PASS
    );
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Fetch settings from the database
$current_settings = [];
try {
    $stmt = $db->query("SELECT setting_name, setting_value FROM self_serve_settings");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $current_settings[$row['setting_name']] = $row['setting_value'];
    }
} catch (PDOException $e) {
    die("Error fetching settings: " . $e->getMessage());
}

// Provide fallback values for SMTP settings
$smtp_host = $current_settings['smtp_host'] ?? '';
$smtp_port = $current_settings['smtp_port'] ?? '587';
$smtp_encryption = $current_settings['smtp_encryption'] ?? 'tls';
$smtp_username = $current_settings['smtp_username'] ?? '';
$smtp_password = $current_settings['smtp_password'] ?? '';

if (empty($smtp_host) || empty($smtp_username) || empty($smtp_password)) {
    die("SMTP settings are not configured. Please update the email settings in the admin panel.");
}

$message = '';

// Function to send email
function send_email($to, $subject, $body) {
    global $smtp_host, $smtp_port, $smtp_encryption, $smtp_username, $smtp_password;

    $mail = new PHPMailer(true);

    try {
        // SMTP configuration
        $mail->isSMTP();
        $mail->Host = $smtp_host;
        $mail->SMTPAuth = true;
        $mail->Username = $smtp_username;
        $mail->Password = $smtp_password;
        $mail->SMTPSecure = $smtp_encryption;
        $mail->Port = $smtp_port;

        // Email content
        $mail->setFrom($smtp_username, 'Middle World Farms');
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->Body = $body;

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email error: " . $mail->ErrorInfo);
        return false;
    }
}

// Process direct reset for Martin
if (isset($_GET['reset_martin'])) {
    try {
        $new_password = 'MiddleWorld2024';
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

        $stmt = $db->prepare("UPDATE users SET password = ? WHERE username = 'martin'");
        $stmt->execute([$hashed_password]);

        if ($stmt->rowCount() > 0) {
            $message = "<div class='success'>Martin's password has been reset to: MiddleWorld2024</div>";
            send_email('martin@example.com', 'Password Reset', 'Your password has been reset to: MiddleWorld2024');
        } else {
            $message = "<div class='error'>User 'martin' not found in the database.</div>";
        }
    } catch (PDOException $e) {
        $message = "<div class='error'>Error resetting password: " . $e->getMessage() . "</div>";
    }
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    $username = $_POST['username'] ?? '';
    $new_password = $_POST['new_password'] ?? '';

    if (empty($username) || empty($new_password)) {
        $message = "<div class='error'>Please enter both username and password.</div>";
    } else {
        try {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

            $stmt = $db->prepare("UPDATE users SET password = ? WHERE username = ?");
            $stmt->execute([$hashed_password, $username]);

            if ($stmt->rowCount() > 0) {
                $message = "<div class='success'>Password reset successful for user: $username!</div>";
                send_email('user@example.com', 'Password Reset', "Your password has been reset successfully.");
            } else {
                $message = "<div class='error'>No user found with username: $username</div>";
            }
        } catch (PDOException $e) {
            $message = "<div class='error'>Error resetting password: " . $e->getMessage() . "</div>";
        }
    }
}

// Get all users
$users = [];
try {
    $stmt = $db->query("SELECT id, username, email FROM users ORDER BY username");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message .= "<div class='error'>Error fetching users: " . $e->getMessage() . "</div>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset User Password - Self-Serve Shop</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            color: #333;
        }
        h1 {
            color: #4CAF50;
            border-bottom: 2px solid #4CAF50;
            padding-bottom: 10px;
        }
        .container {
            background: #f9f9f9;
            border: 1px solid #ddd;
            padding: 20px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .quick-reset {
            background: #e9f5e9;
            border: 1px solid #c8e6c9;
            padding: 20px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            font-weight: bold;
            margin-bottom: 5px;
        }
        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }
        .btn {
            display: inline-block;
            background: #4CAF50;
            color: #fff;
            border: none;
            padding: 10px 15px;
            cursor: pointer;
            text-decoration: none;
            font-size: 16px;
            border-radius: 4px;
        }
        .btn:hover {
            background: #45a049;
        }
        .success {
            background: #dff2bf;
            color: #4F8A10;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 15px;
        }
        .error {
            background: #ffdddd;
            color: #D8000C;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 15px;
        }
        .user-list {
            margin-top: 30px;
        }
        .user-item {
            background: #f0f0f0;
            padding: 10px;
            margin-bottom: 5px;
            border-radius: 3px;
        }
        .warning {
            background: #feefb3;
            color: #9f6000;
            padding: 15px;
            border-radius: 4px;
            margin-top: 30px;
        }
    </style>
</head>
<body>
    <h1>Reset User Password</h1>
    
    <?php if ($message): ?>
        <?php echo $message; ?>
    <?php endif; ?>
    
    <div class="quick-reset">
        <h2>Quick Reset for Martin</h2>
        <p>Click the button below to instantly reset Martin's password:</p>
        <a href="?reset_martin=1&token=<?php echo $allowed_token; ?>" class="btn">Reset Martin's Password</a>
        <p><small>This will set Martin's password to: MiddleWorld2024</small></p>
    </div>
    
    <div class="container">
        <h2>Reset Any User's Password</h2>
        <form method="post" action="">
            <div class="form-group">
                <label for="username">Username:</label>
                <input type="text" id="username" name="username" required>
            </div>
            
            <div class="form-group">
                <label for="new_password">New Password:</label>
                <input type="password" id="new_password" name="new_password" required>
                <div id="password-strength"></div>
            </div>
            
            <button type="submit" name="reset_password" class="btn">Reset Password</button>
        </form>
    </div>
    
    <?php if (count($users) > 0): ?>
    <div class="user-list">
        <h3>Available Users</h3>
        <?php foreach ($users as $user): ?>
            <div class="user-item">
                <strong><?php echo htmlspecialchars($user['username']); ?></strong>
                <?php if (!empty($user['email'])): ?>
                    (<?php echo htmlspecialchars($user['email']); ?>)
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    
    <div class="warning">
        <strong>WARNING:</strong> Delete this file after use for security reasons!
    </div>
    <script>
        document.getElementById('new_password').addEventListener('input', function() {
            var strength = document.getElementById('password-strength');
            var value = this.value;

            if (value.length < 8) {
                strength.textContent = 'Weak';
                strength.style.color = 'red';
            } else if (value.match(/[A-Z]/) && value.match(/[0-9]/)) {
                strength.textContent = 'Strong';
                strength.style.color = 'green';
            } else {
                strength.textContent = 'Moderate';
                strength.style.color = 'orange';
            }
        });
    </script>
</body>
</html>