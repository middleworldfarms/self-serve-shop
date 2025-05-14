<?php
function debug_log($message, $data = null) {
    $log_file = __DIR__ . '/cash-woo-debug.log';
    $timestamp = date('[Y-m-d H:i:s]');
    $log_entry = $timestamp . ' ' . $message;
    
    if ($data !== null) {
        $log_entry .= "\n" . print_r($data, true);
    }
    
    file_put_contents($log_file, $log_entry . "\n\n", FILE_APPEND);
}
?>