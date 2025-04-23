<?php
require_once '../config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// Ensure the admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

// Database connection
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

// Set default dates if not specified
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$view_type = $_GET['view'] ?? 'daily';

// Format for chart labels and SQL queries
$format_string = ($view_type == 'monthly') ? '%Y-%m' : '%Y-%m-%d';
$group_by = ($view_type == 'monthly') ? 'YEAR(created_at), MONTH(created_at)' : 'DATE(created_at)';

// Get sales data
try {
    $stmt = $db->prepare("
        SELECT 
            DATE_FORMAT(created_at, ?) as date_label,
            COUNT(*) as order_count,
            SUM(total_amount) as revenue
        FROM orders
        WHERE created_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)
        GROUP BY {$group_by}
        ORDER BY MIN(created_at)
    ");
    $stmt->execute([$format_string, $start_date, $end_date]);
    $sales_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get totals and summary data
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_orders,
            SUM(total_amount) as total_revenue,
            AVG(total_amount) as avg_order_value,
            MAX(total_amount) as largest_order
        FROM orders
        WHERE created_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)
    ");
    $stmt->execute([$start_date, $end_date]);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get payment method breakdown - handle NULL payment methods
    $stmt = $db->prepare("
        SELECT 
            COALESCE(payment_method, 'manual') as payment_method,
            COUNT(*) as order_count,
            SUM(total_amount) as total_amount
        FROM orders
        WHERE created_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)
        GROUP BY COALESCE(payment_method, 'manual')
        ORDER BY SUM(total_amount) DESC
    ");
    $stmt->execute([$start_date, $end_date]);
    $payment_methods = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get top-selling products
    try {
        // First check if order_items table exists
        $check = $db->query("SHOW TABLES LIKE 'order_items'");
        if ($check->rowCount() > 0) {
            // Use the order_items table approach
            $stmt = $db->prepare("
                SELECT 
                    p.name as product_name,
                    SUM(oi.quantity) as quantity_sold,
                    SUM(oi.price * oi.quantity) as revenue
                FROM order_items oi
                JOIN sss_products p ON oi.product_id = p.id
                JOIN orders o ON oi.order_id = o.id
                WHERE o.created_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)
                GROUP BY p.id, p.name
                ORDER BY quantity_sold DESC
                LIMIT 10
            ");
            $stmt->execute([$start_date, $end_date]);
            $top_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // Check if orders table has an items column that might contain JSON
            $columns = $db->query("DESCRIBE orders");
            $has_items_json = false;
            while ($column = $columns->fetch(PDO::FETCH_ASSOC)) {
                if ($column['Field'] == 'items' || $column['Field'] == 'order_items') {
                    $has_items_json = $column['Field'];
                    break;
                }
            }
            
            // If we found a column that might store items as JSON
            if ($has_items_json) {
                $top_products = [];
                $product_totals = [];
                
                // Get all orders in the date range
                $stmt = $db->prepare("
                    SELECT id, {$has_items_json} as items_json  
                    FROM orders 
                    WHERE created_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)
                ");
                $stmt->execute([$start_date, $end_date]);
                
                // Process each order's items
                while ($order = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if (empty($order['items_json'])) continue;
                    
                    $items = json_decode($order['items_json'], true);
                    if (!$items || !is_array($items)) continue;
                    
                    foreach ($items as $item) {
                        $prod_id = $item['id'] ?? $item['product_id'] ?? null;
                        $name = $item['name'] ?? 'Unknown Product';
                        $quantity = $item['quantity'] ?? 1;
                        $price = $item['price'] ?? $item['unit_price'] ?? 0;
                        $key = $prod_id ? "id_{$prod_id}" : md5($name);
                        
                        if (!isset($product_totals[$key])) {
                            $product_totals[$key] = [
                                'product_name' => $name,
                                'quantity_sold' => 0,
                                'revenue' => 0
                            ];
                        }
                        
                        $product_totals[$key]['quantity_sold'] += $quantity;
                        $product_totals[$key]['revenue'] += $price * $quantity;
                    }
                }
                
                // Convert to the expected format and sort
                $top_products = array_values($product_totals);
                usort($top_products, function($a, $b) {
                    return $b['quantity_sold'] - $a['quantity_sold'];
                });
                
                // Limit to top 10
                $top_products = array_slice($top_products, 0, 10);
            } else {
                // If we can't find order items data
                $top_products = [];
            }
        }
    } catch (PDOException $e) {
        $error_message = "Top products error: " . $e->getMessage();
        error_log($error_message);
        $top_products = [];
    }
    
} catch (PDOException $e) {
    $error_message = "Database error: " . $e->getMessage();
    error_log($error_message);
    $sales_data = [];
    $summary = ['total_orders' => 0, 'total_revenue' => 0, 'avg_order_value' => 0, 'largest_order' => 0];
    $payment_methods = [];
    $top_products = [];
}

