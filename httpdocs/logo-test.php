<?php
require_once 'config.php';

echo "<html><head><title>Logo Test</title><style>body{font-family:Arial;margin:20px;}</style></head><body>";

// Display current logo path from config
echo "<h2>Logo Test Page</h2>";
echo "<p>SHOP_LOGO value: <code>" . htmlspecialchars(SHOP_LOGO) . "</code></p>";

// Check if file exists
$logo_absolute_path = __DIR__ . '/' . SHOP_LOGO;
echo "<p>Absolute path: <code>" . htmlspecialchars($logo_absolute_path) . "</code></p>";
echo "<p>File exists: <strong>" . (file_exists($logo_absolute_path) ? 'Yes' : 'No') . "</strong></p>";

// Display the logo directly
echo "<h3>1. Direct logo display:</h3>";
echo "<img src='" . htmlspecialchars(SHOP_LOGO) . "' alt='Logo' style='max-width:300px; border:1px solid #ccc; padding:5px;'>";

// Display all files in uploads directory
echo "<h3>2. Files in uploads directory:</h3>";
$uploads_dir = __DIR__ . '/uploads/';
if (is_dir($uploads_dir)) {
    $files = scandir($uploads_dir);
    echo "<ul>";
    foreach ($files as $file) {
        if ($file != "." && $file != "..") {
            echo "<li>{$file} - <img src='uploads/{$file}' style='max-width:100px;'></li>";
        }
    }
    echo "</ul>";
} else {
    echo "<p>Uploads directory does not exist!</p>";
}

// Display a test form for uploading
echo "<h3>3. Test logo upload:</h3>";
echo "<form action='logo-test.php' method='post' enctype='multipart/form-data'>";
echo "<input type='file' name='test_logo'> ";
echo "<button type='submit'>Upload Test Logo</button>";
echo "</form>";

// Process the upload
if (isset($_FILES['test_logo']) && $_FILES['test_logo']['error'] === 0) {
    $upload_dir = __DIR__ . '/uploads/';
    $filename = basename($_FILES['test_logo']['name']);
    $timestamp = time();
    $new_filename = 'test_logo_' . $timestamp . '.' . pathinfo($filename, PATHINFO_EXTENSION);
    $upload_path = $upload_dir . $new_filename;
    
    echo "<h4>Upload details:</h4>";
    echo "<pre>";
    print_r($_FILES['test_logo']);
    echo "</pre>";
    
    if (move_uploaded_file($_FILES['test_logo']['tmp_name'], $upload_path)) {
        echo "<p style='color:green'>Test upload successful! Path: {$upload_path}</p>";
        echo "<p>View uploaded logo: <a href='uploads/{$new_filename}' target='_blank'>uploads/{$new_filename}</a></p>";
        echo "<img src='uploads/{$new_filename}' style='max-width:300px; border:1px solid #ccc; padding:5px;'>";
    } else {
        echo "<p style='color:red'>Test upload failed. Check permissions on uploads directory.</p>";
    }
}

echo "</body></html>";
