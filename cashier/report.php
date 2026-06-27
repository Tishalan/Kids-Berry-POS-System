<?php
session_start();

date_default_timezone_set('Asia/Colombo');


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

$branch_id = 1; // Hardcoded for now, update to $_SESSION['branch_id'] later
$_SESSION['branch_id'] = $branch_id;

// Get all active cashiers for dropdown
$cashiers_sql = "SELECT cashier_id, name FROM cashier_users WHERE status = 'active' ORDER BY name";
$cashiers_result = $conn->query($cashiers_sql);

// Initialize variables
$report_type = '';
$from_date = '';
$to_date = '';
$cashier_id = '';
$results = [];
$total_sales = 0;
$total_profit = 0;
$total_discount = 0;
$total_paid_amount = 0;
$total_outstanding_balance = 0;
$total_cash_payments = 0;
$total_card_payments = 0;
$current_date = date('Y-m-d');

// NEW: Variables for credit amounts
$total_credits = 0;
$total_credit_amount = 0;

// Calculate TODAY'S TOTAL RETURNS
$today = date('Y-m-d');
$returns_sql = "SELECT 
                SUM(return_amount) as total_returns,
                COUNT(*) as return_count
                FROM returns_tracking 
                WHERE DATE(return_date) = '$today'";
$returns_result = $conn->query($returns_sql);
$today_returns = 0;
$today_return_count = 0;

if ($returns_result->num_rows > 0) {
    $returns_data = $returns_result->fetch_assoc();
    $today_returns = $returns_data['total_returns'] ?? 0;
    $today_return_count = $returns_data['return_count'] ?? 0;
}

$bills_sql = "SELECT bill_no, date, payment_method, paid_amount, total, subtotal, total_discount FROM bills WHERE DATE(date) = '$current_date' ORDER BY date ASC";
$bills_result = $conn->query($bills_sql);

while ($bill = $bills_result->fetch_assoc()) {
    $bill_no = $bill['bill_no'];
    $payment_method = $bill['payment_method'];
    $paid_amount = floatval($bill['paid_amount']);
    $total_bill = floatval($bill['total']);
    $subtotal_bill = floatval($bill['subtotal']);
    $discount_bill = floatval($bill['total_discount']);

    
    $items_sql = "SELECT bi.*, p.original_price FROM bill_items bi JOIN products p ON bi.product_id = p.product_id WHERE bi.bill_no = '$bill_no'";
    $items_result = $conn->query($items_sql);
    
    while ($item = $items_result->fetch_assoc()) {
        $cost = $item['original_price'] * $item['quantity'];
        $revenue = $item['subtotal'] - $item['discount'];
        $profit = $revenue - $cost;
        $total_profit += $profit;
    }
    
}

// Handle report generation
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['generate_report'])) {
    $report_type = mysqli_real_escape_string($conn, $_POST['report_type']);
    $from_date = mysqli_real_escape_string($conn, $_POST['from']);
    $to_date = isset($_POST['to']) && !empty($_POST['to']) ? mysqli_real_escape_string($conn, $_POST['to']) : $from_date;
    $cashier_id = isset($_POST['cashier_id']) ? mysqli_real_escape_string($conn, $_POST['cashier_id']) : '';
    
    // ========== IMPORTANT FIX ==========
    // Calculate TOTAL SALES for the selected period
    $total_sales_sql = "SELECT SUM(total) as period_total_sales 
                        FROM bills 
                        WHERE branch_id = $branch_id 
                        AND DATE(date) BETWEEN '$from_date' AND '$to_date'";
    
    if (!empty($cashier_id)) {
        $total_sales_sql .= " AND cashier_id = '$cashier_id'";
    }
    
    $total_sales_result = $conn->query($total_sales_sql);
    $period_total_sales = 0;
    if ($total_sales_result->num_rows > 0) {
        $total_sales_data = $total_sales_result->fetch_assoc();
        $period_total_sales = $total_sales_data['period_total_sales'] ?? 0;
    }
    // ===================================
    
    // NEW: Calculate total credit amounts for the selected period
    $credits_sql = "SELECT COUNT(DISTINCT b.bill_no) as total_credits, 
                           SUM(ABS(b.balance)) as total_credit_amount 
                    FROM bills b 
                    WHERE b.branch_id = $branch_id 
                    AND b.payment_method = 'Credit' 
                    AND b.balance < 0 
                    AND DATE(b.date) BETWEEN '$from_date' AND '$to_date'";
    
    if (!empty($cashier_id)) {
        $credits_sql .= " AND b.cashier_id = '$cashier_id'";
    }
    
    $credits_result = $conn->query($credits_sql);
    if ($credits_result->num_rows > 0) {
        $credits_data = $credits_result->fetch_assoc();
        $total_credits = $credits_data['total_credits'] ?? 0;
        $total_credit_amount = $credits_data['total_credit_amount'] ?? 0;
    }
    
    // Build the base SQL query - Include payment details
    $sales_sql = "SELECT 
                    DATE(b.date) as date,
                    TIME(b.date) as time,
                    DATE_FORMAT(CONVERT_TZ(b.date, '+00:00', '+05:30'), '%H:%i:%s') as time,
                    b.bill_no,
                    c.name as cashier_name,
                    COALESCE(bi.product_name, 'Product Not Found') as product_name,
                    bi.imei,
                    bi.quantity,
                    bi.discount,
                    bi.price as sale_price,
                    COALESCE(bi.original_price, 0) as original_price,
                    (bi.price * bi.quantity - bi.discount - COALESCE(bi.original_price, 0) * bi.quantity) as profit,
                    b.payment_method,
                    b.paid_amount,
                    b.total as total_amount,
                    b.balance as outstanding_balance
                  FROM bills b
                  JOIN bill_items bi ON b.bill_no = bi.bill_no
                  JOIN cashier_users c ON b.cashier_id = c.cashier_id
                  WHERE b.branch_id = $branch_id
                  AND DATE(b.date) BETWEEN '$from_date' AND '$to_date'
                  AND bi.return_status = 'sold'";
    
    // Add cashier filter if selected
    if (!empty($cashier_id)) {
        $sales_sql .= " AND b.cashier_id = '$cashier_id'";
    }
    
    if ($report_type == 'Daily Sales') {
        // Daily Sales Report
        $sales_sql .= " ORDER BY b.date DESC";
        
        $sales_result = $conn->query($sales_sql);
        
        if ($sales_result->num_rows > 0) {
            while ($row = $sales_result->fetch_assoc()) {
                $results[] = $row;
                $total_sales += ($row['sale_price'] * $row['quantity'] - $row['discount']);
                $total_profit += $row['profit'];
                $total_discount += $row['discount'];
                $total_paid_amount += $row['paid_amount'];
                $total_outstanding_balance += $row['outstanding_balance'];
                
                // *** IMPORTANT CHANGE HERE ***
                // Calculate payment method totals based on NET PRICE instead of paid_amount
                $net_price = ($row['sale_price'] * $row['quantity']) - $row['discount'];
                
                if (strtolower($row['payment_method']) == 'cash') {
                    $total_cash_payments += $net_price; // Changed: Use net_price instead of paid_amount
                } elseif (strtolower($row['payment_method']) == 'card') {
                    $total_card_payments += $net_price; // Changed: Use net_price instead of paid_amount
                }
            }
        }
    } else {
        // Monthly Sales Report
        $sales_sql .= " ORDER BY b.date DESC";
        
        $sales_result = $conn->query($sales_sql);
        
        if ($sales_result->num_rows > 0) {
            while ($row = $sales_result->fetch_assoc()) {
                $month = $row['month'];
                if (!isset($results[$month])) {
                    $results[$month] = [
                        'items' => [],
                        'total_sales' => 0,
                        'total_profit' => 0,
                        'total_discount' => 0,
                        'total_paid_amount' => 0,
                        'total_outstanding_balance' => 0,
                        'total_cash_payments' => 0,
                        'total_card_payments' => 0
                    ];
                }
                
                $results[$month]['items'][] = $row;
                $results[$month]['total_sales'] += ($row['sale_price'] * $row['quantity'] - $row['discount']);
                $results[$month]['total_profit'] += $row['profit'];
                $results[$month]['total_discount'] += $row['discount'];
                $results[$month]['total_paid_amount'] += $row['paid_amount'];
                $results[$month]['total_outstanding_balance'] += $row['outstanding_balance'];
                
                // *** IMPORTANT CHANGE HERE ***
                // Calculate payment method totals for monthly based on NET PRICE instead of paid_amount
                $net_price = ($row['sale_price'] * $row['quantity']) - $row['discount'];
                
                if (strtolower($row['payment_method']) == 'cash') {
                    $results[$month]['total_cash_payments'] += $net_price; // Changed: Use net_price
                    $total_cash_payments += $net_price;
                } elseif (strtolower($row['payment_method']) == 'card') {
                    $results[$month]['total_card_payments'] += $net_price; // Changed: Use net_price
                    $total_card_payments += $net_price;
                }
                
                $total_sales += ($row['sale_price'] * $row['quantity'] - $row['discount']);
                $total_profit += $row['profit'];
                $total_discount += $row['discount'];
                $total_paid_amount += $row['paid_amount'];
                $total_outstanding_balance += $row['outstanding_balance'];
            }
        }
    }
    
    // Calculate returns for the selected period
    $period_returns_sql = "SELECT 
                          SUM(return_amount) as period_returns,
                          COUNT(*) as period_return_count
                          FROM returns_tracking 
                          WHERE DATE(return_date) BETWEEN '$from_date' AND '$to_date'";
    $period_returns_result = $conn->query($period_returns_sql);
    $period_returns = 0;
    $period_return_count = 0;
    
    if ($period_returns_result->num_rows > 0) {
        $period_data = $period_returns_result->fetch_assoc();
        $period_returns = $period_data['period_returns'] ?? 0;
        $period_return_count = $period_data['period_return_count'] ?? 0;
    }
    
    // Use period total sales (corrected)
    $total_sales = $period_total_sales; // This is the corrected total sales
}

