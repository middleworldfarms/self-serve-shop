<?php
// filepath: /var/www/vhosts/middleworldfarms.org/self-serve-shop/admin/download-template.php
require_once '../config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// Check if user is logged in as admin
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

// Set headers for CSV download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="product-import-template.csv"');

// Create output stream
$output = fopen('php://output', 'w');

// Output CSV header row
fputcsv($output, ['name', 'description', 'price', 'regular_price', 'sale_price', 'image', 'status']);

// Output sample data rows
fputcsv($output, ['Sample Product 1', 'This is a description for sample product 1', '10.99', '12.99', '10.99', 'https://example.com/image1.jpg', 'active']);
fputcsv($output, ['Sample Product 2', 'This is a description for sample product 2', '15.99', '15.99', '', '', 'active']);
fputcsv($output, ['Sample Product 3', 'This is a description for sample product 3', '7.50', '9.99', '7.50', '', 'inactive']);

fclose($output);
exit;