<?php
session_start();

if (!isset($_SESSION['cashiers_id'])) {
    header("Location: ../index.php");
    exit();
}

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "kidsberry";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get bill number from GET parameter
if (isset($_GET['bill_no']) && !empty($_GET['bill_no'])) {
    $bill_no = mysqli_real_escape_string($conn, $_GET['bill_no']);
    
    // Fetch bill summary
    $bill_sql = "SELECT b.*, c.name as cashier_name 
                 FROM bills2 b 
                 JOIN cashier_users2 c ON b.cashier_id = c.cashier_id 
                 WHERE b.bill_no = '$bill_no'";
    $bill_result = $conn->query($bill_sql);
    
    if ($bill_result->num_rows > 0) {
        $bill = $bill_result->fetch_assoc();
        
        // Fetch bill items
        $items_sql = "SELECT * FROM bill_items2 WHERE bill_no = '$bill_no' ORDER BY id";
        $items_result = $conn->query($items_sql);
        
        echo '<div class="bill-details">';
        echo '<div class="bill-item">';
        echo '<span class="item-label">Bill Number:</span>';
        echo '<span class="item-value">' . htmlspecialchars($bill['bill_no']) . '</span>';
        echo '</div>';
        
        echo '<div class="bill-item">';
        echo '<span class="item-label">Date & Time:</span>';
        echo '<span class="item-value">' . date('d/m/Y H:i:s', strtotime($bill['date'])) . '</span>';
        echo '</div>';
        
        echo '<div class="bill-item">';
        echo '<span class="item-label">Cashier:</span>';
        echo '<span class="item-value">' . htmlspecialchars($bill['cashier_name']) . '</span>';
        echo '</div>';
        
        echo '<div class="bill-item">';
        echo '<span class="item-label">Payment Method:</span>';
        echo '<span class="item-value">' . htmlspecialchars($bill['payment_method']) . '</span>';
        echo '</div>';
        
        echo '<div class="bill-item">';
        echo '<span class="item-label">Paid Amount:</span>';
        echo '<span class="item-value">Rs' . number_format($bill['paid_amount'], 2) . '</span>';
        echo '</div>';
        
        echo '<div class="bill-item">';
        echo '<span class="item-label">Total Amount:</span>';
        echo '<span class="item-value">Rs' . number_format($bill['total'], 2) . '</span>';
        echo '</div>';
        
        echo '<div class="bill-item">';
        echo '<span class="item-label">Balance:</span>';
        echo '<span class="item-value">Rs' . number_format($bill['balance'], 2) . '</span>';
        echo '</div>';
        echo '</div>';
        
        // Display items
        if ($items_result->num_rows > 0) {
            echo '<h4 style="margin-bottom: 15px; color: var(--primary);"><i class="fas fa-shopping-cart"></i> Items</h4>';
            echo '<div style="background: white; border-radius: 8px; padding: 15px; border: 1px solid #eee;">';
            
            $item_count = 1;
            while ($item = $items_result->fetch_assoc()) {
                $item_total = $item['price'] * $item['quantity'] - $item['discount'];
                
                echo '<div style="padding: 10px; border-bottom: 1px solid #f0f0f0; margin-bottom: 10px;">';
                echo '<div style="display: flex; justify-content: space-between; margin-bottom: 5px;">';
                echo '<span style="font-weight: 600;">' . $item_count . '. ' . htmlspecialchars($item['product_name'] ?? 'Product') . '</span>';
                echo '<span>Qty: ' . $item['quantity'] . '</span>';
                echo '</div>';
                
                if (!empty($item['imei'])) {
                    echo '<div style="font-size: 13px; color: #666; margin-bottom: 5px;">';
                    echo 'IMEI: ' . htmlspecialchars($item['imei']);
                    echo '</div>';
                }
                
                echo '<div style="display: flex; justify-content: space-between; font-size: 14px;">';
                echo '<div>';
                echo 'Price: Rs' . number_format($item['price'], 2);
                if ($item['discount'] > 0) {
                    echo ' | Discount: Rs' . number_format($item['discount'], 2);
                }
                echo '</div>';
                echo '<div style="font-weight: 600;">';
                echo 'Total: Rs' . number_format($item_total, 2);
                echo '</div>';
                echo '</div>';
                echo '</div>';
                
                $item_count++;
            }
            echo '</div>';
        } else {
            echo '<div class="alert alert-error">';
            echo '<i class="fas fa-exclamation-triangle"></i>';
            echo 'No items found for this bill.';
            echo '</div>';
        }
    } else {
        echo '<div class="alert alert-error">';
        echo '<i class="fas fa-exclamation-triangle"></i>';
        echo 'Bill not found.';
        echo '</div>';
    }
} else {
    echo '<div class="alert alert-error">';
    echo '<i class="fas fa-exclamation-triangle"></i>';
    echo 'Invalid bill number.';
    echo '</div>';
}

$conn->close();
?>