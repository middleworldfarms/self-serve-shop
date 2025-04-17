<?php
/**
 * Order Logging System
 * Records various events related to orders for auditing and analytics
 */

// Make sure we have database connection
if (!isset($db) && file_exists(__DIR__ . '/../config.php')) {
    require_once __DIR__ . '/../config.php';
    try {
        $db = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, 
            DB_USER, 
            DB_PASS
        );
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e) {
        // Silently fail if we can't connect - logging shouldn't break the main application
    }
}

/**
 * Log an order event
 * 
 * @param int $order_id The ID of the order
 * @param string $event_type Type of event (created, paid, cancelled, etc)
 * @param string|array $details Additional details about the event
 * @return bool Whether logging was successful
 */
function log_order_event($order_id, $event_type, $details = '') {
    global $db;
    
    if (!isset($db)) {
        return false;
    }
    
    // Convert details array to JSON if needed
    if (is_array($details)) {
        $details = json_encode($details);
    }
    
    try {
        $stmt = $db->prepare("
            INSERT INTO order_logs 
                (order_id, event_type, details, ip_address, user_agent)
            VALUES 
                (:order_id, :event_type, :details, :ip_address, :user_agent)
        ");
        
        $stmt->execute([
            'order_id' => $order_id,
            'event_type' => $event_type,
            'details' => $details,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
        
        return true;
    } catch (PDOException $e) {
        // Log to error file if database logging fails
        error_log("Order logging failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Get logs for a specific order
 * 
 * @param int $order_id Order ID to get logs for
 * @return array Array of log entries
 */
function get_order_logs($order_id) {
    global $db;
    
    if (!isset($db)) {
        return [];
    }
    
    try {
        $stmt = $db->prepare("
            SELECT * FROM order_logs 
            WHERE order_id = :order_id 
            ORDER BY created_at DESC
        ");
        
        $stmt->execute(['order_id' => $order_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}