<?php
// Set cache control headers
header('Cache-Control: public, max-age=86400'); 

// Get URL parameter
$url = isset($_GET['url']) ? $_GET['url'] : '';

// Only allow proxying images from middleworldfarms.org
if (empty($url) || !preg_match('/^https?:\/\/middleworldfarms\.org\//i', $url)) {
    header('HTTP/1.1 400 Bad Request');
    exit('Invalid image URL');
}

// Initialize curl session
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; ImageProxy/1.0)');

$response = curl_exec($ch);
$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($status_code === 200 && !empty($response)) {
    $body = substr($response, $header_size);
    header('Content-Type: ' . $content_type);
    echo $body;
} else {
    header('HTTP/1.1 404 Not Found');
    exit('Image not found');
}