// Prepare data for charts
$chart_labels = [];
$revenue_data = [];
$orders_data = [];

foreach ($sales_data as $day) {
    $chart_labels[] = $day['date_label'];
    $revenue_data[] = floatval($day['revenue']);
    $orders_data[] = intval($day['order_count']);
}

require_once 'includes/header.php';
?>

<style>
    .reports-container {
        max-width: 1200px;
        margin: 2rem auto;
        padding: 0 1rem;
    }
    .report-section {
        background: white;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.08);
        padding: 1.5rem;
        margin-bottom: 2rem;
    }
    .date-filter {
        display: flex;
        gap: 1rem;
        margin-bottom: 1.5rem;
        align-items: flex-end;
        flex-wrap: wrap;
    }
    .date-filter label {
        display: block;
        margin-bottom: 0.5rem;
        font-weight: 500;
    }
    .date-filter input, .date-filter select {
        padding: 0.5rem;
        border: 1px solid #ddd;
        border-radius: 4px;
    }
    .filter-submit {
        background: #4CAF50;
        color: white;
        border: none;
        border-radius: 4px;
        padding: 0.5rem 1rem;
        cursor: pointer;
    }
    .summary-cards {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2rem;
    }
    .summary-card {
        background: #f9f9f9;
        border-radius: 6px;
        padding: 1.25rem;
        text-align: center;
    }
    .summary-card h3 {
        margin: 0 0 0.5rem 0;
        color: #555;
        font-size: 0.9rem;
        text-transform: uppercase;
    }
    .summary-card .value {
        font-size: 1.8rem;
        font-weight: bold;
        color: #333;
    }
    table.data-table {
        width: 100%;
        border-collapse: collapse;
        margin: 1.5rem 0;
    }
    table.data-table th, table.data-table td {
        padding: 0.8rem;
        text-align: left;
        border-bottom: 1px solid #eee;
    }
    table.data-table th {
        background: #f5f5f5;
        font-weight: 600;
    }
    .chart-container {
        position: relative;
        height: 350px;
        margin: 1.5rem 0;
    }
    .section-title {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.5rem;
    }
    .export-button {
        background: #f0f0f0;
        border: 1px solid #ddd;
        border-radius: 4px;
        padding: 0.4rem 0.8rem;
        font-size: 0.9rem;
        cursor: pointer;
    }
    @media (max-width: 768px) {
        .summary-cards {
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        }
    }
</style>

