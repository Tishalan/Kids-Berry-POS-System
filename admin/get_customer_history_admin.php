<?php
// get_customer_history_admin.php
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
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

// Function to convert UTC to Colombo time (UTC+5:30)
function convertToColomboTime($utc_time) {
    if (empty($utc_time) || $utc_time == '0000-00-00 00:00:00') {
        return 'Never';
    }
    
    // Create DateTime object from UTC time
    $utc_date = new DateTime($utc_time, new DateTimeZone('UTC'));
    
    // Convert to Colombo time (UTC+5:30)
    $colombo_timezone = new DateTimeZone('Asia/Colombo');
    $utc_date->setTimezone($colombo_timezone);
    
    // Format as Y-m-d H:i
    return $utc_date->format('Y-m-d H:i:s');
}

if (isset($_GET['phone_no'])) {
    $phone_no = mysqli_real_escape_string($conn, $_GET['phone_no']);
    
    // Get customer name
    $customer_sql = "SELECT name FROM customers WHERE phone_no = '$phone_no'";
    $customer_result = $conn->query($customer_sql);
    $customer_name = "Customer";
    if ($customer_result->num_rows > 0) {
        $customer_row = $customer_result->fetch_assoc();
        $customer_name = $customer_row['name'];
    }
    
    // Get purchase history
    $history_sql = "SELECT bill_no, date, total, payment_method FROM bills 
                   WHERE phone_no = '$phone_no' 
                   ORDER BY date DESC 
                   LIMIT 50";
    $history_result = $conn->query($history_sql);
    
    if ($history_result->num_rows > 0) {
        echo "<p><strong>Customer:</strong> $customer_name</p>";
        echo "<p><strong>Phone:</strong> $phone_no</p>";
        echo "<div style='max-height: 400px; overflow-y: auto;'>";
        echo "<table class='history-table'>";
        echo "<thead>";
        echo "<tr>";
        echo "<th>Bill No</th>";
        echo "<th>Date (Colombo Time)</th>";
        echo "<th>Total</th>";
        echo "<th>Payment Method</th>";
        echo "</tr>";
        echo "</thead>";
        echo "<tbody>";
        
        $total_spent = 0;
        $total_bills = 0;
        
        while ($row = $history_result->fetch_assoc()) {
            $total_bills++;
            $total_spent += $row['total'];
            $colombo_time = convertToColomboTime($row['date']);
            
            echo "<tr>";
            echo "<td>{$row['bill_no']}</td>";
            echo "<td>$colombo_time</td>";
            echo "<td>Rs " . number_format($row['total'], 2) . "</td>";
            echo "<td>{$row['payment_method']}</td>";
            echo "</tr>";
        }
        
        echo "</tbody>";
        echo "</table>";
        echo "</div>";
        echo "<div style='margin-top: 15px; padding: 10px; background: #f8f9fa; border-radius: 8px;'>";
        echo "<p><strong>Total Bills:</strong> $total_bills</p>";
        echo "<p><strong>Total Spent:</strong> Rs " . number_format($total_spent, 2) . "</p>";
        echo "</div>";
    } else {
        echo "<p>No purchase history found for this customer.</p>";
    }
} else {
    echo "<p>Invalid request.</p>";
}

$conn->close();
?>