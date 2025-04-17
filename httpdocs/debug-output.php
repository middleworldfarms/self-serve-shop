<?php
// This script will check all PHP files for echo statements outside HTML
$directory = __DIR__;
$results = [];

function checkFile($file) {
    $content = file_get_contents($file);
    preg_match_all('/(<\?php.*?\?>)|(<html>.*?<\/html>)/s', $content, $matches);
    
    // Check if there are any echo/print statements outside HTML tags
    $remainder = preg_replace('/(<\?php.*?\?>)|(<html>.*?<\/html>)/s', '', $content);
    if (preg_match('/(echo|print)/i', $remainder)) {
        return true;
    }
    return false;
}

function scanDirectory($dir) {
    global $results;
    $files = scandir($dir);
    
    foreach ($files as $file) {
        if ($file == '.' || $file == '..') continue;
        
        $path = $dir . '/' . $file;
        if (is_dir($path)) {
            scanDirectory($path);
        } 
        else if (pathinfo($path, PATHINFO_EXTENSION) == 'php') {
            if (checkFile($path)) {
                $results[] = $path;
            }
        }
    }
}

scanDirectory($directory);

echo "<h2>PHP Files with echo/print statements outside HTML structure:</h2>";
echo "<pre>";
print_r($results);
echo "</pre>";