// Handle export to Excel
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['export_excel'])) {
    // Set headers for Excel download
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="sales_report_' . date('Y-m-d') . '.xls"');
    
    $export_report_type = mysqli_real_escape_string($conn, $_POST['report_type']);
    $export_from_date = mysqli_real_escape_string($conn, $_POST['from']);
    $export_to_date = mysqli_real_escape_string($conn, $_POST['to']);
    $export_cashier_id = mysqli_real_escape_string($conn, $_POST['cashier_id']);
    
    echo "Sales Report\n";
    echo "Report Type: " . $export_report_type . "\n";
    echo "Period: " . $export_from_date . " to " . $export_to_date . "\n";
    if (!empty($export_cashier_id)) {
        $cashier_name_sql = "SELECT name FROM cashier_users WHERE cashier_id = '$export_cashier_id'";
        $cashier_name_result = $conn->query($cashier_name_sql);
        if ($cashier_name_result->num_rows > 0) {
            $cashier_name = $cashier_name_result->fetch_assoc()['name'];
            echo "Cashier: " . $cashier_name . "\n";
        }
    }
    echo "\n";
    
    // Build export SQL - Include payment details
    $export_sql = "SELECT 
                    DATE(b.date) as date,
                    TIME(b.date) as time,
                    DATE_FORMAT(b.date, '%Y-%m') as month,
                    b.bill_no,
                    c.name as cashier_name,
                    COALESCE(bi.product_name, 'Product Not Found') as product_name,
                    bi.imei,
                    bi.quantity,
                    bi.discount,
                    bi.price as sale_price,
                    COALESCE(bi.original_price, 0) as original_price,
                    (bi.price * bi.quantity - bi.discount - COALESCE(bi.original_price, 0) * bi.quantity) as profit,
                    b.payment_method,
                    b.paid_amount,
                    b.total as total_amount,
                    b.balance as outstanding_balance
                  FROM bills b
                  JOIN bill_items bi ON b.bill_no = bi.bill_no
                  JOIN cashier_users c ON b.cashier_id = c.cashier_id
                  WHERE b.branch_id = $branch_id
                  AND DATE(b.date) BETWEEN '$export_from_date' AND '$export_to_date'";
    
    if (!empty($export_cashier_id)) {
        $export_sql .= " AND b.cashier_id = '$export_cashier_id'";
    }
    
    if ($export_report_type == 'Daily Sales') {
        $export_sql .= " ORDER BY b.date DESC";
        
        echo "Date\tTime\tBill No\tCashier\tProduct\tIMEI\tQuantity\tDiscount\tSale Price\tNet Price\tPayment Method\tPaid Amount\tTotal Amount\n";
        
        $export_result = $conn->query($export_sql);
        
        while ($row = $export_result->fetch_assoc()) {
            $net_price = ($row['sale_price'] * $row['quantity']) - $row['discount'];
            echo $row['date'] . "\t";
            echo $row['time'] . "\t";
            echo $row['bill_no'] . "\t";
            echo $row['cashier_name'] . "\t";
            echo $row['product_name'] . "\t";
            echo ($row['imei'] ?? '') . "\t";
            echo $row['quantity'] . "\t";
            echo $row['discount'] . "\t";
            echo $row['sale_price'] . "\t";
            echo $net_price . "\t"; // Net Price column
            echo $row['payment_method'] . "\t";
            echo $row['paid_amount'] . "\t"; // Paid Amount column
            echo $row['total_amount'] . "\n";
        }
    } else {
        $export_sql .= " ORDER BY b.date DESC";
        
        echo "Month\tBill No\tCashier\tProduct\tIMEI\tQuantity\tDiscount\tSale Price\tNet Price\tPayment Method\tPaid Amount\tTotal Amount\n";
        
        $export_result = $conn->query($export_sql);
        
        while ($row = $export_result->fetch_assoc()) {
            $net_price = ($row['sale_price'] * $row['quantity']) - $row['discount'];
            echo $row['month'] . "\t";
            echo $row['bill_no'] . "\t";
            echo $row['cashier_name'] . "\t";
            echo $row['product_name'] . "\t";
            echo ($row['imei'] ?? '') . "\t";
            echo $row['quantity'] . "\t";
            echo $row['discount'] . "\t";
            echo $row['sale_price'] . "\t";
            echo $net_price . "\t"; // Net Price column
            echo $row['payment_method'] . "\t";
            echo $row['paid_amount'] . "\t"; // Paid Amount column
            echo $row['total_amount'] . "\n";
        }
    }
    
    exit();
}

