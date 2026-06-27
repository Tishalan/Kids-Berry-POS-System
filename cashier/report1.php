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
                $month = date('F Y', strtotime($row['date']));
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
            --primary: #2A4B8C;
            --primary-dark: #1A336B;
            --primary-light: #4A7BDE;
            --secondary: #FF6B35;
            --secondary-dark: #E55A2B;
            --secondary-light: #FF8E5C;
            --accent: #00C9A7;
            --accent-dark: #00A88B;
            --accent-light: #2CE5CC;
            --success: #2E7D32;
            --warning: #F9A825;
            --danger: #D32F2F;
            --light: #F8F9FC;
            --dark: #1A1D29;
            --gray: #6C757D;
            --card-shadow: 0 12px 24px rgba(42, 75, 140, 0.08), 0 4px 8px rgba(42, 75, 140, 0.04);
            --card-shadow-hover: 0 20px 40px rgba(42, 75, 140, 0.12), 0 8px 16px rgba(42, 75, 140, 0.08);
            --transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
            --radius: 16px;
            --radius-sm: 8px;
            --radius-lg: 24px;
            --gradient-primary: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            --gradient-secondary: linear-gradient(135deg, var(--secondary) 0%, var(--secondary-light) 100%);
            --gradient-accent: linear-gradient(135deg, var(--accent) 0%, var(--accent-light) 100%);
            --gradient-dark: linear-gradient(135deg, var(--dark) 0%, #2D3748 100%);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #F5F7FF 0%, #E8ECFF 50%, #F0F4FF 100%);
            color: var(--dark);
            min-height: 100vh;
            line-height: 1.6;
            overflow-x: hidden;
        }

        .header {
            background: var(--gradient-dark);
            color: white;
            padding: 24px 40px;
            border-radius: 0 0 var(--radius-lg) var(--radius-lg);
            box-shadow: var(--card-shadow);
            position: relative;
            overflow: hidden;
            margin-bottom: 30px;
        }

        .header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: radial-gradient(circle at 20% 80%, rgba(74, 123, 222, 0.1) 0%, transparent 50%),
                        radial-gradient(circle at 80% 20%, rgba(255, 107, 53, 0.1) 0%, transparent 50%);
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
            z-index: 2;
        }

        .logo-container {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .logo-container img {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            border: 4px solid white;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
            transition: var(--transition);
        }

        .logo-container img:hover {
            transform: rotate(10deg) scale(1.05);
            box-shadow: 0 12px 32px rgba(0, 0, 0, 0.3);
        }

        .brand-info {
            display: flex;
            flex-direction: column;
        }

        .header h1 {
            font-size: 32px;
            font-weight: 800;
            background: linear-gradient(90deg, #FFF 0%, #FFE5D9 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 4px;
            letter-spacing: -0.5px;
        }

        .header-tagline {
            font-size: 14px;
            opacity: 0.9;
            color: #E2E8F0;
            font-weight: 500;
        }

        .date-time {
            text-align: right;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            padding: 12px 24px;
            border-radius: var(--radius);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .current-date {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .current-time {
            font-size: 14px;
            opacity: 0.9;
        }

        .logout-btn {
            background: rgba(255, 255, 255, 0.15);
            color: white;
            border: 2px solid rgba(255, 255, 255, 0.25);
            padding: 12px 28px;
            border-radius: 50px;
            cursor: pointer;
            transition: var(--transition);
            font-weight: 600;
            font-size: 15px;
            backdrop-filter: blur(10px);
            display: flex;
            align-items: center;
            gap: 10px;
            margin-left: 20px;
        }

        .logout-btn:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2);
            border-color: rgba(255, 255, 255, 0.4);
        }

        .nav-container {
            background: white;
            border-radius: var(--radius);
            padding: 20px;
            margin: 0 40px 30px;
            box-shadow: var(--card-shadow);
            border: 1px solid rgba(42, 75, 140, 0.1);
        }

        .nav {
            display: flex;
            justify-content: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .nav a {
            text-decoration: none;
            color: var(--dark);
            font-weight: 600;
            padding: 14px 28px;
            border-radius: 50px;
            background: var(--light);
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 12px;
            border: 2px solid transparent;
            font-size: 15px;
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
            background: linear-gradient(90deg, transparent, rgba(42, 75, 140, 0.1), transparent);
            transition: 0.6s;
        }

        .nav a:hover::before {
            left: 100%;
        }

        .nav a:hover {
            background: white;
            border-color: var(--primary);
            color: var(--primary);
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(42, 75, 140, 0.1);
        }

        .nav a.active {
            background: var(--gradient-primary);
            color: white;
            border-color: transparent;
            box-shadow: 0 8px 20px rgba(42, 75, 140, 0.2);
        }

        .nav a.active i {
            color: white;
        }

        .nav a i {
            color: var(--primary);
            font-size: 16px;
        }

        .main-container {
            padding: 0 40px;
            max-width: 1800px;
            margin: 0 auto 40px;
        }

        .card {
            background: white;
            border-radius: var(--radius-lg);
            padding: 32px;
            box-shadow: var(--card-shadow);
            border: 1px solid rgba(42, 75, 140, 0.1);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            margin-bottom: 30px;
        }

        .card:hover {
            box-shadow: var(--card-shadow-hover);
            transform: translateY(-4px);
        }

        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 6px;
            height: 100%;
            background: var(--gradient-primary);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 28px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--light);
            position: relative;
        }

        .card-header::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 120px;
            height: 4px;
            background: var(--gradient-primary);
            border-radius: 2px;
        }

        .card-title {
            color: var(--dark);
            font-size: 24px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .card-title i {
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-size: 22px;
        }

        /* Today's Returns Info */
        .returns-info {
            background: linear-gradient(135deg, #FFF3CD 0%, #FFEAA7 100%);
            border: 2px solid var(--warning);
            border-radius: var(--radius);
            padding: 24px;
            margin-bottom: 28px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: 0 8px 20px rgba(249, 168, 37, 0.1);
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
            padding: 12px 24px;
            background: white;
            border-radius: 50px;
            border: 2px solid var(--primary);
            transition: var(--transition);
        }

        .returns-info a:hover {
            background: var(--gradient-primary);
            color: white;
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(42, 75, 140, 0.3);
            border-color: transparent;
        }

        /* Form Container */
        .form-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 24px;
            margin-bottom: 30px;
            align-items: end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            margin-bottom: 12px;
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
            padding: 16px 20px;
            border: 2px solid #E2E8F0;
            border-radius: var(--radius);
            font-size: 15px;
            transition: var(--transition);
            background: white;
            box-shadow: 0 4px 12px rgba(42, 75, 140, 0.05);
            font-weight: 500;
        }

        .form-group input:focus,
        .form-group select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(42, 75, 140, 0.1);
            outline: none;
            transform: translateY(-2px);
        }

        .btn {
            padding: 16px 24px;
            border: none;
            border-radius: var(--radius);
            cursor: pointer;
            font-weight: 700;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            font-size: 16px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
            position: relative;
            overflow: hidden;
            height: 56px;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: 0.6s;
        }

        .btn:hover::before {
            left: 100%;
        }

        .btn-primary {
            background: var(--gradient-accent);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-5px);
            box-shadow: 0 16px 40px rgba(0, 201, 167, 0.3);
        }

        .btn-warning {
            background: #F9A825;
            color: var(--dark);
        }

        .btn-warning:hover {
            background: #e0a800;
            transform: translateY(-5px);
            box-shadow: 0 16px 40px rgba(249, 168, 37, 0.3);
        }

        /* Stats Container */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 24px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 24px;
            border-radius: var(--radius);
            box-shadow: var(--card-shadow);
            text-align: center;
            transition: var(--transition);
            border: 1px solid rgba(42, 75, 140, 0.1);
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--card-shadow-hover);
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
            background: linear-gradient(135deg, #fff 0%, #FFF3CD 100%);
            border-left: 5px solid var(--danger);
        }

        .stat-card.today-returns .value {
            color: var(--danger);
        }

        .stat-card.net-sales {
            background: linear-gradient(135deg, #fff 0%, #E3F2FD 100%);
            border-left: 5px solid var(--primary-light);
        }

        .stat-card.net-sales .value {
            color: var(--primary);
        }

        .stat-card.cash-payments {
            background: linear-gradient(135deg, #fff 0%, #E8F5E9 100%);
            border-left: 5px solid var(--success);
        }

        .stat-card.cash-payments .value {
            color: var(--success);
        }

        .stat-card.card-payments {
            background: linear-gradient(135deg, #fff 0%, #E3F2FD 100%);
            border-left: 5px solid #007bff;
        }

        .stat-card.card-payments .value {
            color: #007bff;
        }

        .stat-card.credit-amounts {
            background: linear-gradient(135deg, #fff 0%, #F3E5F5 100%);
            border-left: 5px solid #9c27b0;
        }

        .stat-card.credit-amounts .value {
            color: #9c27b0;
        }

        .stat-card h3 {
            font-size: 14px;
            color: var(--gray);
            margin-bottom: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 700;
        }

        .stat-card .value {
            font-size: 28px;
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

        /* Table Container */
        .table-container {
            overflow-x: auto;
            border-radius: var(--radius);
            box-shadow: var(--card-shadow);
            margin-bottom: 25px;
            border: 1px solid rgba(42, 75, 140, 0.1);
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
            background-color: #F8FAFF;
        }

        .report-table tr:hover {
            background-color: #E8F0FE;
            transform: translateX(5px);
        }

        .report-table td {
            padding: 16px;
            border-bottom: 1px solid #E2E8F0;
            font-size: 14px;
            font-weight: 500;
        }

        .monthly-total {
            background-color: #E8ECFF !important;
            font-weight: bold;
        }

        .monthly-total:hover {
            background-color: #D0D9FF !important;
            transform: none !important;
        }

        .hidden-column {
            display: none;
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
            color: var(--gray);
        }

        .no-records i {
            font-size: 80px;
            margin-bottom: 20px;
            color: #E2E8F0;
        }

        .no-records h3 {
            font-size: 24px;
            margin-bottom: 10px;
            color: var(--dark);
            font-weight: 700;
        }

        .no-records p {
            font-size: 16px;
            max-width: 400px;
            margin: 0 auto;
            color: var(--gray);
        }

        /* Action buttons styles */
        .action-buttons {
            display: flex;
            gap: 10px;
        }

        .view-btn {
            background: var(--gradient-primary);
            color: white;
            border: none;
            padding: 10px 18px;
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            font-size: 13px;
            box-shadow: 0 4px 12px rgba(42, 75, 140, 0.2);
        }

        .view-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(42, 75, 140, 0.3);
        }

        .print-btn {
            background: var(--gradient-accent);
            color: white;
            border: none;
            padding: 10px 18px;
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            font-size: 13px;
            box-shadow: 0 4px 12px rgba(0, 201, 167, 0.2);
        }

        .print-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0, 201, 167, 0.3);
        }

        /* Modal styles */
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
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 32px;
            border-radius: var(--radius-lg);
            width: 80%;
            max-width: 800px;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 25px 50px rgba(0,0,0,0.3);
            animation: slideDown 0.3s ease;
            position: relative;
            border: 1px solid rgba(42, 75, 140, 0.1);
        }

        .close-modal {
            position: absolute;
            right: 25px;
            top: 20px;
            font-size: 32px;
            font-weight: bold;
            cursor: pointer;
            color: var(--gray);
            transition: var(--transition);
        }

        .close-modal:hover {
            color: var(--danger);
            transform: scale(1.2);
        }

        .modal-header {
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--light);
            position: relative;
        }

        .modal-header::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 100px;
            height: 4px;
            background: var(--gradient-primary);
            border-radius: 2px;
        }

        .modal-header h3 {
            color: var(--dark);
            font-size: 24px;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 700;
        }

        .modal-header h3 i {
            color: var(--primary);
        }

        .details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .detail-item {
            background: #F8FAFF;
            padding: 20px;
            border-radius: var(--radius);
            border-left: 4px solid var(--primary);
            box-shadow: 0 4px 12px rgba(42, 75, 140, 0.05);
        }

        .detail-label {
            font-size: 12px;
            color: var(--gray);
            text-transform: uppercase;
            font-weight: 700;
            margin-bottom: 8px;
            letter-spacing: 0.5px;
        }

        .detail-value {
            font-size: 16px;
            font-weight: 700;
            color: var(--dark);
        }

        /* Notification */
        .notification {
            position: fixed;
            bottom: 40px;
            right: 40px;
            background: var(--gradient-primary);
            color: white;
            padding: 20px 32px;
            border-radius: var(--radius);
            box-shadow: 0 16px 40px rgba(42, 75, 140, 0.3);
            display: none;
            z-index: 1000;
            animation: slideInRight 0.5s ease, fadeOut 0.5s ease 2.5s forwards;
            font-weight: 600;
            font-size: 16px;
            max-width: 400px;
            backdrop-filter: blur(10px);
            border: 2px solid rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            gap: 12px;
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

        /* Responsive Design */
        @media (max-width: 1400px) {
            .main-container {
                padding: 0 20px;
            }
            
            .nav-container {
                margin: 0 20px 30px;
            }
        }

        @media (max-width: 1200px) {
            .form-container {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            }
            
            .stats-container {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            }
        }

        @media (max-width: 1024px) {
            .header {
                padding: 20px;
            }
            
            .header-content {
                flex-direction: column;
                gap: 20px;
                text-align: center;
            }
            
            .nav-container {
                margin: 0 20px 20px;
            }
            
            .card {
                padding: 24px;
            }
        }

        @media (max-width: 768px) {
            .header h1 {
                font-size: 24px;
            }
            
            .nav {
                flex-direction: column;
                align-items: stretch;
            }
            
            .nav a {
                justify-content: center;
            }
            
            .main-container {
                padding: 0 15px;
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
            
            .action-buttons {
                flex-direction: column;
            }
            
            .view-btn, .print-btn {
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .header {
                border-radius: 0 0 var(--radius) var(--radius);
            }
            
            .logo-container img {
                width: 60px;
                height: 60px;
            }
            
            .returns-info {
                flex-direction: column;
                text-align: center;
            }
            
            .stats-container {
                grid-template-columns: 1fr;
            }
            
            .report-table {
                font-size: 12px;
            }
            
            .report-table th,
            .report-table td {
                padding: 12px;
            }
            
            .details-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-content">
            <div class="logo-container">
                <img src="../logo.jpg" alt="Kids Berry Logo">
                <div class="brand-info">
                    <h1><i class="fas fa-baby"></i> KIDS Berry</h1>
                    <div class="header-tagline">Reports & Analytics</div>
                </div>
            </div>
            
            <div style="display: flex; align-items: center; gap: 20px;">
                <div class="date-time">
                    <div class="current-date"><?php echo date('l, F j, Y'); ?></div>
                    <div class="current-time"><?php echo date('g:i:s A'); ?></div>
                </div>
                <form method="post">
                    <button type="submit" name="logout" class="logout-btn">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="nav-container">
        <div class="nav">
            <a href="billing1.php"><i class="fas fa-cash-register"></i> Billing</a>
            <a href="customer.php"><i class="fas fa-users"></i> Customers</a>
            <a href="bill_management.php"><i class="fas fa-history"></i> Bill History</a>
            <a href="report.php" class="active"><i class="fas fa-chart-line"></i> Reports</a>
            <a href="credit_payments.php"><i class="fas fa-credit-card"></i> Credit Customers</a>
            <a href="return_sale.php"><i class="fas fa-undo-alt"></i> Manage Returns</a>
            <a href="prediction_dashboard.php"><i class="fas fa-bullseye"></i> Predictions</a>
        </div>
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
                    <label><i class="fas fa-calendar-start"></i> From Date</label>
                    <input type="date" name="from" value="<?php echo htmlspecialchars($from_date); ?>" required>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-calendar-end"></i> To Date</label>
                    <input type="date" name="to" value="<?php echo htmlspecialchars($to_date); ?>">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-user-tie"></i> Sales Officer</label>
                    <select name="cashier_id">
                        <option value="">All Sales Officers</option>
                        <?php 
                        $cashiers_result->data_seek(0);
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
                        <i class="fas fa-sync-alt"></i> Generate Report
                    </button>
                </div>
            </form>

            <?php if (!empty($results)) { ?>
                <div class="stats-container">
                    <div class="stat-card">
                        <h3>Total Items</h3>
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
                    
                    <div class="stat-card today-returns">
                        <h3>Period Returns</h3>
                        <div class="value">Rs<?php echo number_format($period_returns, 2); ?></div>
                        <div class="sub-value"><?php echo $period_return_count; ?> items</div>
                    </div>
                    
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
                    
                    <div class="stat-card cash-payments">
                        <h3>Cash Payments</h3>
                        <div class="value">Rs<?php echo number_format($total_cash_payments, 2); ?></div>
                        <div class="sub-value">Based on Net Price</div>
                    </div>
                    
                    <div class="stat-card card-payments">
                        <h3>Card Payments</h3>
                        <div class="value">Rs<?php echo number_format($total_card_payments, 2); ?></div>
                        <div class="sub-value">Based on Net Price</div>
                    </div>

                    <div class="stat-card credit-amounts">
                        <h3>Credit Amounts</h3>
                        <div class="value">Rs<?php echo number_format($total_credit_amount, 2); ?></div>
                        <div class="sub-value"><?php echo $total_credits; ?> credit bills</div>
                    </div>
                    
                    <div class="stat-card">
                        <h3>Total Discount</h3>
                        <div class="value">Rs<?php echo number_format($total_discount, 2); ?></div>
                    </div>
                    
                    <div class="stat-card">
                        <h3>Report Period</h3>
                        <div class="value"><?php echo $from_date; ?></div>
                        <?php if ($to_date && $to_date != $from_date): ?>
                            <div class="sub-value">to <?php echo $to_date; ?></div>
                        <?php endif; ?>
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
                                <th>Cashier</th>
                                <th>Product</th>
                                <th>IMEI</th>
                                <th>Qty</th>
                                <th>Discount</th>
                                <th>Price</th>
                                <th>Net Price</th>
                                <th>Payment</th>
                                <th class="hidden-column">Paid</th>
                                <th class="hidden-column">Total</th>
                                <th class="hidden-column">Balance</th>
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
                                    echo "<td><strong>Rs" . number_format($net_price, 2) . "</strong></td>";
                                    echo "<td><span style='background: " . ($row['payment_method'] == 'Cash' ? 'var(--success)' : ($row['payment_method'] == 'Card' ? '#007bff' : '#9c27b0')) . "; color: white; padding: 6px 12px; border-radius: 50px; font-size: 12px; font-weight: 600;'>" . htmlspecialchars($row['payment_method']) . "</span></td>";
                                    echo "<td class='hidden-column'>Rs" . number_format($row['paid_amount'], 2) . "</td>";
                                    echo "<td class='hidden-column'>Rs" . number_format($row['total_amount'], 2) . "</td>";
                                    echo "<td class='hidden-column'>Rs" . number_format($row['outstanding_balance'], 2) . "</td>";
                                    echo "<td>";
                                    echo "<div class='action-buttons'>";
                                    echo "<button type='button' class='view-btn' onclick='viewSaleDetails(" . json_encode([
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
                                    ]) . ")'>";
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
                                        echo "<td><strong>" . htmlspecialchars($month) . "</strong></td>";
                                        echo "<td>" . htmlspecialchars($row['bill_no']) . "</td>";
                                        echo "<td>" . htmlspecialchars($row['cashier_name']) . "</td>";
                                        echo "<td>" . htmlspecialchars($row['product_name'] ?? 'N/A') . "</td>";
                                        echo "<td>" . htmlspecialchars($row['imei'] ?? '') . "</td>";
                                        echo "<td>" . $row['quantity'] . "</td>";
                                        echo "<td>Rs" . number_format($row['discount'], 2) . "</td>";
                                        echo "<td>Rs" . number_format($row['sale_price'], 2) . "</td>";
                                        echo "<td><strong>Rs" . number_format($net_price, 2) . "</strong></td>";
                                        echo "<td><span style='background: " . ($row['payment_method'] == 'Cash' ? 'var(--success)' : ($row['payment_method'] == 'Card' ? '#007bff' : '#9c27b0')) . "; color: white; padding: 6px 12px; border-radius: 50px; font-size: 12px; font-weight: 600;'>" . htmlspecialchars($row['payment_method']) . "</span></td>";
                                        echo "<td class='hidden-column'>Rs" . number_format($row['paid_amount'], 2) . "</td>";
                                        echo "<td class='hidden-column'>Rs" . number_format($row['total_amount'], 2) . "</td>";
                                        echo "<td class='hidden-column'>Rs" . number_format($row['outstanding_balance'], 2) . "</td>";
                                        echo "<td>";
                                        echo "<div class='action-buttons'>";
                                        echo "<button type='button' class='view-btn' onclick='viewSaleDetails(" . json_encode([
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
                                        ]) . ")'>";
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
                                    echo "<td>";
                                    echo "<div style='display: flex; flex-direction: column; gap: 5px;'>";
                                    echo "<span style='color: var(--success); font-weight: 700;'>Cash: Rs" . number_format($group['total_cash_payments'] ?? 0, 2) . "</span>";
                                    echo "<span style='color: #007bff; font-weight: 700;'>Card: Rs" . number_format($group['total_card_payments'] ?? 0, 2) . "</span>";
                                    echo "</div>";
                                    echo "</td>";
                                    echo "<td class='hidden-column'></td>";
                                    echo "<td class='hidden-column'><strong>Rs" . number_format($group['total_sales'], 2) . "</strong></td>";
                                    echo "<td class='hidden-column'><strong>Rs" . number_format($group['total_outstanding_balance'], 2) . "</strong></td>";
                                    echo "<td></td>";
                                    echo "</tr>";
                                }
                            }
                            ?>
                        </tbody>
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
                        <input type="hidden" name="logged_cashiers_id" value="<?php echo $_SESSION['cashiers_id']; ?>">
                        <button type="submit" name="print_report" class="btn btn-primary">
                            <i class="fas fa-print"></i> Print Report
                        </button>
                    </form>
                </div>
            <?php } else if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['generate_report'])) { ?>
                <div class="no-records">
                    <i class="fas fa-inbox"></i>
                    <h3>No data found</h3>
                    <p>Try selecting a different date range or cashier</p>
                </div>
            <?php } else { ?>
                <div class="no-records">
                    <i class="fas fa-chart-line"></i>
                    <h3>Generate a report</h3>
                    <p>Select report type and date range to view sales data</p>
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
            
            <div style="display: flex; gap: 15px; justify-content: center; margin-top: 30px;">
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
        <i class="fas fa-check-circle"></i> Report generated successfully!
    </div>

    <script>
        // Update time in real-time
        function updateClock() {
            const now = new Date();
            const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            document.querySelector('.current-date').textContent = now.toLocaleDateString('en-US', options);
            let hours = now.getHours();
            let minutes = now.getMinutes();
            let seconds = now.getSeconds();
            const ampm = hours >= 12 ? 'PM' : 'AM';
            hours = hours % 12 || 12;
            minutes = minutes < 10 ? '0' + minutes : minutes;
            seconds = seconds < 10 ? '0' + seconds : seconds;
            const timeString = `${hours}:${minutes}:${seconds} ${ampm}`;
            document.querySelector('.current-time').textContent = timeString;
            setTimeout(updateClock, 1000);
        }
        updateClock();

        // Show notification if report was generated
        <?php if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['generate_report']) && !empty($from_date)): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const notification = document.getElementById('notification');
            notification.style.display = 'flex';
            setTimeout(function() {
                notification.style.display = 'none';
            }, 3000);
        });
        <?php endif; ?>
        
        // Add animation to table rows
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
                const data = saleData;
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
                            <div class="detail-value"><span style="background: ${data.payment_method == 'Cash' ? 'var(--success)' : (data.payment_method == 'Card' ? '#007bff' : '#9c27b0')}; color: white; padding: 6px 12px; border-radius: 50px; font-size: 12px; font-weight: 600;">${data.payment_method}</span></div>
                        </div>
                    </div>
                    
                    <div class="details-grid">
                        <div class="detail-item">
                            <div class="detail-label">Product Name</div>
                            <div class="detail-value">${data.product_name || 'N/A'}</div>
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
                    
                    <div style="background: linear-gradient(135deg, #F8FAFF 0%, #F0F4FF 100%); padding: 24px; border-radius: var(--radius); margin: 25px 0; border: 2px solid rgba(42, 75, 140, 0.1);">
                        <h4 style="color: var(--primary); margin-bottom: 20px; display: flex; align-items: center; gap: 10px; font-size: 18px;">
                            <i class="fas fa-calculator"></i> Price Breakdown
                        </h4>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
                            <div>
                                <div class="detail-label">Total Price</div>
                                <div class="detail-value" style="font-size: 18px;">Rs${(parseFloat(data.sale_price) * parseInt(data.quantity)).toFixed(2)}</div>
                            </div>
                            <div>
                                <div class="detail-label">Discount</div>
                                <div class="detail-value" style="color: var(--danger); font-size: 18px;">- Rs${parseFloat(data.discount).toFixed(2)}</div>
                            </div>
                            <div>
                                <div class="detail-label">Net Price</div>
                                <div class="detail-value" style="color: var(--success); font-size: 22px; font-weight: 800;">Rs${netPrice.toFixed(2)}</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="details-grid">
                        <div class="detail-item">
                            <div class="detail-label">Paid Amount</div>
                            <div class="detail-value" style="color: var(--success);">Rs${parseFloat(data.paid_amount).toFixed(2)}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Total Amount</div>
                            <div class="detail-value">Rs${parseFloat(data.total_amount).toFixed(2)}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Outstanding Balance</div>
                            <div class="detail-value" style="color: ${data.outstanding_balance > 0 ? 'var(--danger)' : 'var(--success)'};">
                                Rs${parseFloat(data.outstanding_balance).toFixed(2)}
                            </div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Cost Price</div>
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
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'print_bill.php';
                form.target = '_blank';
                
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'bill_no';
                input.value = billNo;
                form.appendChild(input);
                
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
                        body { font-family: 'Inter', Arial, sans-serif; padding: 30px; background: #F5F7FF; }
                        h2 { color: #2A4B8C; border-bottom: 2px solid #2A4B8C; padding-bottom: 15px; }
                        .details-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 20px 0; }
                        .detail-item { background: white; padding: 20px; border-radius: 12px; border-left: 4px solid #2A4B8C; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
                        .detail-label { font-size: 12px; color: #6C757D; text-transform: uppercase; font-weight: 700; margin-bottom: 8px; }
                        .detail-value { font-size: 16px; font-weight: 700; color: #1A1D29; }
                        .total { font-weight: bold; font-size: 20px; color: #00C9A7; }
                    </style>
                </head>
                <body>
                    <h2><i class="fas fa-receipt"></i> Sale Details</h2>
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