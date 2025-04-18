<?php
require_once '../config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

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
    // Silently fail
}

// Initialize variables
$success_message = '';
$error_message = '';
$users = [];

// Pagination only (no sorting)
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

try {
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
} catch (PDOException $e) {
    $users = [];
    $total_pages = 1;
}

// Helper function
function get_user_role($role) {
    if (empty($role)) return 'No role assigned';
    return ucfirst($role);
}

// Handle edit user request
$user_to_edit = null;
$edit_user_id = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_user_id'])) {
    $edit_user_id = (int)$_POST['edit_user_id'];
    $stmt = $db->prepare("SELECT id, username as user_login, email as user_email, role FROM users WHERE id = ?");
    $stmt->execute([$edit_user_id]);
    $user_to_edit = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Handle update user request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user'])) {
    $user_id = (int)$_POST['user_id'];
    $username = trim($_POST['edit_username']);
    $email = trim($_POST['edit_email']);
    $role = $_POST['edit_role'];
    $password = $_POST['edit_password'];

    if ($username && $email && $role) {
        if (!empty($password)) {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare("UPDATE users SET username=?, email=?, role=?, password=? WHERE id=?");
            $stmt->execute([$username, $email, $role, $hashed, $user_id]);
        } else {
            $stmt = $db->prepare("UPDATE users SET username=?, email=?, role=? WHERE id=?");
            $stmt->execute([$username, $email, $role, $user_id]);
        }
        $success_message = "User updated successfully.";
        $user_to_edit = null;
    } else {
        $error_message = "All fields except password are required.";
    }
}

require_once 'includes/header.php';
?>

<style>
    .admin-container {
        max-width: 1100px;
        margin: 2rem auto;
        background: #fff;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        padding: 2rem;
    }
    .users-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 1.5rem;
        background: #fafbfc;
        border-radius: 8px;
        overflow: hidden;
    }
    .users-table th, .users-table td {
        padding: 12px 10px;
        border-bottom: 1px solid #eee;
        text-align: left;
    }
    .users-table th {
        background: #f5f5f5;
        font-weight: bold;
        font-size: 1rem;
    }
    .users-table tr:last-child td {
        border-bottom: none;
    }
    .users-table tr:hover {
        background: #f1f8e9;
    }
    .action-button, .delete-button {
        padding: 6px 14px;
        border: none;
        border-radius: 4px;
        font-size: 0.95rem;
        cursor: pointer;
        margin-right: 4px;
        transition: background 0.2s;
    }
    .action-button {
        background: #388E3C;
        color: #fff;
    }
    .action-button:hover {
        background: #256029;
    }
    .delete-button {
        background: #F44336;
        color: #fff;
    }
    .delete-button:hover {
        background: #b71c1c;
    }
    .success-message, .error-message {
        padding: 12px 18px;
        border-radius: 4px;
        margin-bottom: 18px;
        font-weight: bold;
        text-align: center;
    }
    .success-message {
        background: #e6f4ea;
        color: #256029;
    }
    .error-message {
        background: #fdecea;
        color: #b71c1c;
    }
    .sidebar {
        flex: 0 0 320px; /* wider sidebar */
        background: #f8f9fa;
        border-radius: 8px;
        padding: 18px 16px;
        min-width: 260px;
        max-width: 400px;
    }
    .sidebar-section {
        background: #fff;
        padding: 14px 10px;
        border-radius: 5px;
        margin-bottom: 18px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    }
    .sidebar-section h3 {
        margin: 0 0 10px 0;
        font-size: 1rem;
        border-bottom: 1px solid #eee;
        padding-bottom: 6px;
    }
    .main-content {
        flex: 1 1 0;
        min-width: 0;
    }
    .add-user-button {
        width: 100%;
        margin-bottom: 18px;
        background: #388E3C;
        color: #fff;
        font-weight: bold;
        padding: 12px 0;
        border-radius: 4px;
        border: none;
        font-size: 1rem;
        cursor: pointer;
        transition: background 0.2s;
    }
    .add-user-button:hover {
        background: #256029;
    }
    .form-row {
        margin-bottom: 14px;
    }
    .form-row label {
        display: block;
        margin-bottom: 4px;
        font-weight: 500;
    }
    .form-row input, .form-row select {
        width: 100%;
        padding: 7px 8px;
        border-radius: 4px;
        border: 1px solid #ccc;
        font-size: 1rem;
    }
    .form-submit {
        text-align: right;
    }
    .form-submit button {
        background: #388E3C;
        color: #fff;
        border: none;
        border-radius: 4px;
        padding: 8px 18px;
        font-size: 1rem;
        cursor: pointer;
        font-weight: bold;
    }
    .form-submit button:hover {
        background: #256029;
    }
    .create-user-form, .edit-user-form {
        background: #f5f5f5;
        border-radius: 8px;
        padding: 22px 18px;
        margin-bottom: 24px;
        box-shadow: 0 1px 4px rgba(0,0,0,0.04);
        max-width: 420px;
    }
    .password-field {
        display: flex;
        align-items: center;
    }
    .password-field input[type="password"], .password-field input[type="text"] {
        flex: 1;
    }
    .toggle-password {
        background: none;
        border: none;
        font-size: 1.2rem;
        margin-left: 8px;
        cursor: pointer;
    }
    .pagination {
        text-align: center;
        margin: 18px 0 0 0;
    }
    .pagination a {
        display: inline-block;
        margin: 0 3px;
        padding: 6px 12px;
        background: #f5f5f5;
        color: #388E3C;
        border-radius: 4px;
        text-decoration: none;
        font-weight: bold;
        transition: background 0.2s;
    }
    .pagination a.active, .pagination a:hover {
        background: #388E3C;
        color: #fff;
    }
    @media (max-width: 900px) {
        .admin-layout {
            flex-direction: column;
            gap: 0;
        }
        .sidebar, .main-content {
            max-width: 100%;
            width: 100%;
            margin: 0;
        }
        .main-content {
            margin-top: 24px;
        }
    }
    /* Layout: sidebar and main-content side by side, with better proportions */
    .admin-layout {
        display: flex;
        gap: 32px;
        align-items: flex-start;
    }