// Handle logout
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['logout'])) {
    // Only destroy cashier related sessions
    unset($_SESSION['cashier_id']);
    unset($_SESSION['branch_id']);
    header("Location: ../index.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KIDS Berry - Reports</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #5a3d7e;
            --secondary: #28a745;
            --accent: #dc3545;
            --warning: #ffc107;
            --danger: #dc3545;
            --info: #17a2b8;
            --success: #28a745;
            --light: #f8f9fa;
            --dark: #343a40;
            --text: #212529;
            --card-shadow: 0 6px 12px rgba(0,0,0,0.15), 0 2px 6px rgba(0,0,0,0.1);
            --transition: all 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            --radius: 15px;
            --gradient-primary: linear-gradient(135deg, #5a3d7e 0%, #8e44ad 100%);
            --gradient-secondary: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            --gradient-accent: linear-gradient(135deg, #dc3545 0%, #ff6b6b 100%);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8f0 100%);
            color: var(--text);
            min-height: 100vh;
            position: relative;
            overflow-x: hidden;
        }

        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, var(--secondary), var(--primary), var(--accent));
            z-index: 1000;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--gradient-primary);
            color: white;
            padding: 18px 35px;
            border-radius: 0 0 var(--radius) var(--radius);
            box-shadow: var(--card-shadow);
            position: relative;
            overflow: hidden;
            animation: slideDown 0.7s ease;
        }

        .header::after {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.1), transparent);
            animation: shimmer 3s infinite;
        }

        .header-content {
            display: flex;
            flex-direction: column;
            align-items: center;
            flex-grow: 1;
            z-index: 1;
        }

        .header h1 {
            margin: 0;
            font-size: 32px;
            font-weight: 800;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
            color: #ffffff;
            letter-spacing: 0.5px;
        }

        .header .date-time {
            font-size: 16px;
            text-align: center;
            margin-top: 5px;
            opacity: 0.9;
        }

        .logo-container img {
            width: 85px;
            height: 85px;
            border-radius: 50%;
            border: 3px solid white;
            box-shadow: 0 0 20px rgba(255,255,255,0.6);
            transition: var(--transition);
            z-index: 1;
        }

        .logo-container img:hover {
            transform: rotate(5deg) scale(1.05);
            box-shadow: 0 0 25px rgba(255,255,255,0.8);
        }

        .logout-btn {
            background: rgba(255,255,255,0.15);
            color: white;
            border: 2px solid rgba(255,255,255,0.3);
            padding: 12px 25px;
            border-radius: 50px;
            cursor: pointer;
            transition: var(--transition);
            font-weight: 600;
            font-size: 15px;
            backdrop-filter: blur(10px);
            z-index: 1;
        }

        .logout-btn:hover {
            background: rgba(255,255,255,0.25);
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        .nav {
            margin: 25px auto;
            display: flex;
            justify-content: center;
            max-width: 95%;
            flex-wrap: wrap;
            gap: 10px;
        }

        .nav a {
            text-decoration: none;
            color: white;
            font-weight: 600;
            padding: 14px 28px;
            border-radius: 50px;
            background: var(--gradient-secondary);
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            position: relative;
            overflow: hidden;
        }

        .nav a::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: 0.5s;
        }

        .nav a:hover::before {
            left: 100%;
        }

        .nav a.active {
            background: var(--gradient-accent);
            box-shadow: 0 6px 12px rgba(220, 53, 69, 0.3);
        }

        .nav a:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 15px rgba(0,0,0,0.2);
        }

        .main-container {
            padding: 0 30px;
            max-width: 1600px;
            margin: 0 auto 30px;
        }

        .card {
            background: white;
            border-radius: var(--radius);
            padding: 30px;
            box-shadow: var(--card-shadow);
            animation: fadeInUp 0.8s ease;
            border: 1px solid rgba(0,0,0,0.05);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            margin-bottom: 30px;
        }

        .card:hover {
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
        }

        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
            background: var(--gradient-primary);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 18px;
            border-bottom: 2px solid var(--light);
            position: relative;
        }

        .card-header::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 100px;
            height: 3px;
            background: var(--gradient-primary);
            border-radius: 3px;
        }

        .card-title {
            color: var(--primary);
            font-size: 26px;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 700;
        }

        .card-title i {
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        /* Today's Returns Info - Updated Design */
        .returns-info {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
            border: 2px solid #ffc107;
            border-radius: var(--radius);
            padding: 20px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: 0 4px 8px rgba(255, 193, 7, 0.1);
            animation: fadeIn 0.5s ease;
        }

        .returns-info > div {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 16px;
            font-weight: 600;
        }

        .returns-info i {
            color: var(--danger);
            font-size: 22px;
        }

        .returns-info strong {
            color: var(--dark);
        }

        .returns-info a {
            text-decoration: none;
            color: var(--primary);
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px;
            background: white;
            border-radius: 8px;
            border: 2px solid var(--primary);
            transition: var(--transition);
        }

        .returns-info a:hover {
            background: var(--gradient-primary);
            color: white;
            transform: translateY(-3px);
            box-shadow: 0 5px 12px rgba(90, 61, 126, 0.3);
        }

        /* Form Container - Updated Design */
        .form-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
            align-items: end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            margin-bottom: 10px;
            font-weight: 700;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 15px;
        }

        .form-group label i {
            color: var(--primary);
        }

        .form-group input,
        .form-group select {
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 15px;
            transition: var(--transition);
            background: white;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            font-weight: 500;
        }

        .form-group input:focus,
        .form-group select:focus {
            border-color: var(--secondary);
            box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.15);
            outline: none;
            transform: translateY(-2px);
        }

        .btn {
            padding: 14px 20px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 700;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font-size: 16px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            position: relative;
            overflow: hidden;
            height: 50px;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: 0.5s;
        }

        .btn:hover::before {
            left: 100%;
        }

        .btn-primary {
            background: var(--gradient-secondary);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(40, 167, 69, 0.3);
        }

        .btn-warning {
            background: #FF9800;
            color: var(--dark);
        }

        .btn-warning:hover {
            background: #e0a800;
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(255, 193, 7, 0.3);
        }

        /* Stats Container - Updated Design */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 22px;
            border-radius: var(--radius);
            box-shadow: var(--card-shadow);
            text-align: center;
            transition: var(--transition);
            border: 1px solid rgba(0,0,0,0.05);
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 12px 25px rgba(0,0,0,0.15);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
        }

        .stat-card.today-returns {
            background: linear-gradient(135deg, #fff 0%, #f8f9fa 100%);
            border-left: 5px solid var(--danger);
        }

        .stat-card.today-returns .value {
            color: var(--danger);
        }

        .stat-card.net-sales {
            background: linear-gradient(135deg, #fff 0%, #f0f8ff 100%);
            border-left: 5px solid var(--info);
        }

        .stat-card.net-sales .value {
            color: var(--info);
        }

        .stat-card.cash-payments {
            background: linear-gradient(135deg, #fff 0%, #e8f5e8 100%);
            border-left: 5px solid #28a745;
        }

        .stat-card.cash-payments .value {
            color: #28a745;
        }

        .stat-card.card-payments {
            background: linear-gradient(135deg, #fff 0%, #e8f0fe 100%);
            border-left: 5px solid #007bff;
        }

        .stat-card.card-payments .value {
            color: #007bff;
        }

        .stat-card.credit-amounts {
            background: linear-gradient(135deg, #fff 0%, #f3e5f5 100%);
            border-left: 5px solid #9c27b0;
        }

        .stat-card.credit-amounts .value {
            color: #9c27b0;
        }

        .stat-card h3 {
            font-size: 14px;
            color: var(--dark);
            margin-bottom: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 700;
        }

        .stat-card .value {
            font-size: 26px;
            font-weight: 800;
            color: var(--primary);
            margin-bottom: 8px;
        }

        .stat-card .sub-value {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
            font-weight: 600;
        }

        /* Table Container - Updated Design */
        .table-container {
            overflow-x: auto;
            border-radius: var(--radius);
            box-shadow: var(--card-shadow);
            margin-bottom: 25px;
            border: 1px solid rgba(0,0,0,0.05);
        }

        .report-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }

        .report-table th {
            background: var(--gradient-primary);
            color: white;
            padding: 18px;
            text-align: left;
            font-weight: 700;
            position: sticky;
            top: 0;
            font-size: 15px;
            white-space: nowrap;
        }

        .report-table tr {
            transition: var(--transition);
            animation: fadeIn 0.5s ease;
        }

        .report-table tr:nth-child(even) {
            background-color: #f8f9fa;
        }

        .report-table tr:hover {
            background-color: #e8f4fc;
            transform: translateX(5px);
        }

        .report-table td {
            padding: 16px;
            border-bottom: 1px solid #eee;
            font-size: 14px;
            font-weight: 500;
        }

        .monthly-total {
            background-color: #e9ecef !important;
            font-weight: bold;
        }

        .monthly-total:hover {
            background-color: #dde1e5 !important;
            transform: none !important;
        }

        .export-buttons {
            display: flex;
            justify-content: flex-end;
            margin-top: 25px;
            gap: 15px;
        }

        .no-records {
            text-align: center;
            padding: 60px 20px;
            color: #7f8c8d;
        }

        .no-records i {
            font-size: 70px;
            margin-bottom: 20px;
            color: #dee2e6;
        }

        .no-records h3 {
            font-size: 22px;
            margin-bottom: 10px;
            color: var(--dark);
            font-weight: 700;
        }

        .no-records p {
            font-size: 16px;
            max-width: 400px;
            margin: 0 auto;
            color: #6c757d;
        }

        /* Action buttons styles */
        .action-buttons {
            display: flex;
            gap: 10px;
        }

        .view-btn {
            background: purple;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 6px;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 6px;
            font-weight: 600;
            font-size: 13px;
        }

        .view-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(23, 162, 184, 0.3);
        }

        .print-btn {
            background: var(--gradient-secondary);
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 6px;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 6px;
            font-weight: 600;
            font-size: 13px;
        }

        .print-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.3);
        }

        /* Modal styles for view details */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            animation: fadeIn 0.3s ease;
        }

        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 30px;
            border-radius: var(--radius);
            width: 80%;
            max-width: 800px;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 15px 35px rgba(0,0,0,0.3);
            animation: slideDown 0.3s ease;
            position: relative;
        }

        .close-modal {
            position: absolute;
            right: 20px;
            top: 15px;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            color: var(--primary);
            transition: var(--transition);
        }

        .close-modal:hover {
            color: var(--accent);
            transform: scale(1.2);
        }

        .modal-header {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light);
        }

        .modal-header h3 {
            color: var(--primary);
            font-size: 24px;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .detail-item {
            background: #f8f9fa;
            padding: 18px;
            border-radius: 8px;
            border-left: 4px solid var(--primary);
        }

        .detail-label {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .detail-value {
            font-size: 16px;
            font-weight: 700;
            color: var(--dark);
        }

        /* Notification */
        .notification {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background:#9C27B0;
            color: white;
            padding: 18px 30px;
            border-radius: 10px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.2);
            display: none;
            z-index: 1000;
            animation: slideInRight 0.5s ease, fadeOut 0.5s ease 2.5s forwards;
            font-weight: 600;
            max-width: 350px;
            backdrop-filter: blur(10px);
        }

        @keyframes slideDown {
            from { transform: translateY(-100%); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes fadeInUp {
            from { transform: translateY(40px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        @keyframes slideInRight {
            from { transform: translateX(150px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        @keyframes fadeOut {
            from { opacity: 1; }
            to { opacity: 0; visibility: hidden; }
        }

        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }

        /* Responsive Design */
        @media (max-width: 1200px) {
            .form-container {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            }
            
            .stats-container {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                gap: 20px;
                text-align: center;
                padding: 20px;
            }
            
            .nav {
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .nav a {
                padding: 12px 20px;
                font-size: 14px;
            }
            
            .main-container {
                padding: 0 15px;
            }
            
            .card {
                padding: 20px;
            }
            
            .form-container {
                grid-template-columns: 1fr;
            }
            
            .export-buttons {
                flex-direction: column;
            }
            
            .modal-content {
                width: 95%;
                padding: 20px;
            }
        }

        @media (max-width: 480px) {
            .header h1 {
                font-size: 24px;
            }
            
            .returns-info {
                flex-direction: column;
                text-align: center;
            }
            
            .stats-container {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .report-table {
                font-size: 12px;
            }
            
            .report-table th,
            .report-table td {
                padding: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="logo-container">
            <img src="../logo.jpg" alt="Kids Berry Logo">
        </div>
        <div class="header-content">
            <h1><i class="fas fa-baby"></i> KIDS Berry - Reports</h1>
            <div class="date-time">
                <p id="current-date"><?php echo date('l, F j, Y'); ?></p>
                <p id="current-time"><?php echo date('g:i:s A'); ?></p>
            </div>
        </div>
        <form method="post">
            <button type="submit" name="logout" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i> Logout
            </button>
        </form>
    </div>
    
    <div class="nav">
        <a href="billing.php"><i class="fas fa-cash-register"></i> Billing</a>
        <a href="customer.php"><i class="fas fa-users"></i> Customers</a>
        <a href="bill_management.php" class=""><i class="fas fa-history"></i> Bill History</a>
        <a href="report.php" class="active"><i class="fas fa-chart-line"></i> Reports</a>
        <a href="credit_payments.php"><i class="fas fa-credit-card"></i> Credit Customers</a>
        <a href="return_sale.php"><i class="fas fa-undo-alt"></i> Manage Returns</a>
                <!--<a href="prediction_dashboard.php" class=""><i class="fas fa-bullseye"></i> Predictions</a>-->

    </div>
    
    <div class="main-container">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-chart-pie"></i> Sales Reports</h2>
            </div>
            
            <!-- Today's Returns Info -->
            <div class="returns-info">
                <div>
                    <i class="fas fa-exchange-alt"></i>
                    <strong>Today's Returns Summary:</strong> 
                    <span style="margin-left: 10px;">
                        Total Returns: <strong style="color: var(--danger);">Rs<?php echo number_format($today_returns, 2); ?></strong> 
                        (<?php echo $today_return_count; ?> items)
                    </span>
                </div>
                <a href="return_sale.php">
                    <i class="fas fa-external-link-alt"></i> Manage Returns
                </a>
            </div>
            
            <form method="post" class="form-container">
                <div class="form-group">
                    <label><i class="fas fa-chart-bar"></i> Report Type</label>
                    <select name="report_type" required>
                        <option value="Daily Sales" <?php echo $report_type == 'Daily Sales' ? 'selected' : ''; ?>>Daily Sales</option>
                        <option value="Monthly Sales" <?php echo $report_type == 'Monthly Sales' ? 'selected' : ''; ?>>Monthly Sales</option>
                    </select>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-calendar-start"></i> From</label>
                    <input type="date" name="from" value="<?php echo htmlspecialchars($from_date); ?>" required>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-calendar-end"></i> To</label>
                    <input type="date" name="to" value="<?php echo htmlspecialchars($to_date); ?>">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-user-tie"></i> Sales Officer</label>
                    <select name="cashier_id">
                        <option value="">All Sales Officers</option>
                        <?php 
                        $cashiers_result = $conn->query($cashiers_sql);
                        while ($cashier = $cashiers_result->fetch_assoc()): 
                        ?>
                            <option value="<?php echo $cashier['cashier_id']; ?>" <?php echo $cashier_id == $cashier['cashier_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cashier['name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group">
                    <button type="submit" name="generate_report" class="btn btn-primary">
                        <i class="fas fa-sync"></i> Generate Report
                    </button>
                </div>
            </form>

            <?php if (!empty($results)) { ?>
                <div class="stats-container">
                    <div class="stat-card">
                        <h3>Total Sales Items</h3>
                        <div class="value">
                            <?php 
                            $total_items = 0;
                            if ($report_type == 'Daily Sales') {
                                $total_items = count($results);
                            } else {
                                foreach ($results as $group) {
                                    $total_items += count($group['items']);
                                }
                            }
                            echo $total_items;
                            ?>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <h3>Total Sales</h3>
                        <div class="value">Rs<?php 
                            if (isset($period_total_sales)) {
                                echo number_format($period_total_sales, 2);
                            } else {
                                // Default to today's sales if no report generated
                                $today_sales_sql = "SELECT SUM(total) as today_total FROM bills 
                                                  WHERE DATE(date) = '$current_date'";
                                $today_sales_result = $conn->query($today_sales_sql);
                                $today_total = 0;
                                if ($today_sales_result->num_rows > 0) {
                                    $today_data = $today_sales_result->fetch_assoc();
                                    $today_total = $today_data['today_total'] ?? 0;
                                }
                                echo number_format($today_total, 2);
                            }
                        ?></div>
                    </div>
                    
                    <!-- Today's Returns Card -->
                    <div class="stat-card today-returns">
                        <h3>Total Returns (Period)</h3>
                        <div class="value">Rs<?php echo number_format($period_returns, 2); ?></div>
                        <div class="sub-value"><?php echo $period_return_count; ?> items returned</div>
                    </div>
                    
                    <!-- Net Sales Card -->
                    <div class="stat-card net-sales">
                        <h3>Net Sales</h3>
                        <div class="value">Rs<?php 
                            if (isset($period_total_sales)) {
                                $net_sales = $period_total_sales - $period_returns;
                                echo number_format($net_sales, 2);
                            } else {
                                echo number_format(0, 2);
                            }
                        ?></div>
                        <div class="sub-value">(Sales - Returns)</div>
                    </div>
                    
                    <!-- Cash Payments Card -->
                    <div class="stat-card cash-payments">
                        <h3>Total Cash Payments</h3>
                        <div class="value">Rs<?php echo number_format($total_cash_payments, 2); ?></div>
                        <div class="sub-value">Based on Net Price</div>
                    </div>
                    
                    <!-- Card Payments Card -->
                    <div class="stat-card card-payments">
                        <h3>Total Card Payments</h3>
                        <div class="value">Rs<?php echo number_format($total_card_payments, 2); ?></div>
                        <div class="sub-value">Based on Net Price</div>
                    </div>

                    <!-- NEW: Credit Amounts Card -->
                    <div class="stat-card credit-amounts">
                        <h3>Total Credit Amounts</h3>
                        <div class="value">Rs<?php echo number_format($total_credit_amount, 2); ?></div>
                        <div class="sub-value"><?php echo $total_credits; ?> credit bills</div>
                    </div>
                    
                    <div class="stat-card">
                        <h3>Total Discount</h3>
                        <div class="value">Rs<?php echo number_format($total_discount, 2); ?></div>
                    </div>
                    
                    <div class="stat-card">
                        <h3>Report Period</h3>
                        <div class="value"><?php echo $from_date . ($to_date ? ' to ' . $to_date : ''); ?></div>
                    </div>
                    
                    <?php if (!empty($cashier_id)): 
                        $selected_cashier_sql = "SELECT name FROM cashier_users WHERE cashier_id = '$cashier_id'";
                        $selected_cashier_result = $conn->query($selected_cashier_sql);
                        if ($selected_cashier_result->num_rows > 0) {
                            $selected_cashier = $selected_cashier_result->fetch_assoc()['name'];
                        ?>
                        <div class="stat-card">
                            <h3>Selected Cashier</h3>
                            <div class="value"><?php echo htmlspecialchars($selected_cashier); ?></div>
                        </div>
                    <?php } endif; ?>
                </div>

                <div class="table-container">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <?php if ($report_type == 'Daily Sales'): ?>
                                    <th>Date</th>
                                    <th>Time</th>
                                <?php else: ?>
                                    <th>Month</th>
                                <?php endif; ?>
                                <th>Bill No</th>
                                <th>Sales Officers</th>
                                <th>Product</th>
                                <th>Customer Name</th>
                                <th>Quantity</th>
                                <th>Discount</th>
                                <th>Sale Price</th>
                                <th>Net Price</th> <!-- NEW COLUMN -->
                                <th>Payment Method</th>
                                <!-- Hidden columns for Paid Amount and Outstanding Balance -->
                                <th class="hidden-column">Paid Amount</th>
                                <th class="hidden-column">Total Amount</th>
                                <th class="hidden-column">Outstanding Balance</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if ($report_type == 'Daily Sales') {
                                foreach ($results as $row) {
                                    $net_price = ($row['sale_price'] * $row['quantity']) - $row['discount'];
                                    echo "<tr>";
                                    echo "<td>" . htmlspecialchars($row['date']) . "</td>";
                                    echo "<td>" . htmlspecialchars($row['time']) . "</td>";
                                    echo "<td>" . htmlspecialchars($row['bill_no']) . "</td>";
                                    echo "<td>" . htmlspecialchars($row['cashier_name']) . "</td>";
                                    echo "<td>" . htmlspecialchars($row['product_name'] ?? 'N/A') . "</td>";
                                    echo "<td>" . htmlspecialchars($row['imei'] ?? '') . "</td>";
                                    echo "<td>" . $row['quantity'] . "</td>";
                                    echo "<td>Rs" . number_format($row['discount'], 2) . "</td>";
                                    echo "<td>Rs" . number_format($row['sale_price'], 2) . "</td>";
                                    echo "<td>Rs" . number_format($net_price, 2) . "</td>"; // Net Price column
                                    echo "<td>" . htmlspecialchars($row['payment_method']) . "</td>";
                                    // Hidden columns
                                    echo "<td class='hidden-column'>Rs" . number_format($row['paid_amount'], 2) . "</td>";
                                    echo "<td class='hidden-column'>Rs" . number_format($row['total_amount'], 2) . "</td>";
                                    echo "<td class='hidden-column'>Rs" . number_format($row['outstanding_balance'], 2) . "</td>";
                                    echo "<td>";
                                    echo "<div class='action-buttons'>";
                                    echo "<button type='button' class='view-btn' onclick='viewSaleDetails(\"" . addslashes(json_encode([
                                        'date' => $row['date'],
                                        'time' => $row['time'],
                                        'bill_no' => $row['bill_no'],
                                        'cashier_name' => $row['cashier_name'],
                                        'product_name' => $row['product_name'],
                                        'imei' => $row['imei'],
                                        'quantity' => $row['quantity'],
                                        'discount' => $row['discount'],
                                        'sale_price' => $row['sale_price'],
                                        'payment_method' => $row['payment_method'],
                                        'paid_amount' => $row['paid_amount'],
                                        'total_amount' => $row['total_amount'],
                                        'outstanding_balance' => $row['outstanding_balance'],
                                        'original_price' => $row['original_price']
                                    ])) . "\")'>";
                                    echo "<i class='fas fa-eye'></i> View";
                                    echo "</button>";

                                    echo "<button type='button' class='print-btn' onclick='printBill(\"" . htmlspecialchars($row['bill_no']) . "\")'>";
                                    echo "<i class='fas fa-print'></i> Print";
                                    echo "</button>";
                                    echo "</div>";
                                    echo "</td>";
                                    echo "</tr>";
                                }
                            } else {
                                foreach ($results as $month => $group) {
                                    foreach ($group['items'] as $row) {
                                        $net_price = ($row['sale_price'] * $row['quantity']) - $row['discount'];
                                        echo "<tr>";
                                        echo "<td>" . htmlspecialchars($month) . "</td>";
                                        echo "<td>" . htmlspecialchars($row['bill_no']) . "</td>";
                                        echo "<td>" . htmlspecialchars($row['cashier_name']) . "</td>";
                                        echo "<td>" . htmlspecialchars($row['product_name'] ?? 'N/A') . "</td>";
                                        echo "<td>" . htmlspecialchars($row['imei'] ?? '') . "</td>";
                                        echo "<td>" . $row['quantity'] . "</td>";
                                        echo "<td>Rs" . number_format($row['discount'], 2) . "</td>";
                                        echo "<td>Rs" . number_format($row['sale_price'], 2) . "</td>";
                                        echo "<td>Rs" . number_format($net_price, 2) . "</td>"; // Net Price column
                                        echo "<td>" . htmlspecialchars($row['payment_method']) . "</td>";
                                        // Hidden columns
                                        echo "<td class='hidden-column'>Rs" . number_format($row['paid_amount'], 2) . "</td>";
                                        echo "<td class='hidden-column'>Rs" . number_format($row['total_amount'], 2) . "</td>";
                                        echo "<td class='hidden-column'>Rs" . number_format($row['outstanding_balance'], 2) . "</td>";
                                        echo "<td>";
                                        echo "<div class='action-buttons'>";
                                        echo "<button type='button' class='view-btn' onclick='viewSaleDetails(\"" . addslashes(json_encode([
                                            'date' => $row['date'],
                                            'time' => $row['time'],
                                            'bill_no' => $row['bill_no'],
                                            'cashier_name' => $row['cashier_name'],
                                            'product_name' => $row['product_name'],
                                            'imei' => $row['imei'],
                                            'quantity' => $row['quantity'],
                                            'discount' => $row['discount'],
                                            'sale_price' => $row['sale_price'],
                                            'payment_method' => $row['payment_method'],
                                            'paid_amount' => $row['paid_amount'],
                                            'total_amount' => $row['total_amount'],
                                            'outstanding_balance' => $row['outstanding_balance'],
                                            'original_price' => $row['original_price']
                                        ])) . "\")'>";
                                        echo "<i class='fas fa-eye'></i> View";
                                        echo "</button>";

                                        echo "<button type='button' class='print-btn' onclick='printBill(\"" . htmlspecialchars($row['bill_no']) . "\")'>";
                                        echo "<i class='fas fa-print'></i> Print";
                                        echo "</button>";
                                        echo "</div>";
                                        echo "</td>";
                                        echo "</tr>";
                                    }
                                    echo "<tr class='monthly-total'>";
                                    echo "<td colspan='10'><strong>Monthly Total for " . $month . "</strong></td>";
                                    echo "<td><strong>Cash: Rs" . number_format($group['total_cash_payments'] ?? 0, 2) . "</strong><br>";
                                    echo "<strong>Card: Rs" . number_format($group['total_card_payments'] ?? 0, 2) . "</strong></td>";
                                    // Hidden columns for monthly totals
                                    echo "<td class='hidden-column'></td>";
                                    echo "<td class='hidden-column'><strong>Rs" . number_format($group['total_sales'], 2) . "</strong></td>";
                                    echo "<td class='hidden-column'><strong>Rs" . number_format($group['total_outstanding_balance'], 2) . "</strong></td>";
                                    echo "<td colspan='1'></td>";
                                    echo "</tr>";
                                }
                            }
                            ?>
                        </tbody>
                        <!--<tfoot>-->
                        <!--    <tr>-->
                        <!--        <td colspan="<?php echo $report_type == 'Daily Sales' ? '11' : '10'; ?>"><strong>Total Sales</strong></td>-->
                        <!--        <td>-->
                        <!--            <strong>Cash: Rs<?php echo number_format($total_cash_payments, 2); ?></strong><br>-->
                        <!--            <strong>Card: Rs<?php echo number_format($total_card_payments, 2); ?></strong><br>-->
                        <!--            <strong>Total: Rs<?php echo number_format($total_cash_payments + $total_card_payments, 2); ?></strong>-->
                        <!--        </td>-->
                                <!-- Hidden columns for footer totals -->
                        <!--        <td class="hidden-column"></td>-->
                        <!--        <td class="hidden-column"><strong>Rs<?php echo number_format($total_sales, 2); ?></strong></td>-->
                        <!--        <td class="hidden-column"><strong>Rs<?php echo number_format($total_outstanding_balance, 2); ?></strong></td>-->
                        <!--        <td colspan="1"></td>-->
                        <!--    </tr>-->
                        <!--</tfoot>-->
                    </table>
                </div>

                <div class="export-buttons">
                    <form method="post">
                        <input type="hidden" name="report_type" value="<?php echo htmlspecialchars($report_type); ?>">
                        <input type="hidden" name="from" value="<?php echo htmlspecialchars($from_date); ?>">
                        <input type="hidden" name="to" value="<?php echo htmlspecialchars($to_date); ?>">
                        <input type="hidden" name="cashier_id" value="<?php echo htmlspecialchars($cashier_id); ?>">
                        <button type="submit" name="export_excel" class="btn btn-warning">
                            <i class="fas fa-file-excel"></i> Export to Excel
                        </button>
                    </form>
                    <form method="post" action="print_report.php" target="_blank">
                        <input type="hidden" name="report_type" value="<?php echo htmlspecialchars($report_type); ?>">
                        <input type="hidden" name="from" value="<?php echo htmlspecialchars($from_date); ?>">
                        <input type="hidden" name="to" value="<?php echo htmlspecialchars($to_date); ?>">
                        <input type="hidden" name="cashier_id" value="<?php echo htmlspecialchars($cashier_id); ?>">
                        <input type="hidden" name="total_items" value="<?php echo $total_items; ?>">
                        <input type="hidden" name="total_sales" value="<?php echo $period_total_sales ?? 0; ?>">
                        <input type="hidden" name="period_returns" value="<?php echo $period_returns; ?>">
                        <input type="hidden" name="period_return_count" value="<?php echo $period_return_count; ?>">
                        <input type="hidden" name="net_sales" value="<?php echo ($period_total_sales ?? 0) - $period_returns; ?>">
                        <input type="hidden" name="total_cash_payments" value="<?php echo $total_cash_payments; ?>">
                        <input type="hidden" name="total_card_payments" value="<?php echo $total_card_payments; ?>">
                        <input type="hidden" name="total_discount" value="<?php echo $total_discount; ?>">
                        <input type="hidden" name="total_credits" value="<?php echo $total_credits; ?>">
                        <input type="hidden" name="total_credit_amount" value="<?php echo $total_credit_amount; ?>">
                        <!-- Session data-யும் pass செய்யவும் -->
                        <input type="hidden" name="logged_cashiers_id" value="<?php echo $_SESSION['cashiers_id']; ?>">
                        <button type="submit" name="print_report" class="btn btn-primary">
                            <i class="fas fa-print"></i> Print Report Summary
                        </button>
                    </form>
                </div>
            <?php } else if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['generate_report'])) { ?>
                <div class="no-records">
                    <i class="fas fa-inbox"></i>
                    <h3>No data found for the selected period</h3>
                    <p>Try selecting a different date range or cashier</p>
                </div>
            <?php } else { ?>
                <div class="no-records">
                    <i class="fas fa-chart-line"></i>
                    <h3>Generate a sales report</h3>
                    <p>Select report type and date range to generate a report</p>
                </div>
            <?php } ?>
        </div>
    </div>

    <!-- View Details Modal -->
    <div id="viewModal" class="modal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeModal()">&times;</span>
            <div class="modal-header">
                <h3><i class="fas fa-receipt"></i> Sale Details</h3>
            </div>
            
            <div id="modalContent">
                <!-- Dynamic content will be loaded here -->
            </div>
            
            <div style="text-align: center; margin-top: 20px;">
                <button class="btn btn-primary" onclick="printModalDetails()">
                    <i class="fas fa-print"></i> Print Details
                </button>
                <button class="btn btn-warning" onclick="closeModal()">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
    </div>

    <div class="notification" id="notification">
        Report generated successfully!
    </div>

    <script>
        // Update time in real-time
        function updateClock() {
            const now = new Date();
            const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            document.getElementById('current-date').textContent = now.toLocaleDateString('en-US', options);
            let hours = now.getHours();
            let minutes = now.getMinutes();
            let seconds = now.getSeconds();
            const ampm = hours >= 12 ? 'PM' : 'AM';
            hours = hours % 12 || 12;
            minutes = minutes < 10 ? '0' + minutes : minutes;
            seconds = seconds < 10 ? '0' + seconds : seconds;
            const timeString = `${hours}:${minutes}:${seconds} ${ampm}`;
            document.getElementById('current-time').textContent = timeString;
            setTimeout(updateClock, 1000);
        }

        updateClock();

        // Show notification if report was generated
        <?php if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['generate_report']) && !empty($from_date)): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const notification = document.getElementById('notification');
            notification.style.display = 'block';
            setTimeout(function() {
                notification.style.display = 'none';
            }, 3000);
        });
        <?php endif; ?>
        
        // Add animation to table rows when they come into view
        document.addEventListener('DOMContentLoaded', function() {
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = 1;
                        entry.target.style.transform = 'translateY(0)';
                    }
                });
            }, { threshold: 0.1 });
            
            const tableRows = document.querySelectorAll('.report-table tr');
            tableRows.forEach(row => {
                row.style.opacity = 0;
                row.style.transform = 'translateY(20px)';
                row.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                observer.observe(row);
            });
        });
        
        // View sale details
        function viewSaleDetails(saleData) {
            try {
                const data = JSON.parse(saleData);
                const modalContent = document.getElementById('modalContent');
                
                // Calculate net price
                const netPrice = (data.sale_price * data.quantity) - data.discount;
                
                // Create details HTML
                let html = `
                    <div class="details-grid">
                        <div class="detail-item">
                            <div class="detail-label">Bill Number</div>
                            <div class="detail-value">${data.bill_no}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Date & Time</div>
                            <div class="detail-value">${data.date} ${data.time}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Cashier</div>
                            <div class="detail-value">${data.cashier_name}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Payment Method</div>
                            <div class="detail-value">${data.payment_method}</div>
                        </div>
                    </div>
                    
                    <div class="details-grid">
                        <div class="detail-item">
                            <div class="detail-label">Product Name</div>
                            <div class="detail-value">${data.product_name}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">IMEI/Serial</div>
                            <div class="detail-value">${data.imei || 'N/A'}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Quantity</div>
                            <div class="detail-value">${data.quantity}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Unit Price</div>
                            <div class="detail-value">Rs${parseFloat(data.sale_price).toFixed(2)}</div>
                        </div>
                    </div>
                    
                    <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin: 20px 0;">
                        <h4 style="color: var(--primary); margin-bottom: 15px;"><i class="fas fa-calculator"></i> Price Details</h4>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                            <div>
                                <div class="detail-label">Total Price</div>
                                <div class="detail-value">Rs${(parseFloat(data.sale_price) * parseInt(data.quantity)).toFixed(2)}</div>
                            </div>
                            <div>
                                <div class="detail-label">Discount</div>
                                <div class="detail-value">- Rs${parseFloat(data.discount).toFixed(2)}</div>
                            </div>
                            <div>
                                <div class="detail-label">Net Price</div>
                                <div class="detail-value" style="color: var(--success); font-size: 18px;">Rs${netPrice.toFixed(2)}</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="details-grid">
                        <div class="detail-item">
                            <div class="detail-label">Paid Amount</div>
                            <div class="detail-value">Rs${parseFloat(data.paid_amount).toFixed(2)}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Total Amount</div>
                            <div class="detail-value">Rs${parseFloat(data.total_amount).toFixed(2)}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Outstanding Balance</div>
                            <div class="detail-value" style="color: ${data.outstanding_balance > 0 ? 'var(--danger)' : 'var(--success)'}">
                                Rs${parseFloat(data.outstanding_balance).toFixed(2)}
                            </div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Original Price (Cost)</div>
                            <div class="detail-value">Rs${parseFloat(data.original_price).toFixed(2)}</div>
                        </div>
                    </div>
                `;
                
                modalContent.innerHTML = html;
                document.getElementById('viewModal').style.display = 'block';
            } catch (error) {
                alert('Error loading sale details: ' + error.message);
            }
        }

        // Print bill function
        function printBill(billNo) {
            if (confirm(`Print bill ${billNo}?`)) {
                // Create a form to submit to print_bill.php
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'print_bill.php';
                form.target = '_blank';
                
                // Add bill number
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'bill_no';
                input.value = billNo;
                form.appendChild(input);
                
                // Submit the form
                document.body.appendChild(form);
                form.submit();
                document.body.removeChild(form);
            }
        }

        // Print modal details
        function printModalDetails() {
            const modalContent = document.getElementById('modalContent').innerHTML;
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Sale Details</title>
                    <style>
                        body { font-family: Arial, sans-serif; padding: 20px; }
                        h2 { color: #5a3d7e; }
                        .detail-row { margin-bottom: 10px; }
                        .label { font-weight: bold; color: #666; }
                        .value { margin-left: 10px; }
                        .total { font-weight: bold; font-size: 16px; color: #28a745; }
                    </style>
                </head>
                <body>
                    <h2>Sale Details</h2>
                    ${modalContent}
                </body>
                </html>
            `);
            printWindow.document.close();
            printWindow.print();
        }

        // Close modal
        function closeModal() {
            document.getElementById('viewModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('viewModal');
            if (event.target == modal) {
                closeModal();
            }
        }
    </script>
</body>
</html>
<?php
$conn->close();
?>