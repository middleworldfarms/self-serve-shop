<?php
// Simple script to securely serve images
if (isset($_GET['file'])) {
    $file = __DIR__ . '/' . $_GET['file'];
    
    // Simple security check - only allow images from uploads or images directory
    if (strpos($_GET['file'], 'uploads/') !== 0 && strpos($_GET['file'], 'images/') !== 0) {
        header('HTTP/1.0 403 Forbidden');
        exit('Access denied');
    }
    
    if (file_exists($file)) {
        // Determine content type based on file extension
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $content_types = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif'
        ];
        
        $content_type = $content_types[$ext] ?? 'application/octet-stream';
        
        // Set headers
        header('Content-Type: ' . $content_type);
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: public, max-age=86400');
        
        // Output the file
        readfile($file);
    } else {
        header('HTTP/1.0 404 Not Found');
        echo 'File not found';
    }
} else {
    header('HTTP/1.0 400 Bad Request');
    echo 'No file specified';
}
