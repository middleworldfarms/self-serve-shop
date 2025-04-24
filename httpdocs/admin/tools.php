// Create a new admin tool page for moving images:

<?php
require_once '../config.php';
require_once 'includes/header.php';

// Admin authentication
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

$message = '';

// Handle copy action
if (isset($_POST['copy_images'])) {
    // Copy shopping bag placeholder
    if (file_exists('../admin/uploads/Shopping bag.png')) {
        if (!is_dir('../uploads/icons')) {
            mkdir('../uploads/icons', 0777, true);
        }
        copy('../admin/uploads/Shopping bag.png', '../uploads/icons/placeholder.png');
    }
    
    $message = 'Essential images copied to new structure!';
}
?>

<div class="admin-container">
    <h2>Image Organization Tools</h2>
    
    <?php if ($message): ?>
    <div class="message success"><?php echo $message; ?></div>
    <?php endif; ?>
    
    <p>This tool helps organize your images into the new structure.</p>
    
    <form method="post">
        <button type="submit" name="copy_images" class="button">Copy Essential Images</button>
    </form>
</div>

<?php require_once 'includes/footer.php'; ?>