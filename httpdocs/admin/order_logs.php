<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

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

require_once '../includes/logger.php';

// Ensure the logs table exists (run once, then you can remove this block)
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS order_logs (
            log_id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT,
            event_type VARCHAR(50) NOT NULL,
            details TEXT,
            ip_address VARCHAR(45),
            user_agent VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
} catch (PDOException $e) {
    $error = "Error creating logs table: " . $e->getMessage();
}

$order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : null;
$logs = [];
$page_title = '';

if ($order_id) {
    $logs = get_order_logs($order_id);
    $page_title = "Logs for Order #$order_id";
} else {
    // Get all logs, with pagination
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $items_per_page = 50;
    $offset = ($page - 1) * $items_per_page;

    try {
        $count_stmt = $db->query("SELECT COUNT(*) FROM order_logs");
        $total_items = $count_stmt->fetchColumn();
        $total_pages = ceil($total_items / $items_per_page);

        $stmt = $db->prepare("
            SELECT * FROM order_logs 
            ORDER BY created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindParam(':limit', $items_per_page, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }

    $page_title = "All Order Logs";
}

require_once 'includes/header.php';
?>

<div class="admin-container">
    <h1><?php echo $page_title; ?></h1>
    <?php if (isset($error)): ?>
        <div class="error-message"><?php echo $error; ?></div>
    <?php endif; ?>
    <?php if (empty($logs)): ?>
        <p>No logs found.</p>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date/Time</th>
                    <th>Order ID</th>
                    <th>Event</th>
                    <th>Details</th>
                    <th>IP Address</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <td><?php echo date('Y-m-d H:i:s', strtotime($log['created_at'])); ?></td>
                    <td>
                        <?php if ($log['order_id']): ?>
                            <a href="view_order.php?id=<?php echo $log['order_id']; ?>">#<?php echo $log['order_id']; ?></a>
                        <?php else: ?>
                            N/A
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($log['event_type']); ?></td>
                    <td>
                        <?php 
                        $details = $log['details'];
                        if (json_decode($details)) {
                            $json = json_decode($details, true);
                            echo '<ul>';
                            foreach ($json as $key => $value) {
                                echo '<li><strong>' . htmlspecialchars($key) . ':</strong> ' . htmlspecialchars($value) . '</li>';
                            }
                            echo '</ul>';
                        } else {
                            echo htmlspecialchars($details); 
                        }
                        ?>
                    </td>
                    <td><?php echo htmlspecialchars($log['ip_address']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!$order_id && isset($total_pages) && $total_pages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="?page=<?php echo $page - 1; ?>">&laquo; Previous</a>
            <?php endif; ?>
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <?php if ($i == $page): ?>
                    <span class="current-page"><?php echo $i; ?></span>
                <?php else: ?>
                    <a href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                <?php endif; ?>
            <?php endfor; ?>
            <?php if ($page < $total_pages): ?>
                <a href="?page=<?php echo $page + 1; ?>">Next &raquo;</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>
    <p><a href="orders.php">&laquo; Back to Orders</a></p>
</div>

<?php require_once 'includes/footer.php'; ?>