</style>

<div class="admin-container">
    <h2>Manage Users</h2>
    <?php if (!empty($success_message)): ?>
        <div class="success-message"><?php echo $success_message; ?></div>
    <?php endif; ?>
    <?php if (!empty($error_message)): ?>
        <div class="error-message"><?php echo $error_message; ?></div>
    <?php endif; ?>

    <div class="admin-layout" style="display: flex; flex-wrap: wrap;">
        <form method="post" action="" id="bulk-form" style="display: flex; width: 100%;">
            <!-- Sidebar -->
            <div class="sidebar">
                <button type="button" class="add-user-button" onclick="toggleNewUserForm()">+ Add New User</button>
                <div class="sidebar-section">
                    <h3>Bulk Actions</h3>
                    <div>
                        <label for="bulk_action">Action:</label>
                        <select id="bulk_action" name="bulk_action" style="width: 100%;">
                            <option value="">Choose an action...</option>
                            <option value="set_admin">Set as Administrator</option>
                            <option value="set_editor">Set as Editor</option>
                            <option value="delete">Delete</option>
                        </select>
                    </div>
                    <div style="margin: 10px 0;">
                        <input type="checkbox" id="select_all" onclick="toggleSelectAll(this)">
                        <label for="select_all">Select All</label>
                    </div>
                    <button type="submit" class="button" style="width:100%;">Apply</button>
                </div>
            </div>
            <!-- Main content -->
            <div class="main-content" style="flex:1;">
                <table class="users-table">
                    <thead>
                        <tr>
                            <th></th>
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
                                    <button type="submit" name="delete_user" class="delete-button" onclick="return confirm('Are you sure?');">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?page=<?php echo $i; ?>"<?php if ($i == $page) echo ' class="active"'; ?>><?php echo $i; ?></a>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>

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
                                <input type="password" id="password" name="password" required autocomplete="current-password">
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
                                <input type="password" id="edit_password" name="edit_password" autocomplete="current-password">
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
                        <div class="form-submit">
                            <button type="submit" name="update_user" class="action-button">Update User</button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

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
<?php require_once 'includes/footer.php'; ?>