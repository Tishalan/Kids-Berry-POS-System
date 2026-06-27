[file name]: get_details.php
[file content begin]
<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['cashiers_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "kidsberry";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

if (!isset($_GET['bill_no'])) {
    echo json_encode(['success' => false, 'message' => 'Bill number required']);
    exit();
}

$bill_no = mysqli_real_escape_string($conn, $_GET['bill_no']);

// Get bill details
$bill_sql = "SELECT 
                b.*,
                c.name as cashier_name,
                DATE(b.date) as bill_date,
                TIME(b.date) as bill_time
              FROM bills b
              JOIN cashier_users c ON b.cashier_id = c.cashier_id
              WHERE b.bill_no = '$bill_no'";
$bill_result = $conn->query($bill_sql);

if ($bill_result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Bill not found']);
    exit();
}

$bill = $bill_result->fetch_assoc();

// Get bill items
$items_sql = "SELECT 
                bi.*,
                p.color as product_color,
                DATE_FORMAT(bi.return_date, '%Y-%m-%d %H:%i:%s') as return_date_formatted
              FROM bill_items bi
              LEFT JOIN products p ON bi.product_id = p.product_id
              WHERE bi.bill_no = '$bill_no'
              ORDER BY bi.id";
$items_result = $conn->query($items_sql);
$items = [];

while ($item = $items_result->fetch_assoc()) {
    $items[] = $item;
}

// Format dates for display
$bill['date'] = date('Y-m-d', strtotime($bill['bill_date']));
$bill['time'] = $bill['bill_time'];

echo json_encode([
    'success' => true,
    'bill' => $bill,
    'items' => $items,
    'item_count' => count($items)
]);

$conn->close();
[file content end]