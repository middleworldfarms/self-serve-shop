<?php
require_once '../config.php';

// Ensure the admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

// CSRF token generation
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Get current logged-in user info for display
$current_user = [
    'username' => $_SESSION['admin_username'] ?? 'Unknown',
    'id' => $_SESSION['admin_user_id'] ?? 0
];

// Try to get more details about the current user
try {
    $db = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $db->prepare("SELECT email, role FROM users WHERE id = ?");
    $stmt->execute([$current_user['id']]);
    $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user_data) {
        $current_user['email'] = $user_data['email'];
        $current_user['role'] = $user_data['role'];
    }
} catch (PDOException $e) {
    // Silently fail - we'll just show limited user info
}

// Initialize variables
$success_message = '';
$error_message = '';
$users = [];

// Database connection
try {
    $db = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Handle user creation
    if (isset($_POST['create_user'])) {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            die('Invalid CSRF token.');
        }

        $username = $_POST['username'] ?? '';
        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'editor';

        if (empty($username) || empty($email) || empty($password)) {
            $error_message = "All fields are required.";
        } elseif (strlen($password) < 8) {
            $error_message = "Password must be at least 8 characters.";
        } else {
            $valid_roles = ['editor', 'administrator'];
            if (!in_array($role, $valid_roles)) {
                $error_message = "Invalid role selected.";
            } else {
                // Check if username exists in the users table
                $check = $db->prepare("SELECT id FROM users WHERE username = ?");
                $check->execute([$username]);

                if ($check->rowCount() > 0) {
                    $error_message = "Username already exists.";
                } else {
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    // Insert directly into the users table with the role column
                    $stmt = $db->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, ?)");
                    
                    if ($stmt->execute([$username, $email, $hashed_password, $role])) {
                        $success_message = "User created successfully.";
                    } else {
                        $error_message = "Failed to create user.";
                    }
                }
            }
        }
    }

    // Handle user editing
    if (isset($_POST['edit_user'])) {
        $edit_user_id = (int)$_POST['edit_user_id'];
        $stmt = $db->prepare("SELECT username as user_login, email as user_email, role FROM users WHERE id = ?");
        $stmt->execute([$edit_user_id]);
        $user_to_edit = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Handle user update
    if (isset($_POST['update_user'])) {
        $user_id = (int)$_POST['user_id'];
        $username = $_POST['edit_username'] ?? '';
        $email = $_POST['edit_email'] ?? '';
        $password = $_POST['edit_password'] ?? '';
        $role = $_POST['edit_role'] ?? '';

        if (empty($username) || empty($email)) {
            $error_message = "Username and email are required.";
        } else {
            try {
                // First update the username and email
                $stmt = $db->prepare("UPDATE users SET username = ?, email = ?, role = ? WHERE id = ?");
                $stmt->execute([$username, $email, $role, $user_id]);
                
                // Then update the password if provided
                if (!empty($password)) {
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $pwd_stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
                    if (!$pwd_stmt->execute([$hashed_password, $user_id])) {
                        throw new PDOException("Failed to update password");
                    }
                }

                $success_message = "User updated successfully.";
            } catch (PDOException $e) {
                error_log("Password update error: " . $e->getMessage());
                $error_message = "Database error: " . $e->getMessage();
            }
        }
    }

    // Handle user deletion
    if (isset($_POST['delete_user']) && isset($_POST['user_id'])) {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            die('Invalid CSRF token.');
        }
        
        $user_id = (int)$_POST['user_id'];
        
        // Prevent deleting the current user
        if ($user_id === $_SESSION['admin_user_id']) {
            $error_message = "You cannot delete your own account while logged in.";
        } else {
            // Delete user from the standalone users table
            $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $success_message = "User deleted successfully.";
        }
    }
    
    // Handle bulk actions
    if (isset($_POST['bulk_action']) && isset($_POST['selected_users']) && !empty($_POST['selected_users'])) {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            die('Invalid CSRF token.');
        }
        
        $action = $_POST['bulk_action'];
        $selected_users = $_POST['selected_users'];
        
        // Ensure selected_users is an array
        if (!is_array($selected_users)) {
            $selected_users = [$selected_users];
        }
        
        // Sanitize user IDs
        $selected_users = array_map('intval', $selected_users);
        
        try {
            $count = 0;
            if ($action === 'delete') {
                foreach ($selected_users as $user_id) {
                    // Prevent deleting the current user
                    if ($user_id === $_SESSION['admin_user_id']) {
                        continue;
                    }
                    
                    // Delete user
                    $stmt = $db->prepare("DELETE FROM " . TABLE_PREFIX . "users WHERE ID = ?");
                    $stmt->execute([$user_id]);
                    
                    // Delete user meta
                    $meta_stmt = $db->prepare("DELETE FROM " . TABLE_PREFIX . "usermeta WHERE user_id = ?");
                    $meta_stmt->execute([$user_id]);
                    
                    $count++;
                }
                $success_message = $count . " user(s) deleted successfully.";
            } 
            elseif ($action === 'set_admin' || $action === 'set_editor') {
                $role = ($action === 'set_admin') ? 'administrator' : 'editor';
                
                foreach ($selected_users as $user_id) {
                    // Update user role
                    $capabilities = serialize([$role => true]);
                    
                    // Check if user meta exists
                    $check_stmt = $db->prepare("SELECT meta_id FROM " . TABLE_PREFIX . "usermeta 
                                               WHERE user_id = ? AND meta_key = 'wp_capabilities'");
                    $check_stmt->execute([$user_id]);
                    
                    if ($check_stmt->rowCount() > 0) {
                        // Update existing meta
                        $update_stmt = $db->prepare("UPDATE " . TABLE_PREFIX . "usermeta 
                                                   SET meta_value = ? 
                                                   WHERE user_id = ? AND meta_key = 'wp_capabilities'");
                        $update_stmt->execute([$capabilities, $user_id]);
                    } else {
                        // Insert new meta
                        $insert_stmt = $db->prepare("INSERT INTO " . TABLE_PREFIX . "usermeta 
                                                   (user_id, meta_key, meta_value) 
                                                   VALUES (?, 'wp_capabilities', ?)");
                        $insert_stmt->execute([$user_id, $capabilities]);
                    }
                    
                    $count++;
                }
                $role_text = ($action === 'set_admin') ? 'Administrator' : 'Editor';
                $success_message = $count . " user(s) set to " . $role_text . " role successfully.";
            }
        } catch (PDOException $e) {
            $error_message = "Database error: " . $e->getMessage();
        }
    }
    
    // Get all users with pagination
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = 10;
    $offset = ($page - 1) * $limit;

    try {
        // Use the standalone users table structure, not WordPress tables
        $users_query = $db->prepare("
            SELECT id as ID, username as user_login, email as user_email, 
                   created_at as user_registered, role as capabilities
            FROM users
            ORDER BY id ASC
            LIMIT ? OFFSET ?
        ");
        $users_query->bindValue(1, $limit, PDO::PARAM_INT);
        $users_query->bindValue(2, $offset, PDO::PARAM_INT);
        $users_query->execute();
        $users = $users_query->fetchAll(PDO::FETCH_ASSOC);
        
        $total_users = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $total_pages = ceil($total_users / $limit);

        if ($total_pages > 1) {
            echo '<div class="pagination">';
            for ($i = 1; $i <= $total_pages; $i++) {
                echo '<a href="?page=' . $i . '">' . $i . '</a> ';
            }
            echo '</div>';
        }
    } catch (PDOException $e) {
        error_log("Database error in pagination: " . $e->getMessage());
        echo '<p style="color:red;">Database error: ' . $e->getMessage() . '</p>';
    }
    
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage() . " in query: " . $stmt->queryString, 3, '/var/log/php_errors.log');
    echo '<p style="color:red;">Database error occurred. Please try again later.</p>';
}

// Function to get user role (simplified since we store it directly)
function get_user_role($role) {
    if (empty($role)) return 'No role assigned';
    return ucfirst($role); // Capitalize the first letter of the role
}

$hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - Self-Serve Shop Admin</title>
    <link rel="stylesheet" href="../css/styles.css">
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f5f5f5;
            margin: 0;
            padding: 0;
        }

        .admin-container {
            max-width: 1000px;
            margin: 20px auto;
            padding: 20px;
            background-color: white;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
        }

        .success-message, .error-message {
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1rem;
        }

        .success-message {
            background-color: #d4edda;
            color: #155724;
        }

        .error-message {
            background-color: #f8d7da;
            color: #721c24;
        }

        .users-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
            font-size: 0.9rem;
        }

        .users-table th, .users-table td {
            padding: 8px;
            border: 1px solid #ddd;
            text-align: left;
        }

        .users-table th {
            background-color: #f5f5f5;
            font-weight: bold;
        }

        .users-table tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        .users-table tr:hover {
            background-color: #f1f1f1;
        }

        .form-row {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }

        .form-row label {
            width: 150px; /* Adjust this value to match the width of other labels */
            margin-right: 15px;
            text-align: right;
        }

        .form-row input, .form-row select, .password-field {
            flex: 1; /* Ensure all inputs, selects, and password fields take up the same space */
            display: flex;
            align-items: center;
            position: relative;
        }

        .password-field input {
            width: 100%;
            padding-right: 40px; /* Space for the toggle button */
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 8px;
        }

        .password-field .toggle-password {
            position: absolute;
            right: 10px; /* Align closer to the edge */
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            font-size: 16px;
            color: #666;
        }

        .password-field .toggle-password:hover {
            color: #333;
        }

        .delete-button {
            background-color: #ff4d4d; /* Red background */
            color: white; /* White text */
            border: none; /* Remove border */
            padding: 8px 12px; /* Add padding */
            border-radius: 4px; /* Rounded corners */
            cursor: pointer; /* Pointer cursor on hover */
            font-size: 14px; /* Adjust font size */
        }

        .delete-button:hover {
            background-color: #e60000; /* Darker red on hover */
        }
    </style>