<div class="reports-container">
    <div class="report-section">
        <h1>Sales Reports</h1>
        
        <form method="get" class="date-filter">
            <div>
                <label for="start_date">Start Date:</label>
                <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
            </div>
            <div>
                <label for="end_date">End Date:</label>
                <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
            </div>
            <div>
                <label for="view">View By:</label>
                <select id="view" name="view">
                    <option value="daily" <?php echo $view_type == 'daily' ? 'selected' : ''; ?>>Daily</option>
                    <option value="monthly" <?php echo $view_type == 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                </select>
            </div>
            <button type="submit" class="filter-submit">Apply Filter</button>
        </form>
        
        <?php if (isset($error_message)): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>
    </div>
    
    <div class="report-section">
        <div class="summary-cards">
            <div class="summary-card">
                <h3>Total Orders</h3>
                <div class="value"><?php echo number_format($summary['total_orders']); ?></div>
            </div>
            <div class="summary-card">
                <h3>Total Revenue</h3>
                <div class="value">£<?php echo number_format($summary['total_revenue'], 2); ?></div>
            </div>
            <div class="summary-card">
                <h3>Average Order</h3>
                <div class="value">£<?php echo number_format($summary['avg_order_value'], 2); ?></div>
            </div>
            <div class="summary-card">
                <h3>Largest Order</h3>
                <div class="value">£<?php echo number_format($summary['largest_order'], 2); ?></div>
            </div>
        </div>
    </div>
    
    <div class="report-section">
        <div class="section-title">
            <h2>Sales Over Time</h2>
            <button class="export-button" onclick="exportTableToCSV('sales-data.csv', 'sales-table')">Export CSV</button>
        </div>
        
        <div class="chart-container">
            <canvas id="revenueChart"></canvas>
        </div>
        
        <h3>Sales Data</h3>
        <table class="data-table" id="sales-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Orders</th>
                    <th>Revenue</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sales_data as $day): ?>
                <tr>
                    <td><?php echo htmlspecialchars($day['date_label']); ?></td>
                    <td><?php echo number_format($day['order_count']); ?></td>
                    <td>£<?php echo number_format($day['revenue'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($sales_data)): ?>
                <tr>
                    <td colspan="3" style="text-align: center;">No data available for the selected period</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <div class="report-section">
        <div class="section-title">
            <h2>Payment Methods</h2>
            <button class="export-button" onclick="exportTableToCSV('payment-methods.csv', 'payment-methods-table')">Export CSV</button>
        </div>
        
        <table class="data-table" id="payment-methods-table">
            <thead>
                <tr>
                    <th>Payment Method</th>
                    <th>Orders</th>
                    <th>Revenue</th>
                    <th>% of Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($payment_methods as $method): ?>
                <tr>
                    <td><?php echo htmlspecialchars(ucfirst($method['payment_method'] ?? 'Unknown')); ?></td>
                    <td><?php echo number_format($method['order_count']); ?></td>
                    <td>£<?php echo number_format($method['total_amount'], 2); ?></td>
                    <td><?php echo number_format(($method['total_amount'] / max(1, $summary['total_revenue'])) * 100, 1); ?>%</td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($payment_methods)): ?>
                <tr>
                    <td colspan="4" style="text-align: center;">No data available for the selected period</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <?php
        // Debug info
        $debug_stmt = $db->query("SELECT payment_method, COUNT(*) as count, SUM(total_amount) as total 
                                 FROM orders GROUP BY payment_method");
        $debug_methods = $debug_stmt->fetchAll(PDO::FETCH_ASSOC);
        ?>

        <div style="margin-bottom:20px;padding:10px;background:#f8f9fa;border:1px solid #ddd;border-radius:4px;">
            <strong>Debug - All Payment Methods:</strong>
            <ul style="margin:5px 0 0 0;padding-left:20px;">
            <?php foreach($debug_methods as $method): ?>
                <li><?php echo $method['payment_method'] === null ? 'NULL' : htmlspecialchars($method['payment_method']); ?>: 
                    <?php echo $method['count']; ?> orders, 
                    £<?php echo number_format($method['total'], 2); ?></li>
            <?php endforeach; ?>
            </ul>
        </div>
    </div>
    
    <div class="report-section">
        <div class="section-title">
            <h2>Top Products</h2>
            <button class="export-button" onclick="exportTableToCSV('top-products.csv', 'top-products-table')">Export CSV</button>
        </div>
        
        <table class="data-table" id="top-products-table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Units Sold</th>
                    <th>Revenue</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($top_products as $product): ?>
                <tr>
                    <td><?php echo htmlspecialchars($product['product_name']); ?></td>
                    <td><?php echo number_format($product['quantity_sold']); ?></td>
                    <td>£<?php echo number_format($product['revenue'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($top_products)): ?>
                <tr>
                    <td colspan="3" style="text-align: center;">No data available for the selected period</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Include Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
// Sales chart
const ctx = document.getElementById('revenueChart').getContext('2d');
const revenueChart = new Chart(ctx, {
    type: 'line',
    data: {
        labels: <?php echo json_encode($chart_labels); ?>,
        datasets: [
            {
                label: 'Revenue (£)',
                data: <?php echo json_encode($revenue_data); ?>,
                borderColor: '#4CAF50',
                backgroundColor: 'rgba(76, 175, 80, 0.1)',
                borderWidth: 2,
                fill: true,
                yAxisID: 'y'
            },
            {
                label: 'Orders',
                data: <?php echo json_encode($orders_data); ?>,
                borderColor: '#2196F3',
                backgroundColor: 'rgba(33, 150, 243, 0)',
                borderWidth: 2,
                fill: false,
                yAxisID: 'y1'
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            y: {
                beginAtZero: true,
                position: 'left',
                title: {
                    display: true,
                    text: 'Revenue (£)'
                }
            },
            y1: {
                beginAtZero: true,
                position: 'right',
                grid: {
                    drawOnChartArea: false
                },
                title: {
                    display: true,
                    text: 'Number of Orders'
                }
            },
            x: {
                title: {
                    display: true,
                    text: '<?php echo $view_type == 'monthly' ? 'Month' : 'Date'; ?>'
                }
            }
        },
        interaction: {
            mode: 'index',
            intersect: false,
        }
    }
});

// CSV Export function
function exportTableToCSV(filename, tableId) {
    const table = document.getElementById(tableId);
    let csv = [];
    const rows = table.querySelectorAll('tr');
    
    for (let i = 0; i < rows.length; i++) {
        const row = [], cols = rows[i].querySelectorAll('td, th');
        
        for (let j = 0; j < cols.length; j++) {
            // Replace £ and commas in numbers
            let data = cols[j].innerText.replace(/£/g, '').replace(/,/g, '');
            // Wrap in quotes if contains comma or quotes
            if (data.includes(',') || data.includes('"')) {
                data = '"' + data.replace(/"/g, '""') + '"';
            }
            row.push(data);
        }
        csv.push(row.join(','));        
    }
    
    // Download CSV file
    downloadCSV(csv.join('\n'), filename);
}

function downloadCSV(csv, filename) {
    const csvFile = new Blob([csv], {type: "text/csv"});
    const downloadLink = document.createElement("a");
    
    // File download
    downloadLink.download = filename;
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = "none";
    
    // Add to DOM, click, and remove
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
}
</script>

<?php require_once 'includes/footer.php'; ?>