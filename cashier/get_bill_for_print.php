<?php
session_start();

if (!isset($_SESSION['cashiers_id'])) {
    header('Content-Type: application/json');
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
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

$bill_no = isset($_GET['bill_no']) ? $conn->real_escape_string($_GET['bill_no']) : '';

if (empty($bill_no)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Bill number is required']);
    exit();
}

// Get bill details
$bill_sql = "SELECT 
                b.bill_no,
                b.cashier_id,
                b.payment_method,
                b.paid_amount,
                b.subtotal,
                b.total_discount,
                b.total,
                b.balance,
                b.customer_name,
                b.phone_no,
                b.nic_no,
                b.date,
                c.name as cashier_name
              FROM bills b
              JOIN cashier_users c ON b.cashier_id = c.cashier_id
              WHERE b.bill_no = '$bill_no'";
              
$bill_result = $conn->query($bill_sql);

if ($bill_result->num_rows == 0) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Bill not found']);
    exit();
}

$bill = $bill_result->fetch_assoc();

// Get bill items
$items_sql = "SELECT 
                product_id,
                product_name,
                product_color,
                imei,
                psn_numbers,
                quantity,
                price,
                discount,
                discount_type,
                discount_input,
                return_status
              FROM bill_items 
              WHERE bill_no = '$bill_no'";
              
$items_result = $conn->query($items_sql);
$items = [];

while ($row = $items_result->fetch_assoc()) {
    // Format items for printing (similar to cart session structure)
    $items[] = [
        'product_id' => $row['product_id'],
        'name' => $row['product_name'],
        'color' => $row['product_color'],
        'imei' => $row['imei'],
        'psn_numbers' => $row['psn_numbers'],
        'quantity' => $row['quantity'],
        'price' => floatval($row['price']),
        'discount' => floatval($row['discount']),
        'discount_type' => $row['discount_type'],
        'discount_input' => $row['discount_input'],
        'return_status' => $row['return_status']
    ];
}

$conn->close();

// Return JSON response
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'bill' => $bill,
    'items' => $items
]);
?>