</head>
<body>
    <header class="admin-header">
        <div class="header-container">
            <div class="header-left">
                <h1>Manage Users</h1>
                <a href="index.php" class="back-link">Return to Dashboard</a>
            </div>
            <div class="header-right">
                <a href="?logout=1" class="logout-link">Logout</a>
            </div>
        </div>
    </header>
    
    <main>
        <div class="admin-container">
            <h2>Manage Users</h2>
            
            <?php if (!empty($success_message)): ?>
            <div class="success-message">
                <?php echo $success_message; ?>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($error_message)): ?>
            <div class="error-message">
                <?php echo $error_message; ?>
            </div>
            <?php endif; ?>
            
            <div class="admin-layout">
                <form method="post" action="" id="bulk-form">
                    <!-- Sidebar -->
                    <div class="sidebar">
                        <button type="button" class="button add-user-button" onclick="toggleNewUserForm()">+ Add New User</button>
                        
                        <!-- Bulk actions in sidebar -->
                        <div class="sidebar-section">
                            <h3>Bulk Actions</h3>
                            <div class="bulk-actions-sidebar">
                                <div>
                                    <label for="bulk_action">Action:</label>
                                    <select id="bulk_action" name="bulk_action" style="width: 100%;">
                                        <option value="">Choose an action...</option>
                                        <option value="set_admin">Set as Administrator</option>
                                        <option value="set_editor">Set as Editor</option>
                                        <option value="delete">Delete</option>
                                    </select>
                                </div>
                                
                                <div class="select-all-container">
                                    <input type="checkbox" id="select_all" onclick="toggleSelectAll(this)">
                                    <label for="select_all">Select All</label>
                                </div>
                                
                                <button type="submit" class="button">Apply</button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Main content -->
                    <div class="main-content">
                        <table class="users-table">
                            <thead>
                                <tr>
                                    <th>Select</th>
                                    <th>ID</th>
                                    <th>Username</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Registered</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                <tr>
                                    <td><input type="checkbox" name="selected_users[]" value="<?php echo $user['ID']; ?>" class="user-checkbox"></td>
                                    <td><?php echo $user['ID']; ?></td>
                                    <td><?php echo htmlspecialchars($user['user_login']); ?></td>
                                    <td><?php echo htmlspecialchars($user['user_email']); ?></td>
                                    <td><?php echo get_user_role($user['capabilities']); ?></td>
                                    <td><?php echo $user['user_registered']; ?></td>
                                    <td>
                                        <form method="post" action="" style="display:inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                            <input type="hidden" name="edit_user_id" value="<?php echo $user['ID']; ?>">
                                            <button type="submit" name="edit_user" class="action-button">Edit</button>
                                        </form>
                                        <form method="post" action="" style="display:inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                            <input type="hidden" name="user_id" value="<?php echo $user['ID']; ?>">
                                            <button type="submit" name="delete_user" class="action-button delete-button" onclick="return confirm('Are you sure?');">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        
                        <!-- Create user form -->
                        <div class="create-user-form new-user-form" id="new-user-form" style="display:none;">
                            <h3>Create New User</h3>
                            <form method="post" action="">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <div class="form-row">
                                    <label for="username">Username:</label>
                                    <input type="text" id="username" name="username" required>
                                </div>
                                <div class="form-row">
                                    <label for="email">Email:</label>
                                    <input type="email" id="email" name="email" required>
                                </div>
                                <div class="form-row">
                                    <label for="password">Password:</label>
                                    <div class="password-field">
                                        <input type="password" id="password" name="password" required>
                                        <button type="button" class="toggle-password" onclick="togglePasswordVisibility('password')">👁️</button>
                                    </div>
                                </div>
                                <div class="form-row">
                                    <label for="role">Role:</label>
                                    <select id="role" name="role">
                                        <option value="editor">Editor</option>
                                        <option value="administrator">Administrator</option>
                                    </select>
                                </div>
                                <div class="form-submit">
                                    <button type="submit" name="create_user">Create User</button>
                                </div>
                            </form>
                        </div>

                        <!-- Edit user form -->
                        <?php if (isset($user_to_edit)): ?>
                        <div class="edit-user-form">
                            <h3>Edit User</h3>
                            <form method="post" action="">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <input type="hidden" name="user_id" value="<?php echo $edit_user_id; ?>">
                                <div class="form-row">
                                    <label for="edit_username">Username:</label>
                                    <input type="text" id="edit_username" name="edit_username" value="<?php echo htmlspecialchars($user_to_edit['user_login']); ?>" required>
                                </div>
                                <div class="form-row">
                                    <label for="edit_email">Email:</label>
                                    <input type="email" id="edit_email" name="edit_email" value="<?php echo htmlspecialchars($user_to_edit['user_email']); ?>" required>
                                </div>
                                <div class="form-row">
                                    <label for="edit_password">Password (leave blank to keep current):</label>
                                    <div class="password-field">
                                        <input type="password" id="edit_password" name="edit_password">
                                        <button type="button" class="toggle-password" onclick="togglePasswordVisibility('edit_password')">👁️</button>
                                    </div>
                                </div>
                                <div class="form-row">
                                    <label for="edit_role">Role:</label>
                                    <select id="edit_role" name="edit_role" required>
                                        <option value="editor" <?php echo ($user_to_edit['role'] === 'editor') ? 'selected' : ''; ?>>Editor</option>
                                        <option value="administrator" <?php echo ($user_to_edit['role'] === 'administrator') ? 'selected' : ''; ?>>Administrator</option>
                                    </select>
                                </div>
                                <button type="submit" name="update_user" class="action-button">Update User</button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </main>
    
    <script>
        function togglePasswordVisibility(inputId) {
            const passwordInput = document.getElementById(inputId);
            const toggleButton = passwordInput.nextElementSibling;

            if (passwordInput.type === "password") {
                passwordInput.type = "text";
                toggleButton.textContent = "🔒";
            } else {
                passwordInput.type = "password";
                toggleButton.textContent = "👁️";
            }
        }

        function toggleNewUserForm() {
            const form = document.getElementById('new-user-form');
            form.style.display = form.style.display === 'none' ? 'block' : 'none';
        }

        function toggleSelectAll(checkbox) {
            const checkboxes = document.querySelectorAll('.user-checkbox');
            checkboxes.forEach(cb => cb.checked = checkbox.checked);
        }
    </script>
</body>
</html>