<?php
// filepath: /var/www/vhosts/middleworldfarms.org/self-serve-shop/admin/new-password.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Add to the top of each admin file
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

// Add CSRF protection
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require_once '../config.php';

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

$message = '';
$validToken = false;
$token = '';

if (isset($_GET['token'])) {
    $token = $_GET['token'];
    try {
        // Find user with this token
        $stmt = $db->prepare("SELECT user_id FROM " . TABLE_PREFIX . "usermeta WHERE meta_key = 'password_reset_token' AND meta_value = ?");
        $stmt->execute([$token]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            $userId = $result['user_id'];
            // Check if token is expired
            $stmt = $db->prepare("SELECT meta_value FROM " . TABLE_PREFIX . "usermeta WHERE user_id = ? AND meta_key = 'password_reset_expiry'");
            $stmt->execute([$userId]);
            $expiry = $stmt->fetchColumn();

            if ($expiry && strtotime($expiry) > time()) {
                $validToken = true;
            } else {
                $message = "Password reset link has expired. Please request a new one.";
            }
        } else {
            $message = "Invalid password reset link.";
        }
    } catch (PDOException $e) {
        $message = "Database error: " . $e->getMessage();
    }
} else {
    $message = "No reset token provided.";
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken) {
    if (isset($_POST['password']) && isset($_POST['confirm_password'])) {
        $password = $_POST['password'];
        $confirmPassword = $_POST['confirm_password'];

        if (strlen($password) < 8) {
            $message = "Password must be at least 8 characters long.";
        } elseif ($password !== $confirmPassword) {
            $message = "Passwords do not match.";
        } else {
            try {
                // Find user with this token
                $stmt = $db->prepare("SELECT user_id FROM " . TABLE_PREFIX . "usermeta WHERE meta_key = 'password_reset_token' AND meta_value = ?");
                $stmt->execute([$token]);
                $userId = $stmt->fetchColumn();

                if ($userId) {
                    // Update password
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $db->prepare("UPDATE " . TABLE_PREFIX . "users SET user_pass = ? WHERE ID = ?");
                    $stmt->execute([$hashedPassword, $userId]);

                    // Remove reset token
                    $stmt = $db->prepare("DELETE FROM " . TABLE_PREFIX . "usermeta WHERE user_id = ? AND meta_key IN ('password_reset_token', 'password_reset_expiry')");
                    $stmt->execute([$userId]);

                    // Redirect to login with success message
                    header("Location: ../index.php?reset=success");
                    exit;
                } else {
                    $message = "User not found.";
                    $validToken = false;
                }
            } catch (PDOException $e) {
                $message = "Database error: " . $e->getMessage();
            }
        }
    } else {
        $message = "Please fill in all required fields.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set New Password - Middle World Farms</title>
    <link rel="stylesheet" href="../css/styles.css">
</head>
<body>
    <header>
        <h1>Set New Password</h1>
        <a href="../index.php" class="back-link">← Back to Login</a>
    </header>
    <main>
        <div class="form-container" style="max-width: 500px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
            <h2>Set New Password</h2>
            <?php if (!$validToken): ?>
                <div class="error-message" style="background-color: #FFDDDD; color: #D8000C; padding: 10px; border-radius: 4px; margin-bottom: 20px;"><?php echo $message; ?></div>
                <p><a href="reset-password.php" class="button">Request New Reset Link</a></p>
            <?php else: ?>
                <?php if ($message): ?>
                    <div class="error-message" style="background-color: #FFDDDD; color: #D8000C; padding: 10px; border-radius: 4px; margin-bottom: 20px;"><?php echo $message; ?></div>
                <?php endif; ?>
                <form method="post" action="new-password.php?token=<?php echo htmlspecialchars($token); ?>">
                    <div class="form-row">
                        <label for="password">New Password</label>
                        <input type="password" id="password" name="password" required minlength="8">
                        <small style="color: #666;">Must be at least 8 characters</small>
                    </div>
                    <div class="form-row">
                        <label for="confirm_password">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" required minlength="8">
                    </div>
                    <button type="submit" class="button" style="width: 100%;">Set New Password</button>
                </form>
            <?php endif; ?>
        </div>
    </main>
    <footer>
        <p>&copy; <?php echo date('Y'); ?> Middle World Farms. All rights reserved.</p>
    </footer>
</body>
</html>