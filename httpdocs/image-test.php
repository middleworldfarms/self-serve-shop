<?php
// Set proper content type for images
$file = __DIR__ . '/uploads/test-image.jpg';

if (file_exists($file)) {
    // Get file size and modification time
    $filesize = filesize($file);
    $modified = filemtime($file);
    
    // Set headers for proper image display
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . $filesize);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $modified) . ' GMT');
    header('Cache-Control: public, max-age=86400');
    
    // Output the file contents
    readfile($file);
} else {
    echo "File not found: $file";
}
